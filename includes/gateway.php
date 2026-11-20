<?php
/**
 * Log fields for an AI Gateway request.
 *
 * A direct call to api.cloudflare.com does not get these fields.
 * The prompt is not a field.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * True when the URL host is the AI Gateway host.
 *
 * @param string $url Request URL.
 * @return bool
 */
function skyphusion_ai_gateway_host( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return false;
	}
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return false;
	}
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- AI Gateway API host, not a remote asset.
	return 'gateway.ai.cloudflare.com' === strtolower( (string) $parts['host'] );
}

/**
 * Site address for the log, without a user, a query, or a fragment.
 *
 * @return string
 */
function skyphusion_ai_gateway_site() {
	if ( ! function_exists( 'home_url' ) ) {
		return '';
	}
	$parts = wp_parse_url( (string) home_url() );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}
	$site = $parts['scheme'] . '://' . $parts['host'];
	if ( ! empty( $parts['port'] ) ) {
		$site .= ':' . (int) $parts['port'];
	}
	if ( isset( $parts['path'] ) && is_string( $parts['path'] ) && '' !== $parts['path'] && '/' !== $parts['path'] ) {
		$site .= $parts['path'];
	}
	return $site;
}

/**
 * Numeric user id, or an empty string for a visitor.
 *
 * @return string
 */
function skyphusion_ai_gateway_user() {
	if ( ! function_exists( 'get_current_user_id' ) ) {
		return '';
	}
	$user_id = (int) get_current_user_id();
	if ( 1 > $user_id ) {
		return '';
	}
	return (string) $user_id;
}

/**
 * Feature name that may appear in the log.
 *
 * @param string $feature Requested feature.
 * @return string
 */
function skyphusion_ai_gateway_feature( $feature ) {
	$allowed = array( 'text', 'vision', 'image', 'speech', 'embeddings' );
	$feature = (string) $feature;
	if ( in_array( $feature, $allowed, true ) ) {
		return $feature;
	}
	return 'text';
}

/**
 * Whether the admin asked the gateway to log prompts.
 *
 * @return bool
 */
function skyphusion_ai_gateway_log_prompts() {
	if ( ! function_exists( 'skyphusion_ai_settings' ) ) {
		return false;
	}
	$settings = skyphusion_ai_settings();
	if ( ! is_array( $settings ) || ! isset( $settings['log_prompts'] ) ) {
		return false;
	}
	return '1' === (string) $settings['log_prompts'];
}

/**
 * Header value for prompt logging. Embeddings stay off.
 *
 * @param string $feature Feature name.
 * @return string
 */
function skyphusion_ai_gateway_collect_value( $feature ) {
	if ( 'embeddings' === skyphusion_ai_gateway_feature( $feature ) ) {
		return 'false';
	}
	if ( skyphusion_ai_gateway_log_prompts() ) {
		return 'true';
	}
	return 'false';
}

/**
 * Saved dynamic route slug, or an empty string.
 *
 * @return string
 */
function skyphusion_ai_gateway_route() {
	if ( ! function_exists( 'skyphusion_ai_settings' ) ) {
		return '';
	}
	$settings = skyphusion_ai_settings();
	if ( ! is_array( $settings ) || ! isset( $settings['gateway_route'] ) ) {
		return '';
	}
	$route = (string) $settings['gateway_route'];
	if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $route ) ) {
		return '';
	}
	return $route;
}

/**
 * Model id for a saved dynamic route, or an empty string.
 *
 * Both the gateway id and the route must be set.
 * A refused gateway uses the direct API, so this stays empty.
 * Translation, image, speech, and embeddings do not use this id.
 *
 * @return string
 */
