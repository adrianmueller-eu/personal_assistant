<?php

// Import function "curl"
require_once __DIR__."/utils.php";

/**
 * This class manages the connection to the OpenAI API.
 */
class OpenAI {
    public $api_key;

    /**
     * Create a new OpenAI instance.
     * 
     * @param string $api_key The OpenAI API key.
     */
    public function __construct($api_key) {
        $this->api_key = $api_key;
    }

    /**
     * Send a request to the OpenAI API to create a chat completion.
     * 
     * @param string $data The data to send to the OpenAI API, as a JSON string.
     * @return object The response from the OpenAI API.
     */
    public function gpt($data) {
        // Request a chat completion from OpenAI
        // The response has the following format:
        // $server_output = '{
        //     "id": "chatcmpl-123",
        //     "object": "chat.completion",
        //     "created": 1677652288,
        //     "choices": [{
        //         "index": 0,
        //         "message": {
        //         "role": "assistant",
        //         "content": "\n\nHello there, how may I assist you today?"
        //         },
        //         "finish_reason": "stop"
        //     }],
        //     "usage": {
        //         "prompt_tokens": 9,
        //         "completion_tokens": 12,
        //         "total_tokens": 21
        //     }
        // }';

        // curl https://api.openai.com/v1/chat/completions \
        // -H "Content-Type: application/json" \
        // -H "Authorization: Bearer $OPENAI_API_KEY" \
        // -d '{
        //   "model": "gpt-3.5-turbo",
        //   "messages": [{"role": "user", "content": "Hello!"}]
        // }'

        $response = $this->send_request("chat/completions", $data);
        if (isset($response->choices)) {
            return $response->choices[0]->message->content;
        }
        return $response;
    }

    /**
     * Send a request to the OpenAI API to generate an image.
     * 
     * @param string $prompt The prompt to use for the image generation.
     * @return string The URL of the generated image.
     */
    public function dalle($prompt) {
        // Request a DALL-E image generation from OpenAI
        // The response has the following format:
        // {
        //     "created": 1680875700,
        //     "data": [
        //         {
        //         "url": "https://example.com/image.png",
        //         }
        //     ]
        // }

        // curl https://api.openai.com/v1/images/generations \
        // -H "Content-Type: application/json" \
        // -H "Authorization: Bearer $OPENAI_API_KEY" \
        // -d '{
        //   "prompt": "a white siamese cat",
        //   "n": 1,
        //   "size": "1024x1024"
        // }'
        $data = array(
            "prompt" => $prompt,
            "n" => 1,
            "size" => "1024x1024",
        );
        $data = json_encode($data);
        $response = $this->send_request("images/generations", $data);
        if (isset($response->data)) {
            $image_url = $response->data[0]->url;
            return $image_url;
        }
        return $response;
    }

    /**
     * Send a request to the OpenAI API.
     * 
     * @param string $endpoint The endpoint to send the request to.
     * @param string $data The data to send, as a JSON string.
     * @return object|string The response from the API or an error message.
     */
    private function send_request($endpoint, $data) {
        $url = "https://api.openai.com/v1/".$endpoint;
        $headers = array('Authorization: Bearer '.$this->api_key, 'Content-Type: application/json');
        $response = curl($url, $data, $headers);

        // {
        //     "error": {
        //         "message": "0.1 is not of type number - temperature",
        //         "type": "invalid_request_error",
        //         "param": null,
        //         "code": null
        //     }
        // }
        if (isset($response->error)) {
            if (is_string($data)) {
                $data = json_decode($data);
            }
            log_error(json_encode(array(
                "timestamp" => time(),
                "endpoint" => $endpoint,
                "data" => $data,
                "response" => $response,
            )));
            return 'Error: '.$response->error->message;
        }
        return $response;
    }
}