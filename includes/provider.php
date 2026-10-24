<?php
/**
 * Register the Cloudflare provider with the WordPress AI Client, and expose
 * Workers AI embeddings plus the public AI Search endpoint to WordPress and WooCommerce.
 */

if (!defined('ABSPATH')) {
    exit;
}

function cloudflare_ai_provider_boot()
{
    if (!class_exists('WordPress\\AiClient\\AiClient')) {
        return;
    }
    $text = 'WordPress\\AiClient\\Providers\\Models\\TextGeneration\\Contracts\\TextGenerationModelInterface';
    $image = 'WordPress\\AiClient\\Providers\\Models\\ImageGeneration\\Contracts\\ImageGenerationModelInterface';
    if (!interface_exists($text) || !interface_exists($image)) {
        return;
    }
    require_once __DIR__ . '/provider-models.php';
    $speech = 'WordPress\\AiClient\\Providers\\Models\\SpeechGeneration\\Contracts\\SpeechGenerationModelInterface';
    if (interface_exists($speech)) {
        require_once __DIR__ . '/provider-speech.php';
    }
    add_action('init', 'cloudflare_ai_provider_register', 5);
}
add_action('plugins_loaded', 'cloudflare_ai_provider_boot');

function cloudflare_ai_provider_register()
{
    if (!class_exists('Cloudflare_AI_Provider') || !class_exists('WordPress\\AiClient\\AiClient')) {
        return;
    }
    $registry = WordPress\AiClient\AiClient::defaultRegistry();
    if ($registry->hasProvider('cloudflare') || $registry->hasProvider('Cloudflare_AI_Provider')) {
        return;
    }
    $registry->registerProvider('Cloudflare_AI_Provider');
}

/**
 * Embed texts with Workers AI. WordPress and WooCommerce code can call this directly.
 *
 * The token is the Connectors key: environment, then constant, then the saved option.
 * On failure the return is a WP_Error and the message does not include the token.
 *
 * @param array<int, string> $texts
 * @return array<int, array<int, float>>|WP_Error
 */
function cloudflare_ai_provider_embeddings($texts)
{
    if (!is_array($texts)) {
        return new WP_Error('cloudflare_ai_embeddings', __('Embeddings need a list of texts.', 'skyphusion-ai'));
    }
    $settings = skyphusion_ai_settings();
    $key = cloudflare_ai_provider_connector_key();
    $credentials = cloudflare_ai_provider_resolve_credentials($key, $settings['account_id']);
    $url = cloudflare_ai_provider_run_url(
        $credentials['account_id'],
        $settings['gateway_id'],
        cloudflare_ai_provider_embeddings_model()
    );
    $body = cloudflare_ai_provider_embeddings_body($texts);
    if ($url === '' || $credentials['token'] === '' || !$body['text']) {
        return new WP_Error('cloudflare_ai_embeddings', __('Add the Cloudflare account ID and API token before embedding text.', 'skyphusion-ai'));
    }

    $response = wp_remote_post($url, array(
        'timeout' => 30,
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $credentials['token'],
        ),
        'body' => wp_json_encode($body),
    ));
    if (is_wp_error($response)) {
        return new WP_Error('cloudflare_ai_embeddings', __('The site could not reach Cloudflare.', 'skyphusion-ai'));
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status >= 300) {
        return new WP_Error('cloudflare_ai_embeddings', cloudflare_ai_provider_error_message($status, $decoded));
    }
    return cloudflare_ai_provider_read_embeddings($decoded);
}

/**
 * Ask the public AI Search chat endpoint. No API token.
 * The connection check stays on /search, which returns pages and no sentence.
 *
 * @param string $question
 * @return array{answer: string, sources: array<int, string>}|WP_Error
 */
