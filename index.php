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

$telegram = new Telegram($telegram_token, $chat_id);
$user_config_manager = new UserConfigManager($chat_id);
$openai = new OpenAI($openai_api_key);

if ($global_config_manager->is_allowed_user($username, "general")) { } // Skip below
// Check if username is in the list of users for which the mental health chatbot is enabled
else if ($global_config_manager->is_allowed_user($username, "mental_health")) {
    if (!isset($update->message->text)) {
        $telegram->send_message("Sorry, I can only read text messages.");
        exit;
    }
    $message = $update->message->text;

    if (substr($message, 0, 1) == "/") {
        $command_manager = new CommandManager(array("Mental health", "Misc"));

        $command_manager->add_command(array("/start"), function($command, $_) use ($telegram, $user_config_manager, $openai, $telegram_admin, $username) {
            $session_info = $user_config_manager->get_session_info("session");
            // If there is no session info, create one
            if ($session_info == null) {
                $session_info = (object) array(
                    "running" => false,
                    "this_session_start" => null,
                    "profile" => "",
                    "last_session_start" => null,
                );
            }
            // If there is a session running, don't start a new one
            else if ($session_info->running === true) {
                $telegram->send_message("You are already in a session. Please type /end to end the session.");
                return;
            }
            $session_info->running = true;
            $session_info->this_session_start = time();
            $telegram_admin->send_message("@".$username." (chat_id: ".$telegram->get_chat_id().") started a session!", null);

            $telegram->send_message("Hello! I am a chatbot that can help you with your mental health. "
                ."I am currently in beta, so please be patient with me.\n\n"
                ."You can start a session by typing /start and end it by typing /end. "
                ."If you want to see the list of commands available, type /help.\n\n"
                ."Please remember to /end the session.");
            $chat = (object) array(
                "model" => "gpt-4",
                "temperature" => 0.5,
                "messages" => array(
                    array("role" => "system", "content" => "You are a therapist assisting me to connect to myself and heal. "
                    ."Show compassion by acknowledging and validating my feelings. Your primary goal is to provide a safe, "
                    ."nurturing, and supportive environment for me. Your task is to help me explore my thoughts, feelings, "
                    ."and experiences, while guiding me towards personal growth and emotional healing. You are also familiar "
                    ."with Internal Family Systems (IFS) therapy and might use it implicitly to guide the process. Keep your "
                    ."responses very short and compact, but as helpful as possible. And please ask if something is unclear to "
                    ."you or some important information is missing. The current time is ".date("g:ia")."."),
                ),
            );
            // If there is a previous session, add the profile to the chat history
            if ($session_info->profile != "") {
                $time_passed = time_diff($session_info->this_session_start, $session_info->last_session_start);
                $profile = $session_info->profile;
                $chat->messages[] = array("role" => "system", "content" => "For your own reference, here is the profile you previously wrote after "
                ."the last session (".$time_passed." ago) as a basis for this session:\n\n".$profile);
            }
            // Request an opening message, because it is nice and invites sharing :)
            $initial_response = $openai->gpt($chat);
            // Save gpt's response to the chat history, except if it starts with "Error: "
            if (substr($initial_response, 0, 7) != "Error: ") {
                $telegram->send_message($initial_response);

                $chat->messages = array_merge($chat->messages, array(
                    array("role" => "assistant", "content" => $initial_response)
                ));

                $user_config_manager->save_config($chat);
                $user_config_manager->save_session_info($session_info, "session");
            } else {
                $telegram->send_message("Sorry, I am having trouble connecting to the server. Please try again /start.");
            }
        }, "Mental health", "Start a new session");

        // The command /end ends the current session
        $command_manager->add_command(array("/end"), function($command, $_) use ($telegram, $openai, $user_config_manager) {
            $session_info = $user_config_manager->get_session_info("session");
            // Check if there is a session running
            if ($session_info == null || $session_info->running == false) {
                $telegram->send_message("The session isn't started yet. Please start a new session with /start.");
                exit;
            }
            // If there were more than 5 messages (2x system, 2 responses), request a session summary
            $chat = $user_config_manager->get_config();
            if (count($chat->messages) > 5) {
                if ($session_info->profile != "") {
                    $time_passed = time_diff($session_info->this_session_start, $session_info->last_session_start);
                    $chat->messages = array_merge($chat->messages, array(
                        array("role" => "system", "content" => "Here is again the profile you wrote after our previous session (".$time_passed." ago):\n\n"
                        .$session_info->profile."\n\n"."Please update it with the new information you got in this session. The goal is to have a detailed "
                        ."description of me that is useful for whatever comes up in the next session. Hence, include only information that is really "
                        ."necessary for upcoming sessions. To have an all-encompassing profile after many session, avoid removing relevant information "
                        ."from previous sessions, but integrate them into a bigger picture."),
                    ));
                } else {
                    $chat->messages = array_merge($chat->messages, array(
                        array("role" => "system", "content" => "Please write a short profile that summarizes the information you got in this session. "
                        ."Please include only information that is really necessary for upcoming sessions."),
                    ));
                }
                $new_profile = $openai->gpt($chat);
                // Error handling
                if (substr($new_profile, 0, 7) == "Error: ") {
                    $telegram->send_message("Sorry, I am having trouble connecting to the server. Please try again /end.");
                    exit;
                }
                $session_info->profile = $new_profile;
                $session_info->last_session_start = $session_info->this_session_start;
                $chat->messages = array();
                $user_config_manager->save_config($chat);
            }
            $session_info->running = false;
            $session_info->this_session_start = null;
            $user_config_manager->save_session_info($session_info, "session");
            $telegram->send_message("Session ended. Thank you for being with me today.");
        }, "Mental health", "End the current session");

        // The command /profile shows the profile of the user
        $command_manager->add_command(array("/profile"), function($command, $_) use ($telegram, $user_config_manager) {
            $session_info = $user_config_manager->get_session_info("session");
            if ($session_info->profile == "") {
                $telegram->send_message("No profile has been generated yet. Please start a new session with /start.");
            } else {
                $telegram->send_message("Here is your current profile:\n\n".$session_info->profile);
            }
        }, "Mental health", "Show your profile");

        $response = $command_manager->run_command($message);
        if ($response != "") {
            $telegram->send_message($response);
        }
        exit;
    }

    $session_info = $user_config_manager->get_session_info("session");
    // If there is no session running, don't do anything
    if ($session_info == null || $session_info->running == false) {
        $telegram->send_message("The session isn't started yet. Please start a new session with /start.");
        exit;
    }
    $user_config_manager->add_message("user", $message);
    // $telegram->send_message("Sending message to OpenAI: ".$message);
    $chat = $user_config_manager->get_config();
    $response = $openai->gpt($chat);

    // Append GPT's response to the messages array, except if it starts with "Error: "
    if (substr($response, 0, 7) != "Error: ") {
        $user_config_manager->add_message("assistant", $response);
        $telegram->send_message($response);
    }
    else {
        $user_config_manager->delete_messages(1);
        $telegram->send_message("Sorry, I am having trouble connecting to the server. Please send me your last message again.");
    }
    exit;
}
else if ($chat_id != $chat_id_admin) {
    $telegram->send_message("Sorry, I can't talk to you (chat_id: ".$chat_id.")");
    
    // Tell me ($chat_id_admin) that someone tried to talk to the bot
    // This could be used to spam the admin
    if (isset($update->message->from->username)) {
        $telegram_admin->send_message("@".$username." tried to talk to me");
    }
    else {
        $telegram_admin->send_message("Someone without a username tried to talk to me");
    }
    exit;
}

