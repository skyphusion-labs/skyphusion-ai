<?php
/**
 * Workers AI and AI Gateway addresses. This file does not load WordPress.
 *
 * AI Search stays on the public endpoint in endpoint.php. These helpers build
 * the authenticated Workers AI URLs, and the same paths through AI Gateway
 * when a gateway id is set. They do not send a request and they do not store a token.
 * The standalone test defines ABSPATH before it loads this file.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 32 hex characters, or an empty string.
 */
function cloudflare_ai_provider_account_id($value)
{
    $value = strtolower(trim((string) $value));
    if (!preg_match('/^[a-f0-9]{32}$/', $value)) {
        return '';
    }
    return $value;
}

/**
 * Gateway slug, or an empty string when the call should go direct to Workers AI.
 */
function cloudflare_ai_provider_gateway_id($value)
{
    $value = trim((string) $value);
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value)) {
        return '';
    }
	if ( function_exists( 'skyphusion_ai_gateway_fallback_active' ) && skyphusion_ai_gateway_fallback_active() ) {
		return '';
	}
    return $value;
}

/**
 * A Workers AI model id, or an empty string. The id is a path, so this rejects anything else.
 */
function cloudflare_ai_provider_model_id($value)
{
    $value = trim((string) $value);
    if (!preg_match('#^@cf/[a-z0-9][a-z0-9._-]*/[A-Za-z0-9][A-Za-z0-9._-]*$#', $value)) {
        return '';
    }
    return $value;
}

/**
 * Split a Connectors key. account-id:token is accepted. A token with no colon stays whole.
 *
 * @return array{account_id: string, token: string}
 */
function cloudflare_ai_provider_credentials($api_key, $account_id, $fallback_account_id = '')
{
    $api_key = trim((string) $api_key);
    $account = cloudflare_ai_provider_account_id($account_id);
    $token = $api_key;

    if (preg_match('/^([a-f0-9]{32}):(.+)$/i', $api_key, $match)) {
        $account = cloudflare_ai_provider_account_id($match[1]);
        $token = (string) $match[2];
    }
    if ($account === '') {
        $account = cloudflare_ai_provider_account_id($fallback_account_id);
    }
    if ($token === '' || strpos($token, ' ') !== false) {
        $token = '';
    }

    return array(
        'account_id' => $account,
        'token' => $token,
    );
}

/**
 * Read CLOUDFLARE_ACCOUNT_ID when the setting is empty.
 *
 * Environment, then the PHP constant. Same order WordPress uses for a Connectors key.
 */
function cloudflare_ai_provider_fallback_account_id()
{
    $env = getenv('CLOUDFLARE_ACCOUNT_ID');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    if (defined('CLOUDFLARE_ACCOUNT_ID')) {
        return (string) constant('CLOUDFLARE_ACCOUNT_ID');
    }
    return '';
}

/**
 * @return array{account_id: string, token: string}
 */
function cloudflare_ai_provider_resolve_credentials($api_key, $account_id)
{
    return cloudflare_ai_provider_credentials(
        $api_key,
        $account_id,
        cloudflare_ai_provider_fallback_account_id()
    );
}

function cloudflare_ai_provider_chat_url($account_id, $gateway_id)
{
    $account_id = cloudflare_ai_provider_account_id($account_id);
    if ($account_id === '') {
        return '';
    }
    $gateway_id = cloudflare_ai_provider_gateway_id($gateway_id);
    if ($gateway_id === '') {
        return 'https://api.cloudflare.com/client/v4/accounts/' . $account_id . '/ai/v1/chat/completions';
    }
    // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- AI Gateway API host, not a remote asset.
    return 'https://gateway.ai.cloudflare.com/v1/' . $account_id . '/' . $gateway_id . '/workers-ai/v1/chat/completions';
}

