<?php

require_once __DIR__."/logger.php";
require_once __DIR__."/utils.php";
require_once __DIR__."/openai.php";
require_once __DIR__."/anthropic.php";
require_once __DIR__."/openrouter.php";

/**
 * This class manages the connection to the large language models (LLM).
 */
class LLMConnector {
    public $DEBUG;
    public $user;

    /**
     * Create a new instance.
     *
     * @param UserConfigManager $user The user to use for the requests.
     * @param bool $DEBUG Whether to enable debug mode.
     */
    public function __construct($user, $DEBUG = False) {
        $this->DEBUG = $DEBUG;
        $this->user = $user;
    }

    /**
     * Send a request to create a chat completion with the model specified in the data.
     *
     * @param object|array $data The data to send.
     * @param bool $enable_websearch Whether to enable websearch in the output (default: false).
     * @return string|array The response from the model or an error message (starts with "Error: ").
     */
    public function message($data, $enable_websearch = false): string|array {
        $data = json_decode(json_encode($data), false);  // copy data do not modify the original object

        // If not using Claude, convert any structured array content in messages to text
        if (!str_starts_with($data->model, "claude-") && isset($data->messages)) {
            foreach ($data->messages as $message) {
                if (is_array($message->content)) {
                    $message->content = text_from_websearch($message->content, true);
                }
            }
        }

        if (str_starts_with($data->model, "gpt-")) {
            $result = $this->parse_gpt($data);
        } else if (preg_match("/^o\d/", $data->model)) {
            $result = $this->parse_o($data);
        } else if (str_starts_with($data->model, "claude-")) {
            $result = $this->parse_claude($data, $enable_websearch);
        } else {
            $result = $this->parse_openrouter($data);
        }

        if (is_string($result))
            return $result;

        $this->user->set_last_thinking_output($result['thinking'] ?? "");  // always override previous reasoning output
        return $result['content'];
    }

    /**
     * Parse requests for gpt-* models.
     *
     * @param object|array $data
     * @return string|array
     */
    private function parse_gpt($data) {
        $openai = new OpenAI($this->user, $this->DEBUG);
        $content = $openai->gpt($data);
        if (is_string($content)) {
            return $content;
        }
        return [
            'content' => $content,
            'thinking' => ""
        ];
    }

    /**
     * Parse requests for o* models.
     *
     * @param object|array $data
     * @return string|array
     */
    private function parse_o($data): string|array {
        // replace all "system" roles with "developer"
        for ($i = 0; $i < count($data->messages); $i++) {
            if ($data->messages[$i]->role == "system") {
                $data->messages[$i]->role = "developer";
            }
        }
        // remove temperature parameter
        if (isset($data->temperature)) {
            unset($data->temperature);
        }
        $data->reasoning_effort = "high";  # "low", "medium", "high"
        $openai = new OpenAI($this->user, $this->DEBUG);
        $content = $openai->gpt($data);
        if (is_string($content)) {
            return $content;
        }
        return [
            'content' => $content,
            'thinking' => "OpenAI doesn't provide reasoning output."
        ];
    }