function skyphusion_ai_gateway_dynamic_model() {
	if ( ! function_exists( 'skyphusion_ai_settings' ) ) {
		return '';
	}
	$settings = skyphusion_ai_settings();
	$gateway  = '';
	if ( is_array( $settings ) && isset( $settings['gateway_id'] ) ) {
		$gateway = (string) $settings['gateway_id'];
	}
	if ( '' === $gateway ) {
		return '';
	}
	$route = skyphusion_ai_gateway_route();
	if ( '' === $route ) {
		return '';
	}
	return 'dynamic/' . $route;
}

/**
 * Set the dynamic route model on a chat body.
 *
 * The body is unchanged when no route is saved.
 *
 * @param mixed $body Request body.
 * @return mixed
 */
function skyphusion_ai_gateway_apply_route( $body ) {
	if ( ! is_array( $body ) ) {
		return $body;
	}
	$dynamic = skyphusion_ai_gateway_dynamic_model();
	if ( '' === $dynamic ) {
		return $body;
	}
	$body['model'] = $dynamic;
	return $body;
}

/**
 * URL for a text or chat call.
 *
 * A saved gateway and route use the chat path.
 * Any other call keeps the Workers AI model path.
 *
 * @param string $account_id Account id.
 * @param string $gateway_id Gateway id.
 * @param string $model      Model id.
 * @return string
 */
function skyphusion_ai_gateway_text_url( $account_id, $gateway_id, $model ) {
	if ( '' !== skyphusion_ai_gateway_dynamic_model() && function_exists( 'cloudflare_ai_provider_chat_url' ) ) {
		return cloudflare_ai_provider_chat_url( $account_id, $gateway_id );
	}
	if ( ! function_exists( 'cloudflare_ai_provider_run_url' ) ) {
		return '';
	}
	return cloudflare_ai_provider_run_url( $account_id, $gateway_id, $model );
}

/**
 * Tool name that may appear in the log.
 *
 * An unknown name is an empty string. The prompt is not a tool name.
 *
 * @param string $tool Requested tool.
 * @return string
 */
function skyphusion_ai_gateway_tool( $tool ) {
	$allowed = array(
		'assistant',
		'translate',
		'backfill',
		'prompts',
		'links',
		'insights',
		'embeddings',
		'provider',
	);
	$tool    = (string) $tool;
	if ( in_array( $tool, $allowed, true ) ) {
		return $tool;
	}
	return '';
}

/**
 * Option that stores the fallback expiry.
 *
 * @return string
 */
function skyphusion_ai_gateway_down_option() {
	return 'skyphusion_ai_gateway_down';
}

/**
 * Unix time when the direct API hour ends, or zero.
 *
 * @return int
 */
function skyphusion_ai_gateway_fallback_until() {
	if ( ! function_exists( 'get_option' ) ) {
		return 0;
	}
	return max( 0, (int) get_option( skyphusion_ai_gateway_down_option(), 0 ) );
}

/**
 * True while a refused gateway is sending calls to the direct API.
 *
 * @return bool
 */
function skyphusion_ai_gateway_fallback_active() {
	return skyphusion_ai_gateway_fallback_until() > time();
}

/**
 * Start one hour on the direct API after a gateway refusal.
 *
 * An image failure does not start the hour when a dynamic route is saved.
 * A direct API URL never starts it. A second call does not move the end earlier.
 *
 * @param string $url     Request URL.
 * @param string $feature Feature name.
 * @return void
 */
function skyphusion_ai_gateway_mark( $url, $feature ) {
	if ( ! skyphusion_ai_gateway_host( $url ) || ! function_exists( 'update_option' ) ) {
		return;
	}
	if ( 'image' === skyphusion_ai_gateway_feature( $feature ) && '' !== skyphusion_ai_gateway_route() ) {
		return;
	}
	$current = skyphusion_ai_gateway_fallback_until();
	if ( $current > time() ) {
		return;
	}
	update_option( skyphusion_ai_gateway_down_option(), time() + 3600, false );
}

/**
 * Sentence for the admin, or empty when the gateway is in use.
 *
 * @return string
 */
