<?php
// Import function "curl"
require_once __DIR__."/utils.php";

/**
 * This class manages the chat file.
 * 
 * The JSON file is named after the chat ID. It has the following format:
 * ```json
 * {
 *     "model": "gpt-4",
 *     "temperature": 0.7,
 *     "messages": [
 *         {"role": "system", "content": "You are a helpful assistant."},
 *         {"role": "user", "content": "Who won the world series in 2020?"},
 *         {"role": "assistant", "content": "The Los Angeles Dodgers won the World Series in 2020."},
 *         {"role": "user", "content": "Where was it played?"}
 *     ]
 * }
 * ```
 */
class ChatManager {
    /**
     * The path to the chat file.
     */
    private $chat_file;

    /**
     * Create a new ChatManager instance.
     * 
     * @param string $chat_id The chat ID.
     */
    public function __construct($chat_id) {
        $this->chat_file = __DIR__."/".$chat_id.".json";
    }

    /**
     * Add a message to the chat file.
     * 
     * @param string $role The role of the message sender.
     * @param string $content The message content.
     * @return void
     */
    public function add_message($role, $content) {
        $chat = $this->get();
        // Add the message
        $chat->messages[] = (object) array(
            "role" => $role,
            "content" => $content,
        );
        $this->save($chat);
    }

    /**
     * Delete the last $n messages from the chat file.
     * 
     * @param int $n The number of messages to delete.
     * @return int The number of actually deleted messages.
     */
    public function delete_messages($n = 1) {
        // Delete the last $n messages
        $chat = $this->get();
        // n must not be greater than the actual number of messages
        $n = min($n, count($chat->messages));
        $chat->messages = array_slice($chat->messages, 0, -$n);
        $this->save($chat);
        // Return the number of actually deleted messages
        return $n;
    }

    /**
     * Get the chat file.
     * 
     * @return object The chat object with messages and model parameters.
     */
    public function get() {
        return json_decode(file_get_contents($this->chat_file), false);
    }

    /**
     * Get the model of the chat.
     * 
     * @param object|array $chat The chat object with messages and model parameters.
     * @return void
     */
    public function save($chat) {
        file_put_contents($this->chat_file, json_encode($chat, JSON_PRETTY_PRINT));
    }
}

?>