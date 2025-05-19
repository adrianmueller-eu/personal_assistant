<?php

// Require PDF parser library
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

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

/**
 * This function downloads a PDF from a URL, extracts the text using PdfParser and returns it.
 *
 * @param string $url The URL of the PDF file to download
 * @return string The extracted text from the PDF or error message
 */
function text_from_pdf($url) {
    // Generate a temporary file name
    $temp_file = tempnam(sys_get_temp_dir(), "pdf_");

    // Download the PDF file
    $file_content = file_get_contents($url);
    if ($file_content === false) {
        return "Error: Could not download the PDF file.";
    }

    // Save the content to the temporary file
    if (file_put_contents($temp_file, $file_content) === false) {
        return "Error: Could not save the PDF file to a temporary location.";
    }

    try {
        // Check if the PDF parser library is available
        if (!class_exists("\\Smalot\\PdfParser\\Parser")) {
            return "Error: PDF Parser library not found.";
        }

        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($temp_file);
        $text = $pdf->getText();
        unlink($temp_file);

        // Apply post-processing to improve text quality
        $text = post_process_pdf_text($text);
        return $text;
    } catch (\Exception $e) {
        // Clean up if there was an error
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
        return "Error: Could not extract text from PDF: " . $e->getMessage();
    }
}

/**
 * Post-processes extracted PDF text to improve readability and consistency
 *
 * @param string $text The raw text extracted from a PDF
 * @return string The improved text after post-processing
 */
