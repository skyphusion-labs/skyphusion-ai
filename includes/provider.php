<?php
/**
 * Register the Cloudflare provider with the WordPress AI Client, and expose
 * Workers AI embeddings plus the public AI Search endpoint to WordPress and WooCommerce.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/budget.php';
require_once __DIR__ . '/models.php';

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

	$skyphusion_ai_neuron_refusal = skyphusion_ai_neuron_refusal();
	if ( is_wp_error( $skyphusion_ai_neuron_refusal ) ) {
		return $skyphusion_ai_neuron_refusal;
	}
	$response = skyphusion_ai_neuron_embeddings_post( $url, $credentials['token'], $body );
	if ( is_wp_error( $response ) ) {
		$skyphusion_ai_error_code = '';
		if ( method_exists( $response, 'get_error_code' ) ) {
			$skyphusion_ai_error_code = (string) $response->get_error_code();
		} elseif ( isset( $response->code ) ) {
			$skyphusion_ai_error_code = (string) $response->code;
		}
		if ( 'skyphusion_ai_neuron_cap' === $skyphusion_ai_error_code ) {
			return $response;
		}
		return new WP_Error(
			'cloudflare_ai_embeddings',
			__( 'The site could not reach Cloudflare.', 'skyphusion-ai' )
		);
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
	return skyphusion_ai_neuron_count_search( $decoded );
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
 * Option that stores the 24 hour search counts.
 *
 * Counts only. The question, the answer, the sources, the address, the
 * token, and the error text are not stored.
 *
 * @return string
 */
function skyphusion_ai_search_outcome_option() {
	return 'skyphusion_ai_search_outcomes';
}

/**
 * Seconds in one search-count window.
 *
 * @return int
 */
function skyphusion_ai_search_outcome_window() {
	return 86400;
}

/**
 * Outcome names that are counted.
 *
 * The rate_limited name is the plugin call ceiling. It is not a provider failure.
 *
 * @return string[]
 */
function skyphusion_ai_search_outcome_keys() {
	return array(
		'ai',
		'provider_error',
		'no_endpoint',
		'rate_limited',
	);
}

/**
 * A new window with every count at zero.
 *
 * @param int $now Unix time for window_start. Zero means no open window.
 * @return array{window_start: int, ai: int, provider_error: int, no_endpoint: int, rate_limited: int}
 */
function skyphusion_ai_search_outcome_fresh( $now ) {
	$row = array(
		'window_start' => (int) $now,
	);
	foreach ( skyphusion_ai_search_outcome_keys() as $key ) {
		$row[ $key ] = 0;
	}
	return $row;
}

/**
 * The open window, or null when the stored value is missing or expired.
 *
 * Reading does not write. The next decision starts the new window.
 *
 * @param mixed $stored Value from the option.
 * @param int   $now    Unix time.
 * @return array{window_start: int, ai: int, provider_error: int, no_endpoint: int, rate_limited: int}|null
 */
function skyphusion_ai_search_outcome_current( $stored, $now ) {
	if ( ! is_array( $stored ) || ! isset( $stored['window_start'] ) ) {
		return null;
	}
	$start = (int) $stored['window_start'];
	$age   = (int) $now - $start;
	if ( $start <= 0 || $age < 0 || $age >= skyphusion_ai_search_outcome_window() ) {
		return null;
	}
	$row = array(
		'window_start' => $start,
	);
	foreach ( skyphusion_ai_search_outcome_keys() as $key ) {
		$value = isset( $stored[ $key ] ) ? (int) $stored[ $key ] : 0;
		if ( $value < 0 ) {
			$value = 0;
		}
		$row[ $key ] = $value;
	}
	return $row;
}

/**
 * Counts for the open window.
 *
 * An expired window reads as zeros. The stored row is left until the next
 * decision replaces it, so this function does not hide a write.
 *
 * @return array{window_start: int, ai: int, provider_error: int, no_endpoint: int, rate_limited: int}
 */
function skyphusion_ai_search_outcomes() {
	$now = time();
	if ( ! function_exists( 'get_option' ) ) {
		return skyphusion_ai_search_outcome_fresh( 0 );
	}
	$row = skyphusion_ai_search_outcome_current(
		get_option( skyphusion_ai_search_outcome_option(), array() ),
		$now
	);
	if ( null === $row ) {
		return skyphusion_ai_search_outcome_fresh( 0 );
	}
	return $row;
}

/**
 * Record one search decision.
 *
 * Browser snippets do not call this. They do not pass through PHP.
 *
 * Unknown names are ignored, so a question cannot be stored by being passed
 * as the outcome. The call ceiling is its own name and is not folded into
 * provider_error.
 *
 * @param string $outcome One of ai, provider_error, no_endpoint, rate_limited.
 * @return void
 */
function skyphusion_ai_search_outcome_record( $outcome ) {
	if ( ! in_array( $outcome, skyphusion_ai_search_outcome_keys(), true ) ) {
		return;
	}
	// Flag name for this request. The search words are not stored here.
	$GLOBALS['skyphusion_ai_search_outcome_last'] = $outcome;
	if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
		return;
	}
	$now = time();
	$row = skyphusion_ai_search_outcome_current(
		get_option( skyphusion_ai_search_outcome_option(), array() ),
		$now
	);
	if ( null === $row ) {
		$row = skyphusion_ai_search_outcome_fresh( $now );
	}
	$row[ $outcome ] = (int) $row[ $outcome ] + 1;
	// Not autoloaded. The row changes on search, and autoload would rewrite
	// the alloptions cache on every one of those writes.
	update_option( skyphusion_ai_search_outcome_option(), $row, false );
}

