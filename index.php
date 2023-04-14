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

// Tokens and keys
$telegram_token = $_SERVER['TELEGRAM_BOT_TOKEN'];
$secret_token = $_SERVER['TELEGRAM_BOT_SECRET'];
$chat_id_admin = $_SERVER['CHAT_ID_ADMIN']; // For now, we only have one chat
$openai_api_key = $_SERVER['OPENAI_API_KEY'];

// Set the time zone to give the correct time to the model
if (isset($_SERVER['TIME_ZONE'])) {
    date_default_timezone_set($_SERVER['TIME_ZONE']);
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

require_once __DIR__."/utils.php";
require_once __DIR__."/logger.php";
require_once __DIR__."/telegram.php";
require_once __DIR__."/openai.php";
require_once __DIR__."/storage_manager.php";
require_once __DIR__."/command_manager.php";

// ###################
// ### Main script ###
// ###################
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the message from the Telegram API
    $content = file_get_contents("php://input");

    // An incoming message is in the following format:
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

    $update = json_decode($content, true);
    if (!$update) {
        exit;
    }

    // Avoid processing the same message twice by checking whether update_id was already processed
    $update_id = $update["update_id"];
    $message = $update["message"]["text"];
    $chat_id = $update["message"]["chat"]["id"];

    // Initialize the telegram manager
    $telegram = new Telegram($telegram_token, $chat_id);
    // $telegram->send_message("You said: ".$message); // Debug

    if (already_seen($update_id)) {
        $telegram->send_message("Repeated message ignored (len: ".strlen($message).", update_id: ".$update_id.")");
        exit;
    }
    log_update_id($update_id);

    // Security checks
    // 1. Reject message if it's not from $chat_id_admin
    if ($chat_id != $chat_id_admin) {
        $telegram->send_message("Sorry, I can't talk to you (chat_id: ".$chat_id.")");
        // Tell me ($chat_id_admin) that someone tried to talk to me
        $telegram2 = new Telegram($telegram_token, $chat_id_admin);
        $username = $update["message"]["from"]["username"];
        $telegram2->send_message("@".$username." tried to talk to me");
        exit;
    }
    // 2. Check if the secret token is correct. It is sent in the header “X-Telegram-Bot-Api-Secret-Token”
    if (!$DEBUG && $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] != $secret_token) {
        $telegram->send_message("Sorry, I can't talk to you. Wrong secret token.");
        exit;
    }

    // Initialize the storage manager, openai manager and command manager
    $storage_manager = new StorageManager($chat_id);
    $openai = new OpenAI($openai_api_key);
    $command_manager = new CommandManager(array("Presets", "Settings", "Chat history management", "Misc"));

    // ###############
    // ### Presets ###
    // ###############
    // The command is /start or /reset resets the bot and sends a welcome message
    $reset = function($command, $_) {
        global $telegram, $storage_manager;
        $storage_manager->save_config(array(
            "model" => "gpt-4",
            "temperature" => 0.9,
            "messages" => array(
                array("role" => "system", "content" => "You are a helpful and supportive assistant. Feel free to recommend something, but only if it seems very useful and appropriate. Keep your responses concise and compact. You can use Telegram Markdown to format your messages."),
            )
        ));
        $telegram->send_message("Hello, there! I am your personal assistant ❤️\n\nIf you want to know what I can do, type /help.");
    };

    $command_manager->add_command(array("/start", "/reset", "/r"), $reset, "Presets", "Generic personal assistant");

    // The command /therapist is a preset for a therapist
    $command_manager->add_command(array("/therapist", "/therapy", "/t"), function($command, $_) use ($telegram, $storage_manager, $openai) {
        $chat = (object) array(
            "model" => "gpt-4",
            "temperature" => 0.5,
            "messages" => array(
                array("role" => "system", "content" => "You are a therapist assisting me to connect to myself and heal. Show compassion by acknowledging and validating my feelings. Your primary goal is to provide a safe, nurturing, and supportive environment for me. Your task is to help me explore my thoughts, feelings, and experiences, while guiding me towards personal growth and emotional healing. You are also familiar with Internal Family Systems (IFS) therapy and might use it implicitly to guide the process. Keep your responses very short and compact, but as helpful as possible. The current time is ".date("g:ia")."."),
            ),
        );
        $session_info = $storage_manager->get_session_info("therapy");
        // If there is a previous session ($session info not null), add the profile to the chat history
        if ($session_info != null) {
            $time_passed = time_diff(time(), intval($session_info->time));
            $profile = $session_info->profile;
            if ($profile != "") {
                $chat->messages[] = array("role" => "system", "content" => "Here is the profile you previously wrote after the last session (".$time_passed." ago) as a basis for this session:\n\n".$profile);
            }
        }
        // send_message("Chat history reset. I am now a therapist.", $chat_id);
        // Request an opening message, because it is nice and invites sharing :)
        $initial_response = $openai->gpt($chat);
        $telegram->send_message($initial_response);
        // Save gpt's response to the chat history, except if it starts with "Error: "
        if (substr($initial_response, 0, 7) != "Error: ") {
            $chat->messages = array_merge($chat->messages, array(
                array("role" => "assistant", "content" => $initial_response)
            ));
        }
        $storage_manager->save_config($chat);
    }, "Presets", "Therapist");

    // The command /end ends the current session
    $command_manager->add_command(array("/end"), function($command, $_) use ($telegram, $openai, $storage_manager, $reset) {
        // Check if the current session is a therapy session, by checking if the first message starts with "You are a therapist"
        $chat = $storage_manager->get_config();
        // Fail if messages is empty or the first message does not start with "You are a therapist"
        if (count($chat->messages) == 0 || substr($chat->messages[0]->content, 0, 19) != "You are a therapist") {
            $telegram->send_message("Non-therapy sessions don't exist yet.");
            exit;
        }
        // If there were more than 7 messages (2x system, 3 responses), request a session summary
        if (count($chat->messages) > 7) {
            $session_info = $storage_manager->get_session_info("therapy");
            if ($session_info != null) {
                $time_passed = time_diff(time(), intval($session_info->time));
                $chat->messages = array_merge($chat->messages, array(
                    array("role" => "system", "content" => "Here is again the profile you wrote after our previous session (".$time_passed." ago):\n\n".$session_info->profile."\n\nPlease update it with the information you got in this session. Please include only information that is really necessary for upcoming sessions."),
                ));
            } else {
                $chat->messages = array_merge($chat->messages, array(
                    array("role" => "system", "content" => "Please write a short profile that summarizes the information you got in this session. Please include only information that is really necessary for upcoming sessions."),
                ));
            }
            $new_profile = $openai->gpt($chat);
            // Error handling
            if (substr($new_profile, 0, 7) == "Error: ") {
                $telegram->send_message("Error: ".$new_profile);
                exit;
            }
            $storage_manager->save_session_info(array(
                "profile" => $new_profile,
                "time" => time(),
            ), "therapy");
            $storage_manager->save_config($chat);
            $telegram->send_message("Session ended. Thank you for being with me today. Here is the updated profile:\n\n".$new_profile);
        }
        $reset($command, $_);
    }, "Presets", "End the current session");

    // The command /responder writes a response to a given message
    $command_manager->add_command(array("/responder", "/re"), function($command, $_) use ($telegram, $storage_manager, $openai) {
        $storage_manager->save_config(array(
            "model" => "gpt-4",
            "temperature" => 0.7,
            "messages" => array(array("role" => "system", "content" => "Your task is to generate responses to messages sent to me. Use a causal, calm, and kind voice. Keep your responses concise."))
        ));
        $telegram->send_message("Chat history reset. I am now a message responder.");
    }, "Presets", "Message responder");

    // The command /summarizer summarizes a given text
    $command_manager->add_command(array("/summarizer", "/sum"), function($command, $_) use ($telegram, $storage_manager, $openai) {
        $storage_manager->save_config(array(
            "model" => "gpt-4",
            "temperature" => 0.7,
            "messages" => array(array("role" => "system", "content" => "Your task is to generate a summary of the messages sent to me. Use a causal, calm, and kind voice. Keep your responses concise."))
        ));
        $telegram->send_message("Chat history reset. I am now a message summarizer.");
    }, "Presets", "Summarizer");

    // The command /translator translates a given text
    $command_manager->add_command(array("/translator", "/trans"), function($command, $_) use ($telegram, $storage_manager, $openai) {
        $storage_manager->save_config(array(
            "model" => "gpt-4",
            "temperature" => 0.7,
            "messages" => array(array("role" => "system", "content" => "Your task is to translate the messages sent to you into English. Say which language or encoding you translate the text from."))
        ));
        $telegram->send_message("Chat history reset. I am now a translator.");
    }, "Presets", "Translator");

    // The command /calendar converts event descriptions to an iCalendar file
    $command_manager->add_command(array("/calendar", "/cal"), function($command, $_) use ($telegram, $storage_manager, $openai) {
        $storage_manager->save_config(array(
            "model" => "gpt-4",
            "temperature" => 0,
            "messages" => array(array("role" => "system", "content" => "Extract details about events from the provided text and output an event in iCalendar format. Ensure that the code is valid. Output the code only. The current year is ".date("Y")."."))
        ));
        $telegram->send_message("Chat history reset. I am now a calendar bot. Give me an invitation or event description!");
    }, "Presets", "Converts to iCalendar format");

    // The command /paper supports writing an academic paper
    $command_manager->add_command(array("/paper", "/research"), function($command, $_) use ($telegram, $storage_manager, $openai) {
        $storage_manager->save_config(array(
            "model" => "gpt-4",
            "temperature" => 0.7,
            "messages" => array(array("role" => "system", "content" => "Your task is to assist me in composing a research-grade paper. I will provide a paragraph containing notes or half-formed sentences. Please formulate it into a simple, well-written academic text. The text is written in LaTeX. Add details and equations wherever you would find them useful."))
        ));
        $telegram->send_message("Chat history reset. I will support you in writing academic text.");
    }, "Presets", "Paper writing support");

    // The command /code is a programming assistant
    $command_manager->add_command(array("/code", "/program"), function($command, $_) use ($telegram, $storage_manager, $openai) {
        $storage_manager->save_config(array(
            "model" => "gpt-4",
            "temperature" => 0.7,
            "messages" => array(array("role" => "system", "content" => "You are a programming and system administration assistant. If there is a lack of details, state your uncertainty and ask for clarification. Do not show any warnings or information regarding your capabilities. Keep your response short and avoid unnecessary explanations. If you provide code, ensure it is valid."))
        ));
        $telegram->send_message("Chat history reset. I will support you in writing code.");
    }, "Presets", "Programming assistant");


    // TODO !!! Add more presets here !!!




    // ###############
    // ### Settings ###
    // ###############
    // The command /model shows the current model and allows to change it
    $command_manager->add_command(array("/model", "/m"), function($command, $_) use ($telegram, $storage_manager) {
        $chat = $storage_manager->get_config();
        $telegram->send_message("You are currently talking to ".($chat->model).".\nAvailable models are /gpt4 and /chatgpt.");
    }, "Settings", "Show the current model");

    // Specific commands /gpt4 and /chatgpt for the respective models
    $command_manager->add_command(array("/gpt4", "/chatgpt"), function($command, $_) use ($telegram, $storage_manager) {
        $models = array(
            "/gpt4" => "gpt-4",
            "/chatgpt" => "gpt-3.5-turbo",
        );
        $model = $models[$command];
        $chat = $storage_manager->get_config();
        if ($chat->model != $model) {
            $chat->model = $model;
            $storage_manager->save_config($chat);
            $telegram->send_message("You are now talking to ".($chat->model).".");
        } else {
            $telegram->send_message("You are already talking to ".($chat->model).".");
        }
    }, "Settings", "Specific commands for the respective models");

    // The command /temperature shows the current temperature and allows to change it
    $command_manager->add_command(array("/temperature", "/temp"), function($command, $temperature) use ($telegram, $storage_manager) {
        $chat = $storage_manager->get_config();
        if (is_numeric($temperature)) {
            $temperature = floatval($temperature);
            if ($temperature >= 0 && $temperature <= 2) {
                $chat->temperature = $temperature;
                $storage_manager->save_config($chat);
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
    $command_manager->add_command(array("/clear", "/clr"), function($command, $_) use ($telegram, $storage_manager) {
        $chat = $storage_manager->get_config();
        $chat->messages = array();
        $storage_manager->save_config($chat);
        $telegram->send_message("Chat history cleared.");
    }, "Chat history management", "Clear the chat history");

    // The command /delete deletes the last n messages, or the last message if no number is provided
    $command_manager->add_command(array("/delete", "/del"), function($command, $n) use ($telegram, $storage_manager) {
        if (is_numeric($n)) {
            $n = intval($n);
            if ($n > 0) {
                $n = $storage_manager->delete_messages($n);
                if ($n == 0) {
                    $telegram->send_message("There are no messages to delete.");
                } else {
                    $telegram->send_message("Deleted the last ".$n." messages.");
                }
            } else {
                $telegram->send_message("You can only delete a positive number of messages.");
            }
        } else {
            $n = $storage_manager->delete_messages(1);
            if ($n == 0) {
                $telegram->send_message("There are no messages to delete.");
            } else {
                $telegram->send_message("Deleted the last message.");
            }
        }
    }, "Chat history management", "Delete the last n messages, or the last message if no number is provided");

    // The command /user adds a user message to the chat history
    $command_manager->add_command(array("/user", "/u"), function($command, $message) use ($telegram, $storage_manager) {
        if ($message == "") {
            $telegram->send_message("Please provide a message to add.");
            return;
        }
        $storage_manager->add_message("user", $message);
        $telegram->send_message("Added user message to chat history.");
    }, "Chat history management", "Add a message with \"user\" role");

    // The command /assistant adds an assistant message to the chat history
    $command_manager->add_command(array("/assistant", "/a"), function($command, $message) use ($telegram, $storage_manager) {
        if ($message == "") {
            $telegram->send_message("Please provide a message to add.");
            return;
        }
        $storage_manager->add_message("assistant", $message);
        $telegram->send_message("Added assistant message to chat history.");
    }, "Chat history management", "Add a message with \"assistant\" role");

    // The command /system adds a system message to the chat history
    $command_manager->add_command(array("/system", "/s"), function($command, $message) use ($telegram, $storage_manager) {
        if ($message == "") {
            $telegram->send_message("Please provide a message to add.");
            return;
        }
        $storage_manager->add_message("system", $message);
        $telegram->send_message("Added system message to chat history.");
    }, "Chat history management", "Add a message with \"system\" role");

    // ############
    // ### Misc ###
    // ############

    // The command /continue requests a response from the model
    $command_manager->add_command(array("/continue", "/c"), function($command, $_) use ($telegram, $storage_manager, $openai) {
        $chat = $storage_manager->get_config();
        $response = $openai->gpt($chat);
        $storage_manager->add_message("assistant", $response);
        $telegram->send_message($response);
    }, "Misc", "Request another response");

    // The command /image requests an image from the model
    // Hint: Use $openai->dalle($prompt) to request an image from DALL·E
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
    $command_manager->add_command(array("/dump", "/d"), function($command, $_) use ($telegram, $storage_manager) {
        $file = $storage_manager->get_file();
        $telegram->send_message(file_get_contents($file));
    }, "Misc", "Dump the data saved in the permanent storage");

    // ############
    // ### Main ###
    // ############

    // If starts with "." or "\", it's probably typo for a command
    if (substr($message, 0, 1) == "." || (substr($message, 0, 1) == "\\" && !(substr($message, 1, 1) == "." || substr($message, 1, 1) == "\\"))) {
        // Shorten the message if it's too long
        if (strlen($message) > 100) {
            $message = substr($message, 0, 100)."...";
        }
        $telegram->send_message("Did you mean the command /".substr($message, 1)." ? If not, escape the first character with '\\'.");
        exit;
    }

    // If the message starts with "/", it's a command
    if (substr($message, 0, 1) == "/") {
        $resp = $command_manager->run_command($message);
        if ($resp != "") {
            $telegram->send_message($resp);
        }
        exit;
    }

    $storage_manager->add_message("user", $message);
    // $telegram->send_message("Sending message to OpenAI: ".$message);
    $chat = $storage_manager->get_config();
    $response = $openai->gpt($chat);

    // Append GPT's response to the messages array, except if it starts with "Error: "
    if (substr($response, 0, 7) != "Error: ") {
        $storage_manager->add_message("assistant", $response);
    }

    // If the response starts with "BEGIN:VCALENDAR", it is an iCalendar event
    if (substr($response, 0, 15) == "BEGIN:VCALENDAR") {
        $file_name = "event.ics";
        $telegram->send_document($file_name, $response);
    } else {
        // Send the response to Telegram
        $telegram->send_message($response);
    }
} else {
    // If this script is not called by the Telegram API, check if the webhook is set up correctly
    // So, we just need to send a message to the Telegram API
    $ip = $_SERVER['REMOTE_ADDR'];
    $telegram = new Telegram($telegram_token, $chat_id_admin);
    $link = "https://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
    $telegram->send_message("Someone (".$ip.") called on ".$link." with a non-POST method!");
    echo "There is nothing to see here! :P";
}
?>