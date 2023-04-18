<?php
// This script is a relay between the Telegram Bot API and the OpenAI API.
// If this script is called from the Telegram webhook, the user has sent a new message in the Telegram chat


// This is for debugging
$DEBUG=false;

if ($DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// There is a file for each chat that contains hyperparameters for the chatbot and the most recent chat history.
// The file is named after the chat ID. It has the following format:
// {
//     "config": {
//         "model": "gpt-4",
//         "temperature": 0.7,
//         "messages": [{
//             "role": "system",
//             "content": "You are a helpful assistant."
//         }, {
//             "role": "user",
//             "content": "Who won the world series in 2020?"
//         }, {
//             "role": "assistant",
//             "content": "The Los Angeles Dodgers won the World Series in 2020."
//         }, {
//             "role": "user",
//             "content": "Where was it played?"
//         }]
//     },
//     "sessions": {
//         "session_key1": {
//             "property1": "value1",
//             "property2": "value2"
//         }
//     }
// }
    

// ###############
// ### Imports ###
// ###############

require_once __DIR__."/lib/utils.php";
require_once __DIR__."/lib/logger.php";
require_once __DIR__."/lib/telegram.php";
require_once __DIR__."/lib/openai.php";
require_once __DIR__."/lib/user_config_manager.php";
require_once __DIR__."/lib/global_config_manager.php";
require_once __DIR__."/lib/command_manager.php";

require_once __DIR__."/bots/general.php";

// ######################
// ### Initialization ###
// ######################
// Tokens and keys
$global_config_manager = new GlobalConfigManager();
$telegram_token = $global_config_manager->get("TELEGRAM_BOT_TOKEN");
$secret_token = $global_config_manager->get("TELEGRAM_BOT_SECRET");
$chat_id_admin = $global_config_manager->get("TELEGRAM_ADMIN_CHAT_ID");
$openai_api_key = $global_config_manager->get("OPENAI_API_KEY");

// Set the time zone to give the correct time to the model
$timezone = $global_config_manager->get("TIME_ZONE");
if ($timezone != null && $timezone != "") {
    date_default_timezone_set($timezone);
}
$telegram_admin = new Telegram($telegram_token, $chat_id_admin);

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    // This is just out of curiosity, to see if someone is trying to access the script directly
    $ip = $_SERVER['REMOTE_ADDR'];
    $link = "https://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
    $telegram_admin->send_message("Someone (".$ip.") called on ".$link." with a non-POST method!");
    // echo "There is nothing to see here! :P";
    // http_response_code(401) // 401 Unauthorized
    header('Location: '."https://".$_SERVER['HTTP_HOST']);
    exit;
}

// Security check, to know that the request comes from Telegram
if (!$DEBUG && $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] != $secret_token) {
    http_response_code(401); // 401 Unauthorized
    exit;
}

// Get the message from the Telegram API
$content = file_get_contents("php://input");

// An incoming text message is in the following format:
// {
//     "update_id": 10000,
//     "message": {
//         "date": 1441645532,
//         "chat": {
//             "last_name": "Test Lastname",
//             "id": 1111111,
//             "first_name": "Test",
//             "username": "Test"
//         },
//         "message_id": 1365,
//         "from": {
//             "last_name": "Test Lastname",
//             "id": 1111111,
//             "first_name": "Test",
//             "username": "Test"
//         },
//         "text": "/start"
//     }
// }

// Append the message content to the log file
log_info($content);

$update = json_decode($content, false);
// Ignore non-message updates
if (!isset($update->message)) {
    exit;
}

// Avoid processing the same message twice by checking whether update_id was already processed
$update_id = $update->update_id;
// Assume this can't adversarially block future messages
if (!$DEBUG && already_seen($update_id)) {
    // $telegram->send_message("Repeated message ignored (update_id: ".$update_id.")");
    log_info("Repeated message ignored (update_id: ".$update_id.")");
    exit;
}
log_update_id($update_id);

// Initialize
$chat_id = $update->message->chat->id; // Assume that if $update->message exists, so does $update->message->chat->id
$username = $update->message->from->username;
$name = $update->message->from->first_name ?? $username;

$telegram = new Telegram($telegram_token, $chat_id);
$user_config_manager = new UserConfigManager($chat_id, $username, $name);
$openai = new OpenAI($openai_api_key);

$is_admin = $chat_id == $chat_id_admin;

if ($is_admin || $global_config_manager->is_allowed_user($username, "general")) {
    run_general_bot($update, $user_config_manager, $telegram, $openai, $telegram_admin, $username, 
                        $global_config_manager, $is_admin, $DEBUG);
    exit;
}
else {
    $telegram->send_message("Sorry, I can't talk to you (chat_id: ".$chat_id.")");
    
    // Tell me ($chat_id_admin) that someone tried to talk to the bot
    // This could be used to spam the admin
    if ($username != null)
        $telegram_admin->send_message("@".$username." tried to talk to me");
    else
        $telegram_admin->send_message("Someone without a username tried to talk to me");
    exit;
}
?>