<?php
// Import function "curl"
require_once __DIR__."/utils.php";

/**
 * This class manages the persistent data for a chat.
 * 
 * The JSON file is named after the chat ID. It has the following format:
 * ```json
 * {
 *     "config": {
 *         "model": "gpt-4",
 *         "temperature": 0.7,
 *         "messages": [{
 *             "role": "system",
 *             "content": "You are a helpful assistant."
 *         }, {
 *             "role": "user",
 *             "content": "Who won the world series in 2020?"
 *         }, {
 *             "role": "assistant",
 *             "content": "The Los Angeles Dodgers won the World Series in 2020."
 *         }, {
 *             "role": "user",
 *             "content": "Where was it played?"
 *         }]
 *     },
 *     "sessions": {
 *         "default": {
 *             "property1": "value1",
 *             "property2": "value2"
 *         }
 *     }
 * }
 * ```
 */
class StorageManager {
    /**
     * The path to the storage file.
     */
    private $file;
    private $data = null;

    /**
     * @param string $chat_id The chat ID
     */
    public function __construct($chat_id) {
        $this->file = __DIR__."/chats/".$chat_id.".json";
        $this->load();
    }


    private function load() {
        $this->data = json_decode(file_get_contents($this->file), false);
    }

    private function save() {
        file_put_contents($this->file, json_encode($this->data, JSON_PRETTY_PRINT));
    }

    public function get_file() {
        return $this->file;
    }

    /**
     * @return object The config object with messages and model parameters.
     */
    public function get_config() {
        return $this->data->config;
    }

    /**
     * Save the config object permanently. It has the properties "model", "temperature" and "messages".
     * 
     * @param object|array $config The config object with messages and model parameters.
     * @return void
     */
    public function save_config($config) {
        $this->data->config = $config;
        $this->save();
    }

    /**
     * Add a message to the chat history.
     * 
     * @param string $role The role of the message sender.
     * @param string $content The message content.
     * @return void
     */
    public function add_message($role, $content) {
        $chat = $this->get_config();
        // Add the message
        $chat->messages[] = (object) array(
            "role" => $role,
            "content" => $content,
        );
        $this->save_config($chat);
    }

    /**
     * Delete the last $n messages from the chat history.
     * 
     * @param int $n The number of messages to delete.
     * @return int The number of actually deleted messages.
     */
    public function delete_messages($n = 1) {
        // Delete the last $n messages
        $chat = $this->get_config();
        // n must not be greater than the actual number of messages
        $n = min($n, count($chat->messages));
        $chat->messages = array_slice($chat->messages, 0, -$n);
        $this->save_config($chat);
        // Return the number of actually deleted messages
        return $n;
    }

    /**
     * Read the session info. Its properties can be set arbitrarily for each key when saving.
     * 
     * @param string $key The key of the session.
     * @return object|null The session info object or null if the session does not exist.
     */
    public function get_session_info($key) {
        if (!isset($this->data->sessions->$key)) {
            return null;
        }
        return $this->data->sessions->$key;
    }

    /**
     * Save the session info object permanently. Its properties can be set arbitrarily for each key.
     * 
     * @param object|array $session_info The session info object.
     * @param string $key The key of the session.
     */
    public function save_session_info($session_info, $key) {
        $this->data->sessions->$key = $session_info;
        $this->save();
    }
}

?>