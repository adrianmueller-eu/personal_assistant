<?php

/**
 * Perform cURL POST requests. To send a file, set $field_name, $file_name, *and* $file_content.
 *
 * @param string $url The URL to send the request to
 * @param object|array $data Data
 * @param array $headers (optional) Headers
 * @param string $field_name (optional) The name of the field
 * @param string $file_name (optional) The name of the file
 * @param string $file_content (optional) The content of the file
 * @return object|string The response from the API, error object with 'error' property, or error string
 */
function curl_post($url, $data, $headers = array(), $field_name = null, $file_name = null, $file_content = null) {
    if ($field_name != null && $file_name != null && $file_content != null) {
        $boundary = '-------------' . uniqid();
        $data = build_data_files($boundary, $data, $field_name, $file_name, $file_content);
        $headers = array_merge($headers, array(
            "Content-Type: multipart/form-data; boundary=" . $boundary,
            "Content-Length: " . strlen($data)
        ));
    } else {
        $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $headers = array_merge($headers, array(
            "Content-Type: application/json",
            "Content-Length: " . strlen($data)
        ));
    }
    return curl($url, $headers, $data);
}

/**
 * Perform cURL requests.
 *
 * @param string $url The URL to send the request to
 * @param array $headers Headers for the request
 * @param mixed $data Data for POST requests (null for GET)
 * @return object|string Response data, error object with 'error' property, or error string
 */
function curl($url, $headers = array(), $data = null) {
    if (!filter_var($url, FILTER_VALIDATE_URL))
        return 'Error: Invalid URL format';
    $ch = curl_init($url);
    if ($ch === false)
        return 'Error: Failed to initialize cURL';

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    // curl_setopt($ch, CURLOPT_TIMEOUT, 90); // Add timeout to prevent hanging requests

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    } else {
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);  // Follow redirects for GET
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);          // Maximum number of redirects to follow
    }

    $server_output = curl_exec($ch);

    // Error handling
    if (curl_errno($ch)) {
        $response = 'Error: (curl: '.curl_errno($ch).') '.curl_error($ch);
    } else {
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $response = json_decode($server_output, false);
        if ($http_code < 200 || $http_code >= 300 || $server_output === false) {
            if (isset($response->error) || isset($response->ok))
                return $response;
            $mes = $server_output ?? "No valid response from ".parse_url($url, PHP_URL_HOST);
            return "Error: (http: $http_code) $mes";
        }
    }
    curl_close($ch);
    return $response ?? $server_output;
}

// Thanks to https://stackoverflow.com/questions/17862004/send-file-using-multipart-form-data-request-in-php
function build_data_files($boundary, $fields, $field_name, $file_name, $file_content){
    $data = '';
    $eol = "\r\n";

    foreach ($fields as $name => $content) {
        $data .= "--" . $boundary . $eol
            . 'Content-Disposition: form-data; name="' . $name . "\"".$eol.$eol
            . $content . $eol;
    }

    $data .= "--" . $boundary . $eol
        . 'Content-Disposition: form-data; name="' . $field_name . '"; filename="' . $file_name . '"' . $eol
        //. 'Content-Type: image/png'.$eol
        . 'Content-Transfer-Encoding: binary'.$eol;
    $data .= $eol;
    $data .= $file_content . $eol;
    $data .= "--" . $boundary . "--".$eol;

    return $data;
}

/**
 * This function returns the difference between two timestamps in a human readable format.
 *
 * @param int $timeA The first timestamp.
 * @param int $timeB The second timestamp.
 * @return string The difference between the two timestamps.
 */
function time_diff($timeA, $timeB) {
    $time = abs($timeB - $timeA);
    $time = ($time<1)? 1 : $time;
    $tokens = array (
        31536000 => 'year',
        2592000 => 'month',
        604800 => 'week',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute',
        1 => 'second'
    );
    foreach ($tokens as $unit => $text) {
        if ($time < $unit) continue;
        // echo "1 $text is about $unit seconds, so $time is $time / $unit ".$text."s";
        $numberOfUnits = round($time / $unit);
        return $numberOfUnits.' '.$text.(($numberOfUnits>1)?'s':'');
    }
}

