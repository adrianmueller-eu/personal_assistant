<?php

/**
 * This is the main function for the mental health bot.
 * 
 * @param object $update The update object
 * @param UserConfigManager $user_config_manager The user config manager
 * @param Telegram $telegram The Telegram manager for the user
 * @param OpenAI $openai The OpenAI object
 * @param Telegram $telegram_admin The Telegram manager for the admin
 * @param GlobalConfigManager $global_config_manager The global config manager
 * @param bool $is_admin Whether the user is an admin
 * @param bool $DEBUG Whether to enable debug mode
 * @return void
 */
function run_bot($update, $user_config_manager, $telegram, $openai, $telegram_admin, $global_config_manager, $is_admin, $DEBUG) {
    if (isset($update->text)) {
        $message = $update->text;
    }
    else if (isset($update->photo)) {
        $chat = $user_config_manager->get_config();
        // Check if the model can see
        if ($chat->model != "gpt-4-vision-preview") {
            $telegram->send_message("Error: You can only send images if you are talking to `gpt-4-vision-preview`. Try /gpt4v.");
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
            array("type" => "image_url", "image_url" => $file_url),
            array("type" => "text", "text" => $caption),
        );
    } else if (isset($update->voice)) {
        // 1. Get the file content from file_id with $telegram->get_file
        $file_id = $update->voice->file_id;
        $file = $telegram->get_file($file_id);
        if ($file == null) {
            $telegram->send_message("Error: Could not get the file from Telegram. Please try again.");
            exit;
        }

        // 2. Transcribe with $openai->whisper
        $message = $openai->whisper($file, language: $user_config_manager->get_lang());

        // 3. Add the transcription to the chat history
        if (substr($message, 0, 7) == "Error: ") {
            $telegram->send_message($message, false);
            exit;
        }
    } else {
        if ($user_config_manager->get_lang() == "de") {
            $telegram->send_message("Sorry, ich kann bisher noch nicht mit diesem Nachrichtentyp umgehen :/");
        } else {
            $telegram->send_message("Sorry, for now I can't handle this type of message :/");
        }
        exit;
    }

    if (is_string($message)) {
        // If starts with "." or "\", it's probably a typo for a command
        if (substr($message, 0, 1) == "." || (substr($message, 0, 1) == "\\" && !(substr($message, 1, 1) == "." || substr($message, 1, 1) == "\\"))) {
            // Shorten the message if it's too long
            if (strlen($message) > 100) {
                $message = substr($message, 0, 100)."...";
            }
            if ($user_config_manager->get_lang() == "de") {
                $telegram->send_message("Meintest du den Befehl /".substr($message, 1)." ? Wenn nicht, schreibe '\\' vor das erste Zeichen.");
            } else {
                $telegram->send_message("Did you mean the command /".substr($message, 1)." ? If not, escape the first character with '\\'.");
            }
            exit;
        }
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
            "voice_mode" => false
        );
        $user_config_manager->save_session_info("session", $session_info);
    }
    // If there is no session running, start one
    if ((!is_string($message) || substr($message, 0, 1) != "/") && $session_info->running == false) {
        // Add message to chat history
        $user_config_manager->add_message("user", $message);
        $message = "/start";
    }

    if (is_string($message) && substr($message, 0, 1) == "/") {
        if ($is_admin) {
            $command_manager = new CommandManager(array("Mental health", "Settings", "Admin", "Misc"));
        } else {
            $command_manager = new CommandManager(array("Mental health", "Settings", "Misc"));
        }

        // Dictionary of prompts for each mode, e.g. "IFS" => "You are also familiar with Internal Family Systems (IFS) and might use it to guide the process. "
        if ($user_config_manager->get_lang() == "de") {
            $mode_prompts = array(
                "IFS" => "Du bist auch mit Internal Family Systems (IFS) vertraut und verwendest es implizit, um den Prozess zu leiten. ",
                "CBT" => "Du bist auch mit Kognitive Verhaltenstherapie (KVT) vertraut und verwendest es, um den Prozess zu leiten. ",
                "ACT" => "Du bist auch mit Akzeptanz- und Commitmenttherapie (ACT) vertraut und verwendest es, um den Prozess zu leiten. ",
                "DBT" => "Du bist auch mit Dialektisch-Behaviorale Therapie (DBT) vertraut und verwendest es, um den Prozess zu leiten. ",
                "EFT" => "Du bist auch mit Emotionsfokussierter Therapie (EFT) vertraut und verwendest es, um den Prozess zu leiten. ",
                "psychodynamic" => "Du bist auch mit psychodynamischer Therapie vertraut und verwendest es, um den Prozess zu leiten. ",
                "somatic" => "Du bist auch mit somatischer Therapie vertraut und verwendest es, um den Prozess zu leiten. ",
                "meditation" => "Deine Hauptmethode ist Achtsamkeitsmeditation, um den Klienten an einen ruhigeren Ort zu führen. ",
                "none" => "",
            );
        } else {
            $mode_prompts = array(
                "IFS" => "You are also familiar with Internal Family Systems (IFS) and might use it implicitly to guide the process. ",
                "CBT" => "You are also familiar with Cognitive Behavioral Therapy (CBT) and use it to guide the process. ",
                "ACT" => "You are also familiar with Acceptance and Commitment Therapy (ACT) and use it to guide the process. ",
                "DBT" => "You are also familiar with Dialectical Behavior Therapy (DBT) and use it to guide the process. ",
                "EFT" => "You are also familiar with Emotionally Focused Therapy (EFT) and use it to guide the process. ",
                "psychodynamic" => "You are also familiar with psychodynamic therapy and use it to guide the process. ",
                "somatic" => "You are also familiar with somatic therapy and might use it to guide the process. ",
                "meditation" => "Your main method is mindfulness meditation to guide the client to a calmer place. ",
                "none" => "",
            );
        }

        $command_manager->add_command(array("/start"), function($command, $_) use ($telegram, $user_config_manager, $openai, $mode_prompts) {
            $session_info = $user_config_manager->get_session_info("session");
            // If there is a session running, don't start a new one
            if ($session_info->running === true) {
                $telegram->send_message("You are already in a session. Please /end the session first to start a new one.");
                exit;
            }
            $session_info->running = true;
            $session_info->this_session_start = time();

            // If this is the first session, send a welcome message
            if ($session_info->cnt == 0) {
                if ($user_config_manager->get_lang() == "de") {
                    $telegram->send_message("Hallo! Ich bin hier, um deine mentale Gesundheit zu unterstützen. "
                    ."Du kannst eine Sitzung beginnen, indem du mir sagst, was dir auf dem Herzen liegt, oder indem du /start verwendest.\n\n"
                    ."*Bitte beende jede Sitzung mit /end*, um zu aktualisieren, was ich über dich weiß. "
                    ."Du kannst den Befehl /profile verwenden, um die Informationen einzusehen, die ich über dich gesammelt habe. "
                    ."Schau dir /help für weitere verfügbare Befehle an.");
                } else {
                    $telegram->send_message("Hey there! I am here to support your mental health. "
                    ."You can start a session by telling me what's on your mind or using /start.\n\n"
                    ."*Please end every session with /end* to update what I know about you. "
                    ."You can use /profile to see the information I collected about you. "
                    ."Check out /help for more available commands.");
                }
            }
            else {
                if ($user_config_manager->get_lang() == "de") {
                    $telegram->send_message("Eine neue Sitzung beginnt. Bitte verwende /end, um die Sitzung zu beenden.");
                } else {
                    $telegram->send_message("Starting a new session. Please use /end to end the session.");
                }
            }
            $name_string = $user_config_manager->get_name();
            if ($name_string == "" || $name_string == null) {
                $name_string = "";
            } else {
                if ($user_config_manager->get_lang() == "de") {
                    $name_string = "(mein Name ist ".$name_string.") ";
                } else {
                    $name_string = "(my name is ".$name_string.") ";
                }
            }
            $mode_prompt = $mode_prompts[$session_info->mode ?? "none"];
            if ($user_config_manager->get_lang() == "de") {
                $start_prompt = "Du bist eine psychotherapeutische Fachkraft, die mir ".$name_string."hilft, mich selbst zu verbinden "
                    ."und zu heilen. Zeige Empathie und Mitgefühl, indem du meine Gefühle anerkennst und validierst. Dein Hauptziel "
                    ."ist es, mir einen sicheren, fürsorglichen und unterstützenden Raum zu bieten. Hilf mir, meine "
                    ."Gedanken, Gefühle und Erfahrungen zu erkunden, während du mich zu persönliche Weiterentwicklung und emotionale Heilung führst. "
                    .$mode_prompt
                    ."Halte deine Antworten kurz, aber so hilfreich wie möglich. Vermeide im Allgemeinen, Listen von "
                    ."Ratschlägen zu geben, sondern bitte frage stattdessen um eigenen Meinungen und Ideen. Und bitte fragen, wenn etwas "
                    ."unklar ist oder wichtige Informationen fehlen. Die aktuelle Uhrzeit ist ".date("G:i").".";
            } else {
                $start_prompt = "You are a therapist assisting me ".$name_string."to connect to "
                    ."myself and heal. Show empathy and compassion by acknowledging and validating my feelings. Your primary "
                    ."goal is to provide a safe, nurturing, and supportive environment for me. Help me explore my "
                    ."thoughts, feelings, and experiences, while guiding me towards personal growth and emotional healing. "
                    .$mode_prompt
                    ."Keep your responses concise, but as helpful as possible. Generally, avoid giving lists of "
                    ."advice but rather ask the client for their own opinions and ideas instead. And please ask if something "
                    ."is unclear to you or some important information is missing. The current time is ".date("g:ia").".";
            }
            $chat = (object) array(
                "model" => "gpt-4-vision-preview",
                "temperature" => 0.5,
                "max_tokens" => 4096,
                "messages" => array(
                    array("role" => "system", "content" => $start_prompt),
                ),
            );
            // If there is a previous session, add the profile to the chat history
            if ($session_info->profile != "") {
                $time_passed = time_diff($session_info->this_session_start, $session_info->last_session_start);
                $profile = $session_info->profile;
                if ($user_config_manager->get_lang() == "de") {
                    $profile_prompt = "Zu deiner eigenen Referenz, hier ist das Profil, das du nach "
                    ."der letzten Sitzung (".$time_passed." her) geschrieben hast, als Grundlage für diese Sitzung:\n\n".$profile;
                } else {
                    $profile_prompt = "For your own reference, here is the profile you previously wrote after "
                    ."the last session (".$time_passed." ago) as a basis for this session:\n\n".$profile;
                }
                $chat->messages[] = array("role" => "system", "content" => $profile_prompt);
            }
            // If there is already a chat history, append them to the new messages (i.e. have the system messages at the beginning)
            $prev_chat = $user_config_manager->get_config();
            if (isset($prev_chat->messages)) {
                $chat->messages = array_merge($chat->messages, $prev_chat->messages);
            }
            // Save the chat history
            $user_config_manager->save_config($chat);
            // Save the session info
            $user_config_manager->save_session_info("session", $session_info);
            // No exit here to generate an initial response
        }, "Mental health", "Start a new session");

        // The command /end ends the current session
        $command_manager->add_command(array("/end", "/endskip"), function($command, $arg) use ($telegram, $openai, $user_config_manager) {
            $session_info = $user_config_manager->get_session_info("session");
            $chat = $user_config_manager->get_config();
            // Check if there is a session running
            if ($session_info->running == false) {
                // Clean the chat history
                $chat->messages = array();
                $user_config_manager->save_config($chat);
                if ($user_config_manager->get_lang() == "de") {
                    $telegram->send_message("Die Sitzung wurde noch nicht gestartet. Bitte schreibe etwas oder verwende den Befehl /start.");
                } else {
                    $telegram->send_message("The session isn't started yet. Please write something or use the command /start.");
                }
                exit;
            }
            if ($command == "/end" && $arg == "skip") {
                $command = "/endskip";
            }
            // If there were more than 5 messages (2x system, 2 responses), request a session summary
            if ($command != "/endskip" && count($chat->messages) > 7) {
                if ($user_config_manager->get_lang() == "de") {
                    $telegram->send_message("Bitte gib mir einen Moment, um über unsere Sitzung reflektieren...");
                } else {
                    $telegram->send_message("Please give me a moment to reflect on our session...");
                }
                $user_config_manager->save_backup();

                // Session summary
                if ($user_config_manager->get_lang() == "de") {
                    $summary_prompt = "Zeit zum Reflektieren. Schreibe eine Zusammenfassung dieser Sitzung, die später verwendet wird, um das "
                    ."Profil zu aktualisieren. Fasse daher nur Informationen zusammen, die für zukünftige Sitzungen wirklich notwendig sind.";
                } else {
                    $summary_prompt = "Time to reflect. Write a summary of this session that will be used later to update the "
                    ."profile. Hence, include only information that is really necessary for upcoming sessions.";
                }
                $chat->messages = array_merge($chat->messages, array(
                    array("role" => "system", "content" => $summary_prompt)
                ));
                $summary = $openai->gpt($chat, $user_config_manager);
                if (substr($summary, 0, 7) == "Error: ") {
                    if ($user_config_manager->get_lang() == "de") {
                        $telegram->send_message("Entschuldigung, ich habe Probleme, mich mit dem Server zu verbinden. Bitte versuche es erneut /end.");
                    } else {
                        $telegram->send_message("Sorry, I am having trouble connecting to the server. Please try again /end.");
                    }
                    exit;
                }
                // Show the summary to the user
                if ($user_config_manager->get_lang() == "de") {
                    $telegram->send_message("Hier ist eine Zusammenfassung unserer Sitzung:\n\n".$summary);
                } else {
                    $telegram->send_message("Here is a summary of our session:\n\n".$summary);
                }

                // Profile update
                if ($session_info->profile == "") {
                    $new_profile = $summary;
                } else {
                    $time_passed = time_diff($session_info->this_session_start, $session_info->last_session_start);

                    if ($user_config_manager->get_lang() == "de") {
                        $profile_update_prompt = "Danke für die Zusammenfassung. Lass uns jetzt das Profil mit den neuen Informationen aktualisieren, die du "
                        ."in dieser Sitzung erhalten hast. Hier ist noch einmal das Profil, das du nach unserer vorherigen Sitzung geschrieben hast (".$time_passed." her):\n\n"
                        .$session_info->profile."\n\nDas Ziel ist es, eine detaillierte Beschreibung von mir zu haben, die für alles, was in der nächsten Sitzung ansteht, nützlich ist. "
                        ."Um ein ausführliches, umfassendes Profil nach vielen Sitzungen zu haben, vermeide es, relevante Informationen aus früheren Sitzungen zu entfernen, sondern "
                        ."integriere sie in ein detailliertes und informatives Gesamtbild.";
                    } else {
                        $profile_update_prompt = "Thank you for the summary. Now, let's update the profile with the new information you got "
                        ."in this session. Here is again the profile you wrote after our previous session (".$time_passed." ago):\n\n"
                        .$session_info->profile."\n\nThe goal is to have a detailed description of me that is useful for whatever comes up in the next session. "
                        ."To have an elaborate, all-encompassing profile after many sessions, avoid removing relevant information from previous sessions, but "
                        ."integrate them into a detailed and informative bigger picture.";
                    }
                    $chat->messages = array_merge($chat->messages, array(
                        array("role" => "assistant", "content" => $summary),
                        array("role" => "system", "content" => $profile_update_prompt)
                    ));
                    $new_profile = $openai->gpt($chat, $user_config_manager);
                    if (substr($new_profile, 0, 7) == "Error: ") {
                        if ($user_config_manager->get_lang() == "de") {
                            $telegram->send_message("Entschuldigung, ich habe Probleme, mich mit dem Server zu verbinden. Bitte versuche es erneut /end.");
                        } else {
                            $telegram->send_message("Sorry, I am having trouble connecting to the server. Please try again /end.");
                        }
                        exit;
                    }
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
            if ($user_config_manager->get_lang() == "de") {
                $telegram->send_message("Sitzung beendet. Vielen Dank, dass du heute bei mir warst.");
            } else {
                $telegram->send_message("Session ended. Thank you for being with me today.");
            }
            exit;
        }, "Mental health", "End the current session. /endskip skips the session summary and profile update.");

        // The command /profile shows the profile of the user
        $command_manager->add_command(array("/profile"), function($command, $_) use ($telegram, $user_config_manager) {
            $session_info = $user_config_manager->get_session_info("session");
            if ($session_info->profile == "") {
                if ($user_config_manager->get_lang() == "de") {
                    $message = "Es wurde noch kein Profil erstellt. ";
                    if ($session_info->running == true)
                        $message .= "Bitte schreibe etwas oder beende die aktuelle Sitzung mit /end.";
                    else
                        $message .= "Bitte starte eine neue Sitzung mit /start.";
                } else {
                    $message = "No profile has been generated yet. ";
                    if ($session_info->running == true)
                        $message .= "Please type something or end the current session with /end.";
                    else
                        $message .= "Please start a new session with /start.";
                }
                $telegram->send_message($message);
            } else {
                $date = date("d.m.y g:ia", $session_info->last_session_start);
                if ($user_config_manager->get_lang() == "de") {
                    $telegram->send_message("Hier ist dein aktuelles Profil (erstellt am ".$date."):\n\n".$session_info->profile);
                } else {
                    $telegram->send_message("Here is your current profile (created ".$date."):\n\n".$session_info->profile);
                }
            }
            exit;
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
            exit;
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
            exit;
        }, "Settings", "Show or set your name");

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
                $telegram->send_message("The timezone \"".$timezone."\" is not valid. Please provide a valid timezone, e.g. \"/timezone Europe/Berlin\".");
                exit;
            }
            $user_config_manager->set_timezone($timezone);
            $telegram->send_message("Your timezone has been set to \"".$timezone."\".");
            exit;
        }, "Settings", "Set your timezone");

        // The command /lang allows the user to change their language
        $command_manager->add_command(array("/lang"), function($command, $lang) use ($telegram, $user_config_manager) {
            if ($lang == "") {
                $telegram->send_message("Your language is currently set to \"".$user_config_manager->get_lang()."\". Currently supported languages are English (en) and German (de). To change it, please provide a language with the command, e.g. \"/lang de\".");
                exit;
            }
            $lang = strtolower($lang);
            if ($lang == "english") {
                $lang = "en";
            } else if ($lang == "german" || $lang == "deutsch") {
                $lang = "de";
            }
            // Ensure $lang is ISO 639-1
            if (strlen($lang) != 2) {
                $telegram->send_message("The language code must be [ISO 639-1](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) (2 letters).");
                exit;
            }
            $user_config_manager->set_lang($lang);
            $telegram->send_message("Your language has been set to \"".$lang."\".");
            exit;
        }, "Settings", "Set your language");

        // The command /reset deletes the current configuration
        $command_manager->add_command(array("/reset"), function($command, $confirmation) use ($telegram, $user_config_manager) {
            // Check if $confirmation is "yes"
            if ($confirmation != "yes") {
                $telegram->send_message("Please confirm deleting your profile and all other data with \"/reset yes\". Afterwards, this can be undone with /restore unless a new session is started after which the backup is overwritten and the current state can't be recovered.");
                exit;
            }

            $file_path = $user_config_manager->get_file();
            // Save in backup before deleting
            $user_config_manager->save_backup();
            unlink($file_path); // Delete the file to create the default config on next message

            $telegram->send_message("Your configuration has been reset to the default settings. You can start a new session with /start.");
            exit;
        }, "Settings", "Reset your configuration");

        // Command /restore restores the config from the backup file
        $command_manager->add_command(array("/restore"), function($command, $confirmation) use ($telegram, $user_config_manager) {
            // Check if $confirmation is "yes"
            if ($confirmation != "yes") {
                $telegram->send_message("Please confirm with \"/restore yes\". This can't be undone.");
                exit;
            }

            // Restore the backup
            try {
                if($user_config_manager->restore_backup()) {
                    $telegram->send_message("Backup restored. You can start a new session with /start.");
                } else {
                    $telegram->send_message("There is no backup to restore. Please start a new session with /start.");
                }
            } catch (Exception $e) {
                $telegram->send_message("Error in reading backup file: ".$e->getMessage(), false);
            }
            exit;
        }, "Settings", "Restore the config from the backup file");

        // The command /dailyreminder allows the user to toggle the daily reminder
        $command_manager->add_command(array("/dailyreminder"), function($command, $arg) use ($telegram, $user_config_manager, $global_config_manager) {
            $jobs = $global_config_manager->get_jobs();
            $chat_id = $telegram->get_chat_id();
            // Toggle the jobs for $chat_id with name starting with "dailyreminder"
            foreach ($jobs as $job) {
                if ($job->chat_id == $chat_id && substr($job->name, 0, 13) == "dailyreminder") {
                    $job->status = $job->status == "active" ? "inactive" : "active";
                    $global_config_manager->save_jobs($jobs);
                    $telegram->send_message("Daily reminder ".($job->status == "active" ? "activated" : "deactivated").".");
                    exit;
                }
            }
            // Otherwise, create a new job
            $global_config_manager->add_job((object) array(
                "status" => "active",
                "name" => "dailyreminder_".$user_config_manager->get_name(),
                "chat_id" => $chat_id,
                "is_prompt" => true,
                "last_run" => null,
                "next_run" => null,
                "distribution" => array("type" => "uniform_once_a_day", "earliest" => 8, "latest" => 22),
                "message" => $user_config_manager->get_lang() == "de"
                    ? "Du bist eine therapeutische Fachkraft, die sich dem Wohlbefinden und der Weiterentwicklung deiner Klient*in verpflichtet f\u00fchlt. "
                    ."Frage auf herzliche Weise, wie es der Person heute geht und ob etwas gibt, die sie mit dir teilen m\u00f6chte. Die aktuelle Uhrzeit ist {{time}}, "
                    ."aber bitte vermeide es, die genaue Uhrzeit zu erw\u00e4hnen. "
                    ."Deine Nachricht wird in einem Chat gesendet, verwende also gerne Emojis. Verbreite Liebe! \u2764\ufe0f"
                    : "You are a therapist committed to your clients well-being and growth. Ask your client in a heartfelt way how they are feeling today and if "
                    ."they have any concerns they would like to share with you. The current time is {{time}}, but please avoid mentioning the specific time. "
                    ."Your message is sent in a chat, so you can make use of emojis. Spread love! \u2764\ufe0f" 

            ));
            $telegram->send_message("Daily reminder activated. You will receive a message from me once a day at a random time to check in with you.");
            exit;
        }, "Settings", "Toggle the daily reminder");

        // The command /openaiapikey allows the user to set their custom OpenAI API key
        $command_manager->add_command(array("/openaiapikey"), function($command, $key) use ($telegram, $user_config_manager) {
            if ($key == "") {
                $telegram->send_message("Provide an API key with the command, e.g. \"/openaiapikey abc123\".");
                exit;
            }
            $user_config_manager->set_openai_api_key($key);
            $telegram->send_message("Your new OpenAI API key has been set.");
            exit;
        }, "Settings", "Set your own OpenAI API key");

        // The command /c allows to request another response from the model
        $command_manager->add_command(array("/c"), function($command, $_) use ($telegram, $openai, $user_config_manager, $DEBUG) {
            // Check if session is running
            $session_info = $user_config_manager->get_session_info("session");
            if ($session_info->running == false) {
                $telegram->send_message("Please start a new session with /start first.");
                exit;
            }
        }, "Misc", "Request another response from the model");

        if ($is_admin) {
            // #######################
            // ### Commands: Admin ###
            // #######################

            // The command /voice requests a text-to-speech conversion from the model
            $command_manager->add_command(array("/voice"), function($command, $arg) use ($telegram, $user_config_manager) {
                // Check if voice mode is active
                $session_info = $user_config_manager->get_session_info("session");
                if (!isset($session_info->voice_mode)) {
                    $session_info->voice_mode = false;
                    $user_config_manager->save_session_info("session", $session_info);
                }
                if ($session_info->voice_mode == false) {
                    // Turn on voice mode
                    $session_info->voice_mode = true;
                    $user_config_manager->save_session_info("session", $session_info);
                    $telegram->send_message("Voice mode is now active. I will send my responses as voice messages. To turn it off, simply write /voice again.");
                } else {
                    // Turn off voice mode
                    $session_info->voice_mode = false;
                    $user_config_manager->save_session_info("session", $session_info);
                    $telegram->send_message("Voice mode is now inactive. I will send my responses as text messages. To turn it on, simply write /voice again.");
                }
                exit;
            }, "Admin", "Toggle voice mode");

            // The command /addusermh adds a user to the list of authorized users
            $command_manager->add_command(array("/adduser"), function($command, $username) use ($telegram, $global_config_manager) {
                if ($username == "" || $username[0] != "@") {
                    $telegram->send_message("Please provide a username to add.");
                    exit;
                }
                $username = substr($username, 1);
                if ($global_config_manager->is_allowed_user($username, "general")) {
                    $telegram->send_message("User @".$username." is already in the list of authorized users.");
                    exit;
                }
                $global_config_manager->add_allowed_user($username, "general");
                $telegram->send_message("Added user @".$username." to the list of authorized users.");
                exit;
            }, "Admin", "Add a user to access the bot");

            // The command /removeuser removes a user from the list of authorized users
            $command_manager->add_command(array("/removeuser"), function($command, $username) use ($telegram, $global_config_manager) {
                if ($username == "") {
                    $telegram->send_message("Please provide a username to remove.");
                    exit;
                }
                if ($username[0] == "@") {
                    $username = substr($username, 1);
                }
                if (!$global_config_manager->is_allowed_user($username, "general")) {
                    $telegram->send_message("User @".$username." is not in the list of authorized users.");
                    exit;
                }
                try {
                    $global_config_manager->remove_allowed_user($username, "general");
                } catch (Exception $e) {
                    $telegram->send_message("Error: ".json_encode($e), false);
                    exit;
                }
                $telegram->send_message("Removed user @".$username." from the list of authorized users.");
                exit;
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
                exit;
            }, "Admin", "List all users authorized, by category");

            // Command /cnt outputs the number of messages in the chat history
            $command_manager->add_command(array("/cnt"), function($command, $_) use ($telegram, $user_config_manager) {
                $chat = $user_config_manager->get_config();
                $telegram->send_message("There are ".count($chat->messages)." messages in the chat history.");
                exit;
            }, "Admin", "Output the number of messages in the chat history");

            // The command /dumpmessages outputs the messages in a form that could be used to recreate the chat history
            $command_manager->add_command(array("/dumpmessages", "/dm"), function($command, $n) use ($telegram, $user_config_manager) {
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
                    if (is_string($message->content))
                        $telegram->send_message("/".$message->role." ".$message->content, false);
                    else {
                        $image_url = $message->content[0]->image_url;
                        $caption = $message->content[1]->text;
                        $telegram->send_message("/".$message->role." ".$caption."\n".$image_url, false);
                    }
                }
                exit;
            }, "Admin", "Dump all messages in the chat history. You can dump only the last n messages by providing a number with the command.");

            // Command /showcase (with optional parameter $name) let's the admin showcase the bot
            $command_manager->add_command(array("/showcase"), function($command, $name) use ($telegram, $user_config_manager) {
                $file_path = $user_config_manager->get_file();
                $sc_backup_file_path = $file_path."_showcase";
                if ($name == "end") {
                    if (file_exists($sc_backup_file_path)) {
                        // Restore the backup
                        copy($sc_backup_file_path, $file_path);
                        unlink($sc_backup_file_path);
                        $telegram->send_message("Showcase ended.");
                    } else {
                        $telegram->send_message("Showcase is currently not running.");
                    }
                } else if (file_exists($sc_backup_file_path)){
                    $telegram->send_message("Showcase is already running. Please end it first with \"/showcase end\". Afterwards, you can start a new showcase with \"/showcase <name>\".");
                } else {
                    // Save the current config to a backup file
                    copy($file_path, $sc_backup_file_path);
                    // Create a new config file
                    unlink($file_path);
                    $username = $user_config_manager->get_username();
                    $user_config_manager = new UserConfigManager($telegram->get_chat_id(), $username, $name, "en");
                    $telegram->send_message("Showcase prepared. Please send /start to start the showcase and \"/showcase end\" to end it.");
                }
                exit;
            }, "Admin", "Showcase the bot. Use \"/showcase <name>\" to specify a name, and \"/showcase end\" to end it.");

            // The command /jobs lists all jobs
            $command_manager->add_command(array("/jobs"), function($command, $arg) use ($telegram, $global_config_manager) {
                $jobs = $global_config_manager->get_jobs();
                if ($arg == "on" || $arg == "off") {
                    // Set all jobs to active or inactive
                    foreach ($jobs as $job) {
                        $job->status = "active" ? $arg == "on" : "inactive";
                    }
                    $global_config_manager->save_jobs($jobs);
                    $telegram->send_message("All jobs successfully set \"".$arg."\".");
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
                        $telegram->send_message("No jobs with name \"".$arg."\" found.");
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
            $command_manager->add_command(array("/usage"), function($command, $month) use ($telegram, $global_config_manager, $openai) {
                // If monthstring is not in format "ym", send an error message
                if ($month == "") {
                    $month = date("ym");
                }
                else if (!preg_match("/^[0-9]{4}$/", $month)) {
                    $telegram->send_message("Please provide a month in the format \"YYMM\".");
                    exit;
                }
                $chatids = $global_config_manager->get_chatids();
                $message = "Usage statistics for month ".$month.":\n\n";
                foreach ($chatids as $chatid) {
                    // Add a line for each user: @username (chatid): prompt + completion = total tokens
                    $user = new UserConfigManager($chatid, null, null, null);
                    $user_api_key = $user->get_openai_api_key();
                    $is_default_openai_key = $user_api_key == "" || $user_api_key == $openai->api_key ? 'true' : 'false';
                    $message .= "- @".$user->get_username()." (".$chatid.", ".$is_default_openai_key."): ";
                    // Read the counters "openai_chat_prompt_tokens", "openai_chat_completion_tokens", and "openai_chat_total_tokens"
                    $cnt_prompt = $user->get_counter("openai_".$month."_chat_prompt_tokens");
                    $cnt_completion = $user->get_counter("openai_".$month."_chat_completion_tokens");
                    $cnt_total = $user->get_counter("openai_".$month."_chat_total_tokens");
                    if ($cnt_prompt == 0 && $cnt_completion == 0 && $cnt_total == 0) {
                        $message .= "no data\n";
                    } else {
                        // Add a price estimate for each: $0.01 / 1K tokens for prompt, $0.03 / 1K tokens for completion
                        $price_estimate = round($cnt_prompt / 1000 * 0.01 + $cnt_completion / 1000 * 0.03, 2);
                        $message .= $cnt_prompt." + ".$cnt_completion." = ".$cnt_total." tokens (~$".$price_estimate.")\n";
                    }
                }
                $telegram->send_message($message);
                exit;
            }, "Admin", "Print the usage statistics of all users for a given month (default: current month)");
        }

        $response = $command_manager->run_command($message);
        // Help command doesn't have access to the telegram object
        // But some commands need to request a response from the model
        // So, request a response only if the command doesn't return anything
        if (is_string($response) && $response != "") {
            $telegram->send_message($response);
            exit;
        }
    } else {  // If the message is not a command
        $user_config_manager->add_message("user", $message);
    }

    $chat = $user_config_manager->get_config();
    $response = $openai->gpt($chat, $user_config_manager);

    // Append GPT's response to the messages array, except if it starts with "Error: "
    if (substr($response, 0, 7) != "Error: ") {
        $user_config_manager->add_message("assistant", $response);

        // Check if voice mode is active
        $session_info = $user_config_manager->get_session_info("session");
        if ($session_info->voice_mode == true) {
            // Generate the voice message
            $tts_config = $user_config_manager->get_tts_config();
            $audio_data = $openai->tts($response, $tts_config->model, $tts_config->voice, $tts_config->speed, response_format: "opus");  // telegram only supports opus
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
        } else {
            // Send the response as text message
            $telegram->send_message($response);
        }
    }
    else {
        $telegram->send_message("Error: ".$response, false);
        $telegram->send_message("Sorry, I am having trouble connecting to the server. Please write /c to try again.");
    }
}

?>
