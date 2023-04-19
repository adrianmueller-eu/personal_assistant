<?php

class Log {

    private static $log_dir = __DIR__."/../logs";
    private static $log_file = self::$log_dir."/log.txt";
    private static $error_log_file = self::$log_dir."/log_error.txt";
    private static $image_log_file = self::$log_dir."/log_image.txt";
    private static $update_id_log_file = self::$log_dir."/update_ids.txt";

    private static function _log($log_file, $content, $flags = FILE_APPEND) {
        if (!file_exists(self::$log_dir)) {
            mkdir(self::$log_dir);
        }
        file_put_contents($log_file, $content.PHP_EOL, $flags);
    }

    public static function info($message) {
        self::_log(sefl::$log_file, $message);
    }

    public static function error($message) {
        self::_log(self::$error_log_file, $message);
    }

    public static function image($prompt, $image_url, $chat_id) {
        self::_log(self::$image_log_file, json_encode(array(
            "timestamp" => time(),
            "prompt" => $prompt,
            "image_url" => $image_url,
            "chat_id" => $chat_id,
        )));
    }

    public static function update_id($update_id) {
        self::_log(self::$update_id_log_file, json_encode(array(
            "timestamp" => time(),
            "update_id" => $update_id,
        )));
    }

    public static function already_seen($update_id) {
        return strpos(file_get_contents(self::$update_id_log_file), $update_id) !== false;
    }
}

?>