function get_usage_string($user, $month, $show_info) {
    $message = "";
    // Read the counters "openai_chat_prompt_tokens", "openai_chat_completion_tokens", and "openai_chat_total_tokens"
    $counters = $user->get_counters();
    $cnt_prompt = 0;
    $cnt_completion = 0;
    $web_search_requests = 0;
    foreach ($counters as $key => $value) {
        if (str_contains($key, $month)) {
            if (str_ends_with($key, "_prompt_tokens") || str_ends_with($key, "_input_tokens")) {
                $cnt_prompt += $value;
            }
            else if (str_ends_with($key, "_completion_tokens") || str_ends_with($key, "_output_tokens")) {
                $cnt_completion += $value;
            }
            else if (str_ends_with($key, "_web_search_requests")) {
                $web_search_requests += $value;
            }
        }
    }
    if ($cnt_prompt == 0 && $cnt_completion == 0)
        return "no data";

    $input_cost = 3;
    $output_cost = 15;
    $price_estimate = round($cnt_prompt / 1000000 * $input_cost + $cnt_completion / 1000000 * $output_cost, 2);
    $message .= "$cnt_prompt + $cnt_completion tokens (~".$price_estimate."€)";
    if ($show_info) {
        $message .= "\n\nCosts are rough estimates based on ".$input_cost."€ / 1M input and ".$output_cost."€ / 1M output tokens. "
        ."Actual costs are different (likely lower), since prices depend on the model and are constantly changing. See /model for more details.";
    }
    $message .= "\nTotal web search requests: $web_search_requests";
    return $message;
};

/**
 * Checks if the given text starts with 'Error: '
 *
 * @param string|mixed $text The text to check
 * @return bool True if the text starts with 'Error: ', false otherwise
 */
function has_error($text) {
    return is_string($text) && substr($text, 0, 7) == "Error: ";
}

/**
 * Convert a structured websearch response with citations into plain text format
 *
 * @param array $array_response The structured websearch response from a model like Claude
 * @param bool $use_post_processing Whether to use post-processing formatting (quotes vs code blocks)
 * @return string The formatted text with citations
 */
function text_from_claude_websearch($array_response, $use_post_processing) {
    $formatted_text = "";
    $citations = [];

    // Process the array response
    foreach ($array_response as $item) {
        if (isset($item->type) && $item->type === "text") {
            // Handle text with citations
            if (isset($item->citations) && is_array($item->citations)) {
                $text = $item->text;
                foreach ($item->citations as $citation) {
                    if (isset($citation->url) && isset($citation->title)) {
                        // Create a unique ID for this citation
                        $citation_id = count($citations) + 1;
                        $citations[] = (object) [
                            'url' => $citation->url,
                            'title' => $citation->title ?: $citation->url,
                            // 'text' => $citation->cited_text,
                            'id' => $citation_id
                        ];

                        // Add a reference number after the text
                        $text .= " [[$citation_id]({$citation->url})]";
                    }
                }
                $formatted_text .= $text;
            } else {
                // Regular text without citations
                $formatted_text .= $item->text;
            }
        }
    }

    // Append citations at the end
    if (!empty($citations)) {
        $formatted_text .= "\n\n*Sources:*\n";
        foreach ($citations as $citation) {
            $formatted_text .= "[{$citation->id}] [{$citation->title}]({$citation->url})\n";

            // // Format cited text based on post-processing setting
            // if (!empty($citation->text)) {
            //     if ($use_post_processing) {
            //         // Use Telegram markdown v2 quote for the citation
            //         $formatted_text .= "\n>";
            //         $formatted_text .= str_replace("\n", "\n>", $citation->text);
            //     } else {
            //         // Use code blocks
            //         $formatted_text .= "\n```\n{$citation->text}\n```";
            //     }
            // }
            // $formatted_text .= "\n\n";
        }
    }
    return $formatted_text;
}

