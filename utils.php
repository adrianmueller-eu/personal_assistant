<?php

/**
 * This function is a generic wrapper for cURL requests.
 * 
 * @param string $url The URL to send the request to.
 * @param string $data The data to send, as a JSON string.
 * @param array $headers The headers to send.
 * @return object|string The response from the API or an error message.
 */
function curl($url, $data, $headers) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $server_output = curl_exec($ch);
    curl_close($ch);
    if (curl_errno($ch)) {
        return 'Error: ('.curl_errno($ch).')' . curl_error($ch);
    }
    $response = json_decode($server_output, false);
    if (!$response) {
        $domain = parse_url($url, PHP_URL_HOST);
        return 'Error: No response from '.$domain;
    }
    return $response;
}

?>