if (isset($update->message->text)) {
    $message = $update->message->text;
}
else if (isset($update->message->photo)) {
    $file_id = $update->message->photo[0]->file_id; // This is gonna be useful as soon as GPT-4 accepts images
    $file_url = $telegram->get_file_url($file_id); // Don't forget to handle this being null
    $caption = isset($update->message->caption) ? $update->message->caption : "";
    $telegram->send_message("Sorry, I don't know yet what to do with images. Please send me a text message instead.");
    exit;
}
else {
    $telegram->send_message("Sorry, I don't know yet what do to this message:\n\n".json_encode($update->message, JSON_PRETTY_PRINT));
    exit;
}

if ($DEBUG) {
    $telegram->send_message("You said: ".$message);
    echo "You said: ".$message;
}

// #######################
// ### Command parsing ###
// #######################

// If starts with "." or "\", it's probably a typo for a command
if (substr($message, 0, 1) == "." || (substr($message, 0, 1) == "\\" && !(substr($message, 1, 1) == "." || substr($message, 1, 1) == "\\"))) {
    // Shorten the message if it's too long
    if (strlen($message) > 100) {
        $message = substr($message, 0, 100)."...";
    }
    $telegram->send_message("Did you mean the command /".substr($message, 1)." ? If not, escape the first character with '\\'.");
    exit;
}

