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
    private $allowed_users_file;
    private $allowed_users;
    private $data = null;

    /**
     * @param string $chat_id The chat ID
     */
    public function __construct($chat_id) {
        $chats_dir = __DIR__."/chats";
        
        $this->file = $chats_dir."/".$chat_id.".json";
        $this->allowed_users_file = $chats_dir."/allowed_users.json";
        // Create the directory if it does not exist
        if (!file_exists($chats_dir)) {
            mkdir($chats_dir);
        }
        $this->load();
    }


    private function load() {
        $this->data = json_decode(file_get_contents($this->file), false);
        if ($this->data == null) {
            $this->data = (object) array(
                "config" => (object) array(
                    "model" => "gpt-4",
                    "temperature" => 0.7,
                    "messages" => array(),
                ),
                "sessions" => (object) array(),
            );
        }

        // Check if the chat is allowed to use the assistant
        $this->allowed_users = json_decode(file_get_contents($this->allowed_users_file), false);
        if ($this->allowed_users == null) {
            $this->allowed_users = (object) array(
                "general" => array(),
                "mental_health" => array(),
            );
        }

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
        // Ignore empty messages
        if (trim($content) == "") {
            return;
        }
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
    public function delete_messages($n) {
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

    /**
     * Check if a user is allowed to use the assistant.
     * 
     * @param string $username The username of the user.
     * @param string $category The category of the user. Currently only "general" and "mental_health" are supported.
     * @return bool True if the user is allowed to use the assistant.
     */
    public function is_allowed_user($username, $category = "general") {
        if ($username == null || $username == "")
            return false;
        return in_array($username, $this->allowed_users->$category);
    }

    /**
     * Get the list of allowed users.
     * 
     * @param string $category The category of the user. Currently only "general" and "mental_health" are supported.
     * @return array The list of allowed users for the given category.
     */
    public function get_allowed_users($category = "general") {
        return $this->allowed_users->$category;
    }

    /**
     * Save the list of allowed users.
     */
    private function save_allowed_users() {
        file_put_contents($this->allowed_users_file, json_encode($this->allowed_users, JSON_PRETTY_PRINT));
    }

    /**
     * Add a user to the list of allowed users.
     * 
     * @param string $username The username of the user.
     */
    public function add_allowed_user($username, $category = "general") {
        if ($username == null || $username == "") return;
        if (!isset($this->allowed_users->$category)) return;
    
        $this->allowed_users->$category[] = $username;
        $this->save_allowed_users();
    }

    /**
     * Remove a user from the list of allowed users.
     * 
     * @param string $username The username of the user.
     * @param string $category The category of the user. Currently only "general" and "mental_health" are supported.
     */
    public function remove_allowed_user($username, $category = "general") {
        if ($username == null || $username == "") return;
        if (!isset($this->allowed_users->$category)) return;

        $this->allowed_users->$category = array_diff($this->allowed_users->$category, array($username));
        $this->save_allowed_users();
    }

    public function get_categories() {
        return array_keys((array) $this->allowed_users);
    }
}

?>