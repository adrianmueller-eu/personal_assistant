<?php
// Import function "curl"
require_once __DIR__."/utils.php";

/**
 * This class manages the persistent data for all chats.
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
        // Check if the file exists
        if (!file_exists($this->global_config_file)){
            // Copy the template file
            copy(dirname($this->global_config_file)."/config_template.json", $this->global_config_file);
        }
        $this->global_config = json_decode(file_get_contents($this->global_config_file), false);
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
        // Check first if the key exists in the config file
        if(isset($this->global_config->config->$key)) {
            $value = $this->global_config->config->$key;
            if ($value !== null && $value !== "")
                return $value;
        }
        // Fall back to getenv()
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        return null;
    }

    /**
     * Check if a user is allowed to use the assistant.
     * 
     * @param string $username The username of the user.
     * @param string $category The category of the user.
     * @return bool True if the user is allowed to use the assistant.
     */
    public function is_allowed_user($username, $category = "general") {
        if (in_array("all", $this->global_config->users->$category))
            return true;
        if ($username == null || $username == "")
            return false;
        return in_array($username, $this->global_config->users->$category);
    }

    /**
     * Get the list of allowed users.
     * 
     * @param string $category The category of the user.
     * @return array The list of users registered in the given category.
     */
    public function get_allowed_users($category = "general") {
        return $this->global_config->users->$category;
    }

    /**
     * Add a user to the list of allowed users.
     * 
     * @param string $username The username of the user.
     * @param string $category The category to add the user to.
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
     * @param string $category The category to remove the user from.
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