// If $message starts with /, it's a command
if (substr($message, 0, 1) == "/") {
    if ($chat_id == $chat_id_admin) {
        $categories = array("Presets", "Settings", "Chat history management", "Admin", "Misc");
    } else {
        $categories = array("Presets", "Settings", "Chat history management", "Misc");
    }
    $command_manager = new CommandManager($categories);

    // #########################
    // ### Commands: Presets ###
    // #########################
    // The command is /start or /reset resets the bot and sends a welcome message
    $reset = function($command, $_) {
        global $telegram, $user_config_manager;
        $user_config_manager->save_config(array(
            "model" => "gpt-4",
            "temperature" => 0.9,
            "messages" => array(
                array("role" => "system", "content" => "You are a helpful and supportive assistant. Feel free to recommend something, "
                ."but only if it seems very useful and appropriate. Keep your responses concise and compact. "
                ."You can use Telegram Markdown to format your messages."),
            )
        ));
        $telegram->send_message("Hello, there! I am your personal assistant ❤️\n\nIf you want to know what I can do, type /help.");
    };

    $command_manager->add_command(array("/start", "/reset", "/r"), $reset, "Presets", "Generic personal assistant");

    // The command /therapist is a preset for a therapist
    $command_manager->add_command(array("/therapist", "/therapy", "/t"), function($command, $_) use ($telegram, $user_config_manager, $openai) {
        $session_info = $user_config_manager->get_session_info("therapy");
        // If there is no session info, create one
        if ($session_info == null) {
            $session_info = (object) array(
                "running" => false,
                "this_session_start" => null,
                "profile" => "",
                "last_session_start" => null,
            );
        }
        // If there is a session running, don't start a new one
        else if ($session_info->running === true) {
            $telegram->send_message("You are already in a session. Please type /end to end the session.");
            return;
        }
        $session_info->running = true;
        $session_info->this_session_start = time();

        $chat = (object) array(
            "model" => "gpt-4",
            "temperature" => 0.5,
            "messages" => array(
                array("role" => "system", "content" => "You are a therapist assisting me to connect to myself and heal. "
                ."Show compassion by acknowledging and validating my feelings. Your primary goal is to provide a safe, "
                ."nurturing, and supportive environment for me. Your task is to help me explore my thoughts, feelings, "
                ."and experiences, while guiding me towards personal growth and emotional healing. You are also familiar "
                ."with Internal Family Systems (IFS) therapy and might use it implicitly to guide the process. Keep your "
                ."responses very short and compact, but as helpful as possible. And please ask if something is unclear to "
                ."you or some important information is missing. The current time is ".date("g:ia")."."),
            ),
        );
        $session_info = $user_config_manager->get_session_info("therapy");
        // If there is a previous session, add the profile to the chat history
        if ($session_info->profile != "") {
            $time_passed = time_diff($session_info->this_session_start, $session_info->last_session_start);
            $profile = $session_info->profile;
            $chat->messages[] = array("role" => "system", "content" => "For your own reference, here is the profile you previously wrote after "
            ."the last session (".$time_passed." ago) as a basis for this session:\n\n".$profile);
        }
        // Request an opening message, because it is nice and invites sharing :)
        $initial_response = $openai->gpt($chat);
        $telegram->send_message($initial_response);
        // Save gpt's response to the chat history, except if it starts with "Error: "
        if (substr($initial_response, 0, 7) != "Error: ") {
            $chat->messages = array_merge($chat->messages, array(
                array("role" => "assistant", "content" => $initial_response)
            ));

            $user_config_manager->save_config($chat);
            $user_config_manager->save_session_info($session_info, "therapy");
        }
    }, "Presets", "Therapist");

    // The command /end ends the current session
    $command_manager->add_command(array("/end"), function($command, $_) use ($telegram, $openai, $user_config_manager, $reset) {
        $session_info = $user_config_manager->get_session_info("therapy");
        // Check if there is a session running
        if ($session_info == null || $session_info->running == false) {
            $telegram->send_message("The session isn't started yet. Please start a new session with /therapy.");
            exit;
        }
        // If there were more than 7 messages (2x system, 3 responses), request a session summary
        $chat = $user_config_manager->get_config();
        if (count($chat->messages) > 7) {
            if ($session_info->profile != "") {
                $time_passed = time_diff($session_info->this_session_start, intval($session_info->last_session_start));
                $chat->messages = array_merge($chat->messages, array(
                    array("role" => "system", "content" => "Here is again the profile you wrote after our previous session (".$time_passed." ago):\n\n"
                    .$session_info->profile."\n\n"."Please update it with the new information you got in this session. The goal is to have a detailed "
                    ."description of me that is useful for whatever comes up in the next session. Hence, include only information that is really "
                    ."necessary for upcoming sessions. To have an all-encompassing profile after many session, avoid removing relevant information "
                    ."from previous sessions, but integrate them into a bigger picture."),
                ));
            } else {
                $chat->messages = array_merge($chat->messages, array(
                    array("role" => "system", "content" => "Please write a short profile that summarizes the information you got in this session. "
                    ."Please include only information that is really necessary for upcoming sessions."),
                ));
            }
            $new_profile = $openai->gpt($chat);
            // Error handling
            if (substr($new_profile, 0, 7) == "Error: ") {
                $telegram->send_message("There was an error while requesting a session summary. Please try again /end.\n\n".$new_profile);
                exit;
            }
            $session_info->profile = $new_profile;
            $session_info->last_session_start = $session_info->this_session_start;
            $telegram->send_message("Session ended. Thank you for being with me today. Here is your updated profile:\n\n".$new_profile);
        }
        $session_info->running = false;
        $session_info->this_session_start = null;
        $user_config_manager->save_session_info($session_info, "therapy");
        $reset($command, $_);
    }, "Presets", "End the current session");

    // The command /profile shows the current profile
    $command_manager->add_command(array("/profile"), function($command, $_) use ($telegram, $user_config_manager) {
        $session_info = $user_config_manager->get_session_info("therapy");
        if ($session_info == null || $session_info->profile == "") {
            $telegram->send_message("There is no profile yet. Please start a new session with /therapy.");
            exit;
        }
        $telegram->send_message("Here is your current profile:\n\n".$session_info->profile);
    }, "Presets", "Show the current profile");

    // The command /responder writes a response to a given message
    $command_manager->add_command(array("/responder", "/re"), function($command, $_) use ($telegram, $user_config_manager, $openai) {
        $user_config_manager->save_config(array(
            "model" => "gpt-4",
            "temperature" => 0.7,
            "messages" => array(array("role" => "system", "content" => "Your task is to generate responses to messages sent to me. "
            ."Use a causal, calm, and kind voice. Keep your responses concise."))
        ));
        $telegram->send_message("Chat history reset. I am now a message responder.");
    }, "Presets", "Message responder");

    // The command /summarizer summarizes a given text
    $command_manager->add_command(array("/summarizer", "/sum"), function($command, $_) use ($telegram, $user_config_manager, $openai) {
        $user_config_manager->save_config(array(
            "model" => "gpt-4",
            "temperature" => 0.7,
            "messages" => array(array("role" => "system", "content" => "Your task is to generate a summary of the messages sent to me. "
            ."Use a causal, calm, and kind voice. Keep your responses concise."))
        ));
        $telegram->send_message("Chat history reset. I am now a message summarizer.");
    }, "Presets", "Summarizer");

    // The command /translator translates a given text
    $command_manager->add_command(array("/translator", "/trans"), function($command, $_) use ($telegram, $user_config_manager, $openai) {
        $user_config_manager->save_config(array(
            "model" => "gpt-4",
            "temperature" => 0.7,
            "messages" => array(array("role" => "system", "content" => "Your task is to translate the messages sent to you into English. "
            ."Say which language or encoding you translate the text from."))
        ));
        $telegram->send_message("Chat history reset. I am now a translator.");
    }, "Presets", "Translator");

    // The command /calendar converts event descriptions to an iCalendar file
    $command_manager->add_command(array("/calendar", "/cal"), function($command, $_) use ($telegram, $user_config_manager, $openai) {
        $user_config_manager->save_config(array(
            "model" => "gpt-4",
            "temperature" => 0,
            "messages" => array(array("role" => "system", "content" => "Extract details about events from the provided text and output an "
            ."event in iCalendar format. Try to infer the time zone from the location. Ensure that the code is valid. Output the code only. "
            ."The current year is ".date("Y")."."))
        ));
        $telegram->send_message("Chat history reset. I am now a calendar bot. Give me an invitation or event description!");
    }, "Presets", "Converts to iCalendar format");

    // The command /paper supports writing an academic paper
    $command_manager->add_command(array("/paper", "/research"), function($command, $_) use ($telegram, $user_config_manager, $openai) {
        $user_config_manager->save_config(array(
            "model" => "gpt-4",
            "temperature" => 0.7,
            "messages" => array(array("role" => "system", "content" => "Your task is to assist me in composing a research-grade paper. "
            ."I will provide a paragraph containing notes or half-formed sentences. Please formulate it into a simple, well-written academic text. "
            ."The text is written in LaTeX. Add details and equations wherever you would find them useful."))
        ));
        $telegram->send_message("Chat history reset. I will support you in writing academic text.");
    }, "Presets", "Paper writing support");

    // The command /code is a programming assistant
    $command_manager->add_command(array("/code", "/program"), function($command, $_) use ($telegram, $user_config_manager, $openai) {
        $user_config_manager->save_config(array(
            "model" => "gpt-4",
            "temperature" => 0.7,
            "messages" => array(array("role" => "system", "content" => "You are a programming and system administration assistant. "
            ."If there is a lack of details, state your uncertainty and ask for clarification. Do not show any warnings or information "
            ."regarding your capabilities. Keep your response short and avoid unnecessary explanations. If you provide code, ensure it is valid."))
        ));
        $telegram->send_message("Chat history reset. I will support you in writing code.");
    }, "Presets", "Programming assistant");


    // TODO !!! Add more presets here !!!




    // ##########################
    // ### Commands: Settings ###
    // ##########################
    // The command /model shows the current model and allows to change it
    $command_manager->add_command(array("/model", "/m"), function($command, $_) use ($telegram, $user_config_manager) {
        $chat = $user_config_manager->get_config();
        $telegram->send_message("You are currently talking to ".($chat->model).".\nAvailable models are /gpt4 and /chatgpt.");
    }, "Settings", "Show the current model");

    // Specific commands /gpt4 and /chatgpt for the respective models
    $command_manager->add_command(array("/gpt4", "/chatgpt"), function($command, $_) use ($telegram, $user_config_manager) {
        $models = array(
            "/gpt4" => "gpt-4",
            "/chatgpt" => "gpt-3.5-turbo",
        );
        $model = $models[$command];
        $chat = $user_config_manager->get_config();
        if ($chat->model != $model) {
            $chat->model = $model;
            $user_config_manager->save_config($chat);
            $telegram->send_message("You are now talking to ".($chat->model).".");
        } else {
            $telegram->send_message("You are already talking to ".($chat->model).".");
        }
    }, "Settings", "Specific commands for the respective models");

    // The command /temperature shows the current temperature and allows to change it
    $command_manager->add_command(array("/temperature", "/temp"), function($command, $temperature) use ($telegram, $user_config_manager) {
        $chat = $user_config_manager->get_config();
        if (is_numeric($temperature)) {
            $temperature = floatval($temperature);
            if ($temperature >= 0 && $temperature <= 2) {
                $chat->temperature = $temperature;
                $user_config_manager->save_config($chat);
                $telegram->send_message("Temperature set to ".$chat->temperature.".");
            } else {
                $telegram->send_message("Temperature must be between 0 and 2.");
            }
        } else {
            $telegram->send_message("Temperature is currently set to ".$chat->temperature.". To set the temperature, you can provide a number between 0 and 2 with the command.");
        }
    }, "Settings", "Show the current temperature or change it");

    // ###############################
    // ### Chat history management ###
    // ###############################

    // The command /clear clears the chat history
    $command_manager->add_command(array("/clear", "/clr"), function($command, $_) use ($telegram, $user_config_manager) {
        $chat = $user_config_manager->get_config();
        $chat->messages = array();
        $user_config_manager->save_config($chat);
        $telegram->send_message("Chat history cleared.");
    }, "Chat history management", "Clear the chat history");

    // The command /delete deletes the last n messages, or the last message if no number is provided
    $command_manager->add_command(array("/delete", "/del"), function($command, $n) use ($telegram, $user_config_manager) {
        if (is_numeric($n)) {
            $n = intval($n);
            if ($n > 0) {
                $n = $user_config_manager->delete_messages($n);
                if ($n == 0) {
                    $telegram->send_message("There are no messages to delete.");
                } else {
                    $telegram->send_message("Deleted the last ".$n." messages.");
                }
            } else {
                $telegram->send_message("You can only delete a positive number of messages.");
            }
        } else {
            $n = $user_config_manager->delete_messages(1);
            if ($n == 0) {
                $telegram->send_message("There are no messages to delete.");
            } else {
                $telegram->send_message("Deleted the last message.");
            }
        }
    }, "Chat history management", "Delete the last n messages, or the last message if no number is provided");

    // The command /user adds a user message to the chat history
    $command_manager->add_command(array("/user", "/u"), function($command, $message) use ($telegram, $user_config_manager) {
        if ($message == "") {
            $telegram->send_message("Please provide a message to add.");
            return;
        }
        $user_config_manager->add_message("user", $message);
        $telegram->send_message("Added user message to chat history.");
    }, "Chat history management", "Add a message with \"user\" role");

    // The command /assistant adds an assistant message to the chat history
    $command_manager->add_command(array("/assistant", "/a"), function($command, $message) use ($telegram, $user_config_manager) {
        if ($message == "") {
            $telegram->send_message("Please provide a message to add.");
            return;
        }
        $user_config_manager->add_message("assistant", $message);
        $telegram->send_message("Added assistant message to chat history.");
    }, "Chat history management", "Add a message with \"assistant\" role");

    // The command /system adds a system message to the chat history
    $command_manager->add_command(array("/system", "/s"), function($command, $message) use ($telegram, $user_config_manager) {
        if ($message == "") {
            $telegram->send_message("Please provide a message to add.");
            return;
        }
        $user_config_manager->add_message("system", $message);
        $telegram->send_message("Added system message to chat history.");
    }, "Chat history management", "Add a message with \"system\" role");

    if ($chat_id == $chat_id_admin) {
        // #######################
        // ### Commands: Admin ###
        // #######################

        // The command /addusermh adds a user to the list of authorized users for the mental health assistant
        $command_manager->add_command(array("/addusermh"), function($command, $username) use ($telegram, $global_config_manager) {
            if ($username == "" || $username[0] != "@") {
                $telegram->send_message("Please provide a username to add.");
                return;
            }
            $username = substr($username, 1);
            if ($global_config_manager->is_allowed_user($username, "mental_health")) {
                $telegram->send_message("User @".$username." is already in the list of users authorized for the mental health assistant.");
                return;
            }
            $global_config_manager->add_allowed_user($username, "mental_health");
            $telegram->send_message("Added user @".$username." to the list of users authorized for the mental health assistant.");
        }, "Admin", "Add a user for mental health assistant");

        // The command /removeusermh removes a user from the list of authorized users for the mental health assistant
        $command_manager->add_command(array("/removeusermh"), function($command, $username) use ($telegram, $global_config_manager) {
            if ($username == "" || $username[0] != "@") {
                $telegram->send_message("Please provide a username to remove.");
                return;
            }
            $username = substr($username, 1);
            try {
                $global_config_manager->remove_allowed_user($username, "mental_health");
            } catch (Exception $e) {
                $telegram->send_message("Error: ".json_encode($e));
                return;
            }
            $telegram->send_message("Removed user @".$username." from the list of users authorized for the mental health assistant.");
        }, "Admin", "Remove a user from mental health assistant");

        // The command /listusers lists all users authorized, by category
        $command_manager->add_command(array("/listusers"), function($command, $_) use ($telegram, $global_config_manager) {
            $categories = $global_config_manager->get_categories();
            $message = "Lists of authorized users, by category:\n";
            foreach ($categories as $category) {
                $message .= "\n*".$category."*:\n";
                $users = $global_config_manager->get_allowed_users($category);
                if (count($users) == 0) {
                    $message .= "No users authorized for this category.\n";
                } else {
                    foreach ($users as $user) {
                        $message .= "@".$user."\n";
                    }
                }
            }
            $telegram->send_message($message);
        }, "Admin", "List all users authorized, by category");
    }

    // ######################
    // ### Commands: Misc ###
    // ######################

    // The command /continue requests a response from the model
    $command_manager->add_command(array("/continue", "/c"), function($command, $_) use ($telegram, $user_config_manager, $openai) {
        $chat = $user_config_manager->get_config();
        $response = $openai->gpt($chat);
        $user_config_manager->add_message("assistant", $response);
        $telegram->send_message($response);
    }, "Misc", "Request another response");

    // The command /image requests an image from the model
    $command_manager->add_command(array("/image", "/img", "/i"), function($command, $prompt) use ($telegram, $openai) {
        if ($prompt == "") {
            $telegram->send_message("Please provide a prompt with command ".$command.".");
            exit;
        }
        // If prompt is a URL, send the URL to telegram instead of requesting an image
        if (filter_var($prompt, FILTER_VALIDATE_URL)) {
            $telegram->send_image($prompt);
            exit;
        }
        $image_url = $openai->dalle($prompt);
        if ($image_url == "") {
            $telegram->send_message("WTF-Error: Could not generate an image. Please try again later.");
            exit;
        }
        log_image($prompt, $image_url, $telegram->get_chat_id());
        // if image_url starts with "Error: "
        if (substr($image_url, 0, 7) == "Error: ") {
            $error_message = $image_url;
            $telegram->send_message($error_message);
            exit;
        }
        $telegram->send_image($image_url);
    }, "Misc", "Request an image");

    // The command /dump outputs the content of the permanent storage
    $command_manager->add_command(array("/dump", "/d"), function($command, $_) use ($telegram, $user_config_manager) {
        $file = $user_config_manager->get_file();
        $telegram->send_message(file_get_contents($file), null);
    }, "Misc", "Dump the data saved in the permanent storage");

    // The command /dumpmessages outputs the messages in a form that could be used to recreate the chat history
    $command_manager->add_command(array("/dumpmessages", "/dm"), function($command, $_) use ($telegram, $user_config_manager) {
        $messages = $user_config_manager->get_config()->messages;
        // Add the roles in the beginning of each message
        $messages = array_map(function($message) {
            return "/".$message->role." ".$message->content;
        }, $messages);
        // Send each message as a separate message
        foreach ($messages as $message) {
            $telegram->send_message($message);
        }
    }, "Misc", "Dump the messages in the chat history");

    // ############################
    // Actually run the command!
    $response = $command_manager->run_command($message);
    if ($response != "") {
        $telegram->send_message($response);
    }
    exit;
}

// #############################
// ### Main interaction loop ###
// #############################

$user_config_manager->add_message("user", $message);
// $telegram->send_message("Sending message to OpenAI: ".$message);
$chat = $user_config_manager->get_config();
$response = $openai->gpt($chat);

// Append GPT's response to the messages array, except if it starts with "Error: "
if (substr($response, 0, 7) != "Error: ") {
    $user_config_manager->add_message("assistant", $response);
}

// If the response starts with "BEGIN:VCALENDAR", it is an iCalendar event
if (substr($response, 0, 15) == "BEGIN:VCALENDAR") {
    $file_name = "event.ics";
    $telegram->send_document($file_name, $response);
} else {
    // Send the response to Telegram
    $telegram->send_message($response);
}
?>