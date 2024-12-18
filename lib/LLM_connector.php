<?php

require_once __DIR__."/logger.php";
require_once __DIR__."/utils.php";
require_once __DIR__."/openai.php";
require_once __DIR__."/anthropic.php";

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
     */
    public function __construct($user, $DEBUG = False) {
        $this->DEBUG = $DEBUG;
        $this->user = $user;
    }

    /**
     * Send a request to create a chat completion of the model specified in the data.
     * 
     * @param object|array $data The data to send.
     * @return string The response from GPT or an error message (starts with "Error: ").
     */
    public function message($data) {
        if (is_array($data)) {
            $data = (object) $data;
        }
        if (str_starts_with($data->model, "gpt-") || str_starts_with($data->model, "o")) {
            // unset($data->system); // Would also need to undo the base64 -> better just copy the object for claude (also more readable data file)
            $openai = new OpenAI($this->user, $this->DEBUG);
            return $openai->gpt($data);
        } else if (str_starts_with($data->model, "claude-")) {
            $data = json_decode(json_encode($data));  // copy data object to avoid modifying the original object
            // download and base64 encode any image
            // Before, it looks like this:
            // {
            //     "role": "user",
            //     "content": [
            //         {"type": "text", "text": "What’s in this image?"},
            //         {
            //         "type": "image_url",
            //         "image_url": {
            //             "url": "https://upload.wikimedia.org/wikipedia/commons/thumb/d/dd/Gfp-wisconsin-madison-the-nature-boardwalk.jpg/2560px-Gfp-wisconsin-madison-the-nature-boardwalk.jpg",
            //             "detail": "high"
            //         },
            //         },
            //     ],
            //     }
            // Afterwards, it should look like this:
            // {"role": "user", "content": [
            //     {
            //       "type": "image",
            //       "source": {
            //         "type": "base64",
            //         "media_type": "image/jpeg",
            //         "data": "/9j/4AAQSkZJRg...",
            //       }
            //     },
            //     {"type": "text", "text": "What is in this image?"}
            //   ]}
            for ($i = 0; $i < count($data->messages); $i++) {
                $content = $data->messages[$i]->content;
                if (!is_string($content)) {
                    for ($j = 0; $j < count($content); $j++) {
                        if ($content[$j]->type == "image_url") {
                            $url = $content[$j]->image_url->url;
                            $img = file_get_contents($url);
                            // use getimagesizefromstring to get the mime type
                            // $mime = getimagesizefromstring($img)["mime"];
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
            // aggregate all system messages as a single message
            $system_message = "";
            for ($i = 0; $i < count($data->messages); $i++) {
                if ($data->messages[$i]->role == "system") {
                    $system_message .= $data->messages[$i]->content."\n\n";
                    array_splice($data->messages, $i, 1);
                    $i--;
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
                    else if (isset($data->messages[$i]->content[1]->type)) {
                        $data->messages[$i]->content[1]->cache_control = (object) array("type" => "ephemeral");
                        $cached++;
                    }
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
            if ($this->DEBUG) {
                // for debugging, get the indices of all cached messages
                $cached_indices = array();
                for ($i = 0; $i < count($data->messages); $i++) {
                    if (isset($data->messages[$i]->content[0]->cache_control)) {
                        array_push($cached_indices, $i);
                    }
                }
                $cached_indices = implode(", ", $cached_indices);
                // for ($i = 0; $i < count($data->messages); $i++) {
                //     // replace messages' text content with its length
                //     if (is_string($data->messages[$i]->content)) {
                //         $data->messages[$i]->content = strlen(json_encode($data->messages[$i]->content));
                //     }
                //     else if (isset($data->messages[$i]->content[0]->type) && $data->messages[$i]->content[0]->type == "text") {
                //         $data->messages[$i]->content[0]->text = strlen(json_encode($data->messages[$i]->content));
                //     }
                // }
                // return json_encode($data)."\n\[$cached cached \[$cached_indices]]";
                return $anthropic->claude($data)."\n\[$cached cached \[$cached_indices]]";
            }
            return $anthropic->claude($data);
        } else {
            return "Error: Invalid model.";
        }
    }

    /**
     * Send a request to generate an image.
     * 
     * @param string $prompt The prompt to use for the image generation.
     * @param string $model The model to use for the image generation.
     * @return string The URL of the image generated or an error message.
     */
    public function image($prompt, $model="dall-e-3") {
        $openai = new OpenAI($this->user, $this->DEBUG);
        return $openai->dalle($prompt, $model);
    }

    /**
     * Send a request to generate a text-to-speech audio file.
     * 
     * @param string $message The message to generate audio for.
     * @param string $model One of the available TTS models: `tts-1` or `tts-1-hd`
     * @param string $voice The voice to use when generating the audio. Supported voices are `alloy`, `echo`, `fable`, `onyx`, `nova`, and `shimmer`.
     * @param float $speed The speed at which to speak the text. The supported range of values is `[0.25, 4]`. Defaults to `1.0`.
     * @param string $response_format The format of the returned audio. Supported values are `mp3`, `ogg`, and `wav`. Defaults to `ogg`.
     */
    public function tts($message, $model = "tts-1-hd", $voice = "nova", $speed = 1.0, $response_format = "opus") {
        $openai = new OpenAI($this->user, $this->DEBUG);
        return $openai->tts($message, $model, $voice, $speed, $response_format);
    }

    /**
     * Send a request an audio file transcription.
     * 
     * @param string $audio The audio file to transcribe.
     * @param string $model The model to use for the transcription.
     * @return string The transcription of the audio file or an error message (starts with "Error: ").
     */
    public function asr($audio, $model = "whisper-1") {
        $language = $this->user->get_lang();
        $openai = new OpenAI($this->user, $this->DEBUG);
        return $openai->whisper($audio, $model, $language);
    }
}