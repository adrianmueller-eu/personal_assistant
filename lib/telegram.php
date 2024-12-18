<?php

require_once __DIR__."/logger.php";
require_once __DIR__."/utils.php";

/**
 * This class manages the connection to the Telegram API.
 */
class Telegram {
    
    private $telegram_token;
    private $chat_id;
    private $DEBUG;
    private $RETRY_CNT = 0;
    private $MAX_RETRY = 4;

    /**
     * Create a new Telegram instance.
     * 
     * @param string $telegram_token The Telegram bot token.
     * @param string $chat_id The chat ID.
     */
    public function __construct($telegram_token, $chat_id, $DEBUG = False) {
        if (!preg_match("/^[0-9]+:[a-zA-Z0-9_-]+$/", $telegram_token)) {
            throw new Exception("Invalid Telegram token: ".$telegram_token);
        }
        // Check if the chat ID is valid
        if (!preg_match("/^-?[0-9]+$/", $chat_id)) {
            throw new Exception("Invalid chat ID: ".$chat_id);
        }
        $this->telegram_token = $telegram_token;
        $this->chat_id = $chat_id;
        $this->DEBUG = $DEBUG;
    }

    /**
     * Generic function to send a POST request to the Telegram API. To send a file, set $field_name, $file_name, *and* $file_content.
     * 
     * @param string $endpoint The endpoint to send the request to.
     * @param object|array $data The data to send to the Telegram API.
     * @param array $headers (optional) The headers to send to the Telegram API.
     * @param string $field_name (optional) The name of the field with the file content.
     * @param string $file_name (optional) The name of the file to send to the Telegram API.
     * @param string $file_content (optional) The content of the file to send to the Telegram API.
     * @return object|null The response from the Telegram API or null if there was an error.
     */
    private function send($endpoint, $data, $headers = array(), $field_name = null, $file_name = null, $file_content = null) {
        $url = "https://api.telegram.org/bot".$this->telegram_token."/".$endpoint;

        $server_output = curl_post($url, $data, $headers, $field_name, $file_name, $file_content);
        // DEBUG endpoint, data, server_output, and optionally file_name and file_content
        if ($this->DEBUG) {
            if ($file_name != null) {
                Log::debug(array(
                    "interface" => "telegram",
                    "endpoint" => $endpoint,
                    "data" => $data,
                    "file_name" => $file_name,
                    "file_content" => $file_content,
                    "server_response" => $server_output,
                ));
            } else {
                Log::debug(array(
                    "interface" => "telegram",
                    "endpoint" => $endpoint,
                    "data" => $data,
                    "server_response" => $server_output,
                ));
            }
        }
        if (isset($server_output->ok) && $server_output->ok) {
            return $server_output;
        }
        // Error handling
        Log::error(array(
            "interface" => "telegram",
            "endpoint" => $endpoint,
            "server_response" => $server_output,
            "data" => $data,
        ));
        if ($endpoint == "sendMessage") {
            if (is_object($server_output) && !$server_output->ok) {
                // Try again without parse mode if $server_output is a string that contains "can't parse entities"
                if (isset($data->parse_mode) && strpos($server_output->description, "can't parse entities") !== false) {
                    $this->send_message($data->text, false);
                }
                // Try again after a few seconds
                else if ($this->RETRY_CNT < $this->MAX_RETRY) {
                    $this->RETRY_CNT++;
                    sleep(5*$this->RETRY_CNT);
                    $this->send_message("\[Retry $this->RETRY_CNT] $data->text", isset($data->parse_mode));
                }
            }
            // else, silently fail
        } else if (is_string($server_output)) {
            $this->send_message($server_output, false);
        } else {
            $this->send_message("Error: [/$endpoint] ".json_encode($server_output, JSON_PRETTY_PRINT), false);
        }
        // echo json_encode($server_output);
        return null;
    }

    /**
     * Split a message into multiple messages if it is too long.
     * 
     * @param string $message The message to split.
     * @param int $max_length The maximum length of each message.
     * @return array The messages of maximum $max_length characters.
     */
    private function split_message($message, $max_length = 4096) {
        if (strlen($message) < $max_length)
            return array($message);

        // Split message into multiple messages via new lines
        $messages = explode("\n", $message);

        // If a message is still longer than $max_length characters, split it into multiple messages via hard cuts
        $new_messages = array();
        foreach ($messages as $message) {
            if (strlen($message) > $max_length) {
                $message_parts = str_split($message, $max_length);
                foreach ($message_parts as $message_part) {
                    $new_messages[] = $message_part;
                }
            } else {
                $new_messages[] = $message;
            }
        }
        $messages = $new_messages;

        // Merge messages again as long as the result is shorter than $max_length characters
        $new_messages = array();
        $new_message = "";
        foreach ($messages as $message_part) {
            if (strlen($new_message."\n".$message_part) > $max_length) {
                $new_messages[] = $new_message;
                $new_message = $message_part;
            } else {
                $new_message .= "\n".$message_part;
            }
        }
        $new_messages[] = $new_message;

        return $new_messages;
    }

    /**
     * Send a message to Telegram.
     * 
     * @param string $message The message to send.
     * @param bool $is_markdown (optional) Whether the message is markdown or not. Default: true.
     * @return void
     */
    public function send_message($message, $is_markdown = true) {
        if (empty($message) || trim($message) == "") {
            Log::error(array(
                "interface" => "telegram",
                "message" => "Empty message [$message]",
            ));
            return;
        }
        $messages = $this->split_message($message);

        foreach ($messages as $m) {
            $data = (object) array(
                "chat_id" => $this->chat_id,
                "text" => $m,
                "disable_web_page_preview" => "true",
            );
            if ($is_markdown) {
                $data->parse_mode = "Markdown";
            }
            $this->send("sendMessage", $data);
        }
    }

