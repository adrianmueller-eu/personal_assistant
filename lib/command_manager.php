<?php

/**
 * A class that allows to register commands, with alternative commands, a function to execute, a category,
 * as well as a description of the command. The class also allows to parse the command and its arguments
 * passed to the script and execute the corresponding functions.
 *
 * Example:
 * ```
 * $manager = new CommandManager();
 * $manager->add_command(array("/command_alternative1", "/command_alternative2"), foo, "category", "description");
 * $manager->run_command($message);
 * ```
 *
 * The above code will register the command /command_alternative1 and /command_alternative2, with the function
 * foo to execute, in the category "category", with the description "description". The function foo will be
 * executed when the user sends the message "/command_alternative1 argument1 argument2". The arguments will
 * be passed to the function foo as an array.
 */
class CommandManager {

    /**
     * The commands registered with the manager.
     * - alternatives: an array of alternative commands
     * - function: the function to execute
     * - category: the category of the command
     * - description: the description of the command
     */
    private $commands = array();

    /**
     * The categories of the commands, as set in the constructor.
     */
    private $categories = array();

    /**
     * Create a new CommandManager instance.
     *
     * @param array $categories The categories of the commands. The last category will be used for the /help command. The order of the categories in the array will be the same as shown in the help message.
     */
    public function __construct($categories = array("Misc")) {
        $this->categories = $categories;
        // Ensure $categoies is not empty
        if (count($this->categories) == 0) {
            Log::die("Categories cannot be empty.");
        }
        // Add /help command to the last category
        $last_category = $this->categories[count($this->categories) - 1];
        $this->add_command(array("/help", "/h"), function () {
            return $this->print_help();
        }, $last_category, "Print this help message");
    }

    /**
     * Add a command to the manager.
     *
     * @param array $alternatives An array of alternative commands.
     * @param callable $function The function to execute. The function must accept two arguments: the command and the parameter. The parameter is "" if nothing was passed with the command.
     * @param string $category The category of the command. Must be one of the categories passed to the constructor.
     * @param string $description The description of the command. Will be shown in the help message.
     */
    public function add_command($alternatives, $function, $category, $description) {
        // if (!in_array($category, $this->categories)) {
        //     Log::die("Invalid category: ".$category);
        // }
        $this->commands[] = array(
            "alternatives" => $alternatives,
            "function" => $function,
            "category" => $category,
            "description" => $description,
        );
        if (!in_array($category, $this->categories)) {
            $this->categories[] = $category;
        }
    }

    /**
     * Parse the command and its arguments passed to the script and execute the corresponding function.
     *
     * @param string $message The command and its arguments.
     * @return string The output of the function.
     */
    public function run_command($message) {
        $message = trim($message);
        # split at whitespace
        $message = explode(" ", $message, 2);
        $command = $message[0];
        $argument = isset($message[1]) ? $message[1] : "";
        # split at newline
        $command = explode("\n", $command, 2);
        if (isset($command[1])) {
            $argument = $command[1]." ".$argument;
        }
        $command = $command[0];
        foreach ($this->commands as $command_info) {
            if (in_array($command, $command_info["alternatives"])) {
                return $command_info["function"]($command, trim($argument));
            }
        }
        return "Command \"$command\" not found. Type /help to see the list of available commands.";
    }

    /**
     * Print the help message.
     *
     * @return string The help message.
     */
    public function print_help() {
        $help = "Available commands:\n\n";
        foreach ($this->categories as $category) {
            $help .= "*$category*\n";
            foreach ($this->commands as $command_info) {
                if ($command_info["category"] == $category) {
                    $help .= implode(", ", $command_info["alternatives"])." - ".$command_info["description"]."\n";
                }
            }
            $help .= "\n";
        }
        return $help;
    }
}
