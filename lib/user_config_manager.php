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
        "model" => "claude-sonnet-4-20250514",
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
     * @param string|null $username The username of the user. Will only be used if the config is not yet created.
     * @param string|null $name The name of the user. Will only be used if the config is not yet created.
     * @param string $lang The language code of the user. Will only be used if the config is not yet created.
     * @param bool $DEBUG Enable debug mode
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

    /**
     * Destructor.
     * @return void
     */
    public function __destruct() {
        $this->save();
    }

    /**
     * Load user config.
     *
     * @param string|null $username
     * @param string|null $name
     * @param string $lang
     * @return void
     */
    private function load($username, $name, $lang): void {
        if (file_exists($this->user_config_file)) {
            $this->user_data = json_decode(file_get_contents($this->user_config_file), false);
            $this->user_data !== null || Log::die("JSON error: " . json_last_error_msg());
            $this->user_data !== false || Log::die("Could not read file: $this->user_config_file");
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

    /**
     * Save user config.
     *
     * @return void
     */
    public function save(): void {
        $json = json_encode($this->user_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $json !== false || Log::die("JSON encoding failed: " . json_last_error_msg());
        $res  = file_put_contents($this->user_config_file, $json);
        $res  !== false || Log::die("Could not save user config file: $this->user_config_file");
    }

    /**
     * @return object The config object with messages and model parameters.
     */
    public function get_config(): object {
        return $this->user_data->config;
    }

    /**
     * Save the config object permanently. It has the properties "model", "temperature" and "messages".
     *
     * @param object|array $config The config object with messages and model parameters.
     * @return void
     */
    /**
     * @param object|array $config
     * @return void
     */
    public function save_config($config): void {
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
    public function add_message($role, $content): void {
        // Ignore empty messages
        if ($content == null) {
            return;
        }
        if (is_string($content) && trim($content) === "") {
            return;
        }

        // If content is string and starts with an image URL, convert to array format
        // This is important for role commands to work with images (e.g. "/user", "/assistant")
        // and allows us to handle images consistently by simply concatenating URL and caption
        if (is_string($content) && preg_match('/^(https?:\/\/.*?\.(?:jpg|jpeg|png))/i', $content, $matches)) {
            $image_url = $matches[1];
            $text = trim(substr($content, strlen($image_url)));
            $content = [["type" => "image_url", "image_url" => ["url" => $image_url]]];
            if ($text !== "") {
                $content[] = ["type" => "text", "text" => $text];
            }
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
    public function delete_messages($n): int {
        // Delete the last $n messages
        $chat = $this->get_config();
        // n must not be greater than the actual number of messages
        $n = min($n, count($chat->messages));
        $chat->messages = array_slice($chat->messages, 0, -$n);
        // Return the number of actually deleted messages
        return $n;
    }

    /**
     * @return void
     */
    public function clear_messages(): void {
        $chat = $this->get_config();
        $chat->messages = array();
    }

    /**
     * Read the session info. Its properties can be set arbitrarily for each key when saving.
     *
     * @param string $key The key of the session.
     * @return object|null The session info object or null if the session does not exist.
     */
    public function get_session($key): ?object {
        if (!isset($this->user_data->sessions->$key)) {
            return null;
        }
        return $this->user_data->sessions->$key;
    }

    /**
     * @return object
     */
    public function get_sessions(): object {
        return $this->user_data->sessions;
    }

    /**
     * Save the session info object permanently. Its properties can be set arbitrarily for each key.
     *
     * @param string $key The key of the session.
     * @param object|array|null $session_info The session info object.
     * @return void
     */
    public function save_session($key='last', $session_info=null): void {
        if ($session_info === null) {
            $session_info = $this->get_config();
        }
        if (isset($session_info->messages) && count($session_info->messages) <= 1) {
            return;
        }
        $this->user_data->sessions->$key = json_decode(json_encode($session_info, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param string $key
     */
    public function delete_session($key): bool {
        if (!isset($this->user_data->sessions->$key)) {
            return false;
        }
        unset($this->user_data->sessions->$key);
        return true;
    }

    /**
     * @return string The path to the user config file.
     */
    public function get_file(): string {
        return $this->user_config_file;
    }

    /**
     * Delete the user config file.
     *
     * @return bool True if the file was deleted, false if the file does not exist. Throws an exception if the file exists but could not be deleted.
     */
    public function delete(): bool {
        if (!file_exists($this->user_config_file)) {
            return false;
        }
        $res = unlink($this->user_config_file);
        $res !== false || Log::die("Could not delete user config file: $this->user_config_file");
        return true;
    }

    /**
     * Save a backup of the user config file.
     *
     * @return void
     */
    public function save_backup(): void {
        $backup_file = $this->user_config_file.".bak";
        $res = file_put_contents($backup_file, json_encode($this->user_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $res !== false || Log::die("Could not save user config backup file: $backup_file");
    }

    /**
     * Restore the user config from a backup file.
     *
     * @return bool True if restored, false if backup does not exist.
     */
    public function restore_backup(): bool {
        $backup_file = $this->user_config_file.".bak";
        if (!file_exists($backup_file)) {
            return false;
        }
        $this->user_data = json_decode(file_get_contents($backup_file), false);
        $this->user_data !== null  || Log::die("JSON error: " . json_last_error_msg());
        $this->user_data !== false || Log::die("Could not read file: $backup_file");
        return true;
    }

    /**
     * @return string The name of the user.
     */
    public function get_name(): string {
        return $this->user_data->name;
    }

    /**
     * Set the name of the user.
     *
     * @param string $name The name of the user.
     */
    public function set_name($name): void {
        $this->user_data->name = $name;
    }

    /**
     * @return string The username of the user.
     */
    public function get_username(): string {
        return $this->user_data->username;
    }

    /**
     * @return string The language of the user.
     */
    public function get_lang(): string {
        return $this->user_data->lang;
    }

    /**
     * Set the language of the user.
     *
     * @param string $lang The language of the user.
     */
    public function set_lang($lang): void {
        $this->user_data->lang = $lang;
    }

    /**
     * Get the intro text of the user.
     *
     * @return string The intro text of the user.
     */
    public function get_intro(): string {
        return $this->user_data->intro;
    }

    /**
     * Set the intro text of the user.
     *
     * @param string $intro The intro text of the user.
     */
    public function set_intro($intro): void {
        $this->user_data->intro = $intro;
    }

    /**
     * Get the hellos of the user.
     *
     * @return array The hellos of the user.
     */
    public function get_hellos(): array {
        return $this->user_data->hellos;
    }

    /**
     * Set the hellos of the user.
     *
     * @param array $hellos The hellos of the user.
     */
    public function set_hellos($hellos): void {
        $this->user_data->hellos = $hellos;
    }

    /**
     * Return a random hello message to the user from their configured greetings.
     * If no custom greetings are set, returns a default welcome message.
     *
     * @return string A randomly selected greeting message
     */
    public function hello(): string {
        $hellos = $this->get_hellos();
        if (count($hellos) == 0) {
            return "Hi there, this is your personal assistant. How can I help you? Type /help to see what I can do.";
        }
        $index = rand(0, count($hellos) - 1);
        return $hellos[$index];
    }

    /**
     * Get the last thinking output of the model.
     *
     * @param string $last_thinking
     * @return void
     */
    public function set_last_thinking_output($last_thinking): void {
        $this->user_data->last_thinking = $last_thinking;
    }

    /**
     * Get the last thinking output of the model.
     *
     * @return string The last thinking output of the model.
     */
    public function get_last_thinking_output(): string {
        if (!isset($this->user_data->last_thinking)) {
            $this->user_data->last_thinking = "";
        }
        return $this->user_data->last_thinking;
    }


    /**
     * Get the text-to-speech (TTS) configuration of the user.
     * Returns an object containing TTS settings like model, voice, and speed.
     *
     * @return object Object containing the user's TTS configuration settings
     */
    public function get_tts_config(): object {
        return $this->user_data->tts_config;
    }

    /**
     * Set the text-to-speech (TTS) config of the user.
     * @param mixed $tts_config
     */
    public function save_tts_config($tts_config): void {
        $this->user_data->tts_config = $tts_config;
    }

    /**
     * Increment a counter.
     *
     * @param string $name The name of the counter.
     * @param int $cnt The amount to increment the counter by (default: 1).
     */
    public function increment($name, $cnt=1): void {
        if (!isset($this->user_data->counters->$name)) {
            $this->user_data->counters->$name = 0;
        }
        $this->user_data->counters->$name += $cnt;
    }

    /**
     * Get all usage counters for the user.
     * Counters track various metrics like number of messages sent,
     * commands used, etc. to monitor user activity and usage patterns.
     *
     * @return object Object containing all counter values keyed by counter name
     */
    public function get_counters(): object {
        return $this->user_data->counters;
    }

    /**
     * Set the OpenRouter API key of the user.
     *
     * @param mixed $openrouter_api_key
     * @return void
     */
    public function set_openrouter_api_key($openrouter_api_key): void {
        $this->user_data->openrouter_api_key = $openrouter_api_key;
    }

    /**
     * Get the OpenRouter API key of the user.
     *
     * @return string The OpenRouter API key of the user.
     */
    public function get_openrouter_api_key(): string {
        return $this->user_data->openrouter_api_key;
    }

    /**
     * Set the OpenAI API key of the user.
     *
     * @param string $openai_api_key The OpenAI API key of the user.
     */
    public function set_openai_api_key($openai_api_key): void {
        $this->user_data->openai_api_key = $openai_api_key;
    }

    /**
     * Get the OpenAI API key of the user.
     *
     * @return string The OpenAI API key of the user.
     */
    public function get_openai_api_key(): string {
        return $this->user_data->openai_api_key;
    }

    /**
     * Set the Anthropic API key of the user.
     *
     * @param string $anthropic_api_key The Anthropic API key of the user.
     */
    public function set_anthropic_api_key($anthropic_api_key): void {
        $this->user_data->anthropic_api_key = $anthropic_api_key;
    }

    /**
     * Get the Anthropic API key of the user.
     *
     * @return string The Anthropic API key of the user.
     */
    public function get_anthropic_api_key(): string {
        return $this->user_data->anthropic_api_key;
    }

    /**
     * Set the time zone of the user.
     *
     * @param string $time_zone The time zone of the user.
     */
    public function set_timezone($time_zone): void {
        $this->user_data->time_zone = $time_zone;
    }

    /**
     * Get the time zone of the user.
     *
     * @return string The time zone of the user.
     */
    public function get_timezone(): string {
        return $this->user_data->time_zone;
    }

    /**
     * Update the time the user last sent a message.
     *
     * @param string $last_seen The last seen time of the user.
     */
    public function update_last_seen($last_seen): void {
        $this->user_data->last_seen = $last_seen;
    }

    public function toggle_post_processing(): bool {
        $this->user_data->post_processing = !$this->user_data->post_processing;
        return $this->user_data->post_processing;
    }

    /**
     * Get whether message post processing is enabled.
     *
     * @return bool
     */
    public function is_post_processing(): bool {
        return $this->user_data->post_processing ?? false;
    }
}

?>
