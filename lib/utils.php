<?php

/**
 * This function is a generic wrapper for cURL POST requests. To send a file, set $field_name, $file_name, *and* $file_content.
 * 
 * @param string $url The URL to send the request to
 * @param object|array $data Data
 * @param array $headers (optional) Headers
 * @param string $field_name (optional) The name of the field
 * @param string $file_name (optional) The name of the file
 * @param string $file_content (optional) The content of the file
 * @return object|string The response from the API or an error message
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
        $data = json_encode($data);
        $headers = array_merge($headers, array(
            "Content-Type: application/json",
            "Content-Length: " . strlen($data)
        ));
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $server_output = curl_exec($ch);
    curl_close($ch);

    // Error handling
    if (curl_errno($ch)) {
        return 'Error: (curl: '.curl_errno($ch).') ' . curl_error($ch);
    }
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $response = json_decode($server_output, false);
    if ($http_code != 200 || $server_output === false) {
        if (is_object($response) && str_contains($server_output, "error")) {
            $response->http_code = $http_code;
        }
        else if (is_string($server_output)) {
            return "Error: .(http: ".$http_code.") ".$server_output;
        }
        else {
            $domain = parse_url($url, PHP_URL_HOST);
            return 'Error: No response from '.$domain;
        }
    }
    // if server_output is not a valid JSON string, return it as is
    if ($response === null) {
        return $server_output;
    }
    return $response;
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
    // Add all counters ending with "_prompt_tokens" and contains "$month"
    $cnt_prompt = 0;
    foreach ($counters as $key => $value) {
        if (str_ends_with($key, "_prompt_tokens") && str_contains($key, $month)) {
            $cnt_prompt += $value;
        }
    }
    // Add all counters ending with "_completion_tokens"
    $cnt_completion = 0;
    foreach ($counters as $key => $value) {
        if (str_ends_with($key, "_completion_tokens") && str_contains($key, $month)) {
            $cnt_completion += $value;
        }
    }
    if ($cnt_prompt == 0 && $cnt_completion == 0) {
        $message .= "no data";
    } else {
        $input_cost = 5;
        $output_cost = 15;
        $price_estimate = round($cnt_prompt / 1000000 * $input_cost + $cnt_completion / 1000000 * $output_cost, 2);
        $message .= "$cnt_prompt + $cnt_completion tokens (~".$price_estimate."€)";
        if ($show_info) {
            $message .= "\n\nCosts are rough estimates based on ".$input_cost."€ / 1M input and ".$output_cost."€ / 1M output tokens. "
            ."Actual costs are different (likely lower), since prices depend on the model and are constantly changing. See /model for more details.";
        }
    }
    return $message;
};