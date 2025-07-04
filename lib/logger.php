<?php

class Log {

    private static $log_dir = __DIR__."/../logs";
    private static $info_file = "info.log";
    private static $error_file = "error.log";
    private static $debug_file = "debug.log";
    private static $image_file = "image.log";
    private static $update_id_file = "update_ids.txt";

    private static $echo_level = 0;  // 0: no echo, 1: echo error, 2: echo error and info, 3: echo error, info, and debug

    /**
     * Set the echo level that controls which log messages are output to the console.
     *
     * Echo levels:
     * - 0: No console output
     * - 1: Output only errors
     * - 2: Output errors and info messages
     * - 3: Output errors, info, and debug messages
     *
     * @param int $echo_level The echo level to set (0-3)
     */
    public static function set_echo_level($echo_level): void {
        self::$echo_level = $echo_level;
    }

    /**
     * Echo a message.
     *
     * @param string|array $message The message to echo.
     * @param int $echo_level The required echo level.
     */
    public static function echo($message, $echo_level): void {
        if (self::$echo_level < $echo_level)
            return;
        if (is_string($message))
            $message = $message.PHP_EOL;
        else
            $message = json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

        if ($echo_level > 1) {
            file_put_contents("php://stdout", $message);
        } else {
            file_put_contents("php://stderr", $message);
        }
    }

    /**
     * Append a message to the given log file. The message will be prepended with the current timestamp by default.
     *
     * @param string $log_file The log file to append the message to.
     * @param string|array $content The message to append to the log file.
     * @param bool $prepend_timestamp (optional) Whether to prepend the current timestamp to the message.
     * @param int $flags (optional) A bitmask of the flags FILE_USE_INCLUDE_PATH, FILE_APPEND, and LOCK_EX.
     */
    private static function _log($log_file, $content, $prepend_timestamp = True, $flags = FILE_APPEND): void {
        if (!file_exists(self::$log_dir)) {
            mkdir(self::$log_dir);
        }
        // $content is either a string or an array
        if (!is_array($content)) {
            $content = array("message" => $content);
        }
        if ($prepend_timestamp) {
            $content = array_merge(array("timestamp" => date("Y-m-d H:i:s O")), $content);
        }
        $content = json_encode($content, JSON_UNESCAPED_UNICODE);
        file_put_contents(self::$log_dir."/".$log_file, $content.PHP_EOL, $flags);
    }

    /**
     * Append an error message to the log file.
     *
     * @param string|array $message The error message to append to the log file.
     */
    public static function error($message): void {
        self::echo($message, 1);
        self::_log(self::$error_file, $message);
    }

    /**
     * Log an error and terminate the script with HTTP 200.
     *
     * @param string|array $message The error message to log and send.
     */
    public static function die($message): void {
        self::error($message);
        exit;
    }

    /**
     * Append a message to the log file.
     *
     * @param string|array $message The message to append to the log file.
     */
    public static function info($message): void {
        self::echo($message, 2);
        self::_log(self::$info_file, $message);
    }

    /**
     * Append a debug message to the log file.
     *
     * @param string|array $message The debug message to append to the log file.
     */
    public static function debug($message): void {
        self::echo($message, 3);
        self::_log(self::$debug_file, $message);
    }

    /**
     * Append an image generation request to the log file.
     *
     * @param string $prompt The prompt to use for the image generation.
     * @param string $image_url The URL of the image generated by DALL-E.
     * @param string $chat_id The chat ID.
     */
    public static function image($prompt, $image_url, $chat_id): void {
        self::_log(self::$image_file, array(
            "prompt" => $prompt,
            "image_url" => $image_url,
            "chat_id" => $chat_id,
        ));
    }

    /**
     * Append an update ID to the log file.
     *
     * @param string $update_id The update ID to append to the log file.
     */
    public static function update_id($update_id): void {
        self::_log(self::$update_id_file, $update_id, True);
    }

    /**
     * Check whether an update ID was already processed.
     *
     * @param string $update_id The update ID to check.
     * @return bool Whether the update ID was already processed.
     */
    public static function already_seen($update_id): bool {
        return strpos(file_get_contents(self::$log_dir."/".self::$update_id_file), $update_id) !== false;
    }
}

?>