    /**
     * Send an image to Telegram.
     * 
     * @param string $image The file id or URL of the image to send.
     * @param string $caption (optional) The caption of the image.
     * @return void
     */
    public function send_image($image, $caption = "") {
        $this->send("sendPhoto", array(
            "chat_id" => $this->chat_id,
            "photo" => $image,
            "caption" => $caption,
        ));
    }

    /**
     * Send a document to Telegram.
     * For sending via URL: "In sendDocument, sending by URL will currently only work for GIF, PDF and ZIP files."
     * 
     * @param string $file_name The name of the file.
     * @param string $file_content The content of the file.
     */
    public function send_document($file_name, $file_content) {
        $this->send("sendDocument", array(
            "chat_id" => $this->chat_id
        ), array(), "document", $file_name, $file_content);
    }

    /**
     * Send a voice message to Telegram. The file name must be in an .OGG file encoded with OPUS.
     * 
     * @param string $ogg_content The content of the OGG file.
     */
    public function send_voice($ogg_content) {
        $this->send("sendVoice", array(
            "chat_id" => $this->chat_id
        ), array(), "voice", "audio.ogg", $ogg_content);
    }

    /**
     * Get a file url from a Telegram file ID.
     * 
     * @param string $file_id The file ID
     * @return string|null The file url or null if there was an error.
     */
    public function get_file_url($file_id) {
        $server_output = $this->send("getFile", array(
            "file_id" => $file_id
        ));
        if ($server_output == null) {
            return null;
        }
        return "https://api.telegram.org/file/bot".$this->telegram_token."/".$server_output->result->file_path;
    }

    /**
     * Get the file content from a Telegram file ID.
     * 
     * @param string $file_id The file ID
     * @return string|null The file content or null if there was an error.
     */
    public function get_file($file_id) {
        $file_url = $this->get_file_url($file_id);
        if ($file_url == null) {
            return null;
        }
        return file_get_contents($file_url);
    }

    /**
     * Get the chat ID.
     * 
     * @return string The chat ID.
     */
    public function get_chat_id() {
        return $this->chat_id;
    }

    public function format_message($response, $math_mode = false) {
        // If math mode is set, replace the Latex response with the Markdown version
        if ($math_mode) {
            // $this->send_message("Original response: $response", false);

            // For each \[ find the corresponding \] (might be on later lines) and replace both by ```
            $start = 0;
            while (($start < strlen($response) && $start = strpos($response, "\\[", $start)) !== false) {
                $end = strpos($response, "\\]", $start+2);
                if ($end === false) {
                    $end = strlen($response);
                }
                $latex = substr($response, $start, $end - $start + 2);
                $latex_new = substr($latex, 2, strlen($latex)-4);
                $latex_new = trim($latex_new);
                $response = str_replace($latex, "```\n$latex_new\n```", $response);
                $start = $end;
            }
            $response = preg_replace('/ *```/', '```', $response);

            // For each $$ find the corresponding $$ (might be on later lines) and replace both by ```
            $start = 0;
            while (($start < strlen($response) && $start = strpos($response, "\$\$", $start)) !== false) {
                $end = strpos($response, "\$\$", $start+2);
                if ($end === false) {
                    $end = strlen($response);
                }
                $latex = substr($response, $start, $end - $start + 2);
                $latex_new = substr($latex, 2, strlen($latex)-4);
                $latex_new = trim($latex_new);
                $response = str_replace($latex, "```\n$latex_new\n```", $response);
                $start = $end;
            }
            $response = preg_replace('/ *```/', '```', $response);

            // For each \( find the corresponding \) and replace both by `
            $response = preg_replace('/\\\\\( ?(.*?) ?\\\\\)/', '`$1`', $response);
            // Same for $ and $
            $response = preg_replace('/\$ ?(.*?) ?\$/', '`$1`', $response);
        }
        // If a text is not already in a code block
        $response_new = "";
        $lines = explode("\n", $response);
        $is_in_code_block = False;
        for ($i = 0; $i < count($lines); $i++) {
            if (strpos($lines[$i], "```") !== false) {
                $is_in_code_block = !$is_in_code_block;
            }
            if (!$is_in_code_block) {
                // Surround all words containing underscores with backticks
                $matches = array();
                preg_match_all('/(?<!`)(\b\w+_[a-zA-Z0-9_]*\w+\b)(?!`)/', $lines[$i], $matches, PREG_OFFSET_CAPTURE);
                $offset = 0;
                foreach ($matches[1] as $match) {
                    $start = $match[1] + $offset;
                    $end = $start + strlen($match[0]);
                    $count_before = substr_count(substr($lines[$i], 0, $start), '`');
                    $count_after = substr_count(substr($lines[$i], 0, $end), '`');
                    if ($count_before % 2 == 0 && $count_after % 2 == 0) {
                        $lines[$i] = substr($lines[$i], 0, $start)."`".$match[0]."`".substr($lines[$i], $end);
                        $offset += 2;
                    }
                }
                // Replace all ** outside of code blocks by *
                $lines[$i] = preg_replace('/(?<!`)\*\*(.*?)(?<!`)\*\*/', '*$1*', $lines[$i]);
                // Replace headings (a line beginning with at least one #) by bold text
                $lines[$i] = preg_replace('/^#+ (.*)$/', '*$1*', $lines[$i]);
            }
            $response_new .= $lines[$i]."\n";
        }
        // remove trailing newline
        $response = substr($response_new, 0, -1);
        return $response;
    }
}
?>