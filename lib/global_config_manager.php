<?php
// Import function "curl"
require_once __DIR__."/utils.php";

/**
 * This class manages the persistent data for a chat.
 * 
 * The JSON file is located in "chats/config.json". It has the following format:
 * ```json
 * {
 *     "config": {
 *         "VAR_1": "",
 *         "VAR_2": "",
 *     },
 *     "users": {
 *         "category_1" : [],
 *         "category_2" : []
 *     }
 * }
 * ```
 */
class GlobalConfigManager {

    private $global_config_file;
    private $global_config;

    public function __construct() {
        $chats_dir = __DIR__."/../chats";

        $this->global_config_file = $chats_dir."/config.json";
        $this->load();
    }


    private function load() {
        // Check if the chat is allowed to use the assistant
        $this->global_config = json_decode(file_get_contents($this->global_config_file), false);
        if ($this->global_config == null) {
            throw new Exception("Global config file not found. Please create it first.");
        }
    }

    private function save() {
        file_put_contents($this->global_config_file, json_encode($this->global_config, JSON_PRETTY_PRINT));
    }

    /**
     * Get a config value.
     * 
     * @param string $key The key of the config value.
     * @return mixed The value of the config value.
     */
    public function get($key) {
        if(isset($this->global_config->config->$key)) {
            return $this->global_config->config->$key;
        }
        return null;
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
        return in_array($username, $this->global_config->users->$category);
    }

    /**
     * Get the list of allowed users.
     * 
     * @param string $category The category of the user. Currently only "general" and "mental_health" are supported.
     * @return array The list of users registered in the given category.
     */
    public function get_allowed_users($category = "general") {
        return $this->global_config->users->$category;
    }

    /**
     * Add a user to the list of allowed users.
     * 
     * @param string $username The username of the user.
     * @param string $category The category to add the user to. Currently only "general" and "mental_health" are supported.
     */
    public function add_allowed_user($username, $category = "general") {
        if ($username == null || $username == "") return;
        if (!isset($this->global_config->users->$category)) return;
    
        $this->global_config->users->$category[] = $username;
        $this->save();
    }

    /**
     * Remove a user from the list of allowed users for the given category.
     * 
     * @param string $username The username of the user.
     * @param string $category The category to remove the user from. Currently only "general" and "mental_health" are supported.
     */
    public function remove_allowed_user($username, $category = "general") {
        if ($username == null || $username == "") return;
        if (!isset($this->global_config->users->$category)) return;

        $this->global_config->users->$category = array_diff($this->global_config->users->$category, array($username));
        $this->save();
    }

    /**
     * Get the list of valid categories for user groups.
     * 
     * @return array The list of valid categories for user groups.
     */
    public function get_categories() {
        return array_keys((array) $this->global_config->users);
    }
}

?>