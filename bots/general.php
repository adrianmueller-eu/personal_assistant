<?php

require_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../lib/parsers.php';

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

        // Only process as arXiv if the message starts with a valid arXiv link or ID
        if (preg_match('/^\s*((?:https?:\/\/)?arxiv\.org\/(?:abs|pdf)\/(\d+\.\d+(?:v\d+)?)(?:\.pdf)?\/?|arxiv:(\d+\.\d+(?:v\d+)?)(?=\s|$))\s*(.*)$/i',
                        $message, $matches)) {
            // Find the arXiv ID from the capturing groups
            $arxiv_id = $matches[2] ?: $matches[3];
            $user_message = trim($matches[4] ?? '');

            $telegram->send_message("Detected arXiv ID `$arxiv_id`. Processing...");

            $result = text_from_arxiv_id($arxiv_id);
            $telegram->die_if_error($result);

            assert(is_array($result));
            // Format paper information for the chat
            $paper_title = $result['title'];
            $tex_content = $result['content'];
            if ($DEBUG)
                $telegram->send_message("arXiv paper processed: \"$paper_title\"\n- ID: $arxiv_id\n- Words: ".str_word_count($tex_content));

            // Format the message with paper content and metadata
            $message = "arXiv:$arxiv_id \"$paper_title\"\n\n```\n$tex_content\n```";
            if ($user_message !== '') {
                $message .= "\n\n$user_message";
            }
        } else if (preg_match('/^\s*(https?:\/\/[^\s]+)\s*(.*)$/i', $message, $matches)) {
            $link = $matches[1];
            $user_message = trim($matches[2] ?? '');

            $telegram->send_message("Detected link `$link`. Extracting content...");
            // Special handling for github.com repo links to get README.md if exists
            if (preg_match('#^https?://github.com/([^/]+)/([^/]+)(/tree/([^/]+)(/.*)?)?#i', $link, $githubMatch)) {
                $owner = $githubMatch[1];
                $repo = $githubMatch[2];
                $branch = isset($githubMatch[4]) && $githubMatch[4] ? $githubMatch[4] : 'main';
                $path = isset($githubMatch[5]) && $githubMatch[5] ? $githubMatch[5] : '';
                $path = trim($path, '/');
                $try_paths = [];
                if ($path !== '') {
                    $parts = explode('/', $path);
                    // Build all parent paths from deepest to root
                    for ($i = count($parts); $i >= 0; $i--) {
                        $subpath = implode('/', array_slice($parts, 0, $i));
                        $try_paths[] = ($subpath ? $subpath . '/' : '') . 'README.md';
                    }
                } else {
                    $try_paths[] = 'README.md';
                }
                // Try each possible README.md location, all on the specified branch
                foreach ($try_paths as $try_path) {
                    $raw_url = "https://raw.githubusercontent.com/$owner/$repo/$branch/$try_path";
                    $head = @get_headers($raw_url, 1);
                    if ($head && strpos($head[0], '200') !== false) {
                        $link = $raw_url;
                        break;
                    }
                }
            }
            if (preg_match('/\.(md|txt|text|csv|log)$/i', $link)) {
                // direct download of text-like file content into $content
                $content = @file_get_contents($link);
                $content || $telegram->die("Error: Failed to download text file content from the link.");
            } else if (preg_match('/\.(jpg|jpeg|png)$/i', $link)) {  // only the ones widely supported in APIs
                // If the link is to an image, add it to the chat history as an image_url
                $user_config_manager->add_message("assistant", array(
                    array("type" => "image_url", "image_url" => array("url" => $link)),
                    array("type" => "text", "text" => "Image: $link"),
                ));
                $content = "Image link detected and saved to chat history.";
            } else {
                $content = parse_link($link);
                $telegram->die_if_error($content);
            }

            if ($DEBUG)
                $telegram->send_message("Link processed: \"$link\"\n- Words: ".str_word_count($content));
                // $telegram->die("Debug:\n\n$message");

            // Format the message with extracted content
            $message = "Link: $link\n\n```\n$content\n```";
            if ($user_message !== '') {
                $message .= "\n\n$user_message";
            }
        }
    } else if (isset($update->photo)) {
        $chat = $user_config_manager->get_config();
        // Check if the model can see
        if (substr($chat->model, 0, 5) == "gpt-3" || substr($chat->model, 0, 7) == "o3-mini") {
            $telegram->die("Error: You can't send images if you are talking to $chat->model. Try another model.");
        }
        $file_id = end($update->photo)->file_id;
        $caption = $update->caption ?? "";
        $file_url = $telegram->get_file_url($file_id);
        $file_url != null || $telegram->die("Error: Could not get the file URL from Telegram.");

        $message = array(
            array("type" => "image_url", "image_url" => array("url" => $file_url)),
            array("type" => "text", "text" => $caption),
        );
    } else if (isset($update->document) && preg_match('/\.pdf$/i', $update->document->file_name)) {
        // Prefer arXiv TeX if filename matches arXiv ID
        $content = '';
        if (preg_match('/^(\d{4}\.\d{4,5}(?:v\d+)?)\.pdf$/i', $update->document->file_name, $matches)) {
            $arxiv_id = $matches[1];
            $telegram->send_message("PDF filename matches arXiv ID `$arxiv_id`. Fetching arXiv TeX source...");
            $result = text_from_arxiv_id($arxiv_id);
            if (has_error($result)) {
                $telegram->send_message("$result\n\nExtracting text from pdf file...");
            } else {
                assert(is_array($result));
                $title = $result['title'];
                $content = $result['content'];
                $header = "arXiv:$arxiv_id";
            }
        }
        if (empty($content)) {
            // Handle PDF document as usual
            $file_id = $update->document->file_id;

            // Reject PDFs that are too large (over 10MB)
            if (isset($update->document->file_size) && $update->document->file_size > 10 * 1024 * 1024) {
                $size_mb = round($update->document->file_size / (1024 * 1024), 1);
                $telegram->die("Error: PDF is too large ($size_mb MB). Maximum size is 10 MB.");
            }

            $file_url = $telegram->get_file_url($file_id);
            $file_url != null || $telegram->die("Error: Could not get the PDF file URL from Telegram.");

            // Extract text from PDF
            $content = text_from_pdf($file_url);
            $telegram->die_if_error($content);

            $title = $update->document->file_name;
            $header = "PDF";
        }
        $word_count = str_word_count($content);
        $word_count != 0 || $telegram->die("Error: Sorry, I couldn't extract text from the PDF!");
        if ($DEBUG)
            $telegram->send_message("Content has $word_count words");

        // Reject PDFs that are too large
        if ($word_count > 42000) {
            $telegram->die("Error: Content is too long ($word_count words). Maximum size is 42,000 words.");
        }

        // Format the message with PDF content and metadata
        $message = "{$header}: \"{$title}\"\n\n```\n$content\n```";

        // Append caption
        $caption = $update->caption ?? "";
        if (!empty($caption)) {
            $message .= "\n\n$caption";
        }
    } else if (isset($update->voice) || isset($update->audio)) {
        // Get the file content from file_id with $telegram->get_file
        if (isset($update->voice)) {
            $file_id = $update->voice->file_id;
        } else {
            $file_id = $update->audio->file_id;
        }
        $file = $telegram->get_file($file_id);
        $file != null || $telegram->die("Error: Could not get the file from Telegram.");

        // Transcribe
        $message = $llm->asr($file);
        $telegram->die_if_error($message);

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
    } else if (isset($update->document)) {
        $telegram->die("I can only process PDF documents. The file you sent doesn't appear to be a PDF.");
    } else {
        if ($DEBUG) {
            $telegram->send_message("Unknown message: ".json_encode($update, JSON_PRETTY_PRINT), false);
        }
        $telegram->die("Sorry, I don't know yet what do to this message! :/");
    }

    if ($DEBUG) {
        // $telegram->send_message("You said: ".json_encode($message, JSON_PRETTY_PRINT));
        echo "You said: ".json_encode($message, JSON_PRETTY_PRINT)."\n";
    }

    $telegram->set_postprocessing($user_config_manager->is_post_processing());

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
        if ((substr($message, 0, 1) == "." && strlen($message) > 1) || (substr($message, 0, 1) == "\\")) {
            // Remove everything after the first space-like character
            $potential_command = preg_replace("/\s.*/", "", $message);
            // If it only contains word-characters, it might be a command
            $potential_command = substr($potential_command, 1);
            if (!preg_match("/[^A-Za-z0-9]/", $potential_command)) {
                $telegram->die("Did you mean the command /$potential_command ? If not, escape the first character with '\\'.\nNothing was added to the chat history.");
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
        $get_character_description = function($new_character, $previous_descriptions="") use ($telegram, $llm, $user_config_manager) {
            # Request a character description
            $prompt = "Create a character description based on the following information given by the user: $new_character\n\nIf they mentioned multiple "
                ."characters, please generate a description for each of them, otherwise only one.\n\n"
                ."Develop a brief but vivid portrayal of each of the characters, focusing on their professions, key ideas, and worldviews. "
                ."Describe their typical way of thinking and speaking, including any unique expressions or mannerisms they might use. "
                ."Highlight their areas of expertise and any strongly held opinions or beliefs that would come across in conversation. "
                ."Consider their background and experiences that shape their perspective. The goal is to capture the essence of how "
                ."these characters would authentically express themselves in a dialogue, rather than just listing traits. "
                ."Aim for a concise description that will allow for a realistic and engaging conversation with these characters. "
                ."If the request is generic or abstract, keep the descriptions general and adaptable to various contexts without inventing names or other specific details."
                ."Don't write anything else before or after the character descriptions, only output the character descriptions.";
            $previous_descriptions = trim($previous_descriptions);
            $has_previous_characters = $previous_descriptions != "" && !str_starts_with($previous_descriptions, "This room is empty.");
            if ($has_previous_characters) {
                # ask it to append the new character description to the previous ones
                $prompt .= " For your reference, these characters are already in the room:\n\n$previous_descriptions\n\nPlease only output the "
                ."new character description in a concise format, without repeating the previous descriptions.";
            }

            $chat = $user_config_manager->get_config();
            $chat = json_decode(json_encode($chat));
            $chat->messages = array();
            $chat->messages[] = (object) array("role" => "user", "content" => $prompt);
            $description = $llm->message($chat);
            $telegram->die_if_error($description);
            if ($has_previous_characters) {
                # append the new character description to the previous ones
                $description = "$previous_descriptions\n\n$description";
            }
            return $description;
        };

        $default_intro = "Your task is to help and support your friend in their life. "
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
            ."Spread love! ❤️✨";

        // The command is /start or /reset resets the bot and sends a welcome message
        $reset = function($command, $show_message = true) use ($telegram, $user_config_manager, $default_intro) {
            $user_config_manager->save_session();
            $user_config_manager->clear_messages();
            # If the user config contains an intro message, add it as system message
            $intro = $user_config_manager->get_intro();
            # replace {DATE} with the current date
            $intro = str_replace("{DATE}", date("l, j.n.Y"), $intro);
            $user_config_manager->get_config()->messages = array();
            if ($intro != "") {
                $user_config_manager->add_message("system", $intro);
            } else {
                $user_config_manager->add_message("system", $default_intro);
            }
            if ($show_message) {
                $hello = $user_config_manager->hello();
                $telegram->send_message($hello);
            }
        };

        $invite = function($new_characters) use ($telegram, $user_config_manager, $llm, $get_character_description) {
            $chat = $user_config_manager->get_config();
            $is_conversation_room = count($chat->messages) > 1 && substr($chat->messages[1]->content, 0, 25) == "Character description(s):";
            if (!$is_conversation_room) {
                $telegram->send_message("Trying to find $new_characters...");
                # If the first message is intro, remove it and append it to "new characters" message
                $intro = $user_config_manager->get_intro();
                if (count($chat->messages) > 0 && $chat->messages[0]->content == $intro) {
                    $new_characters = "$new_characters\n\nAlso add a personal assistant with a characterization following this prompt:\n$intro";
                    array_shift($chat->messages);
                    // Prepend any "assistant" message with "Presonal assistant:\n"
                    foreach ($chat->messages as $message) {
                        if ($message->role == "assistant") {
                            $message->content = "Personal assistant:\n$message->content";
                        }
                    }
                }
                $previous_messages = $chat->messages;
                $chat->messages = array();
                # create new character descriptions
                $description = $get_character_description($new_characters);
                $user_config_manager->add_message("system", "You are now in a conversation room with one or more characters described below. Your role is to embody these "
                    ."character(s) and engage in dialogue with the user. Respond authentically, representing each character's unique personality, "
                    ."knowledge, and manner of speaking. Draw upon the details provided to inform responses, opinions, and overall demeanor. "
                    ."Stay true to each character's background, expertise, and worldview throughout the interaction.");
                $user_config_manager->add_message("system", "Character description(s):\n\n$description");
                $user_config_manager->add_message("system", "Conversation format:\n"
                    ."- If there's only one character, respond directly as that character.\n"
                    ."- If there are multiple characters, give every character an opportunity to respond.\n\n"
                    ."For each user message, respond as the character(s) would, incorporating their specific knowledge, opinions, and speech patterns. "
                    ."If asked about topics outside a character's expertise or experience, have them respond realistically, which may include admitting "
                    ."uncertainty or redirecting the conversation to their areas of interest.\n\n"
                    ."In multi-character scenarios, characters may interact with or respond to each other's comments if it's natural for them to do so. "
                    ."The user can address questions or comments to specific characters or to the group as a whole. Not every character has to respond every time. "
                    ."It might often be enough to just hear from one or two characters with the most relevant perspectives. Remember to maintain each character's "
                    ."unique voice and perspective throughout the entire conversation, whether it's a one-on-one dialogue or a group discussion.");
                # Ask the AI to write a short information for the user who they are talking with
                $user_config_manager->add_message("user", "Who joined the scene? Please respond with one sentence in the format \"You are now in a conversation room with ...\" with no other text before or after.");
                $joined = $llm->message($user_config_manager->get_config());
                if (has_error($joined)) {
                    // ignore $joined
                    $telegram->send_message("You are now in a conversation room with $new_characters :)");
                } else {
                    $telegram->send_message($joined);
                }
                $user_config_manager->delete_messages(1);
                foreach ($previous_messages as $message) {
                    $user_config_manager->add_message($message->role, $message->content);
                }
            } else if (substr($chat->messages[1]->content, 0, 25) == "Character description(s):") {
                $current_description = substr($chat->messages[1]->content, 25);
                $telegram->send_message("Trying to find $new_characters...");
                $description = $get_character_description($new_characters, $current_description);
                $chat->messages[1]->content = "Character description(s):\n\n$description";
                # ask AI to write a short information for the user who joined the conversation
                $user_config_manager->add_message("user", "Previous character description(s):\n\n$current_description\n\nNew character description(s):\n\n$description\n\n"
                    ."Please analyze the descriptions above and inform us: Who joined the scene? Respond with one short sentence with no other text before or after.");
                $joined = $llm->message($chat);
                if (has_error($joined)) {
                    $joined = "$new_characters joined the conversation.";
                }
                $user_config_manager->delete_messages(1);
                $telegram->send_message($joined);
            }
            # append $joined to the last message of the chat to inform the AI
            $last_message = $chat->messages[count($chat->messages) - 1];
            if ($last_message->role == "assistant") {
                $last_message->content .= "\n\n$joined";
            }
        };

        $command_manager->add_command(array("/start", "/reset", "/r"), function($command, $description) use ($reset, $invite) {
            if ($description == "") {
                $reset($command, true);
            } else {
                $reset($command, false);
                $invite($description);
            }
            exit;
        }, "Presets", "Start a new conversation. You may provide a description who you want to talk with.");

        // The command /invite invites another character to the conversation
        $command_manager->add_command(array("/invite"), function($command, $new_character) use ($telegram, $user_config_manager, $invite) {
            $new_character != "" || $telegram->die("Please provide a description of the character you want to invite into the conversation.");
            $invite($new_character);
            exit;
        }, "Presets", "Invite another character to the conversation. Provide a description of the character with the command.");

        // The command /leave removes the a character from the conversation
        $command_manager->add_command(array("/leave"), function($command, $character) use ($telegram, $llm, $user_config_manager) {
            $character != "" || $telegram->die("Please provide the name of the character you want to remove from the conversation.");
            $chat = $user_config_manager->get_config();
            if (count($chat->messages) == 0) {
                $telegram->send_message("The room is empty.");
            } else if (count($chat->messages) == 1 || (count($chat->messages) > 1 && substr($chat->messages[1]->content, 0, 25) != "Character description(s):")) {
                $telegram->send_message("It looks like you are only chatting with the default personal assistant :) You can set up a new room with /reset or invite other characters with /invite.");
            } else if (substr($chat->messages[1]->content, 0, 25) == "Character description(s):") {
                $telegram->send_message("Asking $character to leave...");
                $current_description = substr($chat->messages[1]->content, 25);
                # request llm to remove the character description
                $user_config_manager->add_message("user", "Remove the character description of \"$character\" from the following descriptions. "
                ."Don't write anything else before or after, only output the remaining descriptions. If the requested character is not among the descriptions, just repeat "
                ."the descriptions as they are with no description removed. If there is no description left after removal, just output \"---\".\n\n$current_description");
                $new_description = $llm->message($chat);
                $user_config_manager->delete_messages(1);
                $telegram->die_if_error($new_description);
                if (abs(strlen($new_description) - strlen($current_description)) < 10) {
                    $telegram->send_message("It seems $character is not in the conversation.");
                    exit;
                }
                $telegram->send_message("$character left the conversation.");
                # append "$character left the conversation." to the last message of the chat to inform the AI
                $last_message = $chat->messages[count($chat->messages) - 1];
                $last_message->content .= "\n\n$character left the conversation.";
                if ($new_description == "---") {
                    $new_description = "This room is empty. Respond only as an empty space sounds.";  # see line 145
                }
                # save the new character descriptions
                $chat->messages[1]->content = "Character description(s):\n\n$new_description";
            }
            exit;
        }, "Presets", "Remove a character from the conversation. Provide the name of the character with the command.");

        // The command /room shows the current character descriptions
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
                $telegram->send_message($response, !has_error($response));
            } else {
                $user_config_manager->save_session();
                $user_config_manager->save_config($chat);
                $telegram->send_message("Chat history reset. I am now a message responder.");
            }
            exit;
        }, "Presets", "Suggests responses to messages from others. Give a message with the command to preserve the previous conversation.");

        // The command /translator translates a given text
        $command_manager->add_command(array("/trans"), function($command, $text) use ($telegram, $user_config_manager, $llm) {
            $chat = UserConfigManager::$default_config;
            $chat["temperature"] = 0.7;
            $chat["messages"] = array(array("role" => "system", "content" => "Translate the messages sent to you into English, ensuring "
                ."accuracy in grammar, verb tenses, and context. Identify the language or encoding of the text you translate from."));
            // If the text is not empty, process the request one-time without saving the config
            if ($text != "") {
                $chat["messages"][] = array("role" => "user", "content" => $text);
                $response = $llm->message($chat);
                $telegram->send_message($response, !has_error($response));
            } else {
                $user_config_manager->save_session();
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
                if (has_error($response)) {
                    $telegram->send_message($response, false);
                // If the response starts with "BEGIN:VCALENDAR", it is an iCalendar event
                } else if (substr($response, 0, 15) == "BEGIN:VCALENDAR") {
                    $file_name = "event.ics";
                    $telegram->send_document($file_name, $response);
                } else {
                    $telegram->send_message($response);
                }
            } else {
                $user_config_manager->save_session();
                $user_config_manager->save_config($chat);
                $telegram->send_message("Chat history reset. I am now a calendar bot. Give me an invitation or event description!");
            }
            exit;
        }, "Presets", "Converts an event description to iCalendar format. Provide a description with the command to preserve the previous conversation.");

        // The command /code is a programming assistant
        $command_manager->add_command(array("/code"), function($command, $query) use ($telegram, $user_config_manager, $llm) {
            $chat = UserConfigManager::$default_config;
            $chat["temperature"] = 0.5;
            $chat["messages"] = array(array("role" => "system", "content" => "You are a programming and system administration assistant. "
                ."If there is a lack of details, state your uncertainty and ask for clarification. Do not show any warnings or information "
                ."regarding your capabilities. Keep your response short and avoid unnecessary explanations. If you provide code, ensure it is valid."));
            // If the query is not empty, process the request one-time without saving the config
            if ($query != "") {
                $chat["messages"][] = array("role" => "user", "content" => $query);
                $response = $llm->message($chat);
                $telegram->send_message($response, !has_error($response));
            } else {
                $user_config_manager->save_session();
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
            $chat = (object) UserConfigManager::$default_config;
            $chat->messages = array(array("role" => "system", "content" => $prompt));
            // If the text is not empty, process the request one-time without saving the config
            if ($text != "") {
                $chat->messages[] = array("role" => "user", "content" => $text);
                $response = $llm->message($chat);
                $telegram->send_message($response, !has_error($response));
            } else {
                $user_config_manager->save_session();
                $user_config_manager->save_config($chat);
                $telegram->send_message("Chat history reset. I am now a typo and grammar assistant.");
            }
            exit;
        }, "Presets", "Typo and grammar assistant. Provide a text to ignore and preserve the previous conversation.");

        // The command /task helps the user to break down a task and track progress
        $command_manager->add_command(array("/task"), function($command, $task) use ($telegram, $user_config_manager, $reset) {
            $task != "" || $telegram->die("Please provide a task with the command.");
            $reset($command, false);  // general prompt
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
            $prompt = "Your task is to prepare a mail based on the previous conversation using the template below. "
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

        // The command /search enables web search for a single query
        $command_manager->add_command(array("/search"), function($command, $query) use ($telegram, $user_config_manager, $llm) {
            $chat = $user_config_manager->get_config();
            if (strpos($chat->model, "claude-") !== 0) {
                $telegram->die("Only Claude can perform websearch.");
            }

            $user_config_manager->add_message("user", $query == "" ? "Please perform the web search." : $query);

            $response = $llm->message($chat, $enable_websearch=true);
            $telegram->die_if_error($response);

            // Handle websearch responses with citations
            if (is_array($response)) {
                // Store the original array response directly in the message history
                $user_config_manager->get_config()->messages[] = (object)["role" => "assistant", "content" => $response];
                // Use the formatted text for display
                $formatted_text = text_from_websearch($response, $user_config_manager->is_post_processing());
                $telegram->send_message($formatted_text);
            } else {
                // If not an array, just add as a regular message
                $user_config_manager->add_message("assistant", $response);
                $telegram->send_message($response);
            }
            exit;
        }, "Shortcuts", "Allow to perform a web search based on the chat history (and optional additional query).");

        // TODO !!! Add more presets here !!!

        // ##########################
        // ### Commands: Settings ###
        // ##########################

        // Shortcuts for models
        $shortcuts_large = array(
            "/claude37sonnetthinking" => "claude-3-7-sonnet-latest-thinking",
            "/claude37sonnet" => "claude-3-7-sonnet-latest",
            "/o4mini" => "o4-mini",
            "/o3mini" => "o3-mini",
            "/o3" => "o3",
            "/gpt41" => "gpt-4.1",
            "/gpt4o" => "gpt-4o",
            "/gemini25pro" => "google/gemini-2.5-pro-preview-03-25",
            "/gpt45" => "gpt-4.5-preview"
        );

        $shortcuts_small = array(
            "/claude35haiku" => "claude-3-5-haiku-latest",
            "/gpt41mini" => "gpt-4.1-mini",
            "/gpt41nano" => "gpt-4.1-nano",
            "/mistralsmall3" => "mistralai/mistral-small-24b-instruct-2501",
            "/googlegeminiflash25" => "google/gemini-2.5-flash-preview",
            "/googlegeminiflash25thinking" => "google/gemini-2.5-flash-preview:thinking"
        );

        // The command /model shows the current model and allows to change it
        $command_manager->add_command(array_merge(array("/model"), array_keys($shortcuts_large), array_keys($shortcuts_small)),
        function($command, $model) use ($telegram, $user_config_manager, $shortcuts_large, $shortcuts_small) {
            $chat = $user_config_manager->get_config();
            if (isset($shortcuts_large[$command])) {
                $model = $shortcuts_large[$command];
            } else if (isset($shortcuts_small[$command])) {
                $model = $shortcuts_small[$command];
            }
            if ($model == "") {
                $telegram->send_message("You are currently talking to `$chat->model`.\n\n"
                ."You can change the model by providing the model name after the /model command. "
                ."The following shortcuts are available:\n\n"
                .implode("\n", array_map(function($key, $value) {
                    return "$key -> `$value`";
                }, array_keys($shortcuts_large), $shortcuts_large))."\n\n"
                ."and for some smaller and cheaper models:\n"
                .implode("\n", array_map(function($key, $value) {
                    return "$key -> `$value`";
                }, array_keys($shortcuts_small), $shortcuts_small))."\n\n"
                ."Other options are other [OpenRouter models](https://openrouter.ai/models), "
                ."[Anthropic models](https://docs.anthropic.com/en/docs/about-claude/models), "
                ."and [OpenAI models](https://platform.openai.com/docs/models) ([pricing](https://platform.openai.com/docs/pricing)).");
            } else if ($chat->model == $model) {
                $telegram->send_message("You are already talking to `$chat->model`.");
            } else {
                $chat->model = $model;
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
                    $telegram->send_message("Temperature set to $chat->temperature.");
                } else {
                    $telegram->send_message("Temperature must be between 0 and 2.");
                }
            } else {
                $telegram->send_message("Temperature is currently set to $chat->temperature. To set the temperature, you can provide a number between 0 and 2 with the command.");
            }
            exit;
        }, "Settings", "Show the current temperature or change it (default: ".UserConfigManager::$default_config["temperature"].")");

        // The command /thikingoutput fetches the last thinking output
        $command_manager->add_command(array("/thinkingoutput"), function($command, $_) use ($telegram, $user_config_manager) {
            $thinking_output = $user_config_manager->get_last_thinking_output();
            $thinking_output != "" || $telegram->die("No thinking output available.");
            $telegram->send_message($thinking_output);
            exit;
        }, "Settings", "Fetch the last thinking output");

        // The command /postprocessing prompts the bot to post-process the message
        $command_manager->add_command(array("/postprocessing"), function($command, $_) use ($telegram, $user_config_manager) {
            $active = $user_config_manager->toggle_post_processing();
            $telegram->send_message("Post processing ".($active ? "activated" : "deactivated").".");
            exit;
        }, "Settings", "Post-processing of text (especially for equations)");

        // The command /name allows the user to change their name
        $command_manager->add_command(array("/name"), function($command, $name) use ($telegram, $user_config_manager) {
            $name != "" || $telegram->die("Your name is currently set to ".$user_config_manager->get_name().". To change it, provide a name with the command.");
            $user_config_manager->set_name($name);
            $telegram->send_message("Your name has been set to $name.");
            exit;
        }, "Settings", "Set your name");

        // The command /timezone allows the user to change their timezone
        $command_manager->add_command(array("/timezone"), function($command, $timezone) use ($telegram, $user_config_manager) {
            $timezone != "" || $telegram->die("Your timezone is currently set to \"".$user_config_manager->get_timezone()."\". To change it, please provide a timezone with the command, e.g. \"/timezone Europe/Berlin\".");
            // Validate the timezone
            try {
                new DateTimeZone($timezone);
            } catch (Exception $e) {
                $telegram->die("The timezone \"$timezone\" is not valid. Please provide a valid timezone, e.g. \"/timezone Europe/Berlin\".");
            }
            $user_config_manager->set_timezone($timezone);
            $telegram->send_message("Your timezone has been set to \"$timezone\".");
            exit;
        }, "Settings", "Set your timezone");

        // The command /lang allows the user to change their language
        $command_manager->add_command(array("/lang"), function($command, $lang) use ($telegram, $user_config_manager) {
            $lang != "" ||
                $telegram->die("Your language is currently set to \"".$user_config_manager->get_lang()."\". "
                ."To change it, provide an [ISO 639-1 language code](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) (2 letters) with the command, e.g. \"/lang en\"."
                ."This property is only used for voice transcription.");
            // Ensure $lang is ISO 639-1
            strlen($lang) == 2 || $telegram->send_message("The language code must be [ISO 639-1](https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes) (2 letters).");
            $user_config_manager->set_lang($lang);
            $telegram->send_message("Your language has been set to \"$lang\".");
            exit;
        }, "Settings", "Set your language");

        // The command /intro allows to read out or set the intro prompt
        $command_manager->add_command(array("/intro"), function($command, $message) use ($telegram, $user_config_manager, $default_intro) {
            if ($message == "") {
                $intro = $user_config_manager->get_intro();
                if ($intro == "") {
                    $telegram->send_message("You are using the default introductory system prompt:\n\n`/intro $default_intro`\n\n"
                        ."To set a custom intro prompt, please provide it with the command as /intro <your prompt>. If you want to reset it back to the default, you can use `/intro reset`.");
                } else {
                    if (strpos($intro, "`") === false) {
                        $intro = "`/intro $intro`";
                    } else {
                        $intro = "/intro $intro";
                    }
                    $telegram->send_message("Your current intro prompt is:\n\n$intro\n\nYou can change it by providing a new prompt with the command as /intro <new prompt>. Use `/intro reset` to revert to the default prompt.");
                }
                exit;
            }
            if ($message == "reset") {
                $user_config_manager->set_intro("");
                $telegram->send_message("Your intro prompt has been reset. You can set a new intro prompt by providing it after the /intro command.");
                exit;
            }
            $user_config_manager->set_intro($message);
            $telegram->send_message("Your intro prompt has been updated.");
            exit;
        }, "Settings", "Set your initial system prompt");

        // The command /hellos allows to read out or set the hello messages
        $command_manager->add_command(array("/hellos"), function($command, $message) use ($telegram, $user_config_manager) {
            if ($message == "") {
                $hellos = $user_config_manager->get_hellos();
                if (count($hellos) == 0) {
                    $telegram->send_message("You have not set any hello messages yet. To set your hello messages, you can provide a list of messages with the command, "
                        ."from which one is drawn at random at every reset. Use \"/hellos reset\" to have no hello messages.");
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
            $key != "" || $telegram->die("Provide an API key with the command, e.g. \"/openaiapikey abc123\".");
            $user_config_manager->set_openai_api_key($key);
            $telegram->send_message("Your new OpenAI API key has been set.");
            exit;
        }, "Settings", "Set your OpenAI own API key");

        // The command /anthropicapikey allows the user to set their custom Anthropic API key
        $command_manager->add_command(array("/anthropicapikey"), function($command, $key) use ($telegram, $user_config_manager) {
            $key != "" || $telegram->die("Provide an API key with the command, e.g. \"/anthropicapikey abc123\".");
            $user_config_manager->set_anthropic_api_key($key);
            $telegram->send_message("Your new Anthropic API key has been set.");
            exit;
        }, "Settings", "Set your Anthropic API key");

        // The command /openrouterapikey allows the user to set their custom OpenRouter API key
        $command_manager->add_command(array("/openrouterapikey"), function($command, $key) use ($telegram, $user_config_manager) {
            $key != "" || $telegram->die("Provide an API key with the command, e.g. \"/openrouterapikey abc123\".");
            $user_config_manager->set_openrouter_api_key($key);
            $telegram->send_message("Your new OpenRouter API key has been set.");
            exit;
        }, "Settings", "Set your OpenRouter API key");

        // ###############################
        // ### Chat history management ###
        // ###############################

        // The command /clear clears the chat history
        $command_manager->add_command(array("/d", "/delall", "/clear"), function($command, $_) use ($telegram, $user_config_manager) {
            $user_config_manager->clear_messages();
            $telegram->send_message("Chat history cleared.");
            exit;
        }, "Chat history management", "Clear the internal chat history");

        // The command /delete deletes the last n messages, or the last message if no number is provided
        $command_manager->add_command(array("/del"), function($command, $n) use ($telegram, $user_config_manager) {
            if ($n == "")
                $n = 1;
            is_numeric($n) || $telegram->die("Please provide a number of messages to delete.");
            $n = intval($n);
            $n > 0 || $telegram->die("You can only delete a positive number of messages.");
            $n_messages = count($user_config_manager->get_config()->messages);
            $n = $user_config_manager->delete_messages($n);
            if ($n == 0) {
                $telegram->send_message("There are no messages to delete.");
            } else if ($n == $n_messages) {
                $telegram->send_message("All $n messages deleted.");
            } else {
                $telegram->send_message("Deleted the last $n messages.");
            }
            exit;
        }, "Chat history management", "Delete the last message from the internal chat history. You can delete multiple messages by adding a number (e.g. \"/del 3\" to delete the last 3 messages).");

        // The command /user adds a user message to the chat history
        $command_manager->add_command(array("/user", "/u"), function($command, $message) use ($telegram, $user_config_manager) {
            $message != "" || $telegram->die("Please provide a message to add.");
            $user_config_manager->add_message("user", $message);
            $telegram->send_message("Added user message to chat history.");
            exit;
        }, "Chat history management", "Add a message with \"user\" role to the internal chat history");

        // The command /assistant adds an assistant message to the chat history
        $command_manager->add_command(array("/assistant", "/a"), function($command, $message) use ($telegram, $user_config_manager) {
            $message != "" || $telegram->die("Please provide a message to add.");
            $user_config_manager->add_message("assistant", $message);
            $telegram->send_message("Added assistant message to chat history.");
            exit;
        }, "Chat history management", "Add a message with \"assistant\" role to the internal chat history");

        // The command /system adds a system message to the chat history
        $command_manager->add_command(array("/system", "/s"), function($command, $message) use ($telegram, $user_config_manager) {
            $message != "" || $telegram->die("Please provide a message to add.");
            $user_config_manager->add_message("system", $message);
            $telegram->send_message("Added system message to chat history.");
            exit;
        }, "Chat history management", "Add a message with \"system\" role to the internal chat history");

        // The command /save saves the chat history to a given session name
        $command_manager->add_command(array("/save"), function($command, $session) use ($telegram, $user_config_manager) {
            $session != "" || $telegram->die("Please provide a session name with the command.");
            $config = $user_config_manager->get_config();
            $user_config_manager->save_session($session, $config);
            $n_messages = count($config->messages);
            $telegram->send_message("Chat history saved as `$session` ({$n_messages} messages)");
            exit;
        }, "Chat history management", "Save the chat history to a session");

        // The commands /restore and /load load a saved session
        $command_manager->add_command(array("/restore", "/load"), function($command, $session) use ($telegram, $user_config_manager) {
            if ($session == "") {
                $session = "last";
            }
            // Load the session
            $new = $user_config_manager->get_session($session);
            $new !== null || $telegram->die("Session `$session` not found. Use command /sessions to see available sessions. Chat history not changed.");
            $user_config_manager->save_session();
            $user_config_manager->save_config($new);
            if ($command == "/restore") {
                $user_config_manager->delete_session($session);
            }
            $n_messages = count($new->messages);
            $telegram->send_message("Session `$session` loaded ({$n_messages} messages). You are talking to `$new->model`.");
            exit;
        }, "Chat history management", "Load a saved session (/restore deletes it after restoring, while /load keeps it)");

        // The command /sessions lists all available sessions
        $command_manager->add_command(array("/sessions"), function($command, $_) use ($telegram, $user_config_manager) {
            $sessions = $user_config_manager->get_sessions();
            $message = "Available sessions:\n";
            // Print session names and number of messages
            foreach ($sessions as $name => $config) {
                $n_messages = count($config->messages);
                $message .= "- `$name` ($n_messages messages)\n";
            }
            $message = substr($message, 0, -1);  // Delete the last newline
            $telegram->send_message($message);
            exit;
        }, "Chat history management", "List all available sessions");

        // The command /drop deletes a session
        $command_manager->add_command(array("/drop"), function($command, $session) use ($telegram, $user_config_manager) {
            $session != "" || $telegram->die("Please provide a session name with the command.");
            if ($user_config_manager->delete_session($session)) {
                $telegram->send_message("Session `$session` deleted.");
            } else {
                $telegram->send_message("Session `$session` not found.");
            }
            exit;
        }, "Chat history management", "Delete a session");

        if (!$is_admin) {
            // The command /usage allows the user to see their usage statistics
            $command_manager->add_command(array("/usage"), function($command, $month) use ($telegram, $user_config_manager) {
                if ($month == "")
                    $month = date("ym");

                preg_match("/^[0-9]{4}$/", $month) || $telegram->die("Please provide a month in the format \"YYMM\".");
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
                ($username != "" && $username[0] == "@") || $telegram->die("Please provide a username to add.");
                $username = substr($username, 1);
                if ($global_config_manager->is_allowed_user($username, "general")) {
                    $telegram->die("User @$username is already in the list of authorized users.");
                }
                $global_config_manager->add_allowed_user($username, "general");
                $telegram->send_message("Added user @$username to the list of authorized users.");
                exit;
            }, "Admin", "Add a user to access the bot (by username)");

            // The command /removeuser removes a user from the list of authorized users
            $command_manager->add_command(array("/removeuser"), function($command, $username) use ($telegram, $global_config_manager) {
                $username != "" || $telegram->die("Please provide a username to remove.");
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
                        $telegram->send_message("$cnt jobs successfully toggled.");
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
                if ($month == "")
                    $month = date("ym");

                preg_match("/^[0-9]{4}$/", $month) || $telegram->die("Please provide a month in the format \"YYMM\".");
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
            $prompt != "" || $telegram->die("Please provide a prompt with command $command.");
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
            $image_url != "" || $telegram->die("WTF-Error: Could not generate an image. Please try again later.");
            Log::image($prompt, $image_url, $telegram->get_chat_id());
            $telegram->die_if_error($image_url);
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
            if (has_error($audio_data)) {
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

        // The command /dumpmessages outputs the messages in a form that could be used to recreate the chat history
        $command_manager->add_command(array("/dm", "/dmf"), function($command, $n) use ($telegram, $user_config_manager) {
            $messages = $user_config_manager->get_config()->messages;
            // Check if there are messages
            count($messages) > 0 || $telegram->die("There are no messages to dump.");
            // If a number is provided, only dump the last n messages
            if ($n != "") {
                is_numeric($n) || $telegram->die("Please provide a number of messages to dump.");
                $n = intval($n);
                $n > 0 || $telegram->die("Please provide a positive number of messages to dump.");
                $messages = array_slice($messages, -$n);
            }
            // Send each message as a separate message
            foreach ($messages as $message) {
                if (is_string($message->content)) {
                    $content = $message->content;
                    $telegram->send_message("/$message->role $content", $command == "/dmf");
                } else if (is_array($message->content) && isset($message->content[0]->image_url)) {
                    $image_url = $message->content[0]->image_url->url;
                    $caption = $message->content[1]->text;
                    $telegram->send_message("/$message->role $caption\n$image_url", $command == "/dmf");
                } else if (is_array($message->content)) {
                    // Handle web search responses with citations
                    $formatted_text = text_from_websearch($message->content, $user_config_manager->is_post_processing());
                    $telegram->send_message("/$message->role $formatted_text", $command == "/dmf");
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
    $telegram->die_if_error($response);

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
        $telegram->send_message($response);
    }
}

?>
