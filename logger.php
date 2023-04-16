<?php

function _log($log_file, $content, $flags = FILE_APPEND) {
    $log_dir = __DIR__."/logs";
    if (!file_exists($log_dir)) {
        mkdir($log_dir);
    }
    file_put_contents($log_dir."/".$log_file, $content.PHP_EOL, $flags);
}

function log_info($message) {
    _log("log.txt", $message);
}

function log_error($message) {
    _log("log_error.txt", $message);
}

function log_image($prompt, $image_url, $chat_id) {
    _log("log_image.txt", json_encode(array(
        "timestamp" => time(),
        "prompt" => $prompt,
        "image_url" => $image_url,
        "chat_id" => $chat_id,
    )));
}

function log_update_id($update_id) {
    _log("update_ids.txt", json_encode(array(
        "timestamp" => time(),
        "update_id" => $update_id,
    )));
}

function already_seen($update_id) {
    return strpos(file_get_contents(__DIR__."/logs/update_ids.txt"), $update_id) !== false;
}

?>