function cloudflare_ai_provider_run_url($account_id, $gateway_id, $model)
{
    $account_id = cloudflare_ai_provider_account_id($account_id);
    $model = cloudflare_ai_provider_model_id($model);
    if ($account_id === '' || $model === '') {
        return '';
    }
    $gateway_id = cloudflare_ai_provider_gateway_id($gateway_id);
    if ($gateway_id === '') {
        return 'https://api.cloudflare.com/client/v4/accounts/' . $account_id . '/ai/run/' . $model;
    }
    // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- AI Gateway API host, not a remote asset.
    return 'https://gateway.ai.cloudflare.com/v1/' . $account_id . '/' . $gateway_id . '/workers-ai/' . $model;
}

function cloudflare_ai_provider_embeddings_model() {
	$fallback = '@cf/baai/bge-large-en-v1.5';
	if ( function_exists( 'skyphusion_ai_model_choice' ) ) {
		return skyphusion_ai_model_choice( 'embeddings' );
	}
	return $fallback;
}

/**
 * @param array<int, string> $texts
 * @return array{text: array<int, string>}
 */
function cloudflare_ai_provider_embeddings_body($texts)
{
    $clean = array();
    foreach ($texts as $text) {
        $text = trim((string) $text);
        if ($text !== '') {
            $clean[] = $text;
        }
    }
    return array('text' => $clean);
}

/**
 * @param array<string, mixed> $spec
 * @return array<string, mixed>
 */
function cloudflare_ai_provider_chat_body($spec)
{
    $model = cloudflare_ai_provider_model_id(isset($spec['model']) ? $spec['model'] : '');
    $messages = array();
    $system = isset($spec['system']) ? trim((string) $spec['system']) : '';
    if ($system !== '') {
        $messages[] = array(
            'role' => 'system',
            'content' => $system,
        );
    }

    $incoming = isset($spec['messages']) && is_array($spec['messages']) ? $spec['messages'] : array();
    foreach ($incoming as $message) {
        if (!is_array($message)) {
            continue;
        }
        $role = isset($message['role']) ? (string) $message['role'] : 'user';
        if ($role === 'model') {
            $role = 'assistant';
        }
        if (!in_array($role, array('system', 'user', 'assistant', 'tool'), true)) {
            $role = 'user';
        }
        if ($role === 'tool') {
            $messages[] = array(
                'role' => 'tool',
                'tool_call_id' => isset($message['tool_call_id']) ? (string) $message['tool_call_id'] : '',
                'content' => cloudflare_ai_provider_tool_content(isset($message['content']) ? $message['content'] : ''),
            );
            continue;
        }

        $text = isset($message['text']) ? (string) $message['text'] : '';
        $images = isset($message['images']) && is_array($message['images']) ? $message['images'] : array();
        $tool_calls = isset($message['tool_calls']) && is_array($message['tool_calls']) ? $message['tool_calls'] : array();
        $row = array('role' => $role);

        if ($images) {
            $content = array();
            if ($text !== '') {
                $content[] = array('type' => 'text', 'text' => $text);
            }
            foreach ($images as $image) {
                $image = (string) $image;
                if ($image === '') {
                    continue;
                }
                $content[] = array(
                    'type' => 'image_url',
                    'image_url' => array('url' => $image),
                );
            }
            $row['content'] = $content;
        } else {
            $row['content'] = $text;
        }

        if ($tool_calls && $role === 'assistant') {
            $encoded = array();
            foreach ($tool_calls as $call) {
                if (!is_array($call)) {
                    continue;
                }
                $arguments = isset($call['arguments']) ? $call['arguments'] : array();
                if (!is_string($arguments)) {
                    $arguments = json_encode($arguments);
                }
                $encoded[] = array(
                    'id' => isset($call['id']) ? (string) $call['id'] : '',
                    'type' => 'function',
                    'function' => array(
                        'name' => isset($call['name']) ? (string) $call['name'] : '',
                        'arguments' => $arguments,
                    ),
                );
            }
            if ($encoded) {
                $row['tool_calls'] = $encoded;
            }
        }
        $messages[] = $row;
    }

    $body = array(
        'model' => $model,
        'messages' => $messages,
    );

    $numbers = array(
        'temperature' => 'temperature',
        'max_tokens' => 'max_tokens',
        'top_p' => 'top_p',
        'top_k' => 'top_k',
        'frequency_penalty' => 'frequency_penalty',
        'presence_penalty' => 'presence_penalty',
        'n' => 'n',
    );
    foreach ($numbers as $from => $to) {
        if (isset($spec[$from]) && $spec[$from] !== null && $spec[$from] !== '') {
            $body[$to] = $spec[$from];
        }
    }
    if (isset($spec['stop']) && is_array($spec['stop']) && $spec['stop']) {
        $body['stop'] = array_values($spec['stop']);
    }
    if (!empty($spec['json'])) {
        if (isset($spec['schema']) && is_array($spec['schema'])) {
            $body['response_format'] = array(
                'type' => 'json_schema',
                'json_schema' => array(
                    'name' => 'response',
                    'schema' => $spec['schema'],
                ),
            );
        } else {
            $body['response_format'] = array('type' => 'json_object');
        }
    }
    if (isset($spec['tools']) && is_array($spec['tools']) && $spec['tools']) {
        $tools = array();
        foreach ($spec['tools'] as $tool) {
            if (!is_array($tool) || empty($tool['name'])) {
                continue;
            }
            $parameters = isset($tool['parameters']) && is_array($tool['parameters'])
                ? $tool['parameters']
                : array('type' => 'object', 'properties' => new stdClass());
            $tools[] = array(
                'type' => 'function',
                'function' => array(
                    'name' => (string) $tool['name'],
                    'description' => isset($tool['description']) ? (string) $tool['description'] : '',
                    'parameters' => $parameters,
                ),
            );
        }
        if ($tools) {
            $body['tools'] = $tools;
        }
    }

    return $body;
}