/**
 * Cached-miss marker.
 *
 * The value is the outcome name only. It is not the question and not the
 * error text. A later hit counts that same outcome and does not dial again.
 *
 * @param string $outcome provider_error. no_endpoint is accepted and not written.
 * @return array{miss: string}
 */
function skyphusion_ai_search_outcome_miss( $outcome ) {
	return array(
		'miss' => $outcome,
	);
}

/**
 * Outcome stored on a cached miss, or an empty string when this is not one.
 *
 * A cached answer has an answer key and is not a miss.
 *
 * @param mixed $cached Value from the question transient.
 * @return string
 */
function skyphusion_ai_search_outcome_miss_name( $cached ) {
	if ( ! is_array( $cached ) || isset( $cached['answer'] ) || ! isset( $cached['miss'] ) ) {
		return '';
	}
	$miss = $cached['miss'];
	if ( ! is_string( $miss ) ) {
		return '';
	}
	if ( 'provider_error' !== $miss && 'no_endpoint' !== $miss ) {
		return '';
	}
	return $miss;
}

/**
 * True when fallback is at least half of a large enough window.
 *
 * The half is provider_error plus no_endpoint. rate_limited is part of the
 * count and is not part of that half. The ceiling is the plugin protecting
 * the bill.
 *
 * @param mixed $counts Outcome row, or any array with those keys.
 * @return bool
 */
function skyphusion_ai_search_outcome_sustained( $counts ) {
	if ( ! is_array( $counts ) ) {
		return false;
	}
	$total = 0;
	$bad   = 0;
	foreach ( skyphusion_ai_search_outcome_keys() as $key ) {
		$value = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
		if ( $value < 0 ) {
			$value = 0;
		}
		$total += $value;
		if ( 'provider_error' === $key || 'no_endpoint' === $key ) {
			$bad += $value;
		}
	}
	if ( $total < 20 ) {
		return false;
	}
	return ( $bad * 2 ) >= $total;
}

/**
 * Lines for wp skyphusion-ai status.
 *
 * Counts only. The question is not included.
 *
 * @return string[]
 */
function skyphusion_ai_search_outcome_status_lines() {
	$counts = skyphusion_ai_search_outcomes();
	return array(
		'search_ai=' . (int) $counts['ai'],
		'search_provider_error=' . (int) $counts['provider_error'],
		'search_no_endpoint=' . (int) $counts['no_endpoint'],
		'search_rate_limited=' . (int) $counts['rate_limited'],
	);
}

/**
 * The guarded entry point the search page uses.
 *
 * Returns the same shape as skyphusion_ai_query() on success, and null
 * whenever nothing should be printed. Null rather than WP_Error because
 * every refusal here is deliberate and silent, and a caller that cannot tell
 * them apart cannot accidentally surface one.
 *
 * A decision a visitor got is counted: an answer, a provider failure, a
 * missing address, or the plugin call ceiling. An empty question and a
 * question over the length cap are guards and are not counted.
 *
 * @param string $question Search text.
 * @return array{answer: string, sources: array<int, string>}|null
 */
function skyphusion_ai_lead_answer( $question ) {
	$question = trim( (string) $question );
	if ( '' === $question || skyphusion_ai_lead_too_long( $question ) ) {
		return null;
	}

	$settings = skyphusion_ai_settings();
	$endpoint = isset( $settings['endpoint'] ) ? (string) $settings['endpoint'] : '';
	if ( '' === $endpoint ) {
		// Not cached. Adding the address should answer the next search.
		// The call ceiling is not used, because nothing was sent.
		skyphusion_ai_search_outcome_record( 'no_endpoint' );
		return null;
	}

	$key        = skyphusion_ai_lead_cache_key( $question );
	$have_cache = function_exists( 'get_transient' ) && function_exists( 'set_transient' );
	if ( $have_cache ) {
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['answer'] ) ) {
			skyphusion_ai_search_outcome_record( 'ai' );
			return $cached;
		}
		// A cached miss keeps the outcome that caused it. A later view
		// counts that outcome again and does not dial the network.
		$miss = skyphusion_ai_search_outcome_miss_name( $cached );
		if ( '' !== $miss ) {
			skyphusion_ai_search_outcome_record( $miss );
			return null;
		}
	}

	if ( ! skyphusion_ai_lead_take_slot() ) {
		// Do not cache a ceiling refusal. One busy window would then
		// hide this question for 300 seconds after the window opens.
		skyphusion_ai_search_outcome_record( 'rate_limited' );
		return null;
	}

	$result = skyphusion_ai_query( $question );
	$answer = '';
	if ( is_array( $result ) && isset( $result['answer'] ) ) {
		$answer = trim( (string) $result['answer'] );
	}
	// Transport failure, a non-200, and an empty answer are the same
	// outcome. The error text is not stored and is not consulted.
	if ( is_wp_error( $result ) || '' === $answer ) {
		if ( $have_cache ) {
			// Short, because an endpoint that is down should recover quickly,
			// but long enough that a failing endpoint is not re-dialled on
			// every page view while it holds a worker for twenty seconds.
			set_transient( $key, skyphusion_ai_search_outcome_miss( 'provider_error' ), 300 );
		}
		skyphusion_ai_search_outcome_record( 'provider_error' );
		return null;
	}

	if ( $have_cache ) {
		set_transient( $key, $result, skyphusion_ai_lead_cache_ttl() );
	}
	skyphusion_ai_search_outcome_record( 'ai' );
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

require_once __DIR__ . '/embed.php';