function skyphusion_ai_gateway_notice_text() {
	if ( ! skyphusion_ai_gateway_fallback_active() ) {
		return '';
	}
	return sprintf(
		/* translators: %s: UTC time when the direct API hour ends. */
		__( 'The gateway refused a call. Workers AI is using api.cloudflare.com until %s UTC.', 'skyphusion-ai' ),
		gmdate( 'Y-m-d H:i', skyphusion_ai_gateway_fallback_until() )
	);
}

/**
 * True when the HTTP client gave up waiting for the answer.
 *
 * @param mixed $error WP_Error from the HTTP call.
 * @return bool
 */
function skyphusion_ai_gateway_timed_out( $error ) {
	$message = '';
	if ( is_object( $error ) && method_exists( $error, 'get_error_message' ) ) {
		$message = (string) $error->get_error_message();
	} elseif ( is_object( $error ) && isset( $error->message ) ) {
		$message = (string) $error->message;
	}
	return false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'curl error 28' );
}

/**
 * Note a failed Workers AI response. A success does nothing.
 *
 * @param string $url      Request URL.
 * @param string $feature  Feature name.
 * @param mixed  $response HTTP response or WP_Error.
 * @return void
 */
function skyphusion_ai_gateway_note( $url, $feature, $response ) {
	if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
		// A client-side timeout on a call that asked the gateway to retry is a slow
		// answer, not a refusal. The hour on the direct API is for a refusal (#230).
		if ( skyphusion_ai_gateway_timed_out( $response ) && array() !== skyphusion_ai_gateway_retry_headers( $url, $feature ) ) {
			return;
		}
		skyphusion_ai_gateway_mark( $url, $feature );
		return;
	}
	if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
		return;
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status >= 300 || ( $status > 0 && $status < 200 ) ) {
		skyphusion_ai_gateway_mark( $url, $feature );
	}
}

/**
 * Log fields. The prompt is not one of them.
 *
 * Cloudflare allows five metadata entries. The plugin version stays out of this header.
 *
 * @param string $feature Feature name.
 * @param string $model   Model id.
 * @param string $tool    Tool name.
 * @return array{site: string, feature: string, model: string, user: string, tool: string}
 */
function skyphusion_ai_gateway_metadata( $feature, $model, $tool = '' ) {
	$clean_model = '';
	if ( function_exists( 'cloudflare_ai_provider_model_id' ) ) {
		$clean_model = cloudflare_ai_provider_model_id( $model );
	}
	return array(
		'site'    => skyphusion_ai_gateway_site(),
		'feature' => skyphusion_ai_gateway_feature( $feature ),
		'model'   => $clean_model,
		'user'    => skyphusion_ai_gateway_user(),
		'tool'    => skyphusion_ai_gateway_tool( $tool ),
	);
}

/**
 * A positive whole number up to a maximum, or an empty string.
 *
 * @param mixed $value Candidate value.
 * @param int   $max   Largest allowed value.
 * @return string
 */
function skyphusion_ai_gateway_number_clean( $value, $max ) {
	$value = trim( (string) $value );
	if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $value ) ) {
		return '';
	}
	if ( (int) $value > (int) $max ) {
		return '';
	}
	return $value;
}

/**
 * Stored whole number. An empty post clears it. A bad post keeps the old one.
 *
 * @param mixed  $original Previous settings.
 * @param mixed  $posted   Posted settings, or null.
 * @param string $key      Settings key.
 * @param int    $max      Largest allowed value.
 * @return string
 */
function skyphusion_ai_gateway_number_stored( $original, $posted, $key, $max ) {
	$previous = '';
	if ( is_array( $original ) && isset( $original[ $key ] ) ) {
		$previous = skyphusion_ai_gateway_number_clean( $original[ $key ], $max );
	}
	if ( ! is_array( $posted ) || ! array_key_exists( $key, $posted ) ) {
		return $previous;
	}
	$typed = trim( (string) $posted[ $key ] );
	if ( '' === $typed ) {
		return '';
	}
	$clean = skyphusion_ai_gateway_number_clean( $typed, $max );
	if ( '' === $clean ) {
		return $previous;
	}
	return $clean;
}

