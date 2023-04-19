<?php

/**
 * This is the main function for the general bot.
 * 
 * @param object $update The update object
 * @param UserConfigManager $user_config_manager The user config manager
 * @param Telegram $telegram The Telegram manager for the user
 * @param OpenAI $openai The OpenAI object
 * @param Telegram $telegram_admin The Telegram manager for the admin
 * @param string $username The username of the user
 * @param GlobalConfigManager $global_config_manager The global config manager
 * @param bool $is_admin Whether the user is an admin
 * @param bool $DEBUG Whether to enable debug mode
 * @return void
 */
function run_general_bot($update, $user_config_manager, $telegram, $openai, $telegram_admin, $username, $global_config_manager, $is_admin, $DEBUG) {
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
        if ($is_admin) {
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

        // The command /name allows the user to change their name
        $command_manager->add_command(array("/name"), function($command, $name) use ($telegram, $user_config_manager) {
            if ($name == "") {
                $telegram->send_message("Your name is currently set to ".$user_config_manager->get_name().". To set your name, you can provide a name with the command.");
                return;
            }
            $user_config_manager->set_name($name);
            $telegram->send_message("Your name has been set to ".$name.".");
        }, "Settings", "Set your name");

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

        if ($is_admin) {
            // #######################
            // ### Commands: Admin ###
            // #######################

            // The command /addusermh adds a user to the list of authorized users
            $command_manager->add_command(array("/adduser"), function($command, $username) use ($telegram, $global_config_manager) {
                if ($username == "" || $username[0] != "@") {
                    $telegram->send_message("Please provide a username to add.");
                    return;
                }
                $username = substr($username, 1);
                if ($global_config_manager->is_allowed_user($username, "general")) {
                    $telegram->send_message("User @".$username." is already in the list of authorized users.");
                    return;
                }
                $global_config_manager->add_allowed_user($username, "general");
                $telegram->send_message("Added user @".$username." to the list of authorized users.");
            }, "Admin", "Add a user to access the bot");

            // The command /removeuser removes a user from the list of authorized users
            $command_manager->add_command(array("/removeuser"), function($command, $username) use ($telegram, $global_config_manager) {
                if ($username == "") {
                    $telegram->send_message("Please provide a username to remove.");
                    return;
                }
                if ($username[0] == "@") {
                    $username = substr($username, 1);
                }
                if (!$global_config_manager->is_allowed_user($username, "general")) {
                    $telegram->send_message("User @".$username." is not in the list of authorized users.");
                    return;
                }
                try {
                    $global_config_manager->remove_allowed_user($username, "general");
                } catch (Exception $e) {
                    $telegram->send_message("Error: ".json_encode($e));
                    return;
                }
                $telegram->send_message("Removed user @".$username." from the list of authorized users.");
            }, "Admin", "Remove a user from access to the bot");

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
            Log::image($prompt, $image_url, $telegram->get_chat_id());
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
}

?>