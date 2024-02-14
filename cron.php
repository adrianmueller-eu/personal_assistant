<?php

$DEBUG = false;

if ($DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// imports
require_once __DIR__."/lib/telegram.php";
require_once __DIR__."/lib/openai.php";
require_once __DIR__."/lib/logger.php";
require_once __DIR__."/lib/global_config_manager.php";
require_once __DIR__."/lib/user_config_manager.php";

if ($DEBUG) {
    Log::set_echo_level(3);
}

// Tokens and keys
$global_config_manager = new GlobalConfigManager();
$telegram_token = $global_config_manager->get("TELEGRAM_BOT_TOKEN");
if ($telegram_token == null || $telegram_token == "") {
    Log::error("TELEGRAM_BOT_TOKEN is not set.");
    exit;
}
$secret_token = $global_config_manager->get("TELEGRAM_BOT_SECRET");
if ($secret_token == null || $secret_token == "") {
    Log::error("TELEGRAM_BOT_SECRET is not set.");
    exit;
}
$chat_id_admin = $global_config_manager->get("TELEGRAM_ADMIN_CHAT_ID");
if ($chat_id_admin == null || $chat_id_admin == "") {
    Log::error("TELEGRAM_ADMIN_CHAT_ID is not set.");
    exit;
}
$openai_api_key = $global_config_manager->get("OPENAI_API_KEY");
if ($openai_api_key == null || $openai_api_key == "") {
    Log::error("OPENAI_API_KEY is not set.");
    exit;
}
$openai = new OpenAI($openai_api_key, $DEBUG);

// Get jobs from GlobalConfigManager
$jobs = $global_config_manager->get_jobs();
if ($jobs == null || count($jobs) == 0) {
    Log::error("No jobs found.");
    exit;
}

// Each entry in the jobs array is a job object, which has the following format:
// {
//     "status": "active",
//     "name": "therapy_reminder",
//     "chat_id": "123456789",
//     "is_prompt": "true",
//     "last_run": null,
//     "next_run": null,
//     "distribution": {
//         "type": "exponential",
//         "mean": 168
//     },
//     "message": "Ask the user how they are feeling right now.",
//     "temperature": 0.9
// }
// Distribution can be have the following types and respective parameters:
// - constant: value (in hours)
// - uniform: min, max (in hours)
// - exponential: mean (in hours)
// - uniform_once_a_day: earliest, latest (in hours)
// If is_prompt is true, the message is used as system prompt for the model and the response is sent to the user.
// If is_prompt is false, the message is sent to the user directly.
// If chat_id is "admin", the message is sent to the admin chat ID instead of the chat ID of the job.
// If temperature is set, it overrides the default temperature of the model.

// Load the default config
$default_config = UserConfigManager::$default_config;
$default_timezone = date("e");

for ($i = 0; $i < count($jobs); $i++) {
    $job = $jobs[$i];

    if ($job->status != "active")
        continue;

    // Check if the job is due
    if ($job->next_run != null && strtotime($job->next_run) > time()) {
        if ($DEBUG) {
            Log::debug("Job \"".$job->name."\" is not due yet. Next run is not before ".$job->next_run.".");
        }
        continue;
    }

    // Execute the job
    Log::info("Executing job \"".$job->name."\"...");
    // Lazy chat id for admin
    if ($job->chat_id == "admin") {
        $job->chat_id = $chat_id_admin;
    }

    if ($job->is_prompt == "true") {  // "false" == true would validate to true!
        // If chats/ folder has a file with name "$job->chat_id.json", load the user config
        if (file_exists(__DIR__."/chats/".$job->chat_id.".json")) {
            // Load the user config
            $user_config_manager = new UserConfigManager($job->chat_id);
            $config = clone $user_config_manager->get_config();
            // Create a new OpenAI object with the user's API key
            $openai_api_key = $user_config_manager->get_openai_api_key();
            if ($user_openai_api_key == null || $user_openai_api_key == "") {
                $user_config_manager->set_openai_api_key($openai_api_key);
            } else {
                $openai_api_key = $user_openai_api_key;
            }
            $user_openai = new OpenAI($openai_api_key, $DEBUG);
            // Set user's timezone
            date_default_timezone_set($user_config_manager->get_timezone());
            // If the last two messages are from the assistant, remove the last
            $cnt = count($config->messages);
            if ($cnt > 1 && $config->messages[$cnt - 1]->role == "assistant" && $config->messages[$cnt - 2]->role == "assistant") {
                $user_config_manager->delete_messages(1);
                array_pop($config->messages);
            }
            // Temporarily set the temperature to the job's temperature if it is set
            if (isset($job->temperature) && $job->temperature != null) {
                $config->temperature = $job->temperature;
            }
            // Replace variables in the message
            $message = $job->message;
            $message = str_replace("{{name}}", $user_config_manager->get_name(), $message);
            $message = str_replace("{{time}}", date("g:ia"), $message);
            $message = str_replace("{{date}}", date("l, F jS"), $message);
            // Use a temporary system prompt to generate the next message
            if ($user_config_manager->get_lang() == "de"){
                $pre_message = "In der nächsten Nachricht, bitte antworte folgend dieser Anweisung:";
            } else {
                $pre_message = "In the next message, please include a response following this instruction:";
            }
            $config->messages[] = array(
                "role" => "system",
                "content" => $pre_message."\n\n".$message,
            );
            // Request a response from the model
            $message = $user_openai->gpt($config, $user_config_manager);
            // Add the message as assistant message
            if (substr($message, 0, 7) != "Error: ") {
                $user_config_manager->add_message("assistant", $message);
            }
        } else {
            // Set default timezone
            date_default_timezone_set($default_timezone);
            // Copy the template config
            $config = clone $default_config;  // clone, because we might execute multiple jobs in a row
            // Update the temperature if it is set in the job
            if (isset($job->temperature) && $job->temperature != null) {
                $config["temperature"] = $job->temperature;
            }
            // Set the system prompt
            $config["messages"] = array(array(
                "role" => "system",
                "content" => $job->message,
            ));
            // Request a response from the model using default openai object
            $message = $openai->gpt($config, $user_config_manager);
        }
    } else {
        $message = $job->message;
    }

    // Send the message to the user
    $telegram = new Telegram($telegram_token, $job->chat_id, $DEBUG);
    $telegram->send_message($message);

    // If $message is an error message, don't update the last_run and next_run
    if (substr($message, 0, 7) == "Error: ") {
        continue;
    }
    // Update the last_run and next_run
    $job->last_run = date("Y-m-d H:i:s O");
    $last_run = strtotime($job->last_run);
    if ($job->distribution->type == "uniform_once_a_day") {
        $earliest = $job->distribution->earliest;
        $latest = $job->distribution->latest;
        // Generate a random time between earliest and latest at the next day
        $next_day_00 = strtotime("tomorrow 00:00:00");
        $next_run = $next_day_00 + mt_rand($earliest * 3600 - 3540, $latest * 3600 - 60);  // script runs only once an hour
    } else if ($job->distribution->type == "exponential") {
        // inverse transform sampling
        $U = mt_rand() / mt_getrandmax();
        $next_run = $last_run + round(- $job->distribution->mean * 3600 * log(1 - $U));
        if ($DEBUG) {
            Log::debug("Job ".$job->name." has a next run of ".date("Y-m-d H:i:s O", $next_run));
        }
    } else if ($job->distribution->type == "constant") {
        $next_run = $last_run + $job->distribution->value * 3600;
    } else if ($job->distribution->type == "uniform") {
        $min = $job->distribution->min;
        $max = $job->distribution->max;
        $next_run = $last_run + mt_rand($min * 3600, $max * 3600);
    } else {
        Log::error("Invalid distribution type: ".$job->distribution->type);
        continue;
    }
    // Save $next_run in job
    $job->next_run = date("Y-m-d H:i:s O", $next_run);
}

// Save the updated jobs
$global_config_manager->save_jobs($jobs);