    /**
     * Parse requests for claude-* models.
     *
     * @param object|array $data The data to send.
     * @param bool $enable_websearch Whether to enable websearch in the output (default: false).
     * @return string|array The response from the model or an error message.
     */
    private function parse_claude($data, $enable_websearch = false): string|array {
        // Allow thinking if the desired
        if (str_ends_with($data->model, "-thinking")) {
            // remove the "-thinking" suffix
            $data->model = substr($data->model, 0, -9);
            // set the thinking parameter
            $data->thinking = (object) array(
                "type" => "enabled",
                "budget_tokens" => 32000
            );
            // remove temperature parameter
            if (isset($data->temperature)) {
                unset($data->temperature);
            }
        }

        // download and base64 encode any image
        for ($i = 0; $i < count($data->messages); $i++) {
            $content = $data->messages[$i]->content;
            if (!is_string($content)) {
                for ($j = 0; $j < count($content); $j++) {
                    if ($content[$j]->type == "image_url") {
                        $url = $content[$j]->image_url->url;
                        $img = file_get_contents($url);
                        $image = base64_encode($img);
                        $data->messages[$i]->content[$j] = array(
                            "type" => "image",
                            "source" => array(
                                "type" => "base64",
                                "media_type" => "image/jpeg",
                                "data" => $image
                            )
                        );
                    }
                }
            }
        }

        // Collect initial system messages
        $system_message = "";
        while (count($data->messages) > 0 && $data->messages[0]->role === "system") {
            if (is_string($data->messages[0]->content)) {
                if ($system_message !== "") {
                    $system_message .= "\n\n";
                }
                $system_message .= $data->messages[0]->content;
            }
            array_splice($data->messages, 0, 1);
        }
        if ($system_message !== "") {
            $data->system = $system_message;
        }

        // Convert any remaining system messages to user messages with SYSTEM GUIDANCE prefix
        for ($i = 0; $i < count($data->messages); $i++) {
            $mes = $data->messages[$i];
            if ($mes->role === "system") {
                $mes->role = "user";
                if (is_string($mes->content)) {
                    $mes->content = "SYSTEM GUIDANCE: " . $mes->content;
                }
                else {
                    for ($j = 0; $j < count($mes->content); $j++) {
                        if (isset($mes->content[$j]->text)) {
                            $mes->content[$j]->text = "SYSTEM GUIDANCE: " . $mes->content[$j]->text;
                        }
                    }
                }
            }
        }

        // aggregate directly consecutive messages of the same role
        for ($i = 0; $i < count($data->messages) - 1; $i++) {
            if ($data->messages[$i]->role === $data->messages[$i + 1]->role) {
                // First message: convert string to array with text object
                if (is_string($data->messages[$i]->content)) {
                    $data->messages[$i]->content = array((object) array(
                        "type" => "text",
                        "text" => $data->messages[$i]->content
                    ));
                }

                // Second message: convert string to array with text object
                if (is_string($data->messages[$i + 1]->content)) {
                    $data->messages[$i + 1]->content = array((object) array(
                        "type" => "text",
                        "text" => $data->messages[$i + 1]->content
                    ));
                }

                // Merge the content arrays
                $data->messages[$i]->content = array_merge($data->messages[$i]->content, $data->messages[$i + 1]->content);
                array_splice($data->messages, $i + 1, 1);
                $i--; // Recheck the current index since we removed an element
            }
        }

        // set prompt caching for every sixth message
        $cached = 0;
        for ($i = 0; $i < count($data->messages); $i++) {
            if ($i % 6 == 4) {
                if (is_string($data->messages[$i]->content)) {
                    $data->messages[$i]->content = array((object) array(
                        "type" => "text",
                        "text" => $data->messages[$i]->content,
                        "cache_control" => (object) array("type" => "ephemeral")
                    ));
                    $cached++;
                }
                // images
                else if (isset($data->messages[$i]->content[0]->type)) {
                    $data->messages[$i]->content[0]->cache_control = (object) array("type" => "ephemeral");
                    $cached++;
                }
            }
        }
        // remove cache control from the first messages if there are too many cached messages
        for ($i = 4; $i < count($data->messages) && $cached > 4; $i += 6) {
            if (isset($data->messages[$i]->content[0]->cache_control)) {
                unset($data->messages[$i]->content[0]->cache_control);
                $cached--;
            }
        }

        $anthropic = new Anthropic($this->user, $this->DEBUG);
        $content = $anthropic->claude($data, $enable_websearch);
        if (is_string($content)) {
            return $content;
        }

        $text = "";
        $thinking = "";
        // Process content based on whether it's an array or simple text
        for ($i = 0; $i < count($content); $i++) {
            if (isset($content[$i]->text)) {
                $text .= $content[$i]->text;
            } else if (isset($content[$i]->thinking)) {
                $thinking = $content[$i]->thinking;
            }
        }

        if ($this->DEBUG) {
            // get the indices of all cached messages
            $cached_indices = array();
            for ($i = 0; $i < count($data->messages); $i++) {
                if (isset($data->messages[$i]->content[0]->cache_control)) {
                    array_push($cached_indices, $i);
                }
            }
            $cached_indices = implode(", ", $cached_indices);
            $text .= "\nCached indices: ".$cached_indices;
        }

        return [
            'content' => $enable_websearch ? $content : $text,
            'thinking' => $thinking
        ];
    }

    /**
     * Parse requests for openrouter models (fallback).
     *
     * @param object|array $data
     * @return string|array
     */
    private function parse_openrouter($data) {
        $openrouter = new OpenRouter($this->user, $this->DEBUG);
        $data->reasoning = (object) array(
            "effort" => "high"
        );
        $message = $openrouter->message($data);
        if (is_string($message)) {
            return $message;
        }
        return [
            'content' => $message->content,
            'thinking' => $message->reasoning ?? ""
        ];
    }

