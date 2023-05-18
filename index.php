<?php
// Set here the bot you want to use
require_once __DIR__."/bots/general.php";
// require_once __DIR__."/bots/mental_health.php";

require_once __DIR__."/lib/utils.php";
require_once __DIR__."/lib/logger.php";
require_once __DIR__."/lib/telegram.php";
require_once __DIR__."/lib/openai.php";
require_once __DIR__."/lib/user_config_manager.php";
require_once __DIR__."/lib/global_config_manager.php";
require_once __DIR__."/lib/command_manager.php";

// This is for debugging
$DEBUG=false;

if ($DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

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

// ##### Emergency stop #####
// $telegram->send_message("This works again.");
// exit;

// #######################
// ### Security checks ###
// #######################

// Check if the script is called with a POST request
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

// ##########################
// ### Message processing ###
// ##########################

// If this script is called from the Telegram webhook, the user has sent a new message in the Telegram chat
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
Log::info($content);

$update = json_decode($content, false);
// Ignore non-message updates
if (!isset($update->message) || !isset($update->update_id)) {
    exit;
}

if ($DEBUG) {
    $telegram_admin->send_message(json_encode($update, JSON_PRETTY_PRINT), null);
}

// Avoid processing the same message twice by checking whether update_id was already processed
$update_id = $update->update_id;
// Assume this can't adversarially block future messages
if (!$DEBUG && Log::already_seen($update_id)) {
    // $telegram->send_message("Repeated message ignored (update_id: ".$update_id.")");
    Log::info("Repeated message ignored (update_id: ".$update_id.")");
    exit;
}
Log::update_id($update_id);

// Parse the message object
$update = $update->message;
$chat_id = $update->chat->id; // Assume that if $update->message exists, so does $update->message->chat->id
$username = $update->from->username;
$name = $update->from->first_name ?? $username;

$telegram = new Telegram($telegram_token, $chat_id);
$is_admin = $chat_id == $chat_id_admin;

// Run the bots
// Note: Instantiate the UserConfigManager only after checking if the user is allowed to talk to the bot to avoid
//       unnecessary database entries
if ($is_admin || $global_config_manager->is_allowed_user($username, "general")) {
    $user_config_manager = new UserConfigManager($chat_id, $username, $name);
    $openai = new OpenAI($openai_api_key);

    run_bot($update, $user_config_manager, $telegram, $openai, $telegram_admin, $username, 
                        $global_config_manager, $is_admin, $DEBUG);
    exit;
}
else {
    // if $update->text contains "chatid", send the chat_id to the user
    if (isset($update->text) && strpos($update->text, "chatid") !== false)
        $telegram->send_message("Your chat_id is: ".$chat_id, null);
    else
        $telegram->send_message("I'm sorry, I'm not allowed to talk with you :/", null);

    // Tell me ($chat_id_admin) that someone tried to talk to the bot
    // This could be used to spam the admin
    if ($username != null && $username != "")
        $telegram_admin->send_message("@".$username." tried to talk to me (chat_id: ".$chat_id.")");
    else if ($name != null && $name != "")
        $telegram_admin->send_message($name." tried to talk to me (chat_id: ".$chat_id.")");
    else
        $telegram_admin->send_message("Someone without username or name tried to talk to me (chat_id: ".$chat_id.")");
}
?>