function cloudflare_ai_provider_tool_content($content)
{
    if (is_string($content)) {
        return $content;
    }
    $encoded = json_encode($content);
    if ($encoded === false) {
        return '';
    }
    return $encoded;
}

/**
 * @param array<string, mixed>|null $decoded
 * @return array{id: string, candidates: array<int, array{text: string, finish: string, tool_calls: array<int, array{id: string, name: string, arguments: mixed}>}>, usage: array{prompt: int, completion: int, total: int}}
 */
function cloudflare_ai_provider_read_text($decoded)
{
    $empty = array(
        'id' => '',
        'candidates' => array(),
        'usage' => array('prompt' => 0, 'completion' => 0, 'total' => 0),
    );
    if (!is_array($decoded)) {
        return $empty;
    }

    $usage_source = $decoded;
    if (isset($decoded['result']) && is_array($decoded['result'])) {
        $usage_source = $decoded['result'];
    }
    $usage = isset($usage_source['usage']) && is_array($usage_source['usage']) ? $usage_source['usage'] : array();
    $empty['usage'] = array(
        'prompt' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : 0,
        'completion' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : 0,
        'total' => isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : 0,
    );
    $empty['id'] = isset($decoded['id']) ? (string) $decoded['id'] : '';

    $choices = array();
    if (isset($decoded['choices']) && is_array($decoded['choices'])) {
        $choices = $decoded['choices'];
    } elseif (isset($decoded['result']['choices']) && is_array($decoded['result']['choices'])) {
        $choices = $decoded['result']['choices'];
    }

    if ($choices) {
        foreach ($choices as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $message = isset($choice['message']) && is_array($choice['message']) ? $choice['message'] : array();
            $text = isset($message['content']) && is_string($message['content']) ? $message['content'] : '';
            $calls = array();
            if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
                foreach ($message['tool_calls'] as $call) {
                    if (!is_array($call)) {
                        continue;
                    }
                    $function = isset($call['function']) && is_array($call['function']) ? $call['function'] : array();
                    $arguments = isset($function['arguments']) ? $function['arguments'] : array();
                    if (is_string($arguments)) {
                        $parsed = json_decode($arguments, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $arguments = $parsed;
                        }
                    }
                    $calls[] = array(
                        'id' => isset($call['id']) ? (string) $call['id'] : '',
                        'name' => isset($function['name']) ? (string) $function['name'] : '',
                        'arguments' => $arguments,
                    );
                }
            }
            $finish = isset($choice['finish_reason']) ? (string) $choice['finish_reason'] : 'stop';
            if (!in_array($finish, array('stop', 'length', 'content_filter', 'tool_calls', 'error'), true)) {
                $finish = $calls ? 'tool_calls' : 'stop';
            }
            $empty['candidates'][] = array(
                'text' => $text,
                'finish' => $finish,
                'tool_calls' => $calls,
            );
        }
        return $empty;
    }

    $response = '';
    if (isset($decoded['result']['response']) && is_string($decoded['result']['response'])) {
        $response = $decoded['result']['response'];
    } elseif (isset($decoded['response']) && is_string($decoded['response'])) {
        $response = $decoded['response'];
    }
    if ($response !== '') {
        $empty['candidates'][] = array(
            'text' => $response,
            'finish' => 'stop',
            'tool_calls' => array(),
        );
    }
    return $empty;
}