function skyphusion_ai_query($question)
{
    $question = trim((string) $question);
    $endpoint = skyphusion_ai_settings()['endpoint'];
    if ($question === '' || $endpoint === '') {
        return new WP_Error('cloudflare_ai_search', __('Add the AI Search address before searching.', 'skyphusion-ai'));
    }
    $response = wp_remote_post($endpoint . '/chat/completions', array(
        'timeout' => 20,
        'headers' => array('Content-Type' => 'application/json'),
        'body' => wp_json_encode(array(
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $question,
                ),
            ),
        )),
    ));
    if (is_wp_error($response)) {
        return new WP_Error('cloudflare_ai_search', __('The site could not reach Cloudflare.', 'skyphusion-ai'));
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($status !== 200) {
        /* translators: %d is an HTTP status code returned by Cloudflare. */
        return new WP_Error('cloudflare_ai_search', sprintf(__('Cloudflare answered HTTP %d.', 'skyphusion-ai'), (int) $status));
    }
    return skyphusion_ai_read_answer($decoded);
}

/**
 * THE GUARDED ENTRY POINT for the one unauthenticated path to a billable
 * call (issue #14). The pure halves of these guards live in `routes.php`
 * beside the other pure helpers, where the test harness already loads them
 * and can drive them with no WordPress at all.
 */

/**
 * Record one generation call and say whether it was allowed.
 *
 * The counter and its window start live in one transient, so the window
 * cannot drift apart from the count it bounds.
 *
 * @return bool
 */
function skyphusion_ai_lead_take_slot()
{
    if (!function_exists('get_transient') || !function_exists('set_transient')) {
        return true;
    }
    $limit = skyphusion_ai_lead_ceiling();
    $key = 'cfais_lead_calls';
    $state = get_transient($key);
    $now = time();
    if (!is_array($state) || !isset($state['count'], $state['start'])) {
        $state = array('count' => 0, 'start' => $now);
    }
    if (($now - (int) $state['start']) >= $limit['window']) {
        $state = array('count' => 0, 'start' => $now);
    }
    if ((int) $state['count'] >= $limit['calls']) {
        return false;
    }
    $state['count'] = (int) $state['count'] + 1;
    set_transient($key, $state, $limit['window']);
    return true;
}

/**
 * The guarded entry point the search page uses.
 *
 * Returns the same shape as `skyphusion_ai_query()` on success, and
 * null whenever nothing should be printed. Null rather than WP_Error because
 * every refusal here is deliberate and silent, and a caller that cannot tell
 * them apart cannot accidentally surface one.
 *
 * @param string $question
 * @return array{answer: string, sources: array<int, string>}|null
 */
function skyphusion_ai_lead_answer($question)
{
    $question = trim((string) $question);
    if ($question === '' || skyphusion_ai_lead_too_long($question)) {
        return null;
    }
    $key = skyphusion_ai_lead_cache_key($question);
    $have_cache = function_exists('get_transient') && function_exists('set_transient');
    if ($have_cache) {
        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['answer'])) {
            return $cached;
        }
        // A cached MISS is still a cache entry. Without it a question the
        // model answers emptily is re-asked, and paid for, on every view.
        if ($cached === 'none') {
            return null;
        }
    }
    if (!skyphusion_ai_lead_take_slot()) {
        return null;
    }
    $result = skyphusion_ai_query($question);
    if (is_wp_error($result) || !is_array($result) || trim((string) $result['answer']) === '') {
        if ($have_cache) {
            // Short, because an endpoint that is down should recover quickly,
            // but long enough that a failing endpoint is not re-dialled on
            // every page view while it holds a worker for twenty seconds.
            set_transient($key, 'none', 300);
        }
        return null;
    }
    if ($have_cache) {
        set_transient($key, $result, skyphusion_ai_lead_cache_ttl());
    }
    return $result;
}

function cloudflare_ai_provider_connector_key()
{
	// The settings screen reports this same decision via
	// skyphusion_ai_token_source(). "Set" means a non-empty string.
	// Environment, then the constant, then the Connectors option. A change
	// to either function has to land in both, or the screen will name a
	// source this function does not return.
    $env = getenv('CLOUDFLARE_API_KEY');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    if (defined('CLOUDFLARE_API_KEY')) {
        $value = constant('CLOUDFLARE_API_KEY');
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }
    $stored = get_option('connectors_ai_cloudflare_api_key', '');
    if (!is_string($stored)) {
        return '';
    }
    return $stored;
}