    /**
     * Send a request to generate an image.
     *
     * @param string $prompt The prompt to use for the image generation.
     * @param string $model The model to use for the image generation.
     * @return string The URL of the image generated or an error message.
     */
    public function image($prompt, $model="dall-e-3") {  # needs ID upload for gpt-image-1
        $openai = new OpenAI($this->user, $this->DEBUG);
        return $openai->dalle($prompt, $model);
    }

    /**
     * Send a request to generate a text-to-speech audio file.
     *
     * @param string $message The message to generate audio for.
     * @param string $model One of the available TTS models: `gpt-4o-mini-tts`, `tts-1`, or `tts-1-hd`
     * @param string $voice The voice to use when generating the audio. Supported voices are `alloy`, `echo`, `fable`, `onyx`, `nova`, and `shimmer`.
     * @param float $speed The speed at which to speak the text. The supported range of values is `[0.25, 4]`. Defaults to `1.0`.
     * @param string $response_format The format of the returned audio. Supported values are `mp3`, `ogg`, and `wav`. Defaults to `ogg`.
     * @return string The URL of the audio file generated or an error message.
     */
    public function tts($message, $model, $voice, $speed, $response_format = "opus") {
        $openai = new OpenAI($this->user, $this->DEBUG);
        return $openai->tts($message, $model, $voice, $speed, $response_format);
    }

    /**
     * Send a request an audio file transcription.
     *
     * @param string $audio The audio file to transcribe.
     * @param string $model The model to use for the transcription: `gpt-4o-transcribe`, `gpt-4o-mini-transcribe`, or `whisper-1`.
     * @return string The transcription of the audio file or an error message (starts with "Error: ").
     */
    public function asr($audio, $model = "gpt-4o-mini-transcribe") {
        $language = $this->user->get_lang();
        $openai = new OpenAI($this->user, $this->DEBUG);
        return $openai->whisper($audio, $model, $language);
    }

    /**
     * Search for academic papers using the Semantic Scholar API
     *
     * @param string $query The search query
     * @param int $limit Maximum number of results to return (default: 12)
     * @param string $api_key Optional API key for Semantic Scholar
     * @param int $max_retries Maximum number of retries for rate limit errors (default: 5)
     * @return array|string Array of papers with their details or error string
     */
    public function semantic_scholar_search($query, $limit = 12, $api_key = null, $max_retries = 15) {
        // API endpoint for Semantic Scholar search
        $url = "https://api.semanticscholar.org/graph/v1/paper/search";

        // Prepare the query parameters
        $params = [
            "query" => $query,
            "limit" => $limit,
            "fields" => "url,title,abstract,year,authors,citationCount"
        ];

        // Add query parameters to URL
        $url .= '?' . http_build_query($params);

        // Prepare headers
        $headers = ["Accept: application/json"];

        // Use API key if provided, otherwise use null
        if ($api_key) {
            $headers[] = "x-api-key: " . $api_key;
        }

        // Make the GET request with retries for rate limit errors
        $retries = 0;
        while (true) {
            $response = curl_get($url, $headers);

            // If successful or error other than rate limit, break the loop
            if (!has_error($response) || strpos($response, "(http: 429)") === false) {
                break;
            }

            // For rate limit errors, retry if we haven't exceeded max_retries
            if (++$retries >= $max_retries || $api_key) {
                // Only retry when no API key is provided
                break;
            }

            // Add a short delay between retries (increasing with each retry)
            usleep(500000 * $retries); // 0.5, 1, 1.5, 2, 2.5 seconds
        }

        // Handle API errors after all retries
        if (has_error($response)) {
            // Check if this is a rate limit error (429)
            if (strpos($response, "(http: 429)") !== false) {
                return "Error: Semantic Scholar API rate limit exceeded ($retries). Please try again later.";
            }
            return $response;
        }

        if (!isset($response->data) || empty($response->data)) {
            return [];
        }

        // Format the papers data as expected by the command handler
        $papers = [];
        foreach ($response->data as $paper) {
            $authors_list = [];
            if (isset($paper->authors) && is_array($paper->authors)) {
                foreach ($paper->authors as $author) {
                    if (isset($author->name)) {
                        $authors_list[] = $author->name;
                    }
                }
            }

            // Format authors as a string (e.g., "Author1, Author2, et al.")
            $authors_text = implode(", ", array_slice($authors_list, 0, 3));
            if (count($authors_list) > 3) {
                $authors_text .= " et al.";
            }

            $papers[] = [
                'url' => $paper->url ?? '',
                'title' => $paper->title ?? '',
                'abstract' => $paper->abstract ?? '',
                'year' => $paper->year ?? '',
                'authors' => $authors_text,
                'citationCount' => $paper->citationCount
            ];
        }
        return $papers;
    }
}
