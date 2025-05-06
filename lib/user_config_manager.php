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
 *     "lang": "en",
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
 *     },
 *     "tts_config": {
 *         "model": "tts-1",
 *         "voice": "shimmer",
 *         "speed": 1.0
 *     },
 *     "intro": "Please always add emojis to your messages.",
 *     "openrouter_api_key": "sk-1234567890",
 *     "openai_api_key": "sk-1234567890",
 *     "anthropic_api_key": "sk-1234567890",
 *     "time_zone": "UTC",
 *     "last_seen": "2024-01-01 12:00:00 UTC",
 *     "post_processing": false,
 *     "hellos": [],
 *     "counters": {}
 * }
 * ```
 */
class UserConfigManager {

    private $user_config_file;
    private $user_data;
    private $DEBUG;

    static $default_config = array(
        "model" => "anthropic/claude-3.5-sonnet",
        "temperature" => 0.9,
        "messages" => array(),
    );

    static $default_tts_config = array(
        "model" => "tts-1-hd",
        "voice" => "shimmer",
        "speed" => 1.0
    );

    /**
     * @param string $chat_id The chat ID
     * @param string $username The username of the user. Will only be used if the config is not yet created.
     * @param string $name The name of the user. Will only be used if the config is not yet created.
     * @param string $lang The language code of the user. Will only be used if the config is not yet created.
     */
    public function __construct($chat_id, $username = null, $name = null, $lang = "en", $DEBUG = false) {
        $chats_dir = __DIR__."/../chats";
        if ($name === null || $name === "") {
            $name = $username;
        }

        // replace - with _ in chat_id, to avoid issues with file names
        $chat_id = str_replace("-", "_", $chat_id);
        $this->user_config_file = "$chats_dir/$chat_id.json";
        $this->load($username, $name, $lang);
        $this->DEBUG = $DEBUG;
    }

    public function __destruct() {
        $this->save();
    }

    private function load($username, $name, $lang) {
        if (file_exists($this->user_config_file)) {
            $this->user_data = json_decode(file_get_contents($this->user_config_file), false);
            if ($this->user_data === null || $this->user_data === false) {
                if ($this->user_data === null) {
                    $error = "JSON error: ".json_last_error_msg();
                } else {
                    $error = "Could not read file: $this->user_config_file";
                }
                Log::error($error);
                http_response_code(500);
                throw new Exception($error);
            }
        } else {
            $this->user_data = (object) array(
                "username" => $username,
                "name" => $name,
                "lang" => $lang,
                "intro" => "",
                "hellos" => array(),
                "config" => (object) self::$default_config,
                "sessions" => (object) array(),
                "tts_config" => (object) self::$default_tts_config,
                "openrouter_api_key" => "",
                "openai_api_key" => "",
                "anthropic_api_key" => "",
                "time_zone" => "Europe/Helsinki",
                "last_seen" => date("Y-m-d H:i:s e"),
                "post_processing" => false,
                "counters" => (object) array(),
            );
        }
    }

    private function save() {
        $res = file_put_contents($this->user_config_file, json_encode($this->user_data, JSON_PRETTY_PRINT));
        if ($res === false) {
            Log::error("Could not save user config file: $this->user_config_file");
            http_response_code(500);
            throw new Exception("Could not save user config file: $this->user_config_file");
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
        if ($content == null) {
            return;
        }
        if (is_string($content) && trim($content) === "") {
            return;
        }
        // if the *last* line of content ends with ], remove the last line
        if ($this->DEBUG && is_string($content) && substr($content, -1) === "]") {
            $content = explode("\n", $content);
            array_pop($content);
            $content = implode("\n", $content);
        }
        $chat = $this->get_config();
        // Add the message
        $chat->messages[] = (object) array(
            "role" => $role,
            "content" => $content,
        );
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
        // Return the number of actually deleted messages
        return $n;
    }

    public function clear_messages() {
        $chat = $this->get_config();
        $chat->messages = array();
    }

    /**
     * Read the session info. Its properties can be set arbitrarily for each key when saving.
     * 
     * @param string $key The key of the session.
     * @return object|null The session info object or null if the session does not exist.
     */
    public function get_session($key) {
        if (!isset($this->user_data->sessions->$key)) {
            return null;
        }
        return $this->user_data->sessions->$key;
    }

    public function get_sessions() {
        return $this->user_data->sessions;
    }

    /**
     * Save the session info object permanently. Its properties can be set arbitrarily for each key.
     * 
     * @param string $key The key of the session.
     * @param object|array $session_info The session info object.
     */
    public function save_session($key='last', $session_info=null) {
        if ($session_info === null) {
            $session_info = $this->get_config();
        }
        if (isset($session_info->messages) && count($session_info->messages) <= 1) {
            return;
        }
        $this->user_data->sessions->$key = json_decode(json_encode($session_info));
    }

    public function delete_session($key) {
        if (!isset($this->user_data->sessions->$key)) {
            return false;
        }
        unset($this->user_data->sessions->$key);
        return true;
    }

    /**
     * @return string The path to the user config file.
     */
    public function get_file() {
        return $this->user_config_file;
    }

    /**
     * Delete the user config file.
     * 
     * @return bool True if the file was deleted, false if the file does not exist. Throws an exception if the file exists but could not be deleted.
     */
    public function delete() {
        if (!file_exists($this->user_config_file)) {
            return false;
        }
        $res = unlink($this->user_config_file);
        if ($res === false) {
            Log::error("Could not delete user config file: $this->user_config_file");
            http_response_code(500);
            throw new Exception("Could not delete user config file: $this->user_config_file");
        }
        return true;
    }

    public function save_backup() {
        $backup_file = $this->user_config_file.".bak";
        $res = file_put_contents($backup_file, json_encode($this->user_data, JSON_PRETTY_PRINT));
        if ($res === false) {
            Log::error("Could not save user config backup file: $backup_file");
            http_response_code(500);
            throw new Exception("Could not save user config backup file: $backup_file");
        }
    }

    public function restore_backup() {
        $backup_file = $this->user_config_file.".bak";
        if (!file_exists($backup_file)) {
            return false;
        }
        $this->user_data = json_decode(file_get_contents($backup_file), false);
        if ($this->user_data === null || $this->user_data === false) {
            if ($this->user_data === null) {
                $error = "JSON error: ".json_last_error_msg();
            } else {
                $error = "Could not read file: $backup_file";
            }
            Log::error($error);
            http_response_code(500);
            throw new Exception($error);
        }
        return true;
    }

    /**
     * @return string The name of the user.
     */
    public function get_name() {
        return $this->user_data->name;
    }

    /**
     * Set the name of the user.
     * 
     * @param string $name The name of the user.
     */
    public function set_name($name) {
        $this->user_data->name = $name;
    }

    /**
     * @return string The username of the user.
     */
    public function get_username() {
        return $this->user_data->username;
    }

    /**
     * @return string The language of the user.
     */
    public function get_lang() {
        return $this->user_data->lang;
    }

    /**
     * Set the language of the user.
     *
     * @param string $lang The language of the user.
     */
    public function set_lang($lang) {
        $this->user_data->lang = $lang;
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

    /**
     * Get the text-to-speech (TTS) config of the user.
     */
    public function get_tts_config() {
        return $this->user_data->tts_config;
    }

    /**
     * Set the text-to-speech (TTS) config of the user.
     */
    public function save_tts_config($tts_config) {
        $this->user_data->tts_config = $tts_config;
    }

    /**
     * Increment a counter.
     * 
     * @param string $name The name of the counter.
     * @param int $cnt The amount to increment the counter by (default: 1).
     */
    public function increment($name, $cnt=1) {
        if (!isset($this->user_data->counters->$name)) {
            $this->user_data->counters->$name = 0;
        }
        $this->user_data->counters->$name += $cnt;
    }

    /**
     * Get all counters.
     */
    public function get_counters() {
        return $this->user_data->counters;
    }

    /**
     * Set the OpenRouter API key of the user.
     * 
     * @param mixed $openrouter_api_key
     * @return void
     */
    public function set_openrouter_api_key($openrouter_api_key) {
        $this->user_data->openrouter_api_key = $openrouter_api_key;
    }

    /**
     * Get the OpenRouter API key of the user.
     * 
     * @return string The OpenRouter API key of the user.
     */
    public function get_openrouter_api_key() {
        return $this->user_data->openrouter_api_key;
    }

    /**
     * Set the OpenAI API key of the user.
     * 
     * @param string $openai_api_key The OpenAI API key of the user.
     */
    public function set_openai_api_key($openai_api_key) {
        $this->user_data->openai_api_key = $openai_api_key;
    }

    /**
     * Get the OpenAI API key of the user.
     * 
     * @return string The OpenAI API key of the user.
     */
    public function get_openai_api_key() {
        return $this->user_data->openai_api_key;
    }

    /**
     * Set the Anthropic API key of the user.
     * 
     * @param string $anthropic_api_key The Anthropic API key of the user.
     */
    public function set_anthropic_api_key($anthropic_api_key) {
        $this->user_data->anthropic_api_key = $anthropic_api_key;
    }

    /**
     * Get the Anthropic API key of the user.
     * 
     * @return string The Anthropic API key of the user.
     */
    public function get_anthropic_api_key() {
        return $this->user_data->anthropic_api_key;
    }

    /**
     * Set the time zone of the user.
     * 
     * @param string $time_zone The time zone of the user.
     */
    public function set_timezone($time_zone) {
        $this->user_data->time_zone = $time_zone;
    }

    /**
     * Get the time zone of the user.
     * 
     * @return string The time zone of the user.
     */
    public function get_timezone() {
        return $this->user_data->time_zone;
    }

    /**
     * Update the time the user last sent a message.
     * 
     * @param string $last_seen The last seen time of the user.
     */
    public function update_last_seen($last_seen) {
        $this->user_data->last_seen = $last_seen;
    }

    public function toggle_post_processing() {
        $this->user_data->post_processing = !$this->user_data->post_processing;
        return $this->user_data->post_processing;
    }

    /**
     * Get whether message post processing is enabled.
     * 
     * @return bool
     */
    public function is_post_processing() {
        return $this->user_data->post_processing ?? false;
    }
}

?>