/**
 * Backoff name to store. Empty clears it.
 *
 * @param mixed $original Previous settings.
 * @param mixed $posted   Posted settings, or null.
 * @return string
 */
function skyphusion_ai_gateway_backoff_stored( $original, $posted ) {
	$allowed  = array( 'constant', 'linear', 'exponential' );
	$previous = '';
	if ( is_array( $original ) && isset( $original['gateway_backoff'] ) && in_array( (string) $original['gateway_backoff'], $allowed, true ) ) {
		$previous = (string) $original['gateway_backoff'];
	}
	if ( ! is_array( $posted ) || ! array_key_exists( 'gateway_backoff', $posted ) ) {
		return $previous;
	}
	$typed = trim( (string) $posted['gateway_backoff'] );
	if ( '' === $typed ) {
		return '';
	}
	if ( ! in_array( $typed, $allowed, true ) ) {
		return $previous;
	}
	return $typed;
}

/**
 * Image and speech opt-in. Only 1 is on.
 *
 * @param mixed $original Previous settings.
 * @param mixed $posted   Posted settings, or null.
 * @return string
 */
function skyphusion_ai_gateway_media_stored( $original, $posted ) {
	$previous = '0';
	if ( is_array( $original ) && isset( $original['gateway_retry_media'] ) && '1' === (string) $original['gateway_retry_media'] ) {
		$previous = '1';
	}
	if ( ! is_array( $posted ) || ! array_key_exists( 'gateway_retry_media', $posted ) ) {
		return $previous;
	}
	return '1' === (string) $posted['gateway_retry_media'] ? '1' : '0';
}

/**
 * Retry and timeout values. Blank fields send no header.
 *
 * Timeout is milliseconds, up to 120000. Attempts are 1 to 5.
 * Delay is milliseconds, up to 60000.
 *
 * @return array{timeout: string, attempts: string, delay: string, backoff: string, media: string}
 */
function skyphusion_ai_gateway_retry_settings() {
	$empty = array(
		'timeout'  => '',
		'attempts' => '',
		'delay'    => '',
		'backoff'  => '',
		'media'    => '0',
	);
	if ( ! function_exists( 'skyphusion_ai_settings' ) ) {
		return $empty;
	}
	$settings = skyphusion_ai_settings();
	if ( ! is_array( $settings ) ) {
		return $empty;
	}
	$timeout  = isset( $settings['gateway_timeout'] ) ? skyphusion_ai_gateway_number_clean( $settings['gateway_timeout'], 120000 ) : '';
	$attempts = isset( $settings['gateway_attempts'] ) ? skyphusion_ai_gateway_number_clean( $settings['gateway_attempts'], 5 ) : '';
	$delay    = isset( $settings['gateway_delay'] ) ? skyphusion_ai_gateway_number_clean( $settings['gateway_delay'], 60000 ) : '';
	$backoff  = '';
	if ( isset( $settings['gateway_backoff'] ) && in_array( (string) $settings['gateway_backoff'], array( 'constant', 'linear', 'exponential' ), true ) ) {
		$backoff = (string) $settings['gateway_backoff'];
	}
	$media = '0';
	if ( isset( $settings['gateway_retry_media'] ) && '1' === (string) $settings['gateway_retry_media'] ) {
		$media = '1';
	}
	return array(
		'timeout'  => $timeout,
		'attempts' => $attempts,
		'delay'    => $delay,
		'backoff'  => $backoff,
		'media'    => $media,
	);
}

