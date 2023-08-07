<?php

/**
 * This is the main function for the mental health bot.
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
function run_bot($update, $user_config_manager, $telegram, $openai, $telegram_admin, $username, $global_config_manager, $is_admin, $DEBUG) {
    if (!isset($update->text)) {
        $telegram->send_message("Sorry, for now I can only read text messages.");
        exit;
    }
    $message = $update->text;

    // If starts with "." or "\", it's probably a typo for a command
    if (substr($message, 0, 1) == "." || (substr($message, 0, 1) == "\\" && !(substr($message, 1, 1) == "." || substr($message, 1, 1) == "\\"))) {
        // Shorten the message if it's too long
        if (strlen($message) > 100) {
            $message = substr($message, 0, 100)."...";
        }
        $telegram->send_message("Did you mean the command /".substr($message, 1)." ? If not, escape the first character with '\\'.");
        exit;
    }

    $session_info = $user_config_manager->get_session_info("session");
    // Initialize session info if it doesn't exist
    if ($session_info == null) {
        $session_info = (object) array(
            "running" => false,
            "this_session_start" => null,
            "profile" => "",
            "last_session_start" => null,
            "cnt" => 0,
            "mode" => "none",
        );
        $user_config_manager->save_session_info("session", $session_info);
    }
    // If there is no session running, start one
    if (substr($message, 0, 1) != "/" && ($session_info->running == false)) {
        // Add message to chat history
        $user_config_manager->add_message("user", $message);
        $message = "/start";
    }

    if (substr($message, 0, 1) == "/") {
        if ($is_admin) {
            $command_manager = new CommandManager(array("Mental health", "Settings", "Admin", "Misc"));
        } else {
            $command_manager = new CommandManager(array("Mental health", "Settings", "Misc"));
        }

        // Dictionary of prompts for each mode, e.g. "IFS" => "You are also familiar with Internal Family Systems (IFS) and might use it to guide the process. "
        $mode_prompts = array(
            "IFS" => "You are also familiar with Internal Family Systems (IFS) and might use it implicitly to guide the process. ",
            "CBT" => "You are also familiar with Cognitive Behavioral Therapy (CBT) and use it to guide the process. ",
            "ACT" => "You are also familiar with Acceptance and Commitment Therapy (ACT) and use it to guide the process. ",
            "DBT" => "You are also familiar with Dialectical Behavior Therapy (DBT) and use it to guide the process. ",
            "psychodynamic" => "You are also familiar with psychodynamic therapy and use it to guide the process. ",
            "somatic" => "You are also familiar with somatic therapy and might use it to guide the process. ",
            "meditation" => "Your main method is mindfulness meditation to guide the client to a calmer place. ",
            "none" => "",
        );

        $command_manager->add_command(array("/start"), function($command, $_) use ($telegram, $user_config_manager, $openai, $mode_prompts) {
            $session_info = $user_config_manager->get_session_info("session");
            // If there is a session running, don't start a new one
            if ($session_info->running === true) {
                $telegram->send_message("You are already in a session. Please /end the session first to start a new one.");
                return;
            }
            $session_info->running = true;
            $session_info->this_session_start = time();

            // If this is the first session, send a welcome message
            if ($session_info->cnt == 0) {
                $telegram->send_message("Hello! I am a chatbot that can help you with your mental health. I am currently "
                ."in beta, so please be patient with me. You can start a session by telling what's on your mind or "
                ."using /start.\n\n*Please end every session with /end* to update what I know about you.");
            } else {
                $telegram->send_message("Starting a new session. You can use /end to end the session.");
            }
            $name_string = $user_config_manager->get_name();
            if ($name_string == "" || $name_string == null) {
                $name_string = "";
            } else {
                $name_string = "(my name is ".$name_string.") ";
            }
            $mode_prompt = $mode_prompts[$session_info->mode ?? "none"];
            $chat = (object) array(
                "model" => "gpt-4",
                "temperature" => 0.5,
                "messages" => array(
                    array("role" => "system", "content" => "You are a therapist assisting me ".$name_string."to connect to "
                    ."myself and heal. Show empathy and compassion by acknowledging and validating my feelings. Your primary "
                    ."goal is to provide a safe, nurturing, and supportive environment for me. Help me explore my "
                    ."thoughts, feelings, and experiences, while guiding me towards personal growth and emotional healing. "
                    .$mode_prompt
                    ."Keep your responses concise, but as helpful as possible. Generally, avoid giving lists of "
                    ."advice but rather ask the client for their own opinions and ideas instead. And please ask if something "
                    ."is unclear to you or some important information is missing. The current time is ".date("g:ia")."."),
                ),
            );
            // If there is a previous session, add the profile to the chat history
            if ($session_info->profile != "") {
                $time_passed = time_diff($session_info->this_session_start, $session_info->last_session_start);
                $profile = $session_info->profile;
                $chat->messages[] = array("role" => "system", "content" => "For your own reference, here is the profile you previously wrote after "
                ."the last session (".$time_passed." ago) as a basis for this session:\n\n".$profile);
            }
            // If there is already a chat history, append them to the new messages
            $prev_chat = $user_config_manager->get_config();
            if (isset($prev_chat->messages)) {
                $chat->messages = array_merge($chat->messages, $prev_chat->messages);
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
                $user_config_manager->save_session_info("session", $session_info);
            }
            // else {
            //     $telegram->send_message("Sorry, I am having trouble connecting to the server. Please try again /start.");
            // }
        }, "Mental health", "Start a new session");

        // The command /end ends the current session
        $command_manager->add_command(array("/end"), function($command, $_) use ($telegram, $openai, $user_config_manager) {
            $session_info = $user_config_manager->get_session_info("session");
            // Check if there is a session running
            if ($session_info->running == false) {
                $telegram->send_message("The session isn't started yet. Please write something or use the command /start.");
                exit;
            }
            // If there were more than 5 messages (2x system, 2 responses), request a session summary
            $chat = $user_config_manager->get_config();
            if ($_ != "skip" && count($chat->messages) > 7) {
                $telegram->send_message("Please give me a moment to reflect on our session...");
                // Create backup before saving by copying the file to the backup file
                copy($user_config_manager->get_file(), $user_config_manager->get_backup_file());

                if ($session_info->profile != "") {
                    $time_passed = time_diff($session_info->this_session_start, $session_info->last_session_start);
                    $chat->messages = array_merge($chat->messages, array(
                        array("role" => "system", "content" => "Time to reflect. Write a summary of this session that will be used later to update the "
                        ."profile above. Hence, include only information that is really necessary for upcoming sessions.")
                        // array("role" => "system", "content" => "Here is again the profile you wrote after our previous session (".$time_passed." ago):\n\n"
                        // .$session_info->profile."\n\n"."Please update it with the new information you got in this session. The goal is to have a detailed "
                        // ."description of me that is useful for whatever comes up in the next session. Hence, include only information that is really "
                        // ."necessary for upcoming sessions. To have an all-encompassing profile after many session, avoid removing relevant information "
                        // ."from previous sessions, but integrate them into a bigger picture."),
                    ));

                    // Request a summary of the session
                    $summary = $openai->gpt($chat);
                    if (substr($summary, 0, 7) == "Error: ") {
                        $telegram->send_message("Sorry, I am having trouble connecting to the server. Please try again /end.");
                        exit;
                    }
                    $telegram->send_message("Here is a summary of our session:\n\n".$summary);

                    // Add answer and profile request to chat history
                    $chat->messages = array_merge($chat->messages, array(
                        array("role" => "assistant", "content" => $summary),
                        array("role" => "system", "content" => "Thank you for the summary. Now, let's update the profile with the new information you got "
                        ."in this session. Here is again the profile you wrote after our previous session (".$time_passed." ago):\n\n"
                        .$session_info->profile."\n\nThe goal is to have a detailed description of me that is useful for whatever comes up in the next session. "
                        ."To have an elaborate, all-encompassing profile after many sessions, avoid removing relevant information from previous sessions, but "
                        ."integrate them into a detailed and informative bigger picture.")
                    ));
                } else {
                    $chat->messages = array_merge($chat->messages, array(
                        array("role" => "system", "content" => "Please write a short profile that summarizes the information you got in this session. "
                        ."Please include only information that is really necessary for upcoming sessions."),
                    ));
                }
                // Request the new profile
                $new_profile = $openai->gpt($chat);
                if (substr($new_profile, 0, 7) == "Error: ") {
                    $telegram->send_message("Sorry, I am having trouble connecting to the server. Please try again /end.");
                    exit;
                }
                // Update the session info
                $session_info->profile = $new_profile;
                $session_info->last_session_start = $session_info->this_session_start;
                $session_info->cnt++;
            }
            // Clear the chat history and end the session
            $chat->messages = array();
            $session_info->running = false;
            $session_info->this_session_start = null;

            // Save the chat history and the new profile
            $user_config_manager->save_config($chat);
            $user_config_manager->save_session_info("session", $session_info);
            $telegram->send_message("Session ended. Thank you for being with me today.");
        }, "Mental health", "End the current session");

        // The command /profile shows the profile of the user
        $command_manager->add_command(array("/profile"), function($command, $_) use ($telegram, $user_config_manager) {
            $session_info = $user_config_manager->get_session_info("session");
            if ($session_info->profile == "") {
                $message = "No profile has been generated yet. ";
                if ($session_info->running == true)
                    $message .= "Please type something or end the current session with /end.";
                else
                    $message .= "Please start a new session with /start.";
                $telegram->send_message($message);
            } else {
                $date = date("d.m.y g:ia", $session_info->last_session_start);
                $telegram->send_message("Here is your current profile (created ".$date."):\n\n".$session_info->profile);
            }
        }, "Mental health", "Show your profile");

        // The command /mode allows the user to change the mode
        $command_manager->add_command(array("/mode"), function($command, $mode) use ($telegram, $user_config_manager, $mode_prompts) {
            // Check if session is running
            $session_info = $user_config_manager->get_session_info("session");
            if ($mode == "") {
                $mode_keys = array_keys($mode_prompts);
                $telegram->send_message("The bot currently uses ".$session_info->mode.". To change it, use /mode <mode>. Valid modes are: \"".implode("\", \"", $mode_keys)."\".");
            } else if ($session_info->running == true) {
                $telegram->send_message("Please end the current session with /end before changing your mode.");
            } else {
                // Get keys from $mode_prompts and check if $mode is a valid key
                $mode_keys = array_keys($mode_prompts);
                if (!in_array($mode, $mode_keys)) {
                    $telegram->send_message("Sorry, I don't know the mode \"".$mode."\". Valid modes are: \"".implode("\", \"", $mode_keys)."\".");
                    exit;
                }
                // Set the mode
                $session_info->mode = $mode;
                $user_config_manager->save_session_info("session", $session_info);
                $telegram->send_message("Your mode has been set to ".$mode.".");
            }
        }, "Settings", "Show or set the current method");

        // The command /name allows the user to change their name
        $command_manager->add_command(array("/name"), function($command, $name) use ($telegram, $user_config_manager) {
            // Check if session is running
            $session_info = $user_config_manager->get_session_info("session");
            if ($name == "") {
                $telegram->send_message("Your name is currently set to ".$user_config_manager->get_name().". To set your name, you can provide a name with the command, e.g. \"/name Joe\".");
            } else if ($session_info->running == true) {
                $telegram->send_message("Please end the current session with /end before changing your name.");
            } else {
                $user_config_manager->set_name($name);
                $telegram->send_message("Your name has been set to ".$name.".");
            }
        }, "Settings", "Show or set your name");

        // The command /reset deletes the current configuration
        $command_manager->add_command(array("/reset"), function($command, $confirmation) use ($telegram, $user_config_manager) {
            // Check if $confirmation is "yes"
            if ($confirmation != "yes") {
                $telegram->send_message("Please confirm deleting your profile and all other data with \"/reset yes\". Afterwards, this can be undone with /restore unless a new session is started after which the backup is overwritten and the current state can't be recovered.");
                return;
            }

            $file_path = $user_config_manager->get_file();
            // Save in backup before deleting
            $user_config_manager->save_backup();
            unlink($file_path); // Delete the file to create the default config on next message

            $telegram->send_message("Your configuration has been reset to the default settings. You can start a new session with /start.");
        }, "Settings", "Reset your configuration");

        // Command /restore restores the config from the backup file
        $command_manager->add_command(array("/restore"), function($command, $confirmation) use ($telegram, $user_config_manager) {
            // Check if $confirmation is "yes"
            if ($confirmation != "yes") {
                $telegram->send_message("Please confirm with \"/restore yes\". This can't be undone.");
                return;
            }

            // Restore the backup
            try {
                if(!$user_config_manager->restore_backup()) {
                    $telegram->send_message("There is no backup to restore. Please start a new session with /start.");
                    return;
                }
            } catch (Exception $e) {
                $telegram->send_message("Error in reading backup file: ".$e->getMessage());
                return;
            }
            $telegram->send_message("Backup restored.");
        }, "Settings", "Restore the config from the backup file");

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

            // Command /cnt outputs the number of messages in the chat history
            $command_manager->add_command(array("/cnt"), function($command, $_) use ($telegram, $user_config_manager) {
                $chat = $user_config_manager->get_config();
                $telegram->send_message("There are ".count($chat->messages)." messages in the chat history.");
            }, "Admin", "Output the number of messages in the chat history");

            // The command /dumpmessages outputs the messages in a form that could be used to recreate the chat history
            $command_manager->add_command(array("/dumpmessages", "/dm"), function($command, $_) use ($telegram, $user_config_manager) {
                $messages = $user_config_manager->get_config()->messages;
                // Add the roles in the beginning of each message
                $messages = array_map(function($message) {
                    return "/".$message->role." ".$message->content;
                }, $messages);
                if (count($messages) == 0) {
                    $telegram->send_message("There are no messages to dump.");
                    return;
                }
                // Send each message as a separate message
                foreach ($messages as $message) {
                    $telegram->send_message($message);
                }
            }, "Misc", "Dump the messages in the chat history");

            // Command /showcase (with optional parameter $name) let's the admin showcase the bot
            $command_manager->add_command(array("/showcase"), function($command, $name) use ($telegram, $user_config_manager, $username) {
                $file_path = $user_config_manager->get_file();
                $sc_backup_file_path = $user_config_manager->get_backup_file()."_showcase";
                if ($name == "end") {
                    if (file_exists($sc_backup_file_path)) {
                        // Restore the backup
                        copy($sc_backup_file_path, $file_path);
                        unlink($sc_backup_file_path);
                        $telegram->send_message("Showcase ended.");
                    } else {
                        $telegram->send_message("Showcase is currently not running.");
                    }
                    return;
                } else if (!file_exists($sc_backup_file_path)){
                    // Save the current config to a backup file
                    copy($file_path, $sc_backup_file_path);
                } else {
                    $telegram->send_message("Showcase is already running. Please end it first with \"/showcase end\". Afterwards, you can start a new showcase with \"/showcase <name>\".");
                    return;
                }
                // Create a new config file
                unlink($file_path);
                $user_config_manager = new UserConfigManager($telegram->get_chat_id(), $username, $name);
                $telegram->send_message("Showcase prepared. Please send /start to start the showcase and \"/showcase end\" to end it.");
            }, "Admin", "Showcase the bot. Use \"/showcase <name>\" to specify a name, and \"/showcase end\" to end it.");
        }

        $response = $command_manager->run_command($message);
        if ($response != "") {
            $telegram->send_message($response);
        }
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
}

?>