/**
 * Base64 image bytes from a Workers AI run response, without a data-URI prefix.
 */
function cloudflare_ai_provider_read_image($decoded)
{
    if (!is_array($decoded)) {
        return '';
    }
    $image = '';
    if (isset($decoded['result']['image']) && is_string($decoded['result']['image'])) {
        $image = $decoded['result']['image'];
    } elseif (isset($decoded['image']) && is_string($decoded['image'])) {
        $image = $decoded['image'];
    }
    if (strpos($image, 'base64,') !== false) {
        $image = (string) substr($image, (int) strpos($image, 'base64,') + 7);
    }
    return $image;
}

/**
 * @param array<string, mixed>|array<int, mixed>|null $decoded
 * @return array<int, array<int, float>>
 */
function cloudflare_ai_provider_read_embeddings($decoded)
{
    if (!is_array($decoded)) {
        return array();
    }
    $data = $decoded;
    if (isset($decoded['result']) && is_array($decoded['result'])) {
        $data = $decoded['result'];
    }
    if (isset($data['data']) && is_array($data['data'])) {
        $data = $data['data'];
    }
    $vectors = array();
    foreach ($data as $row) {
        if (is_array($row) && isset($row['embedding']) && is_array($row['embedding'])) {
            $row = $row['embedding'];
        }
        if (!is_array($row) || !$row || !is_numeric(reset($row))) {
            continue;
        }
        $vector = array();
        foreach ($row as $number) {
            $vector[] = (float) $number;
        }
        $vectors[] = $vector;
    }
    return $vectors;
}

/**
 * @return array{text: string, encoding: string, speaker?: string}
 */
function cloudflare_ai_provider_speech_body($text, $voice)
{
    $body = array(
        'text' => (string) $text,
        'encoding' => 'mp3',
    );
    $voices = array(
        'angus', 'asteria', 'arcas', 'orion', 'orpheus', 'athena',
        'luna', 'zeus', 'perseus', 'helios', 'hera', 'stella',
    );
    $voice = strtolower(trim((string) $voice));
    if (in_array($voice, $voices, true)) {
        $body['speaker'] = $voice;
    }
    return $body;
}

function cloudflare_ai_provider_error_message($status, $decoded)
{
    $message = '';
    if (is_array($decoded)) {
        if (isset($decoded['errors'][0]['message'])) {
            $message = (string) $decoded['errors'][0]['message'];
        } elseif (isset($decoded['error']['message'])) {
            $message = (string) $decoded['error']['message'];
        }
    }
    $message = (string) preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message);
    if (strlen($message) > 300) {
        $message = substr($message, 0, 300);
    }
    if ($message === '') {
        /* translators: %d is an HTTP status code returned by Cloudflare. */
        return sprintf(__('Cloudflare returned HTTP %d.', 'skyphusion-ai'), (int) $status);
    }
    return $message;
}