/**
 * Longest wait, in milliseconds, the gateway may spend on one call.
 *
 * Every caller that sends these headers posts with a 30 second HTTP timeout.
 * Past that limit PHP gives up, the gateway keeps retrying a request nobody
 * reads, and the timeout looks like a refusal (issue #230). Two seconds stay
 * free for the connection and the reply.
 *
 * @return int
 */
function skyphusion_ai_gateway_wait_limit() {
	return 28000;
}

/**
 * Worst case wait for the saved retry values, in milliseconds.
 *
 * @param int    $timeout  Timeout of one attempt.
 * @param int    $attempts Attempts.
 * @param int    $delay    Delay between attempts.
 * @param string $backoff  constant, linear, exponential, or empty.
 * @return int
 */
function skyphusion_ai_gateway_wait_worst( $timeout, $attempts, $delay, $backoff ) {
	$gaps  = max( 0, $attempts - 1 );
	$waits = $delay * $gaps;
	if ( 'linear' === $backoff ) {
		$waits = $delay * (int) ( $gaps * ( $gaps + 1 ) / 2 );
	} elseif ( 'exponential' === $backoff ) {
		$waits = $delay * ( ( 1 << $gaps ) - 1 );
	}
	return $timeout * $attempts + $waits;
}

/**
 * Cut the timeout and the attempts so the gateway finishes inside the caller.
 *
 * Attempts go first, then the timeout. A blank timeout sends no header, so
 * there is nothing to measure and the values stay as saved.
 *
 * @param string $timeout  Timeout in milliseconds, or empty.
 * @param string $attempts Attempts, or empty.
 * @param string $delay    Delay in milliseconds, or empty.
 * @param string $backoff  Backoff name, or empty.
 * @return array{timeout: string, attempts: string}
 */
function skyphusion_ai_gateway_wait_fit( $timeout, $attempts, $delay, $backoff ) {
	if ( '' === $timeout ) {
		return array(
			'timeout'  => $timeout,
			'attempts' => $attempts,
		);
	}
	$limit = skyphusion_ai_gateway_wait_limit();
	$wait  = (int) $timeout;
	$tries = '' === $attempts ? 1 : (int) $attempts;
	$pause = '' === $delay ? 0 : (int) $delay;
	while ( 1 < $tries && skyphusion_ai_gateway_wait_worst( $wait, $tries, $pause, $backoff ) > $limit ) {
		--$tries;
	}
	if ( $wait > $limit ) {
		$wait = $limit;
	}
	return array(
		'timeout'  => (string) $wait,
		'attempts' => '' === $attempts ? '' : (string) $tries,
	);
}

/**
 * Retry and timeout headers for a gateway URL.
 *
 * A direct URL gets none. Image and speech get none until the admin opts in.
 * A blank field is left off the request.
 *
 * @param string $url     Request URL.
 * @param string $feature Feature name.
 * @return array<string, string>
 */
function skyphusion_ai_gateway_retry_headers( $url, $feature ) {
	if ( ! skyphusion_ai_gateway_host( $url ) ) {
		return array();
	}
	$settings = skyphusion_ai_gateway_retry_settings();
	$kind     = skyphusion_ai_gateway_feature( $feature );
	if ( ( 'image' === $kind || 'speech' === $kind ) && '1' !== $settings['media'] ) {
		return array();
	}
	$headers = array();

	$fitted               = skyphusion_ai_gateway_wait_fit( $settings['timeout'], $settings['attempts'], $settings['delay'], $settings['backoff'] );
	$settings['timeout']  = $fitted['timeout'];
	$settings['attempts'] = $fitted['attempts'];
	if ( '' !== $settings['timeout'] ) {
		$headers['cf-aig-request-timeout'] = $settings['timeout'];
	}
	if ( '' !== $settings['attempts'] ) {
		$headers['cf-aig-max-attempts'] = $settings['attempts'];
	}
	if ( '' !== $settings['delay'] ) {
		$headers['cf-aig-retry-delay'] = $settings['delay'];
	}
	if ( '' !== $settings['backoff'] ) {
		$headers['cf-aig-backoff'] = $settings['backoff'];
	}
	return $headers;
}