function post_process_pdf_text($text) {
    // Remove excessive whitespace and normalize line breaks
    $text = preg_replace('/\s+/', ' ', $text);
    $text = preg_replace('/\s*\n\s*/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    // Fix common hyphenation at line breaks
    $text = preg_replace('/(\w+)-\s*\n\s*(\w+)/', '$1$2', $text);

    // Fix spacing issues around punctuation
    $text = preg_replace('/\s+([.,;:!?)])/', '$1', $text);
    $text = preg_replace('/([[(])\s+/', '$1', $text);

    // Fix common encoding problems
    $replacements = [
        '�' => "'",
        '�' => '"',
        '�' => '"',
        '�' => '-',
        '�' => '-',
        '�' => '...',
    ];

    foreach ($replacements as $from => $to) {
        $text = str_replace($from, $to, $text);
    }

    return $text;
}

/**
 * Extracts the arXiv ID from a URL or arXiv reference string
 *
 * @param string $input The arXiv URL or reference string
 * @return string|false The arXiv ID or false if not found
 */
function extract_arxiv_id($input) {
    // Try to match arxiv.org URLs of different formats
    if (preg_match('/arxiv\.org\/(?:abs|pdf)\/(\d+\.\d+(?:v\d+)?)/', $input, $matches))
        return $matches[1];
    // Try to match old-style arXiv identifiers
    if (preg_match('/arxiv:?(\d+\.\d+(?:v\d+)?)/', $input, $matches))
        return $matches[1];
    // Try to match bare arXiv IDs (YYMM.NNNNN or YYMM.NNNNNvN format)
    if (preg_match('/^(\d{4}\.\d{4,5}(?:v\d+)?)$/', $input, $matches))
        return $matches[1];
    return false;
}

/**
 * Downloads LaTeX source code from arXiv for a given paper ID
 *
 * @param string $arxiv_id The arXiv ID (e.g., "2301.00001")
 * @return string|false The LaTeX source code or false on failure
 */
function get_arxiv_source($arxiv_id) {
    // Clean the ID
    $arxiv_id = preg_replace('/v\d+$/', '', $arxiv_id); // Remove version number if present

    // Construct the URL for the source tarball
    $source_url = "https://arxiv.org/e-print/$arxiv_id";

    // Create temp directory
    $temp_dir = sys_get_temp_dir() . '/arxiv_' . uniqid();
    if (!mkdir($temp_dir, 0755, true)) {
        return "Error: Could not create temporary directory.";
    }

    // Download the source
    $temp_file = "$temp_dir/source.tar.gz";
    if (file_put_contents($temp_file, file_get_contents($source_url)) === false) {
        rmdir($temp_dir);
        return "Error: Could not download arXiv source.";
    }

    // Extract the archive
    $output = [];
    $return_var = 0;
    exec("tar -xzf $temp_file -C $temp_dir", $output, $return_var);
    if ($return_var !== 0) {
        // Try alternate file formats
        exec("tar -xf $temp_file -C $temp_dir", $output, $return_var);
        if ($return_var !== 0) {
            array_map('unlink', glob("$temp_dir/*"));
            rmdir($temp_dir);
            return "Error: Failed to extract arXiv source archive.";
        }
    }

    // Find main TeX file
    $tex_files = glob("$temp_dir/*.tex");
    if (empty($tex_files)) {
        // Look in subdirectories
        $tex_files = glob("$temp_dir/*/*.tex");
        if (empty($tex_files)) {
            array_map('unlink', glob("$temp_dir/*"));
            rmdir($temp_dir);
            return "Error: No TeX files found in the archive.";
        }
    }

    // Prioritize files that might be the main file
    $main_file = null;
    foreach ($tex_files as $file) {
        $content = file_get_contents($file);
        if (strpos($content, '\documentclass') !== false ||
            strpos($content, '\begin{document}') !== false) {
            $main_file = $file;
            break;
        }
    }

    // If no main file found, use the first one
    if ($main_file === null && !empty($tex_files)) {
        $main_file = $tex_files[0];
    }

    if ($main_file === null) {
        array_map('unlink', glob("$temp_dir/*"));
        rmdir($temp_dir);
        return "Error: Could not identify main TeX file.";
    }

    // Read the file
    $tex_content = file_get_contents($main_file);

    // Clean up
    array_map('unlink', glob("$temp_dir/*"));
    array_map('unlink', glob("$temp_dir/*/*"));
    array_map('rmdir', glob("$temp_dir/*"));
    rmdir($temp_dir);

    // Process the TeX content
    if ($tex_content) {
        return clean_tex_content($tex_content);
    } else {
        return "Error: Could not read TeX content.";
    }
}

/**
 * Cleans and extracts the useful content from TeX source
 *
 * @param string $tex_content The raw TeX content
 * @return string The cleaned TeX content
 */
function clean_tex_content($tex_content) {
    // Extract content between \begin{document} and \end{document}
    if (preg_match('/\\\\begin\s*{\s*document\s*}(.+)\\\\end\s*{\s*document\s*}/s', $tex_content, $matches)) {
        $tex_content = $matches[1];
    }
    // Remove comments
    $tex_content = preg_replace('/(?<!\\\\)%.*$/m', '', $tex_content);
    // Remove some common TeX macros that might confuse the model
    $tex_content = preg_replace('/\\\\maketitle/', '', $tex_content);
    return $tex_content;
}

/**
 * Processes an arXiv link, downloads the TeX source, and returns formatted content
 *
 * @param string $url The arXiv URL or ID
 * @return string The formatted content or error message
 */
function process_arxiv_link($url) {
    $arxiv_id = extract_arxiv_id($url);

    if (!$arxiv_id) {
        return "Error: Could not extract valid arXiv ID from: $url";
    }

    $source = get_arxiv_source($arxiv_id);

    if (is_string($source) && strpos($source, "Error:") === 0) {
        return $source;
    }

    // Get paper metadata from arXiv API
    $paper_title = "arXiv:$arxiv_id";
    $paper_url = "https://export.arxiv.org/api/query?id_list=$arxiv_id";

    $xml = @simplexml_load_file($paper_url);
    if ($xml && isset($xml->entry->title)) {
        $paper_title = (string)$xml->entry->title;
    }

    return [
        'title' => $paper_title,
        'id' => $arxiv_id,
        'content' => $source
    ];
}

/**
 * Checks if the given text starts with 'Error: '
 *
 * @param string $text
 * @return bool
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
function text_from_websearch($array_response, $use_post_processing) {
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
                        $citations[] = [
                            'url' => $citation->url,
                            'title' => $citation->title,
                            'text' => $citation->cited_text ?? "",
                            'id' => $citation_id
                        ];

                        // Add a reference number after the text
                        $text .= " [" . $citation_id . "]";
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
            $formatted_text .= "[" . $citation['id'] . "] [" . $citation['title'] . "](" . $citation['url'] . ")";

            // Format cited text based on post-processing setting
            if (!empty($citation['text'])) {
                if ($use_post_processing) {
                    // Use Telegram markdown v2 quote for the citation
                    $formatted_text .= "\n>";
                    $formatted_text .= str_replace("\n", "\n>", $citation['text']);
                } else {
                    // Use code blocks
                    $formatted_text .= "\n```\n" . $citation['text'] . "\n```";
                }
            }
            $formatted_text .= "\n\n";
        }
    }

    return $formatted_text;
}