/**
 * Dashboard and docs links for the three products.
 *
 * @return array<string, string>
 */
function cloudflare_ai_provider_links($account_id)
{
    $account_id = cloudflare_ai_provider_account_id($account_id);
    if ($account_id === '') {
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- dashboard link, not a remote asset.
        $dash = 'https://dash.cloudflare.com/?to=/:account/ai/';
    } else {
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- dashboard link, not a remote asset.
        $dash = 'https://dash.cloudflare.com/' . $account_id . '/ai/';
    }
    return array(
        'workers_ai' => $dash . 'workers-ai',
        'gateway' => $dash . 'ai-gateway',
        'search' => $dash . 'ai-search',
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- docs link, not a remote asset.
        'workers_ai_docs' => 'https://developers.cloudflare.com/workers-ai/',
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- docs link, not a remote asset.
        'gateway_docs' => 'https://developers.cloudflare.com/ai-gateway/',
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- docs link, not a remote asset.
        'search_docs' => 'https://developers.cloudflare.com/ai-search/',
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- docs link, not a remote asset.
        'gateway_workers_docs' => 'https://developers.cloudflare.com/ai-gateway/usage/providers/workers-ai/',
    );
}

/**
 * Models the provider offers to WordPress and WooCommerce.
 *
 * Ids are the ones published on developers.cloudflare.com. The first text model
 * is the default. The first vision model is the one used when a prompt includes an image.
 *
 * @return array<int, array{id: string, name: string, kind: string, tools: bool, vision: bool, json: bool}>
 */
function cloudflare_ai_provider_catalog()
{
    return array(
        array(
            'id' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'name' => 'Llama 3.3 70B',
            'kind' => 'text',
            'tools' => true,
            'vision' => false,
            'json' => true,
        ),
        array(
            'id' => '@cf/mistralai/mistral-small-3.1-24b-instruct',
            'name' => 'Mistral Small 3.1',
            'kind' => 'text',
            'tools' => true,
            'vision' => true,
            'json' => true,
        ),
        array(
            'id' => '@cf/meta/llama-3.2-11b-vision-instruct',
            'name' => 'Llama 3.2 11B Vision',
            'kind' => 'text',
            'tools' => false,
            'vision' => true,
            'json' => false,
        ),
        array(
            'id' => '@cf/black-forest-labs/flux-1-schnell',
            'name' => 'FLUX.1 schnell',
            'kind' => 'image',
            'tools' => false,
            'vision' => false,
            'json' => false,
        ),
        array(
            'id' => '@cf/black-forest-labs/flux-2-klein-4b',
            'name' => 'FLUX.2 klein 4B',
            'kind' => 'image',
            'tools' => false,
            'vision' => false,
            'json' => false,
        ),
        array(
            'id' => '@cf/black-forest-labs/flux-2-dev',
            'name' => 'FLUX.2 dev',
            'kind' => 'image',
            'tools' => false,
            'vision' => false,
            'json' => false,
        ),
        array(
            'id' => '@cf/deepgram/aura-1',
            'name' => 'Aura 1',
            'kind' => 'speech',
            'tools' => false,
            'vision' => false,
            'json' => false,
        ),
    );
}

/**
 * @return array{id: string, name: string, kind: string, tools: bool, vision: bool, json: bool}|null
 */
function cloudflare_ai_provider_catalog_entry($model_id)
{
    $model_id = cloudflare_ai_provider_model_id($model_id);
    foreach (cloudflare_ai_provider_catalog() as $entry) {
        if ($entry['id'] === $model_id) {
            return $entry;
        }
    }
    return null;
}