/**
 * Header for a gateway URL, or an empty array for any other URL.
 *
 * @param string $url     Request URL.
 * @param string $feature Feature name.
 * @param string $model   Model id.
 * @param string $tool    Tool name.
 * @return array<string, string>
 */
function skyphusion_ai_gateway_metadata_header( $url, $feature, $model, $tool = '' ) {
	if ( ! skyphusion_ai_gateway_host( $url ) ) {
		return array();
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		return array();
	}
	$encoded = wp_json_encode( skyphusion_ai_gateway_metadata( $feature, $model, $tool ) );
	if ( ! is_string( $encoded ) || '' === $encoded ) {
		return array();
	}
	$headers = array(
		'cf-aig-metadata'            => $encoded,
		'cf-aig-collect-log-payload' => skyphusion_ai_gateway_collect_value( $feature ),
	);
	if ( function_exists( 'skyphusion_ai_defaults_cache_header' ) ) {
		$headers = array_merge( $headers, skyphusion_ai_defaults_cache_header() );
	}
	$headers = array_merge( $headers, skyphusion_ai_gateway_retry_headers( $url, $feature ) );
	return $headers;
}

/**
 * Headers for one Workers AI POST from the AI Client.
 *
 * The feature and the model come from the neuron call already held for
 * that request. This function does not read the prompt.
 *
 * @param string $url   Request URL.
 * @param string $token Connectors token.
 * @return array<string, string>
 */
function skyphusion_ai_gateway_transport_headers( $url, $token ) {
	$feature = 'text';
	$model   = '';
	if ( isset( $GLOBALS['skyphusion_ai_neuron_call'] ) && is_array( $GLOBALS['skyphusion_ai_neuron_call'] ) ) {
		$call = $GLOBALS['skyphusion_ai_neuron_call'];
		if ( isset( $call['feature'] ) ) {
			$feature = (string) $call['feature'];
		}
		if ( isset( $call['model'] ) ) {
			$model = (string) $call['model'];
		}
	}
	$headers = array(
		'Content-Type'  => 'application/json',
		'Authorization' => 'Bearer ' . $token,
	);
	return array_merge( $headers, skyphusion_ai_gateway_metadata_header( $url, $feature, $model, 'provider' ) );
}

/**
 * Log-prompts value to store. Only 1 is on.
 *
 * @param mixed $original Previous settings.
 * @param mixed $posted   Posted settings, or null.
 * @return string
 */
function skyphusion_ai_gateway_log_stored( $original, $posted ) {
	$previous = '0';
	if ( is_array( $original ) && isset( $original['log_prompts'] ) && '1' === (string) $original['log_prompts'] ) {
		$previous = '1';
	}
	if ( ! is_array( $posted ) || ! array_key_exists( 'log_prompts', $posted ) ) {
		return $previous;
	}
	return '1' === (string) $posted['log_prompts'] ? '1' : '0';
}

/**
 * Dynamic route slug to store. Empty clears it.
 *
 * @param mixed $original Previous settings.
 * @param mixed $posted   Posted settings, or null.
 * @return string
 */
function skyphusion_ai_gateway_route_stored( $original, $posted ) {
	$previous = '';
	if ( is_array( $original ) && isset( $original['gateway_route'] ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', (string) $original['gateway_route'] ) ) {
		$previous = (string) $original['gateway_route'];
	}
	if ( ! is_array( $posted ) || ! array_key_exists( 'gateway_route', $posted ) ) {
		return $previous;
	}
	$typed = trim( (string) $posted['gateway_route'] );
	if ( '' === $typed ) {
		return '';
	}
	if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $typed ) ) {
		return $previous;
	}
	return $typed;
}

