<?php

/**
 * This bot just transcribes voice messages sent by the user.
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
    if (isset($update->voice) || isset($update->audio)) {
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
        $transcription = $llm->asr($file);
        $is_error = substr($transcription, 0, 7) == "Error: ";
        $telegram->send_message($transcription, !$is_error);
        exit;
    }

    $message = $update->text ?? "";
    if (substr($message, 0, 1) == "/") {
        $command_manager = new CommandManager();

        // The command /start displays the welcome message
        $command_manager->add_command(array("/start"), function($command) use ($telegram) {
            $telegram->send_message("Welcome to the Voice Transcription Bot! Send me a voice"
            ." message and I'll transcribe it for you. Use /help to see all available commands.");
            exit;
        }, "Settings", "Start the bot");

        // The command /lang allows the user to change their language
        $command_manager->add_command(array("/lang"), function($command, $lang) use ($telegram, $user_config_manager) {
            if ($lang == "") {
                $telegram->send_message("Info: Your language is currently set to \"".$user_config_manager->get_lang()."\". "
                ."To change it, provide an [ISO 639-1 language code](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) (2 letters) with the command, e.g. \"/lang en\"."
                ."This property is only used for voice transcription.");
                exit;
            }
            // Ensure $lang is ISO 639-1
            if (strlen($lang) != 2) {
                $telegram->send_message("Info: The language code must be [ISO 639-1](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) (2 letters).");
                exit;
            }
            $user_config_manager->set_lang($lang);
            $telegram->send_message("Info: Your language has been set to \"$lang\".");
            exit;
        }, "Settings", "Set your language");

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

        // The command /anthropicapikey allows the user to set their custom Anthropic API key
        $command_manager->add_command(array("/anthropicapikey"), function($command, $key) use ($telegram, $user_config_manager) {
            if ($key == "") {
                $telegram->send_message("Provide an API key with the command, e.g. \"/anthropicapikey abc123\".");
                exit;
            }
            $user_config_manager->set_anthropic_api_key($key);
            $telegram->send_message("Your new Anthropic API key has been set.");
            exit;
        }, "Settings", "Set your own Anthropic API key");

        // The command /openrouterapikey allows the user to set their custom OpenAI Router API key
        $command_manager->add_command(array("/openrouterapikey"), function($command, $key) use ($telegram, $user_config_manager) {
            if ($key == "") {
                $telegram->send_message("Provide an API key with the command, e.g. \"/openrouterapikey abc123\".");
                exit;
            }
            $user_config_manager->set_openrouter_api_key($key);
            $telegram->send_message("Your new OpenAI Router API key has been set.");
            exit;
        }, "Settings", "Set your own OpenAI Router API key");

        $response = $command_manager->run_command($message);
        if (is_string($response) && $response != "") {
            $telegram->send_message("Info: $response");
            exit;
        }
    } else if ($update->chat->type == "private") {
        $telegram->send_message("Please send a voice message to transcribe.");
        exit;
    }
}

?>
