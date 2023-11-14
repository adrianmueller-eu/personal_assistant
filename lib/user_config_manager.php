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

    static $default_config = array(
        "model" => "gpt-4-vision-preview",
        "temperature" => 0.9,
        "max_tokens" => 4096,
        "messages" => array(),
    );

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
                "intro" => "",
                "hellos" => array(),
                "config" => (object) self::$default_config,
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
                Log::error($error);
                http_response_code(500);
                throw new Exception($error);
            }
            $this->name = $this->user_data->name;
        }
    }

    private function save() {
        $res = file_put_contents($this->user_config_file, json_encode($this->user_data, JSON_PRETTY_PRINT));
        if ($res === false) {
            Log::error("Could not save user config file: ".$this->user_config_file);
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
        // convert $config to object if it is an array
        if (is_array($config)) {
            $config = (object) $config;
        }
        // set default values if missing
        foreach (self::$default_config as $key => $value) {
            if (!isset($config->$key)) {
                $config->$key = $value;
            }
        }
        $this->user_data->config = $config;
        $this->save();
    }

    /**
     * Add a message to the chat history.
     * 
     * @param string $role The role of the message sender.
     * @param string|array $content The message content.
     * @return void
     */
    public function add_message($role, $content) {
        // Ignore empty messages
        if (is_string($content) && trim($content) === "") {
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

    /**
     * @return string The path to the backup file.
     */
    private function get_backup_file() {
        return $this->user_config_file.".backup";
    }

    /**
     * Save a backup of the current user config file.
     */
    public function save_backup() {
        $backup_file = $this->get_backup_file();
        $res = copy($this->user_config_file, $backup_file);
        if ($res === false) {
            Log::error("Could not save backup of user config file: ".$this->user_config_file);
            http_response_code(500);
            throw new Exception("Could not save backup of user config file: ".$this->user_config_file);
        }
    }

    /**
     * Restore the backup of the user config file.
     * 
     * @return bool True if the backup file exists and was restored, false otherwise. Throws an exception if the backup file exists but could not be restored.
     */
    public function restore_backup() {
        $backup_file = $this->get_backup_file();
        if (!file_exists($backup_file)) {
            return false;
        }
        $res = copy($backup_file, $this->user_config_file);
        if ($res === false) {
            Log::error("Could not restore backup of user config file: ".$this->user_config_file);
            http_response_code(500);
            throw new Exception("Could not restore backup of user config file: ".$this->user_config_file);
        }
        # load the restored file into memory
        $this->load();
        return true;
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

    /**
     * Get the intro text of the user.
     * 
     * @return string The intro text of the user.
     */
    public function get_intro() {
        return $this->user_data->intro;
    }

    /**
     * Set the intro text of the user.
     * 
     * @param string $intro The intro text of the user.
     */
    public function set_intro($intro) {
        $this->user_data->intro = $intro;
        $this->save();
    }

    /**
     * Get the hellos of the user.
     *
     * @return array The hellos of the user.
     */
    public function get_hellos() {
        return $this->user_data->hellos;
    }

    /**
     * Set the hellos of the user.
     *
     * @param array $hellos The hellos of the user.
     */
    public function set_hellos($hellos) {
        $this->user_data->hellos = $hellos;
        $this->save();
    }

    /**
     * Return a random hello to the user.
     */
    public function hello() {
        $hellos = $this->get_hellos();
        if (count($hellos) == 0) {
            return "Hi there, this is your personal assistant. How can I help you? Type /help to see what I can do.";
        }
        $index = rand(0, count($hellos) - 1);
        return $hellos[$index];
    }
}

?>