/**
 * Converts an OpenAI websearch output object to a string with citation markers and sources.
 *
 * @param object $content An object with properties:
 *   - text: string
 *   - annotations: array of objects, each with start_index, end_index, url, title, and optionally text
 *
 * Note: $content must be an object, not an array.
 */
function text_from_openai_websearch($content) {
    $text = $content->text;
    $annotations = $content->annotations;
    if (empty($annotations))
        return $text;
    // Sort annotations by start_index ascending
    usort($annotations, function($a, $b) {
        return $a->start_index <=> $b->start_index;
    });
    $result = '';
    $last_index = 0;
    $citations = [];
    foreach ($annotations as $i => $ann) {
        $start = $ann->start_index;
        $end = $ann->end_index;
        // Clean the URL of utm_source=openai
        $url = preg_replace('/[&?]utm_source=openai$/', '', $ann->url);
        // Add text up to the start of the citation span
        $result .= mb_substr($text, $last_index, $start - $last_index);
        // Replace the citation span with the citation marker
        $citation_id = $i + 1;
        $result .= "[[{$citation_id}]({$url})]";
        $citations[] = (object) [
            'id' => $citation_id,
            'title' => $ann->title ?: $url,
            'url' => $url
        ];
        $last_index = $end;
    }
    $result .= mb_substr($text, $last_index);
    if (count($citations) > 0) {
        $result .= "\n\n*Sources:*\n";
        foreach ($citations as $citation) {
            $result .= "[{$citation->id}] [{$citation->title}]({$citation->url})\n";
        }
    }
    return $result;
}


/**
 * Return a copy of $data in which long message contents are trimmed. Useful for logging or display purposes.
 *
 * @param object $data The data object containing messages to process.
 * @param int $max_length The maximum length for message content (default: 200).
 * @return object The processed data object with truncated message contents.
 */
function strip_long_messages($data, $max_length=20) {
    $data = json_decode(json_encode($data, JSON_UNESCAPED_UNICODE));  // deep copy
    foreach ($data->messages as $message) {
        // Handle string content
        if (is_string($message->content)) {
            $message->content = substr($message->content, 0, $max_length) . '...';
        }
        // Handle array content
        else if (is_array($message->content)) {
            foreach ($message->content as $key => $item) {
                if (isset($item->text)) {
                    $item->text = substr($item->text, 0, $max_length) . '...';
                }
                else if (isset($item->source) && isset($item->source->data)) {
                    $item->source->data = strlen(json_encode($item->source->data, JSON_UNESCAPED_UNICODE)).' bytes';
                }
                else {
                    $len = strlen(json_encode($item, JSON_UNESCAPED_UNICODE));
                    if ($len > $max_length) {
                        $message->content[$key] = "$len bytes";
                    }
                }
            }
        }
    }
    if (isset($data->system)) {
        $data->system = substr($data->system, 0, $max_length) . '...';
    }
    return $data;
}

/**
 * Get statistics for messages: number of messages, words, and tokens.
 *
 * @param mixed $input String, array of messages, or array of objects with 'content'
 * @return array ['messages' => int, 'words' => int, 'tokens' => int]
 */
function get_message_stats($input) {
    // If input is a string, treat as one message
    if (is_string($input)) {
        $n_messages = ($input === '') ? 0 : 1;
    }
    // If input is an array of messages
    else {
        $input = array_map(function($msg) {
            if (is_array($msg->content)) {
                // If content is array (e.g. parts), join their text fields
                return implode("\n", array_map(function($part) {
                    return is_object($part) && isset($part->text) ? $part->text : '';
                }, $msg->content));
            }
            return $msg->content;
        }, $input);
        $n_messages = count($input);
        $input = implode("\n", $input);
    }

    return [
        'messages' => $n_messages,
        'words'    => str_word_count($input),
        'tokens'   => LLMConnector::approximate_token_count($input),
    ];
}
