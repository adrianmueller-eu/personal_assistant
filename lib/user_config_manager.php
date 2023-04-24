<?php
// Import function "curl"
require_once __DIR__."/utils.php";

/**
 * This class manages the persistent data for a chat.
 * 
 * The JSON file is named after the chat ID. It has the following format:
 * ```json
 * {
 *     "username": "test_user",
 *     "name": "Joe",
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
class UserConfigManager {

    private $user_config_file;
    private $user_data;
    private $username;
    private $name;

    /**
     * @param string $chat_id The chat ID
     * @param string $username The username of the user. Will only be used if the config is not yet created.
     * @param string $name The name of the user. Will only be used if the config is not yet created.
     */
    public function __construct($chat_id, $username, $name) {
        $chats_dir = __DIR__."/../chats";
        $this->username = $username;
        if ($name === null || $name === "") {
            $name = $username;
        }
        $this->name = $name;
        
        $this->user_config_file = $chats_dir."/".$chat_id.".json";
        $this->load();
    }

    private function load() {
        if (!file_exists($this->user_config_file)) {
            $this->user_data = (object) array(
                "username" => $this->username,
                "name" => $this->name,
                "config" => (object) array(
                    "model" => "gpt-4",
                    "temperature" => 0.7,
                    "messages" => array(),
                ),
                "sessions" => (object) array(),
            );
            $this->save(); // Keep this
        } else {
            $this->user_data = json_decode(file_get_contents($this->user_config_file), false);
            if ($this->user_data === null || $this->user_data === false) {
                if ($this->user_data === null) {
                    $error = json_last_error_msg();
                } else {
                    $error = "Could not read file: ".$this->user_config_file;
                }
                Log::error(json_encode(array(
                    "timestamp" => time(),
                    "message" => $error,
                )));
                http_response_code(500);
                throw new Exception($error);
            }
            $this->name = $this->user_data->name;
        }
    }

    private function save() {
        $res = file_put_contents($this->user_config_file, json_encode($this->user_data, JSON_PRETTY_PRINT));
        if ($res === false) {
            Log::error(json_encode(array(
                "timestamp" => time(),
                "message" => "Could not save user config file: ".$this->user_config_file,
            )));
            http_response_code(500);
            throw new Exception("Could not save user config file: ".$this->user_config_file);
        }
    }

    /**
     * @return object The config object with messages and model parameters.
     */
    public function get_config() {
        return $this->user_data->config;
    }

    /**
     * Save the config object permanently. It has the properties "model", "temperature" and "messages".
     * 
     * @param object|array $config The config object with messages and model parameters.
     * @return void
     */
    public function save_config($config) {
        $this->user_data->config = $config;
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
        if (!isset($this->user_data->sessions->$key)) {
            return null;
        }
        return $this->user_data->sessions->$key;
    }

    /**
     * Save the session info object permanently. Its properties can be set arbitrarily for each key.
     * 
     * @param string $key The key of the session.
     * @param object|array $session_info The session info object.
     */
    public function save_session_info($key, $session_info) {
        $this->user_data->sessions->$key = $session_info;
        $this->save();
    }

    /**
     * @return string The path to the user config file.
     */
    public function get_file() {
        return $this->user_config_file;
    }

    public function get_backup_file() {
        return $this->user_config_file.".backup";
    }

    /**
     * @return string The name of the user.
     */
    public function get_name() {
        return $this->name;
    }

    /**
     * Set the name of the user.
     * 
     * @param string $name The name of the user.
     */
    public function set_name($name) {
        $this->name = $name;
        $this->save();
    }
}

?>