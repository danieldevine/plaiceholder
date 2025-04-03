<?php

namespace Plaiceholder\Commands;

use WP_CLI;
use WP_CLI_Command;
use WP_Query;

class CreatePlaceholder extends WP_CLI_Command
{
    /**
     * Create placeholder posts using OpenAI.
     *
     * ## OPTIONS
     *
     * [--post_type=<type>]
     * : Post type to create. Default is 'post'.
     *
     * [--count=<number>]
     * : Number of posts to create. Default is 5.
     *
     * [--topic=<prompt>]
     * : A short topic for generating content.
     *
     * [--api_key=<key>]
     * : Your OpenAI API key (optional if set via OPENAI_API_KEY).
     *
     * ## EXAMPLES
     *
     * wp plaiceholder create --post_type=book --count=5 --topic="Chris De Burgh is an important figure in world history"
     */
    public function __invoke($args, $assoc_args)
    {
        $post_type = $assoc_args['post_type'] ?? 'post';
        $count     = intval($assoc_args['count'] ?? 5);
        $prompt    = $assoc_args['topic'] ?? null;
        $api_key   = $assoc_args['api_key'] ?? (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : null);

        if (!$prompt || !$api_key) {
            WP_CLI::error("Both --topic and --api_key (or OPENAI_API_KEY constant) are required.");
        }

        WP_CLI::log("Generating {$count} '{$post_type}' posts from topic: \"{$prompt}\"");

        for ($i = 1; $i <= $count; $i++) {
            $content_data = $this->generate_post_data($prompt, $api_key);

            if (!$content_data) {
                WP_CLI::warning("Skipping post #{$i} due to generation failure.");
                continue;
            }

            $this->cache_response($prompt, $content_data);

            $post_id = wp_insert_post([
                'post_title'   => $content_data['title'],
                'post_content' => $content_data['content'],
                'post_excerpt' => $content_data['excerpt'],
                'post_status'  => 'publish',
                'post_type'    => $post_type,
                'post_date'    => $this->random_date(),
            ]);

            if ($post_id && !is_wp_error($post_id)) {
                $thumb_id = $this->get_random_image_id();
                if ($thumb_id) {
                    set_post_thumbnail($post_id, $thumb_id);
                }

                update_post_meta($post_id, '_plaiceholder_generated', true);

                WP_CLI::success("Created {$post_type} #{$post_id}: {$content_data['title']}");
            }
        }
    }

    private function generate_post_data(string $prompt, string $api_key): ?array
    {
        $full_prompt = <<<EOD
You are a thought leader writing expert and detailed content for a WordPress blog.

Please return a JSON object with the following keys:
- title (string): A compelling title for a blog post
- excerpt (string): A 1–2 sentence summary of the post
- content (string): A few paragraphs of engaging content

The topic is: "{$prompt}"

Avoid repetition at all costs. Be inventive and use a lot of variation, avoid cliche. 

Return only the JSON. No commentary, no explanation. 
EOD;

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a creative content writer.'],
                    ['role' => 'user', 'content' => $full_prompt],
                ],
                'temperature' => 0.8,
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            WP_CLI::error("HTTP request failed: " . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if (isset($json['error']['message'])) {
            WP_CLI::error("OpenAI error: " . $json['error']['message']);
        }

        $text = $json['choices'][0]['message']['content'] ?? null;
        if (!$text) {
            WP_CLI::error("No content returned from OpenAI. Full response:\n" . json_encode($json));
        }

        // Clean up GPT Markdown formatting
        $text = preg_replace('/^```json\n|```$/m', '', trim($text));

        WP_CLI::log("Cleaned JSON:
" . $text);

        $data = json_decode($text, true);
        if (!isset($data['title'], $data['excerpt'], $data['content'])) {
            WP_CLI::warning("Failed to parse OpenAI content:\n" . $text);
            return null;
        }

        return $data;
    }

    private function get_cached_response(string $prompt): ?array
    {
        $path = $this->cache_path();
        if (!file_exists($path)) return null;

        $cache = json_decode(file_get_contents($path), true);
        return $cache[md5($prompt)] ?? null;
    }

    private function cache_response(string $prompt, array $data): void
    {
        $path = $this->cache_path();
        $cache = file_exists($path) ? json_decode(file_get_contents($path), true) : [];

        $cache[md5($prompt)] = $data;
        file_put_contents($path, json_encode($cache, JSON_PRETTY_PRINT));
    }

    private function cache_path(): string
    {
        return wp_upload_dir()['basedir'] . '/placeholder-cache.json';
    }

    private function get_random_image_id(): ?int
    {
        $images = get_posts([
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => 100,
            'orderby'        => 'rand',
            'fields'         => 'ids',
        ]);

        return !empty($images) ? $images[array_rand($images)] : null;
    }

    private function random_date(): string
    {
        $now = time();
        $six_months_ago = strtotime('-6 months', $now);
        $random_timestamp = rand($six_months_ago, $now);
        return date('Y-m-d H:i:s', $random_timestamp);
    }

    /**
     * Deletes all placeholder-generated posts.
     *
     * ## EXAMPLES
     *
     *     wp plaiceholder cleanup
     */
    public function cleanup()
    {
        $query = new WP_Query([
            'post_type'      => 'any',
            'posts_per_page' => -1,
            'meta_key'       => '_plaiceholder_generated',
            'meta_value'     => true,
            'fields'         => 'ids'
        ]);

        $count = 0;
        foreach ($query->posts as $post_id) {
            wp_delete_post($post_id, true);
            $count++;
        }

        WP_CLI::success("Deleted {$count} placeholder posts.");
    }
}
