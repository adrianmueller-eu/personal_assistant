<?php

/**
 * This is the main function for the general bot.
 * 
 * @param object $update The update object
 * @param UserConfigManager $user_config_manager The user config manager
 * @param Telegram $telegram The Telegram manager for the user
 * @param LLMConnector $llm The LMMConnector object
 * @param Telegram $telegram_admin The Telegram manager for the admin
 * @param GlobalConfigManager $global_config_manager The global config manager
 * @param bool $is_admin Whether the user is an admin
 * @param bool $DEBUG Whether to enable debug mode
 * @return void
 */
function run_bot($update, $user_config_manager, $telegram, $llm, $telegram_admin, $global_config_manager, $is_admin, $DEBUG) {
    if (isset($update->text)) {
        $message = $update->text;
    }
    else if (isset($update->photo)) {
        $chat = $user_config_manager->get_config();
        // Check if the model can see
        if (substr($chat->model, 0, 5) == "gpt-3") {
            $telegram->send_message("Error: You can't send images if you are talking to $chat->model. Try another model.");
            exit;
        }
        $file_id = end($update->photo)->file_id;
        $caption = isset($update->caption) ? $update->caption : "";
        $file_url = $telegram->get_file_url($file_id);
        if ($file_url == null) {
            $telegram->send_message("Error: Could not get the file URL from Telegram.");
            exit;
        }

        $message = array(
            array("type" => "image_url", "image_url" => array("url" => $file_url)),
            array("type" => "text", "text" => $caption),
        );
    } else if (isset($update->voice) || isset($update->audio)) {
        // Get the file content from file_id with $telegram->get_file
        if (isset($update->voice)) {
            $file_id = $update->voice->file_id;
        } else {
            $file_id = $update->audio->file_id;
        }
        $file = $telegram->get_file($file_id);
        if ($file == null) {
            $telegram->send_message("Error: Could not get the file from Telegram.");
            exit;
        }

        // Transcribe
        $message = $llm->asr($file);

        if (substr($message, 0, 7) == "Error: ") {
            $telegram->send_message($message, false);
            exit;
        }
        // Send the transcription to the user
        if (isset($update->voice) && $update->voice->duration > 0) {
            if (isset($update->forward_from) || isset($update->forward_sender_name) || isset($update->forward_date)) {
                $telegram->send_message("/re $message");
                exit; // Don't automatically request a response
            } else {
                $telegram->send_message("/user $message");
            }
        } else {
            // If audio, just send the transcription
            $telegram->send_message($message);
            exit;
        }
    }
    else {
        $telegram->send_message("Sorry, I don't know yet what do to this message! :/");
        if ($DEBUG) {
            $telegram->send_message("Unknown message: ".json_encode($update, JSON_PRETTY_PRINT), false);
        }
        exit;
    }

    if ($DEBUG) {
        // $telegram->send_message("You said: ".json_encode($message, JSON_PRETTY_PRINT));
        echo "You said: ".json_encode($message, JSON_PRETTY_PRINT);
    }

    // If it is forwarded, put "/re " in front
    if (is_string($message) && (isset($update->forward_from) || isset($update->forward_sender_name) || isset($update->forward_date))) {
        // Find the sender's name
        if (isset($update->forward_from) && $update->forward_from->first_name != "")
            $sender = $update->forward_from->first_name;
        else if (isset($update->forward_sender_name) && $update->forward_sender_name != "")
            $sender = $update->forward_sender_name;
        else
            $sender = "";

        if ($sender != "")
            $message = "/re $sender:\n$message\n\nResponse:\n";
        else
            $message = "/re $message";
    }

    // #######################
    // ### Command parsing ###
    // #######################

    if (is_string($message)) {
        // If starts with "." or "\", it might be a typo for a command
        if (substr($message, 0, 1) == "." || (substr($message, 0, 1) == "\\")) {
            // Remove everything after the first space-like character
            $potential_command = preg_replace("/\s.*/", "", $message);
            // If it only contains word-characters, it might be a command
            $potential_command = substr($potential_command, 1);
            if (!preg_match("/[^A-Za-z0-9]/", $potential_command)) {
                $telegram->send_message("Did you mean the command /$potential_command ? If not, escape the first character with '\\'.\nNothing was added to the chat history.", false);
                exit;
            }
        }
    }

    // If $message starts with /, it's a command
    if (is_string($message) && substr($message, 0, 1) == "/") {
        if ($is_admin) {
            $categories = array("Presets", "Shortcuts", "Settings", "Chat history management", "Admin", "Misc");
        } else {
            $categories = array("Presets", "Shortcuts", "Settings", "Chat history management", "Misc");
        }
        $command_manager = new CommandManager($categories);

        // #########################
        // ### Commands: Presets ###
        // #########################
        $get_character_description = function($new_character, $previous_descriptions="", $show_message=true) use ($telegram, $llm) {
            if ($show_message) {
                $telegram->send_message("Trying to find $new_character...");
            }
            # Request a character description
            $prompt = "Create a character description based on the following information given by the user: $new_character\n\nIf they mentioned multiple "
                ."characters, please generate a description for each of them, otherwise only one.\n\n"
                ."Develop a brief but vivid portrayal of each of the characters, focusing on their professions, key ideas, and worldviews. "
                ."Describe their typical way of thinking and speaking, including any unique expressions or mannerisms they might use. "
                ."Highlight their areas of expertise and any strongly held opinions or beliefs that would come across in conversation. "
                ."Consider their background and experiences that shape their perspective. The goal is to capture the essence of how "
                ."these characters would authentically express themselves in a dialogue, rather than just listing traits. "
                ."Aim for a concise description that will allow for a realistic and engaging conversation with these characters."
                ."If the request is generic or abstract, keep the descriptions general and adaptable to various contexts without inventing names or other specific details."
                ."Don't write anything else before or after the character descriptions, only output the character descriptions.";
            if ($previous_descriptions != "") {
                # ask it to append the new character description to the previous ones
                $prompt .= "For your reference, these characters are already in the room:\n\n$previous_descriptions\n\nPlease only output the "
                ."new character description in a concise format, without repeating the previous descriptions.";
            }

            $chat = (object) UserConfigManager::$default_config;
            $chat->messages[] = (object) array("role" => "user", "content" => $prompt);
            $description = $llm->message($chat);
            if (substr($description, 0, 7) == "Error: ") {
                $telegram->send_message($description, false);
                exit;
            }
            if ($previous_descriptions != "") {
                # append the new character description to the previous ones
                $description = $previous_descriptions."\n\n".$description;
            }
            return $description;
        };

        // The command is /start or /reset resets the bot and sends a welcome message
        $reset = function($command, $new_character, $show_message = true) use ($get_character_description, $telegram, $user_config_manager, $llm) {
            # first create potential new character descriptions
            if ($new_character) {
                $description = $get_character_description($new_character, "", $show_message);
            }
            # save config intro backup file
            $user_config_manager->save_backup();
            # If the user config contains an intro message, add it as system message
            $user_config_manager->clear_messages();
            if ($new_character) {
                $config = $user_config_manager->get_config();
                $config->messages[] = (object) array("role" => "system", "content" => "You are now in a conversation room with one or more characters described below. Your role is to embody these "
                    ."character(s) and engage in dialogue with the user. Respond authentically, representing each character's unique personality, "
                    ."knowledge, and manner of speaking. Draw upon the details provided to inform responses, opinions, and overall demeanor. "
                    ."Stay true to each character's background, expertise, and worldview throughout the interaction.");
                $config->messages[] = (object) array("role" => "system", "content" => "Character description(s):\n$description");
                $config->messages[] = (object) array("role" => "system", "content" => "Conversation format:\n"
                    ."- If there's only one character, respond directly as that character.\n"
                    ."- If there are multiple characters, give every character an opportunity to respond.\n\n"
                    ."For each user message, respond as the character(s) would, incorporating their specific knowledge, opinions, and speech patterns. "
                    ."If asked about topics outside a character's expertise or experience, have them respond realistically, which may include admitting "
                    ."uncertainty or redirecting the conversation to their areas of interest.\n\n"
                    ."In multi-character scenarios, characters may interact with or respond to each other's comments if it's natural for them to do so. "
                    ."The user can address questions or comments to specific characters or to the group as a whole. Not every character has to respond every time. "
                    ."It might often be enough to just hear from one or two characters with the most relevant perspectives. Remember to maintain each character's "
                    ."unique voice and perspective throughout the entire conversation, whether it's a one-on-one dialogue or a group discussion.");
                $user_config_manager->save_config($config);
                # Tell the user
                if ($show_message) {
                    # Ask the AI to write a short information for the user who they are talking with
                    $config2 = (object) UserConfigManager::$default_config;
                    $config2->messages = $config->messages;
                    $config2->messages[] = (object) array("role" => "user", "content" => "Who joined the scene? Please respond with one sentence in the format \"You are now in a conversation room with ...\" with no other text before or after.");
                    $joined = $llm->message($config2);
                    if (substr($joined, 0, 7) == "Error: ") {
                        $telegram->send_message("You are now in a conversation room with $new_character :)");
                    } else {
                        $telegram->send_message($joined);
                    }
                }
            } else {
                $intro = $user_config_manager->get_intro();
                if ($intro != "") {
                    $user_config_manager->add_message("system", $intro);
                } else {
                    $user_config_manager->add_message("system", "Your task is to help and support your friend in their life. "  # ".$user_config_manager->get_name()."  
                    ."Your voice is generally casual, kind, compassionate, and heartful. "
                    ."Keep your responses concise and compact. "
                    ."Don't draw conclusions before you've finished your reasoning and think carefully about the correctness of your answers. "
                    ."If you are missing information that would allow you to give a much more helpful answer, "
                    ."please don't provide an actual answer, but instead ask for what you'd need to know first. "
                    ."If you are unsure about something, state your uncertainty and ask for clarification. "
                    ."Feel free to give recommendations (actions, books, papers, etc.) that seem useful and appropriate. "
                    ."If you recommend resources, please carefully ensure they actually exist. "
                    ."Avoid showing warnings or information regarding your capabilities. "
                    ."You can use Markdown and emojis to format and enrich your messages. "
                    ."Spread love! ❤️✨");
                }
                if ($show_message) {
                    $hello = $user_config_manager->hello();
                    $telegram->send_message($hello);
                }
            }
        };

        $command_manager->add_command(array("/start", "/reset", "/r"), function($command, $description) use ($reset) {
            $reset($command, $description, true);
            exit;
        }, "Presets", "Start a new conversation. You may provide a description who you want to talk with.");

        // The command /invite invites another character to the conversation
        $command_manager->add_command(array("/invite"), function($command, $new_character) use ($telegram, $user_config_manager, $llm, $reset, $get_character_description) {
            if ($new_character == "") {
                $telegram->send_message("Please provide a description of the character you want to invite into the conversation.");
                exit;
            }
            $chat = $user_config_manager->get_config();
            if (count($chat->messages) == 0) {
                $reset($command, $new_character, true);
            } else if (count($chat->messages) == 1 || (count($chat->messages) > 1 && substr($chat->messages[1]->content, 0, 25) != "Character description(s):")) {
                # prepend a generic and kind personal assistant to $new_character request
                $new_character = "generic compassionate personal assistant (PA) and $new_character";
                $reset($command, $new_character, true);
            } else if (substr($chat->messages[1]->content, 0, 25) == "Character description(s):") {
                $current_description = substr($chat->messages[1]->content, 25);
                $description = $get_character_description($new_character, $current_description);
                $chat->messages[1]->content = "Character description(s):\n$description";
                # ask AI to write a short information for the user who joined the conversation
                $chat2 = (object) UserConfigManager::$default_config;
                $chat2->messages[] = (object) array("role" => "user", "content" => "Previous character description(s):\n\n$current_description\n\nNew character description(s):\n\n$description\n\n"
                    ."Please analyze the descriptions above and inform us: who joined the scene? Respond with one short sentence with no other text before or after.");
                $joined = $llm->message($chat2);
                if (substr($joined, 0, 7) == "Error: ") {
                    $joined = "$new_character joined the conversation.";
                }
                $telegram->send_message($joined);
                # append $joined to the last message of the chat to inform the AI
                $last_message = $chat->messages[count($chat->messages) - 1];
                $last_message->content .= "\n\n$joined";
                $chat->messages[count($chat->messages) - 1] = $last_message;
                $user_config_manager->save_config($chat);
            }
            exit;
        }, "Presets", "Invite another character to the conversation. Provide a description of the character with the command.");

        // The command /leave removes the a character from the conversation
        $command_manager->add_command(array("/leave"), function($command, $character) use ($telegram, $llm, $user_config_manager, $reset) {
            if ($character == "") {
                $telegram->send_message("Please provide the name of the character you want to remove from the conversation.");
                exit;
            }
            $chat = $user_config_manager->get_config();
            if (count($chat->messages) == 0) {
                $telegram->send_message("You are not in a conversation.");
            } else if (count($chat->messages) == 1 || (count($chat->messages) > 1 && substr($chat->messages[1]->content, 0, 25) != "Character description(s):")) {
                $telegram->send_message("It looks like you are only chatting with the default personal assistant :) You can set up a new room with /reset or invite other characters with /invite.");
            } else if (substr($chat->messages[1]->content, 0, 25) == "Character description(s):") {
                $telegram->send_message("Asking $character to leave...");
                $current_description = substr($chat->messages[1]->content, 25);
                # request llm to remove the character description
                $config = (object) UserConfigManager::$default_config;
                $config->messages[] = (object) array("role" => "user", "content" => "Remove the character description of \"$character\" from the following descriptions. "
                ."Don't write anything else before or after, only output the remaining descriptions. If the requested character is not among the descriptions, just repeat "
                ."the descriptions as they are with no description removed. If there is no description left after removal, just output \"---\".\n\n$current_description");
                $new_description = $llm->message($config);
                if (substr($new_description, 0, 7) == "Error: ") {
                    $telegram->send_message($new_description, false);
                    exit;
                }
                if (abs(strlen($new_description) - strlen($current_description)) < 10) {
                    $telegram->send_message("It seems $character is not in the conversation.");
                    exit;
                }
                $telegram->send_message("$character left the conversation.");
                # append "$character left the conversation." to the last message of the chat to inform the AI
                $last_message = $chat->messages[count($chat->messages) - 1];
                $last_message->content .= "\n\n$character left the conversation.";
                $chat->messages[count($chat->messages) - 1] = $last_message;
                # save the new character descriptions
                $chat->messages[1]->content = "Character description(s):\n$new_description";
                $user_config_manager->save_config($chat);
                if ($new_description == "---") {
                    $reset($command, "", true);
                }
            }
            exit;
        }, "Presets", "Remove a character from the conversation. Provide the name of the character with the command.");

        // The command /characters shows the current character descriptions
        $command_manager->add_command(array("/room"), function($command, $_) use ($telegram, $user_config_manager) {
            $chat = $user_config_manager->get_config();
            if (count($chat->messages) == 0) {
                $telegram->send_message("You are not in a conversation.");
            } else if (count($chat->messages) == 1 || (count($chat->messages) > 1 && substr($chat->messages[1]->content, 0, 25) != "Character description(s):")) {
                $telegram->send_message("It looks like you are chatting with the default personal assistant :) You can invite other characters with the /invite command.");
            } else if (substr($chat->messages[1]->content, 0, 25) == "Character description(s):") {
                $telegram->send_message($chat->messages[1]->content);
            }
            exit;
        }, "Presets", "Show the current character descriptions.");

        // The command /responder writes a response to a given message
        $command_manager->add_command(array("/responder", "/re"), function($command, $message) use ($telegram, $user_config_manager, $llm) {
            $chat = UserConfigManager::$default_config;
            $chat["temperature"] = 0.7;
            $chat["messages"] = array(array("role" => "system", "content" => "Your task is to generate responses to messages sent to me, "
                    ."carefully considering the context and my abilities as a human. Use a casual, calm, and kind voice. Keep your responses "
                    ."concise and focus on understanding the message before responding."));
            // If the message is not empty, process the request one-time without saving the config
            if ($message != "") {
                $chat["messages"][] = array("role" => "user", "content" => $message);
                $response = $llm->message($chat);
                $has_error = substr($response, 0, 7) == "Error: ";
                $telegram->send_message($response, !$has_error);
            } else {
                $user_config_manager->save_backup();
                $user_config_manager->save_config($chat);
                $telegram->send_message("Chat history reset. I am now a message responder.");
            }
            exit;
        }, "Presets", "Suggests responses to messages from others. Give a message with the command to preserve the previous conversation.");

        // The command /translator translates a given text
        $command_manager->add_command(array("/translator", "/trans"), function($command, $text) use ($telegram, $user_config_manager, $llm) {
            $chat = UserConfigManager::$default_config;
            $chat["temperature"] = 0.7;
            $chat["messages"] = array(array("role" => "system", "content" => "Translate the messages sent to you into English, ensuring "
                ."accuracy in grammar, verb tenses, and context. Identify the language or encoding of the text you translate from."));
            // If the text is not empty, process the request one-time without saving the config
            if ($text != "") {
                $chat["messages"][] = array("role" => "user", "content" => $text);
                $response = $llm->message($chat);
                $has_error = substr($response, 0, 7) == "Error: ";
                $telegram->send_message($response, !$has_error);
            } else {
                $user_config_manager->save_backup();
                $user_config_manager->save_config($chat);
                $telegram->send_message("Chat history reset. I am now a translator.");
            }
            exit;
        }, "Presets", "Translate messages into English. Give the text with the command to preserve the previous conversation.");

        // The command /event converts event descriptions to an iCalendar file
        $command_manager->add_command(array("/event"), function($command, $description) use ($telegram, $user_config_manager, $llm) {
            $timezone = date("e");
            $chat = UserConfigManager::$default_config;
            $chat["temperature"] = 0;
            $chat["messages"] = array(array("role" => "system", "content" => "Extract details about events from the provided text and output an "
                ."event in iCalendar format. Try to infer the time zone from the location. Use can use the example for the timezone below as "
                ."a template. Ensure that the code is valid. Output the code only. Today is ".date("l, j.n.Y").".\n\n"
."BEGIN:VTIMEZONE
TZID:$timezone
END:VTIMEZONE"));
            // If the description is not empty, process the request one-time without saving the config
            if ($description != "") {
                $chat["messages"][] = array("role" => "user", "content" => $description);
                $response = $llm->message($chat);

                // If the response starts with "Error: ", it is an error message
                if (substr($response, 0, 7) == "Error: ") {
                    $telegram->send_message($response, false);
                // If the response starts with "BEGIN:VCALENDAR", it is an iCalendar event
                } else if (substr($response, 0, 15) == "BEGIN:VCALENDAR") {
                    $file_name = "event.ics";
                    $telegram->send_document($file_name, $response);
                } else {
                    $telegram->send_message($response);
                }
            } else {
                $user_config_manager->save_backup();
                $user_config_manager->save_config($chat);
                $telegram->send_message("Chat history reset. I am now a calendar bot. Give me an invitation or event description!");
            }
            exit;
        }, "Presets", "Converts an event description to iCalendar format. Provide a description with the command to preserve the previous conversation.");

        // The command /code is a programming assistant
        $command_manager->add_command(array("/program"), function($command, $query) use ($telegram, $user_config_manager, $llm) {
            $chat = UserConfigManager::$default_config;
            $chat["temperature"] = 0.5;
            $chat["messages"] = array(array("role" => "system", "content" => "You are a programming and system administration assistant. "
                ."If there is a lack of details, state your uncertainty and ask for clarification. Do not show any warnings or information "
                ."regarding your capabilities. Keep your response short and avoid unnecessary explanations. If you provide code, ensure it is valid."));
            // If the query is not empty, process the request one-time without saving the config
            if ($query != "") {
                $chat["messages"][] = array("role" => "user", "content" => $query);
                $response = $llm->message($chat);
                $has_error = substr($response, 0, 7) == "Error: ";
                $telegram->send_message($response, !$has_error);
            } else {
                $user_config_manager->save_backup();
                $user_config_manager->save_config($chat);
                $telegram->send_message("Chat history reset. I will support you in writing code.");
            }
            exit;
        }, "Presets", "Programming assistant. Add a query to ignore and preserve the previous conversation.");

        // The command /typo is a typo and grammar assistant
        $command_manager->add_command(array("/typo"), function($command, $text) use ($telegram, $user_config_manager, $llm) {
            $prompt = "Please review the following scientific text and provide specific feedback on areas that could be improved. "
                ."Correct any typos, grammatical errors, or whatever else you notice. Do NOT repeat or write a corrected verison of the entire text. "
                ."Keep your answer concise and ensure the correctness of each suggestion.";
            $chat = UserConfigManager::$default_config;
            $chat["messages"] = array(array("role" => "system", "content" => $prompt));
            // If the text is not empty, process the request one-time without saving the config
            if ($text != "") {
                $chat["messages"][] = array("role" => "user", "content" => $text);
                $response = $llm->message($chat);
                $has_error = substr($response, 0, 7) == "Error: ";
                $telegram->send_message($response, !$has_error);
            } else {
                $user_config_manager->save_backup();
                $user_config_manager->save_config($chat);
                $telegram->send_message("Chat history reset. I am now a typo and grammar assistant.");
            }
            exit;
        }, "Presets", "Typo and grammar assistant. Provide a text to ignore and preserve the previous conversation.");

        // The command /task helps the user to break down a task and track progress
        $command_manager->add_command(array("/task"), function($command, $task) use ($telegram, $user_config_manager, $reset) {
            $reset($command, $task, false);  // general prompt
            if ($task == "") {
                $telegram->send_message("Please provide a task with the command.");
                exit;
            }
            $prompt = "Your task is to help the user achieve the following goal: \"$task\". "
                ."Break down it into subtasks, negotiate a schedule, and provide live accountabilty at each step. "
                ."Avoid generic advice, but instead find specific, actionable steps. "
                ."As soon as the steps are clear, walk the user through them one by one to ensure they are completed. ";
            $user_config_manager->add_message("system", $prompt);
        }, "Presets", "Helps the user to break down a task and track immediate progress (preserves the chat history)");

        // The command /anki adds a command to create an Anki flashcard from the previous text
        $command_manager->add_command(array("/anki"), function($command, $topic) use ($user_config_manager) {
            // Prompt the model to write an Anki flashcard
            $prompt = "Your task is to write an Anki flashcard. Provide a concise summary with highlights of key words or phrases. "
                      ."Use HTML, but write it in one line (since Anki automatically converts newlines into <br> tags) and avoid <div> tags."
                      ."Use <b> tags for bold text and <i> tags for italic text. Use lists if appropriate.";
            $user_config_manager->add_message("system", $prompt);
            // Create a prompt on behalf of the user
            if ($topic == "") {
                $user_message = "Please create an Anki card of this.";
            } else {
                $user_message = "Please create an Anki card about $topic.";
            }
            $user_config_manager->add_message("user", $user_message);
            // don't exit, to request a response from the model below
        }, "Shortcuts", "Request an Anki flashcard based on the previous conversation. You can provide a message with the command to clarify the topic.");

        // The command /todo is a shortcut to extract actionable items out of the previous conversation
        $command_manager->add_command(array("/todo"), function($command, $info) use ($user_config_manager) {
            $source_str = "";
            $n_messages = count($user_config_manager->get_config()->messages);
            if ($n_messages > 1 && $info != "") {
                $source_str .= "the previous conversation and further information by the user given below";
            }
            elseif ($n_messages > 1) {
                $source_str .= "the previous conversation";
            }
            elseif ($info != "") {
                $source_str .= "the information by the user given below";
            }
            // Prompt the model to write a todo list
            $prompt = "Please create a consolidated list of specific, actionable items based on $source_str. "
                    ."Keep the points concise, but specific and informative. "
                    ."If there is important information missing, don't provide an actual answer, but instead ask for what you'd need to know first.";
            if ($info != "") {
                $prompt .= " Here is further information given by the user:\n\n$info";
            }
            $user_config_manager->add_message("system", $prompt);
            // don't exit, to request a response from the model below
        }, "Shortcuts", "Extract a list of actionable items from the previous conversation. You can provide a message with the command for more information.");

        // The command /mail builds a mail based on the previous conversation
        $command_manager->add_command(array("/mail"), function($command, $topic) use ($user_config_manager) {
            // Prompt the model to write a mail
            $prompt = "You task is to prepare a mail based on the previous conversation using the template below. "
                    ."Keep your response concise and compact. Your voice is casual and kind. "
                    ."In case the user requests a relative date, today is ".date("l, j.n").". "
                    ."Please don't write any other text than the mail itself, so it can be parsed easily. "
                    ."Use \".".$user_config_manager->get_name()." as sender name.\"\n\n"
                    ."MAIL\n"
                    ."To: [recipient]\n"
                    ."Subject: [subject]\n\n"
                    ."Body:\n[body]";
            if ($topic != "")
                $user_config_manager->add_message("user", $topic);
            $user_config_manager->add_message("system", $prompt);
        }, "Shortcuts", "Create a mail based on the previous conversation. You can provide a message with the command to clarify the request.");

        // The command /math prompts the bot to format Latex equations nicely
        $command_manager->add_command(array("/math"), function($command, $_) use ($telegram, $user_config_manager) {
            $active = $user_config_manager->toggle_math_mode();
            $telegram->send_message("Math mode ".($active ? "activated" : "deactivated").".");
            exit;
        }, "Shortcuts", "Add a system message for better formatting of LaTeX equations");

        // TODO !!! Add more presets here !!!



        // ##########################
        // ### Commands: Settings ###
        // ##########################

        // Shotcuts for models
        $shortcuts = array(
            "/gpt4o" => "gpt-4o-2024-08-06",
            "/gpt4omini" => "gpt-4o-mini",
            "/gpt4turbo" => "gpt-4-turbo",
            "/gpt35turbo" => "gpt-3.5-turbo-0125",
            "/claude35sonnet" => "claude-3-5-sonnet-20240620",
            "/claude3opus" => "claude-3-opus-20240229",
            "/claude3sonnet" => "claude-3-sonnet-20240229",
            "/claude3haiku" => "claude-3-haiku-20240307"
        );

        // The command /model shows the current model and allows to change it
        $command_manager->add_command(array_merge(array("/model"), array_keys($shortcuts)), function($command, $model) use ($telegram, $user_config_manager, $shortcuts) {
            $chat = $user_config_manager->get_config();
            if (isset($shortcuts[$command])) {
                $model = $shortcuts[$command];
            }
            if ($model == "") {
                $telegram->send_message("You are currently talking to `$chat->model`.\n\n"
                ."You can change the model by providing the model name after the /model command. "
                ."The following shortcuts are available:\n\n"
                .implode("\n", array_map(function($key, $value) {
                    return "$key -> `$value`";
                }, array_keys($shortcuts), $shortcuts))."\n\n"
                ."Other options are other [OpenAI models](https://platform.openai.com/docs/models) ([pricing](https://openai.com/pricing)) and "
                ."[Anthropic models](https://docs.anthropic.com/en/docs/about-claude/models).");
            } else if ($chat->model == $model) {
                $telegram->send_message("You are already talking to `$chat->model`.");
            } else {
                $chat->model = $model;
                $user_config_manager->save_config($chat);
                $telegram->send_message("You are now talking to `$chat->model`.");
            }
            exit;
        }, "Settings", "Model selection (default: `".UserConfigManager::$default_config["model"]."`)");

        // The command /temperature shows the current temperature and allows to change it
        $command_manager->add_command(array("/temperature", "/temp"), function($command, $temperature) use ($telegram, $user_config_manager) {
            $chat = $user_config_manager->get_config();
            if (is_numeric($temperature)) {
                $temperature = floatval($temperature);
                if ($temperature >= 0 && $temperature <= 2) {
                    $chat->temperature = $temperature;
                    $user_config_manager->save_config($chat);
                    $telegram->send_message("Temperature set to $chat->temperature.");
                } else {
                    $telegram->send_message("Temperature must be between 0 and 2.");
                }
            } else {
                $telegram->send_message("Temperature is currently set to $chat->temperature. To set the temperature, you can provide a number between 0 and 2 with the command.");
            }
            exit;
        }, "Settings", "Show the current temperature or change it (default: ".UserConfigManager::$default_config["temperature"].")");

        // The command /name allows the user to change their name
        $command_manager->add_command(array("/name"), function($command, $name) use ($telegram, $user_config_manager) {
            if ($name == "") {
                $telegram->send_message("Your name is currently set to ".$user_config_manager->get_name().". To change it, provide a name with the command.");
                exit;
            }
            $user_config_manager->set_name($name);
            $telegram->send_message("Your name has been set to $name.");
            exit;
        }, "Settings", "Set your name");

        // The command /timezone allows the user to change their timezone
        $command_manager->add_command(array("/timezone"), function($command, $timezone) use ($telegram, $user_config_manager) {
            if ($timezone == "") {
                $telegram->send_message("Your timezone is currently set to \"".$user_config_manager->get_timezone()."\". To change it, please provide a timezone with the command, e.g. \"/timezone Europe/Berlin\".");
                exit;
            }
            // Validate the timezone
            try {
                new DateTimeZone($timezone);
            } catch (Exception $e) {
                $telegram->send_message("The timezone \"$timezone\" is not valid. Please provide a valid timezone, e.g. \"/timezone Europe/Berlin\".");
                exit;
            }
            $user_config_manager->set_timezone($timezone);
            $telegram->send_message("Your timezone has been set to \"$timezone\".");
            exit;
        }, "Settings", "Set your timezone");

        // The command /lang allows the user to change their language
        $command_manager->add_command(array("/lang"), function($command, $lang) use ($telegram, $user_config_manager) {
            if ($lang == "") {
                $telegram->send_message("Your language is currently set to \"".$user_config_manager->get_lang()."\". "
                ."To change it, provide an [ISO 639-1 language code](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) (2 letters) with the command, e.g. \"/lang en\"."
                ."This property is only used for voice transcription.");
                exit;
            }
            // Ensure $lang is ISO 639-1
            if (strlen($lang) != 2) {
                $telegram->send_message("The language code must be [ISO 639-1](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) (2 letters).");
                exit;
            }
            $user_config_manager->set_lang($lang);
            $telegram->send_message("Your language has been set to \"$lang\".");
            exit;
        }, "Settings", "Set your language");

        // The command /intro allows to read out or set the intro prompt
        $command_manager->add_command(array("/intro"), function($command, $message) use ($telegram, $user_config_manager) {
            if ($message == "") {
                $intro = $user_config_manager->get_intro();
                if ($intro == "") {
                    $telegram->send_message("You are using the default introductory system prompt. To set a custom intro prompt, please provide it with the command.");
                } else {
                    $telegram->send_message("Your current intro prompt is:\n\n\"$intro\"\n\nYou can change it by providing a new version after the /intro command. Use \"/intro reset\" to use the default prompt.");
                }
                exit;
            }
            if ($message == "reset") {
                $user_config_manager->set_intro("");
                $telegram->send_message("Your intro prompt has been reset. You can set a new intro prompt by providing it after the /intro command.");
                exit;
            }
            $user_config_manager->set_intro($message);
            $telegram->send_message("Your intro prompt has been set to:\n\n$message");
            exit;
        }, "Settings", "Set a custom initial system prompt");

        // The command /hellos allows to read out or set the hello messages
        $command_manager->add_command(array("/hellos"), function($command, $message) use ($telegram, $user_config_manager) {
            if ($message == "") {
                $hellos = $user_config_manager->get_hellos();
                if (count($hellos) == 0) {
                    $telegram->send_message("You have not set any hello messages yet. To set your hello messages, you can provide a list of messages with the command, from which one is drawn at random at every reset. Use \"/hellos reset\" to have no hello messages.");
                } else {
                    $message = "/hellos\n";
                    foreach ($hellos as $hello) {
                        $message .= $hello."\n";
                    }
                    $telegram->send_message($message);
                    $telegram->send_message("You can change your hello messages by providing a list of messages after the /hellos command. Use \"/hellos reset\" to have no hello messages.");
                }
            }
            // if message is "reset", reset the hellos
            else if ($message == "reset") {
                $user_config_manager->set_hellos(array());
                $telegram->send_message("Your hello messages have been reset. You can set new hello messages by providing a list of messages after the /hellos command.");
            }
            // split $message by newlines and set the hellos
            else {
                $hellos = explode("\n", $message);
                // Filter out empty strings
                $hellos = array_filter($hellos, function($hello) {
                    return $hello != "";
                });
                $user_config_manager->set_hellos($hellos);
                $telegram->send_message("Your hello messages have been set to:\n\n$message");
            }
            exit;
        }, "Settings", "Set your hello messages");

        // The command /openaiapikey allows the user to set their custom OpenAI API key
        $command_manager->add_command(array("/openaiapikey"), function($command, $key) use ($telegram, $user_config_manager) {
            if ($key == "") {
                $telegram->send_message("Provide an API key with the command, e.g. \"/openaiapikey abc123\".");
                exit;
            }
            $user_config_manager->set_openai_api_key($key);
            $telegram->send_message("Your new OpenAI API key has been set.");
            exit;
        }, "Settings", "Set your OpenAI own API key");

        // ###############################
        // ### Chat history management ###
        // ###############################

        // The command /clear clears the chat history
        $command_manager->add_command(array("/delall", "/clear"), function($command, $_) use ($telegram, $user_config_manager) {
            $user_config_manager->clear_messages();
            $telegram->send_message("Chat history cleared.");
            exit;
        }, "Chat history management", "Clear the internal chat history");

        // The command /delete deletes the last n messages, or the last message if no number is provided
        $command_manager->add_command(array("/del"), function($command, $n) use ($telegram, $user_config_manager) {
            if ($n == "") {
                $n = 1;
            }
            if (is_numeric($n)) {
                $n = intval($n);
                if ($n > 0) {
                    $n_messages = count($user_config_manager->get_config()->messages);
                    $n = $user_config_manager->delete_messages($n);
                    if ($n == 0) {
                        $telegram->send_message("There are no messages to delete.");
                    } else if ($n == $n_messages) {
                        $telegram->send_message("All $n messages deleted.");
                    } else {
                        $telegram->send_message("Deleted the last $n messages.");
                    }
                } else {
                    $telegram->send_message("You can only delete a positive number of messages.");
                }
            } else {
                $telegram->send_message("Please provide a number of messages to delete.");
            }
            exit;
        }, "Chat history management", "Delete the last message from the internal chat history. You can delete multiple messages by adding a number (e.g. \"/del 3\" to delete the last 3 messages).");

        // The command /user adds a user message to the chat history
        $command_manager->add_command(array("/user", "/u"), function($command, $message) use ($telegram, $user_config_manager) {
            if ($message == "") {
                $telegram->send_message("Please provide a message to add.");
                exit;
            }
            $user_config_manager->add_message("user", $message);
            $telegram->send_message("Added user message to chat history.");
            exit;
        }, "Chat history management", "Add a message with \"user\" role to the internal chat history");

        // The command /assistant adds an assistant message to the chat history
        $command_manager->add_command(array("/assistant", "/a"), function($command, $message) use ($telegram, $user_config_manager) {
            if ($message == "") {
                $telegram->send_message("Please provide a message to add.");
                exit;
            }
            $user_config_manager->add_message("assistant", $message);
            $telegram->send_message("Added assistant message to chat history.");
            exit;
        }, "Chat history management", "Add a message with \"assistant\" role to the internal chat history");

        // The command /system adds a system message to the chat history
        $command_manager->add_command(array("/system", "/s"), function($command, $message) use ($telegram, $user_config_manager) {
            if ($message == "") {
                $telegram->send_message("Please provide a message to add.");
                exit;
            }
            $user_config_manager->add_message("system", $message);
            $telegram->send_message("Added system message to chat history.");
            exit;
        }, "Chat history management", "Add a message with \"system\" role to the internal chat history");

        // The command /restore restores the chat history from the backup file
        $command_manager->add_command(array("/restore"), function($command, $confirmation) use ($telegram, $user_config_manager) {
            // Ask for confirmation with "yes"
            if ($confirmation != "yes") {
                $telegram->send_message("Are you sure you want to restore the chat history from the backup file? This will delete the current chat history. If you are sure, please confirm with \"/restore yes\".");
                exit;
            }

            // Restore the backup
            try {
                if(!$user_config_manager->restore_backup()) {
                    $telegram->send_message("There is no backup file to restore.");
                    exit;
                }
            } catch (Exception $e) {
                $telegram->send_message("Error: ".json_encode($e), false);
                exit;
            }

            $n_messages = count($user_config_manager->get_config()->messages);
            $telegram->send_message("Chat history restored from backup ({$n_messages} messages)");
            exit;
        }, "Chat history management", "Restore the chat history from the backup file");

        if (!$is_admin) {
            // The command /usage allows the user to see their usage statistics
            $command_manager->add_command(array("/usage"), function($command, $month) use ($telegram, $user_config_manager) {
                if ($month == "") {
                    $month = date("ym");
                }
                else if (!preg_match("/^[0-9]{4}$/", $month)) {
                    $telegram->send_message("Please provide a month in the format \"YYMM\".");
                    exit;
                }
                $usage = get_usage_string($user_config_manager, $month, true);
                $telegram->send_message("Your usage statistics for ".($month == "" ? "this month" : $month).":\n\n$usage");
                exit;
            }, "Chat history management", "Show your usage statistics");
        }

        if ($is_admin) {
            // #######################
            // ### Commands: Admin ###
            // #######################

            // The command /addusermh adds a user to the list of authorized users
            $command_manager->add_command(array("/adduser"), function($command, $username) use ($telegram, $global_config_manager) {
                if ($username == "" || $username[0] != "@") {
                    $telegram->send_message("Please provide a username to add.");
                    exit;
                }
                $username = substr($username, 1);
                if ($global_config_manager->is_allowed_user($username, "general")) {
                    $telegram->send_message("User @$username is already in the list of authorized users.");
                    exit;
                }
                $global_config_manager->add_allowed_user($username, "general");
                $telegram->send_message("Added user @$username to the list of authorized users.");
                exit;
            }, "Admin", "Add a user to access the bot (by username)");

            // The command /removeuser removes a user from the list of authorized users
            $command_manager->add_command(array("/removeuser"), function($command, $username) use ($telegram, $global_config_manager) {
                if ($username == "") {
                    $telegram->send_message("Please provide a username to remove.");
                    exit;
                }
                $username = trim($username);
                if ($username[0] == "@") {
                    $username = substr($username, 1);
                }
                if (!$global_config_manager->is_allowed_user($username, "general")) {
                    $telegram->send_message("User @$username is not in the list of authorized users.");
                    exit;
                }
                try {
                    $global_config_manager->remove_allowed_user($username, "general");
                } catch (Exception $e) {
                    $telegram->send_message("Error: ".json_encode($e), false);
                    exit;
                }
                $telegram->send_message("Removed user @$username from the list of authorized users.");
                exit;
            }, "Admin", "Remove a user from access to the bot (by username)");

            // The command /listusers lists all users authorized, by category
            $command_manager->add_command(array("/listusers"), function($command, $_) use ($telegram, $global_config_manager) {
                $categories = $global_config_manager->get_categories();
                $message = "Lists of authorized users, by category:\n";
                foreach ($categories as $category) {
                    $message .= "\n*$category*:\n";
                    $users = $global_config_manager->get_allowed_users($category);
                    if (count($users) == 0) {
                        $message .= "No users authorized for this category.\n";
                    } else {
                        foreach ($users as $user) {
                            $message .= "@$user\n";
                        }
                    }
                }
                $telegram->send_message($message);
                exit;
            }, "Admin", "List all users authorized, by category");

            // The command /jobs lists all jobs
            $command_manager->add_command(array("/jobs"), function($command, $arg) use ($telegram, $global_config_manager) {
                $jobs = $global_config_manager->get_jobs();
                if ($arg == "on" || $arg == "off") {
                    // Set all jobs to active or inactive
                    foreach ($jobs as $job) {
                        $job->status = $arg == "on" ? "active" : "inactive";
                    }
                    $global_config_manager->save_jobs($jobs);
                    $telegram->send_message("All jobs successfully set \"$arg\".");
                } else if ($arg != "") {
                    // Toggle all jobs with name $arg
                    $cnt = 0;
                    foreach ($jobs as $job) {
                        if ($job->name == $arg) {
                            $job->status = $job->status == "active" ? "inactive" : "active";
                            $cnt++;
                        }
                    }
                    $global_config_manager->save_jobs($jobs);
                    if ($cnt == 0) {
                        $telegram->send_message("No jobs with name \"$arg\" found.");
                    } else {
                        $telegram->send_message($cnt." jobs successfully toggled.");
                    }
                } else {
                    // List all jobs
                    $message = "List of jobs:";
                    foreach ($jobs as $job) {
                        $message .= "\n\n".json_encode($job, JSON_PRETTY_PRINT);
                    }
                    $telegram->send_message($message, false);
                }
                exit;
            }, "Admin", "Job management. Use \"/jobs <name>\" to toggle all jobs with name <name>, \"/jobs on\" to set all jobs to active, and \"/jobs off\" to set all jobs to inactive. No argument lists all jobs.");

            // The command /usage prints the usage statistics of all users for a given month
            $command_manager->add_command(array("/usage"), function($command, $month) use ($telegram, $global_config_manager) {
                // If monthstring is not in format "ym", send an error message
                if ($month == "") {
                    $month = date("ym");
                }
                else if (!preg_match("/^[0-9]{4}$/", $month)) {
                    $telegram->send_message("Please provide a month in the format \"YYMM\".");
                    exit;
                }
                $chatids = $global_config_manager->get_chatids();
                $message = "Usage statistics for month $month:\n";
                foreach ($chatids as $chatid) {
                    // Add a line for each user: @username (chatid): prompt + completion = total tokens
                    $user = new UserConfigManager($chatid);
                    // add username
                    $username = $user->get_username();
                    $message .= '- ';
                    if ($username != "")
                        $message .= "@".$user->get_username()." ";
                    // add usage info
                    $message .= get_usage_string($user, $month, false)."\n";
                }
                $telegram->send_message($message);
                exit;
            }, "Admin", "Print the usage statistics of all users for a given month (default: current month)");
        }

        // ######################
        // ### Commands: Misc ###
        // ######################

        // The command /continue requests a response from the model
        $command_manager->add_command(array("/continue", "/c"), function($command, $_) {
            // request another response from the model below
        }, "Misc", "Request another response");

        // The command /image requests an image from the model
        $command_manager->add_command(array("/img"), function($command, $prompt) use ($telegram, $llm, $user_config_manager) {
            if ($prompt == "") {
                $telegram->send_message("Please provide a prompt with command $command.");
                exit;
            }
            // If prompt is a URL, send the URL to telegram instead of requesting an image
            if (filter_var($prompt, FILTER_VALIDATE_URL)) {
                $telegram->send_image($prompt);
                exit;
            }
            // If prompt starts with dalle2 or dalle3, use the corresponding model
            $model = "dall-e-2";  // default model
            if (substr($prompt, 0, 7) == "dalle2 ") {
                $model = "dall-e-2";
                $prompt = substr($prompt, 7);
            } else if (substr($prompt, 0, 7) == "dalle3 ") {
                $model = "dall-e-3";
                $prompt = substr($prompt, 7);
            }
            $image_url = $llm->image($prompt, $model);
            if ($image_url == "") {
                $telegram->send_message("WTF-Error: Could not generate an image. Please try again later.");
                exit;
            }
            Log::image($prompt, $image_url, $telegram->get_chat_id());
            // if image_url starts with "Error: "
            if (substr($image_url, 0, 7) == "Error: ") {
                $error_message = $image_url;
                $telegram->send_message($error_message, false);
                exit;
            }
            // Add the image to the chat history
            $user_config_manager->add_message("assistant", array(
                array("type" => "image_url", "image_url" => array("url" => $image_url)),
                array("type" => "text", "text" => $prompt),
            ));
            // Show the image to the user
            $telegram->send_image($image_url, $prompt);
            exit;
        }, "Misc", "Request an image. If the prompt starts with `dalle2` or `dalle3`, use the corresponding model (default: `dalle2`). If the prompt is a URL, show that picture instead of generating a one.");

        // The command /tts requests a text-to-speech conversion from the model
        $command_manager->add_command(array("/tts"), function($command, $prompt) use ($telegram, $llm, $user_config_manager, $DEBUG) {
            if ($prompt == "") {
                // If the last message is not a system message, use it as prompt
                $messages = $user_config_manager->get_config()->messages;
                if (count($messages) > 0 && $messages[count($messages)-1]->role != "system") {
                    $prompt = $messages[count($messages)-1]->content;
                } else {
                    $telegram->send_message("Please provide a prompt with command $command.");
                    exit;
                }
            }
            $tts_config = $user_config_manager->get_tts_config();
            $audio_data = $llm->tts($prompt, $tts_config->model, $tts_config->voice, $tts_config->speed, response_format: "opus");  // telegram only supports opus
            if ($audio_data == "") {
                $telegram->send_message("WTF-Error: Could not generate audio. Please try again later.");
                exit;
            }
            // if audio_url starts with "Error: "
            if (substr($audio_data, 0, 7) == "Error: ") {
                $error_message = $audio_data;
                $telegram->send_message($error_message, false);
                exit;
            }
            if ($DEBUG) {
                $telegram->send_message("Generated an audio of length ".strlen($audio_data)." bytes.");
            }
            $telegram->send_voice($audio_data);
            exit;
        }, "Misc", "Request a text-to-speech conversion. If no prompt is provided, use the last message.");

        // The command /dump outputs the content of the permanent storage
        $command_manager->add_command(array("/dump"), function($command, $_) use ($telegram, $user_config_manager) {
            $file = $user_config_manager->get_file();
            $telegram->send_message(file_get_contents($file), false);
            exit;
        }, "Misc", "Dump the data saved in the permanent storage");

        // The command /dumpmessages outputs the messages in a form that could be used to recreate the chat history
        $command_manager->add_command(array("/dm", "/dmf"), function($command, $n) use ($telegram, $user_config_manager) {
            $messages = $user_config_manager->get_config()->messages;
            // Check if there are messages
            if (count($messages) == 0) {
                $telegram->send_message("There are no messages to dump.");
                exit;
            }
            // If a number is provided, only dump the last n messages
            if (is_numeric($n)) {
                $n = intval($n);
                if ($n > 0) {
                    $messages = array_slice($messages, -$n);
                }
            }
            // Send each message as a separate message
            foreach ($messages as $message) {
                if (is_string($message->content)) {
                    $content = $message->content;
                    if ($command == "/dmf")
                        $content = $telegram->format_message($message->content, math_mode: $user_config_manager->is_math_mode_active());
                    $telegram->send_message("/$message->role $content", $command == "/dmf");
                } else {
                    $image_url = $message->content[0]->image_url->url;
                    $caption = $message->content[1]->text;
                    if ($command == "/dmf")
                        $caption = $telegram->format_message($caption, math_mode: $user_config_manager->is_math_mode_active());
                    $telegram->send_message("/$message->role $caption\n$image_url", $command == "/dmf");
                }
            }
            exit;
        }, "Misc", "Dump all messages in the chat history. You can dump only the last n messages by providing a number with the command (e.g. \"/dm 3\" to dump the last 3 messages).");

        // The command /cnt outputs the number of messages in the chat history
        $command_manager->add_command(array("/cnt"), function($command, $_) use ($telegram, $user_config_manager) {
            $messages = $user_config_manager->get_config()->messages;
            $n_messages = count($messages);
            $all_messages = implode("\n", array_map(function($message) {
                return $message->content;
            }, $messages));
            $n_words = str_word_count($all_messages);
            switch ($n_messages) {
                case 0:
                    $telegram->send_message("There are no messages (0 words) in the chat history.");
                    break;
                case 1:
                    $telegram->send_message("There is 1 message ($n_words words) in the chat history.");
                    break;
                default:
                    $telegram->send_message("There are $n_messages messages ($n_words words) in the chat history.");
            }
            exit;
        }, "Misc", "Count the number of messages and words in the chat history");

        // ############################
        // Actually run the command!
        $response = $command_manager->run_command($message);
        if (is_string($response) && $response != "") {
            $telegram->send_message($response);
            exit;
        }
    } else {
        // If it is not a command, add the message to the chat history
        $user_config_manager->add_message("user", $message);
    }

    // #############################
    // ### Main interaction loop ###
    // #############################

    // $telegram->send_message("Sending message: ".$message);
    $chat = $user_config_manager->get_config();
    $response = $llm->message($chat);

    // Show error messages
    if (substr($response, 0, 7) == "Error: ") {
        $telegram->send_message($response, false);
        exit;
    }

    // If the response starts with "BEGIN:VCALENDAR", send it as an iCalendar event file
    if (substr($response, 0, 15) == "BEGIN:VCALENDAR") {
        $user_config_manager->add_message("assistant", $response);
        $file_name = "event.ics";
        $telegram->send_document($file_name, $response);
    // If the response starts with "MAIL", parse the response and build a mailto link
    } else if (substr($response, 0, 5) == "MAIL\n") {
        // Build a mailto link from the response
        $mailto = "https://adrianmueller.eu/mailto/";
        // Find the recipient
        $recipient = "";
        $recipient_regex = "/To: (.*)\n/";
        if (preg_match($recipient_regex, $response, $matches)) {
            $recipient = $matches[1];
            /// Might be in form "To: Name <email>"
            if (strpos($recipient, "<") !== false) {
                $recipient = substr($recipient, strpos($recipient, "<") + 1);
                $recipient = substr($recipient, 0, strpos($recipient, ">"));
            }
        }
        // Find the subject
        $subject = "";
        $subject_regex = "/Subject: (.*)\n/";
        if (preg_match($subject_regex, $response, $matches)) {
            $subject = $matches[1];
        }
        // Find the body
        $body = "";
        // $body_regex = "/Body:\n(.*)/"; // this doesn't match newlines; instead matches everything after "Body:\n"
        $body_regex = "/Body:\n((?:.|\n)*)/";
        if (preg_match($body_regex, $response, $matches)) {
            $body = $matches[1];
            $body = urlencode($body);
        }
        // Build the mailto link
        $mailto .= "?to=$recipient&subject=".urlencode($subject)."&body=$body";
        $user_config_manager->add_message("assistant", $response);
        // Add the mailto link to the response as a markdown link
        $response .= "\n\n[Send mail]($mailto)";
        $telegram->send_message($response);
    } else {
        $user_config_manager->add_message("assistant", $response);
        $response = $telegram->format_message($response, $user_config_manager->is_math_mode_active());
        $telegram->send_message($response);
    }
}

?>
