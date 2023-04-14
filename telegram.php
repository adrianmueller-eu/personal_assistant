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
     * Send a message to Telegram.
     * 
     * @param string $message The message to send.
     * @param string $parse_mode The parse mode to use.
     * @return void
     */
    public function send_message($message, $parse_mode = "Markdown") {
        $url = "https://api.telegram.org/bot".$this->telegram_token."/sendMessage";

        $max_length = 4096;

        // If $message is longer than $max_length characters, split it into multiple messages
        if (strlen($message) > $max_length) {
            $messages = explode("
", $message);
            // Ensure that each message is less than $max_length characters
            // If a message is longer than $max_length characters, split it into multiple messages via hard cuts
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
            // Merge messages as long as they are less than $max_length characters
            $new_messages = array();
            $message = "";
            foreach ($messages as $message_part) {
                if (strlen($message."\n".$message_part) > $max_length) {
                    $new_messages[] = $message;
                    $message = $message_part;
                } else {
                    $message .= "\n".$message_part;
                }
            }
            $new_messages[] = $message;
            $messages = $new_messages;
            foreach ($messages as $message) {
                $this->send_message($message);
            }
            return;
        }

        $data = array(
            "chat_id" => $this->chat_id,
            "text" => $message,
        );
        if ($parse_mode != null) {
            $data["parse_mode"] = $parse_mode;
        }

        // Send the message to Telegram
        $server_output = curl($url, $data);
        // Error handling
        if (is_string($server_output)) {
            log_error(json_encode(array(
                "timestamp" => time(),
                "message" => $server_output,
            )));
        }
        else if (!isset($server_output->ok) || !$server_output->ok) {
            log_error(json_encode(array(
                "timestamp" => time(),
                "server_response" => $server_output,
            )));
            // Try again with a different parse mode
            if ($parse_mode != null) {
                $this->send_message($message, null);
            }
        }
        // echo json_encode($server_output);
    }

    /**
     * Send an image to Telegram.
     * 
     * @param string $image_url The URL of the image to send.
     * @return void
     */
    public function send_image($image_url) {
        $url = "https://api.telegram.org/bot".$this->telegram_token."/sendPhoto";

        $data = array(
            "chat_id" => $this->chat_id,
            "photo" => $image_url,
        );
        $server_output = curl($url, $data);
        // Error handling
        if (is_string($server_output)) {
            $this->send_message($server_output);
            $encoded = json_encode(array(
                "timestamp" => time(),
                "message" => $server_output,
            ));
            log_error($encoded);
        }
        else if (!isset($server_output->ok) || !$server_output->ok) {
            $encoded = json_encode($server_output);
            $data = json_encode($data, JSON_PRETTY_PRINT);
            $this->send_message("Error sending image: ".$encoded."\n\n"."Data sent: ".$data, null);
            log_error("Error sending image: ".$encoded);
        }
        // echo json_encode($server_output);
    }

    /**
     * Send a document to Telegram.
     * For sending via URL: "In sendDocument, sending by URL will currently only work for GIF, PDF and ZIP files."
     * 
     * @param string $file_name The name of the file.
     * @param string $file_content The content of the file.
     */
    public function send_document($file_name, $file_content) {
        $url = "https://api.telegram.org/bot".$this->telegram_token."/sendDocument";

        // This doesn't work for some reason
        // $data = array(
        //     "chat_id" => $this->chat_id,
        //     "document" => new CURLStringFile($file_content, $file_name, "text/plain"),
        // );
        // file_put_contents($file_name, $file_content);
        // $data = json_encode($data);
        // $server_output = curl($url, $data, array()); // 'Content-Type: application/json'

        $data = array("chat_id" => $this->chat_id);
        $server_output = curl($url, $data, array(), $file_name, $file_content);
        // Error handling
        if (is_string($server_output)) {
            $this->send_message($server_output);
            $encoded = json_encode(array(
                "timestamp" => time(),
                "message" => $server_output,
            ));
            log_error($encoded);
        }
        else if (!isset($server_output->ok) || !$server_output->ok) {
            $encoded = json_encode($server_output);
            $data = json_encode($data, JSON_PRETTY_PRINT);
            $this->send_message("Error sending document: ".$encoded."\n\n"."Data sent: ".$data, null);
            log_error("Error sending document: ".$encoded);
        }
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