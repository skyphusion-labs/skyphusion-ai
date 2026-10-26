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
 * Note a failed Workers AI response. A success does nothing.
 *
 * @param string $url      Request URL.
 * @param string $feature  Feature name.
 * @param mixed  $response HTTP response or WP_Error.
 * @return void
 */
function skyphusion_ai_gateway_note( $url, $feature, $response ) {
	if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
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
 * @param string $feature Feature name.
 * @param string $model   Model id.
 * @return array{site: string, feature: string, model: string, user: string, version: string}
 */
function skyphusion_ai_gateway_metadata( $feature, $model ) {
	$clean_model = '';
	if ( function_exists( 'cloudflare_ai_provider_model_id' ) ) {
		$clean_model = cloudflare_ai_provider_model_id( $model );
	}
	$version = '0.5.3';
	if ( defined( 'SKYPHUSION_AI_VERSION' ) ) {
		$version = (string) SKYPHUSION_AI_VERSION;
	}
	return array(
		'site'    => skyphusion_ai_gateway_site(),
		'feature' => skyphusion_ai_gateway_feature( $feature ),
		'model'   => $clean_model,
		'user'    => skyphusion_ai_gateway_user(),
		'version' => $version,
	);
}

/**
 * Header for a gateway URL, or an empty array for any other URL.
 *
 * @param string $url     Request URL.
 * @param string $feature Feature name.
 * @param string $model   Model id.
 * @return array<string, string>
 */
function skyphusion_ai_gateway_metadata_header( $url, $feature, $model ) {
	if ( ! skyphusion_ai_gateway_host( $url ) ) {
		return array();
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		return array();
	}
	$encoded = wp_json_encode( skyphusion_ai_gateway_metadata( $feature, $model ) );
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
	return array_merge( $headers, skyphusion_ai_gateway_metadata_header( $url, $feature, $model ) );
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
	$value['log_prompts']   = skyphusion_ai_gateway_log_stored( $original, $posted );
	$value['gateway_route'] = skyphusion_ai_gateway_route_stored( $original, $posted );
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
	echo '<p class="description">' . esc_html__( 'An image failure on this route does not switch the site to the direct API.', 'skyphusion-ai' ) . '</p>';
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