/**
 * Guards around the ONE unauthenticated path to a billable call (issue #14).
 *
 * `skyphusion_ai_query()` POSTs to /chat/completions, which GENERATES.
 * With `lead_search` on, `GET /?s=<anything>` reaches it, so an anonymous
 * visitor drives paid Workers AI generation one call per request. Measured on
 * a live store: the request takes about twelve seconds and the answer renders
 * to the visitor.
 *
 * TWELVE SECONDS IS THE SECOND PROBLEM AND IT IS NOT ABOUT MONEY. The call
 * holds a PHP worker for its whole duration, up to the 20 second timeout, so
 * a handful of concurrent `?s=` requests can exhaust a single origin's worker
 * pool. The cache below is what fixes that case: a repeated question costs no
 * worker time at all.
 *
 * Three guards, in the order they are cheapest to apply:
 *
 *   1. LENGTH CAP, refusing rather than truncating. Truncating still bills.
 *   2. CACHE on the normalised question, so repeats are free.
 *   3. CALL CEILING per window, global rather than per IP, because an IP
 *      ceiling is defeated by the traffic shape it is meant to stop.
 *
 * EVERY GUARD FAILS CLOSED TO PRINTING NOTHING, which is what this path
 * already did on a transport error, so a refused request looks to the visitor
 * exactly like a search with no answer. No new failure mode is introduced.
 *
 * Deliberately NOT a per-IP limit and NOT a crawler heuristic. A referer and
 * Accept-header test was considered and rejected: it guesses, and the cost of
 * guessing wrong is silently withholding the feature from real visitors. The
 * ceiling bounds the damage without that risk.
 */

/** Longest question forwarded to the model. */
function skyphusion_ai_lead_max_length()
{
    $default = 300;
    if (function_exists('apply_filters')) {
        $value = apply_filters('cloudflare_ai_search_lead_max_length', $default);
        if (is_int($value) && $value > 0) {
            return $value;
        }
    }
    return $default;
}

/** Seconds an answer is cached for. */
function skyphusion_ai_lead_cache_ttl()
{
    $default = 3600;
    if (function_exists('apply_filters')) {
        $value = apply_filters('cloudflare_ai_search_lead_cache_ttl', $default);
        if (is_int($value) && $value > 0) {
            return $value;
        }
    }
    return $default;
}

/** Generation calls allowed per window, and the window in seconds. */
function skyphusion_ai_lead_ceiling()
{
    $default = array('calls' => 60, 'window' => 3600);
    if (function_exists('apply_filters')) {
        $value = apply_filters('cloudflare_ai_search_lead_ceiling', $default);
        if (
            is_array($value)
            && isset($value['calls'], $value['window'])
            && is_int($value['calls']) && $value['calls'] > 0
            && is_int($value['window']) && $value['window'] > 0
        ) {
            return $value;
        }
    }
    return $default;
}

/**
 * Normalise a question so that trivially different spellings share a cache
 * entry. Lowercase, collapse whitespace, trim.
 *
 * @param string $question
 * @return string
 */
function skyphusion_ai_lead_normalise($question)
{
    $question = (string) $question;
    $question = preg_replace('/\s+/u', ' ', $question);
    return trim(function_exists('mb_strtolower') ? mb_strtolower($question, 'UTF-8') : strtolower($question));
}

/**
 * True when the question is longer than the cap and must not be sent.
 *
 * Measured in CHARACTERS and not bytes, so a question in a multi-byte script
 * is not refused for being in that script.
 *
 * @param string $question
 * @return bool
 */
function skyphusion_ai_lead_too_long($question)
{
    $question = (string) $question;
    $length = function_exists('mb_strlen') ? mb_strlen($question, 'UTF-8') : strlen($question);
    return $length > skyphusion_ai_lead_max_length();
}

/**
 * Transient key for a question. Hashed, because a transient key has a length
 * limit and a raw question is visitor-controlled text.
 *
 * @param string $question
 * @return string
 */
function skyphusion_ai_lead_cache_key($question)
{
    return 'cfais_lead_' . md5(skyphusion_ai_lead_normalise($question));
}
