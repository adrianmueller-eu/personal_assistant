<?php

require_once __DIR__."/logger.php";
require_once __DIR__."/utils.php";

/**
 * This class manages the connection to the Telegram API.
 */
class Telegram {
    
    private $telegram_token;
    private $chat_id;

    /**
     * Create a new Telegram instance.
     * 
     * @param string $telegram_token The Telegram bot token.
     * @param string $chat_id The chat ID.
     */
    public function __construct($telegram_token, $chat_id) {
        $this->telegram_token = $telegram_token;
        $this->chat_id = $chat_id;
    }

    /**
     * Generic function to send a request to the Telegram API.
     * 
     * @param string $endpoint The endpoint to send the request to.
     * @param object|array $data The data to send to the Telegram API.
     * @param array $headers (optional) The headers to send to the Telegram API.
     * @param string $file_name (optional) The name of the file to send to the Telegram API.
     * @param string $file_content (optional) The content of the file to send to the Telegram API.
     * @return object|null The response from the Telegram API or null if there was an error.
     */
    private function send($endpoint, $data, $headers = array(), $file_name = null, $file_content = null) {
        $url = "https://api.telegram.org/bot".$this->telegram_token."/".$endpoint;

        $server_output = curl($url, $data, $headers, $file_name, $file_content);
        if (isset($server_output->ok) && $server_output->ok) {
            return $server_output;
        }
        // Error handling
        log_error(json_encode(array(
            "timestamp" => time(),
            "endpoint" => $endpoint,
            "server_response" => $server_output,
            "data" => $data,
        )));
        if ($endpoint == "sendMessage") {
            // Try again without parse mode
            if (isset($data->parse_mode)) {
                $this->send_message($data->text, null);
            }
            // else, silently fail
        } else if (is_string($server_output)) {
            $this->send_message($server_output, null);
        } else {
            $this->send_message("Error: [/".$endpoint."] ".json_encode($server_output, JSON_PRETTY_PRINT), null);
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
     * @param string $parse_mode The parse mode to use.
     * @return void
     */
    public function send_message($message, $parse_mode = "Markdown") {
        $messages = $this->split_message($message);

        foreach ($messages as $m) {
            $data = (object) array(
                "chat_id" => $this->chat_id,
                "text" => $m,
            );
            if ($parse_mode != null) {
                $data->parse_mode = $parse_mode;
            }
            $this->send("sendMessage", $data);
        }
    }

    /**
     * Send an image to Telegram.
     * 
     * @param string $image_url The URL of the image to send.
     * @return void
     */
    public function send_image($image_url) {
        $this->send("sendPhoto", array(
            "chat_id" => $this->chat_id,
            "photo" => $image_url,
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
        ), array(), $file_name, $file_content);
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
     * Get the chat ID.
     * 
     * @return string The chat ID.
     */
    public function get_chat_id() {
        return $this->chat_id;
    }
}
?>