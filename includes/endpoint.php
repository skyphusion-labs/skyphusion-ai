<?php
/**
 * Public endpoint checks. This file does not load WordPress.
 * The standalone test defines ABSPATH before it loads this file.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SKYPHUSION_AI_SNIPPET')) {
    define('SKYPHUSION_AI_SNIPPET', '0.0.25');
}

/**
 * Return the canonical https origin, or an empty string when the address is not a Cloudflare AI Search public endpoint.
 */
function skyphusion_ai_normalize_endpoint($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    if (function_exists('wp_parse_url')) {
        $parts = wp_parse_url($url);
    } else {
        // tests/endpoint-test.php loads this file before WordPress defines wp_parse_url().
        $parts = parse_url($url); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
    }
    if (!is_array($parts)) {
        return '';
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return '';
    }
    if (!isset($parts['scheme'], $parts['host']) || strtolower((string) $parts['scheme']) !== 'https') {
        return '';
    }

    $path = isset($parts['path']) ? (string) $parts['path'] : '';
    if ($path !== '' && $path !== '/') {
        return '';
    }

    $host = strtolower((string) $parts['host']);
    $label = '(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)';
    $pattern = '/^(?:ns-)?' . $label . '\.search\.ai\.cloudflare\.com$/';
    if (!preg_match($pattern, $host)) {
        return '';
    }

    return 'https://' . $host;
}

/**
 * Script URL for the pinned snippet build. Empty when the endpoint is not usable.
 */
function skyphusion_ai_snippet_src($url)
{
    $base = skyphusion_ai_normalize_endpoint($url);
    if ($base === '') {
        return '';
    }

    return $base . '/assets/v' . SKYPHUSION_AI_SNIPPET . '/search-snippet.es.js';
}

/**
 * Join a string or a list of {text} parts. Empty parts are dropped.
 *
 * @param mixed $content
 * @return string
 */
function skyphusion_ai_part_text($content)
{
    if (is_string($content)) {
        return trim($content);
    }
    if (!is_array($content)) {
        return '';
    }
    $parts = array();
    foreach ($content as $part) {
        $text = '';
        if (is_string($part)) {
            $text = trim($part);
        } elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) {
            $text = trim($part['text']);
        }
        if ($text !== '') {
            $parts[] = $text;
        }
    }
    return trim(implode("\n", $parts));
}

/**
 * Source text from one search row. A chunk uses text. Older rows use content.
 *
 * @param mixed $row
 * @return string
 */
function skyphusion_ai_row_text($row)
{
    if (!is_array($row)) {
        return '';
    }
    if (isset($row['text']) && is_string($row['text'])) {
        $text = trim($row['text']);
        if ($text !== '') {
            return $text;
        }
    }
    if (!isset($row['content'])) {
        return '';
    }
    return skyphusion_ai_part_text($row['content']);
}

/**
 * Sentence from a chat body. The public endpoint puts it on choices.
 * A wrapped body puts that same object under result.
 *
 * @param mixed $decoded
 * @return string
 */
function skyphusion_ai_choice_text($decoded)
{
    if (!is_array($decoded) || !isset($decoded['choices'][0]['message']['content'])) {
        return '';
    }
    return skyphusion_ai_part_text($decoded['choices'][0]['message']['content']);
}

/**
 * Answer text and source snippets from a public AI Search response.
 *
 * /chat/completions returns the sentence in choices and the pages in chunks.
 * /search returns result.chunks and no sentence. Older bodies used response
 * and data.
 *
 * @param mixed $decoded
 * @return array{answer: string, sources: array<int, string>}
 */
function skyphusion_ai_read_answer($decoded)
{
    $answer = '';
    $sources = array();
    if (!is_array($decoded)) {
        return array('answer' => '', 'sources' => array());
    }

    if (isset($decoded['response']) && is_string($decoded['response'])) {
        $answer = $decoded['response'];
    } elseif (
        isset($decoded['result']) && is_array($decoded['result'])
        && isset($decoded['result']['response']) && is_string($decoded['result']['response'])
    ) {
        $answer = $decoded['result']['response'];
    } else {
        $answer = skyphusion_ai_choice_text($decoded);
        if ($answer === '' && isset($decoded['result']) && is_array($decoded['result'])) {
            $answer = skyphusion_ai_choice_text($decoded['result']);
        }
    }

    $result = array();
    if (isset($decoded['result']) && is_array($decoded['result'])) {
        $result = $decoded['result'];
    }
    $groups = array();
    foreach (array('data', 'chunks') as $key) {
        if (isset($decoded[$key]) && is_array($decoded[$key])) {
            $groups[] = $decoded[$key];
        }
        if (isset($result[$key]) && is_array($result[$key])) {
            $groups[] = $result[$key];
        }
    }
    $seen = array();
    foreach ($groups as $rows) {
        foreach ($rows as $row) {
            $text = skyphusion_ai_row_text($row);
            if ($text === '' || isset($seen[$text])) {
                continue;
            }
            $seen[$text] = true;
            $sources[] = $text;
        }
    }

    return array(
        'answer' => $answer,
        'sources' => $sources,
    );
}