/**
 * Keep the gateway choices after the settings rebuild.
 *
 * Options.php has already checked the settings nonce.
 *
 * @param mixed  $value    Sanitized settings.
 * @param string $option   Option name.
 * @param mixed  $original Previous stored value.
 * @return mixed
 */
function skyphusion_ai_gateway_sanitize_option( $value, $option = '', $original = null ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( '' !== $option && 'skyphusion_ai_settings' !== $option ) {
		return $value;
	}
	$posted = null;
	if ( isset( $_POST['skyphusion_ai_settings'] ) && is_array( $_POST['skyphusion_ai_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php checks the settings nonce before this filter.
		$posted = $_POST['skyphusion_ai_settings']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash runs next when that function exists.
		if ( function_exists( 'wp_unslash' ) ) {
			$posted = wp_unslash( $posted );
		}
	}
	$value['log_prompts']         = skyphusion_ai_gateway_log_stored( $original, $posted );
	$value['gateway_route']       = skyphusion_ai_gateway_route_stored( $original, $posted );
	$value['gateway_timeout']     = skyphusion_ai_gateway_number_stored( $original, $posted, 'gateway_timeout', 120000 );
	$value['gateway_attempts']    = skyphusion_ai_gateway_number_stored( $original, $posted, 'gateway_attempts', 5 );
	$value['gateway_delay']       = skyphusion_ai_gateway_number_stored( $original, $posted, 'gateway_delay', 60000 );
	$value['gateway_backoff']     = skyphusion_ai_gateway_backoff_stored( $original, $posted );
	$value['gateway_retry_media'] = skyphusion_ai_gateway_media_stored( $original, $posted );
	return $value;
}

/**
 * Checkbox for prompt logging.
 *
 * @return void
 */
function skyphusion_ai_field_log_prompts() {
	if ( ! function_exists( 'esc_html' ) ) {
		return;
	}
	echo '<input type="hidden" name="skyphusion_ai_settings[log_prompts]" value="0" />';
	echo '<label><input type="checkbox" name="skyphusion_ai_settings[log_prompts]" value="1"';
	if ( skyphusion_ai_gateway_log_prompts() ) {
		echo ' checked="checked"';
	}
	echo ' /> ' . esc_html__( 'Log prompts in AI Gateway. Embedding calls stay off.', 'skyphusion-ai' ) . '</label>';
}

/**
 * Optional dynamic route slug.
 *
 * @return void
 */
function skyphusion_ai_field_gateway_route() {
	if ( ! function_exists( 'esc_attr' ) || ! function_exists( 'esc_html' ) ) {
		return;
	}
	printf(
		'<input type="text" class="regular-text code" name="skyphusion_ai_settings[gateway_route]" value="%s" maxlength="64" spellcheck="false" autocomplete="off" />',
		esc_attr( skyphusion_ai_gateway_route() )
	);
	echo '<p class="description">';
	echo esc_html__( 'Text and chat calls use this route on the chat path.', 'skyphusion-ai' ) . ' ';
	echo esc_html__( 'The model id is dynamic/ plus this route.', 'skyphusion-ai' ) . ' ';
	echo esc_html__( 'Image, speech, and embeddings stay on the model path.', 'skyphusion-ai' ) . ' ';
	echo esc_html__( 'An image failure on this route does not switch the site to the direct API.', 'skyphusion-ai' );
	echo '</p>';
}

/**
 * Retry and timeout fields. Blank values send no header.
 *
 * @return void
 */
function skyphusion_ai_field_gateway_retry() {
	if ( ! function_exists( 'esc_attr' ) || ! function_exists( 'esc_html' ) ) {
		return;
	}
	$settings = skyphusion_ai_gateway_retry_settings();
	echo '<p class="description">' . esc_html__( 'Blank fields send no header.', 'skyphusion-ai' ) . '</p>';
	echo '<p><label>' . esc_html__( 'Timeout in milliseconds', 'skyphusion-ai' ) . ' ';
	printf(
		'<input type="text" class="small-text code" name="skyphusion_ai_settings[gateway_timeout]" value="%s" maxlength="6" spellcheck="false" autocomplete="off" />',
		esc_attr( $settings['timeout'] )
	);
	echo '</label></p>';
	echo '<p><label>' . esc_html__( 'Attempts, from 1 to 5', 'skyphusion-ai' ) . ' ';
	printf(
		'<input type="text" class="small-text code" name="skyphusion_ai_settings[gateway_attempts]" value="%s" maxlength="1" spellcheck="false" autocomplete="off" />',
		esc_attr( $settings['attempts'] )
	);
	echo '</label></p>';
	echo '<p><label>' . esc_html__( 'Delay in milliseconds', 'skyphusion-ai' ) . ' ';
	printf(
		'<input type="text" class="small-text code" name="skyphusion_ai_settings[gateway_delay]" value="%s" maxlength="5" spellcheck="false" autocomplete="off" />',
		esc_attr( $settings['delay'] )
	);
	echo '</label></p>';
	echo '<p><label>' . esc_html__( 'Backoff', 'skyphusion-ai' ) . ' ';
	echo '<select name="skyphusion_ai_settings[gateway_backoff]">';
	echo '<option value="">' . esc_html__( 'Blank', 'skyphusion-ai' ) . '</option>';
	foreach ( array( 'constant', 'linear', 'exponential' ) as $choice ) {
		echo '<option value="' . esc_attr( $choice ) . '"';
		if ( $settings['backoff'] === $choice ) {
			echo ' selected="selected"';
		}
		echo '>' . esc_html( $choice ) . '</option>';
	}
	echo '</select></label></p>';
	echo '<input type="hidden" name="skyphusion_ai_settings[gateway_retry_media]" value="0" />';
	echo '<p><label><input type="checkbox" name="skyphusion_ai_settings[gateway_retry_media]" value="1"';
	if ( '1' === $settings['media'] ) {
		echo ' checked="checked"';
	}
	echo ' /> ' . esc_html__( 'Also send these headers on image and speech calls.', 'skyphusion-ai' ) . '</label></p>';
	echo '<p class="description">' . esc_html__( 'Image and speech stay off until you turn this on.', 'skyphusion-ai' ) . '</p>';
	echo '<p class="description">' . esc_html__( 'A refused gateway still uses the direct API for one hour.', 'skyphusion-ai' ) . '</p>';
}

/**
 * Register the gateway fields.
 *
 * @return void
 */
function skyphusion_ai_gateway_register_fields() {
	if ( ! function_exists( 'add_settings_field' ) ) {
		return;
	}
	add_settings_field( 'log_prompts', __( 'Log prompts', 'skyphusion-ai' ), 'skyphusion_ai_field_log_prompts', 'cloudflare-ai-search', 'workers' );
	add_settings_field( 'gateway_route', __( 'Dynamic route', 'skyphusion-ai' ), 'skyphusion_ai_field_gateway_route', 'cloudflare-ai-search', 'workers' );
	add_settings_field( 'gateway_retry', __( 'Retry and timeout', 'skyphusion-ai' ), 'skyphusion_ai_field_gateway_retry', 'cloudflare-ai-search', 'workers' );
}

/**
 * Tell the admin when the direct API is in use.
 *
 * @return void
 */
function skyphusion_ai_gateway_admin_notice() {
	if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$text = skyphusion_ai_gateway_notice_text();
	if ( '' === $text || ! function_exists( 'esc_html' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>' . esc_html( $text ) . '</p></div>';
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'admin_init', 'skyphusion_ai_gateway_register_fields', 30 );
	add_action( 'admin_notices', 'skyphusion_ai_gateway_admin_notice' );
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'sanitize_option_skyphusion_ai_settings', 'skyphusion_ai_gateway_sanitize_option', 20, 3 );
}
