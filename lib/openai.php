<?php

require_once __DIR__."/logger.php";
require_once __DIR__."/utils.php";

/**
 * This class manages the connection to the OpenAI API.
 */
class OpenAI {
    public $api_key;
    public $DEBUG;

    /**
     * Create a new OpenAI instance.
     * 
     * @param string $api_key The OpenAI API key.
     */
    public function __construct($api_key, $DEBUG = False) {
        $this->api_key = $api_key;
        $this->DEBUG = $DEBUG;
    }

    /**
     * Send a request to the OpenAI API to create a chat completion.
     * 
     * @param object|array $data The data to send to the OpenAI API.
     * @param UserConfigManager $user_config_manager The user config manager, to count the usage.
     * @return string The response from GPT or an error message (starts with "Error: ").
     */
    public function gpt($data, $user_config_manager) {
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
            // Get a month year string
            $month = date("ym");
            // Count the usages
            $user_config_manager->increment("openai_".$month."_chat_prompt_tokens", $response->usage->prompt_tokens);
            $user_config_manager->increment("openai_".$month."_chat_completion_tokens", $response->usage->completion_tokens);
            $user_config_manager->increment("openai_".$month."_chat_total_tokens", $response->usage->total_tokens);
            return $response->choices[0]->message->content;
        }
        return $response;
    }

    /**
     * Send a request to the OpenAI API to generate an image.
     * 
     * @param string $prompt The prompt to use for the image generation.
     * @param string $model The model to use for the image generation.
     * @return string The URL of the image generated by DALL-E or an error message.
     */
    public function dalle($prompt, $model) {
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
            "model" => $model,
            "prompt" => $prompt,
            "n" => 1,
            "size" => "1024x1024",
        );
        $response = $this->send_request("images/generations", $data);
        if (isset($response->data)) {
            $image_url = $response->data[0]->url;
            return $image_url;
        }
        return $response;
    }

    /**
     * Send a request to the OpenAI API to generate a text-to-speech audio file.
     * 
     * @param string $message The message to generate audio for.
     * @param string $model One of the available TTS models: `tts-1` or `tts-1-hd`
     * @param string $voice The voice to use when generating the audio. Supported voices are `alloy`, `echo`, `fable`, `onyx`, `nova`, and `shimmer`.
     * @param float $speed The speed at which to speak the text. The supported range of values is `[0.25, 4]`. Defaults to `1.0`.
     * @param string $response_format The format of the returned audio. Supported values are `mp3`, `ogg`, and `wav`. Defaults to `ogg`.
     */
    public function tts($message, $model = "tts-1-hd", $voice = "nova", $speed = 1.0, $response_format = "opus") {
        $data = array(
            "model" => $model,
            "input" => $message,
            "voice" => $voice,
            "speed" => $speed,
            "response_format" => $response_format
        );
        $response = $this->send_request("audio/speech", $data);
        Log::info("TTS of $message with model $model and voice $voice at speed $speed returned a response of length ".strlen($response));
        return $response;
    }

    /**
     * Send a request to the OpenAI API to transcribe an audio file.
     * 
     * @param string $audio The audio file to transcribe.
     * @param string $model The model to use for the transcription. Currently only `whisper-1`.
     * @param string $language The language of the audio file. Supplying the input language in ISO-639-1 format will improve accuracy and latency.
     * @return string The transcription of the audio file or an error message (starts with "Error: ").
     */
    public function whisper($audio, $model = "whisper-1", $language = "en") {
        $data = array(
            "model" => $model,
            "language" => $language,
        );
        $response = $this->send_request("audio/transcriptions", $data, "file", "audio.ogg", $audio);
        if (isset($response->text)) {
            return $response->text;
        }
        return $response;
    }

    /**
     * Send a request to the OpenAI API.
     * 
     * @param string $endpoint The endpoint to send the request to.
     * @param object|array $data The data to send.
     * @param string $field_name (optional) The name of the field with the file content.
     * @param string $file_name (optional) The name of the file to send.
     * @param string $file_content (optional) The content of the file to send.
     * @return object|string The response object from the API or an error message (starts with "Error: ").
     */
    private function send_request($endpoint, $data, $field_name = null, $file_name = null, $file_content = null) {
        $url = "https://api.openai.com/v1/$endpoint";
        $headers = array('Authorization: Bearer '.$this->api_key);

        $response = curl_post($url, $data, $headers, $field_name, $file_name, $file_content);
        if ($this->DEBUG) {
            $response_log = json_encode($response);
            if (strlen($response_log) > 10000) {
                $response_log = substr($response_log, 0, 10000)."...";
            }
            Log::debug(array(
                "interface" => "openai",
                "endpoint" => $endpoint,
                "data" => $data,
                "response" => $response_log,
            ));
        }

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
            Log::error(array(
                "interface" => "openai",
                "endpoint" => $endpoint,
                "data" => $data,
                "response" => $response,
            ));
            // Return the error message
            return 'Error: '.$response->error->message;
        }
        return $response;
    }
}