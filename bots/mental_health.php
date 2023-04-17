<?php

/**
 * This is the main function for the mental health bot.
 * 
 * @param object $update The update object
 * @param UserConfigManager $user_config_manager The user config manager
 * @param Telegram $telegram The Telegram object for the user
 * @param OpenAI $openai The OpenAI object
 * @param Telegram $telegram_admin The Telegram object for the admin
 * @param string $username The username of the user
 * @return void
 */
function run_mental_health_bot($update, $user_config_manager, $telegram, $openai, $telegram_admin, $username) {
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
}

?>