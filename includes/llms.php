<?php
/**
 * Optional /llms.txt for AI crawlers.
 *
 * Off until an admin turns it on. The file does not edit posts.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * True when the site should serve /llms.txt.
 *
 * @return bool
 */
function skyphusion_ai_llms_on() {
	if ( ! function_exists( 'skyphusion_ai_settings' ) ) {
		return false;
	}
	$settings = skyphusion_ai_settings();
	return is_array( $settings ) && ! empty( $settings['llms_txt'] );
}

/**
 * Build the llms.txt body. Pure aside from bloginfo helpers.
 *
 * @param array{name?: string, home?: string, description?: string, endpoint?: string} $parts Optional overrides for tests.
 * @return string
 */
function skyphusion_ai_llms_body( $parts = array() ) {
	$name     = isset( $parts['name'] ) ? (string) $parts['name'] : '';
	$home     = isset( $parts['home'] ) ? (string) $parts['home'] : '';
	$desc     = isset( $parts['description'] ) ? (string) $parts['description'] : '';
	$endpoint = isset( $parts['endpoint'] ) ? (string) $parts['endpoint'] : '';

	if ( '' === $name && function_exists( 'get_bloginfo' ) ) {
		$name = (string) get_bloginfo( 'name' );
	}
	if ( '' === $home && function_exists( 'home_url' ) ) {
		$home = (string) home_url( '/' );
	}
	if ( '' === $desc && function_exists( 'get_bloginfo' ) ) {
		$desc = (string) get_bloginfo( 'description' );
	}
	if ( '' === $endpoint && function_exists( 'skyphusion_ai_settings' ) ) {
		$settings = skyphusion_ai_settings();
		if ( is_array( $settings ) && isset( $settings['endpoint'] ) ) {
			$endpoint = (string) $settings['endpoint'];
		}
	}

	$lines   = array();
	$lines[] = '# ' . ( '' !== $name ? $name : 'WordPress' );
	$lines[] = '';
	if ( '' !== $desc ) {
		$lines[] = '> ' . $desc;
		$lines[] = '';
	}
	if ( '' !== $home ) {
		$lines[] = '- Home: ' . $home;
	}
	if ( '' !== $endpoint ) {
		$lines[] = '- AI Search: ' . $endpoint;
	}
	$lines[] = '- Ability: skyphusion-ai/search (read-only; MCP Adapter required)';
	$lines[] = '- Provider: skyphusion-ai (WordPress AI Client)';
	$lines[] = '';
	$lines[] = 'This file is published by Skyphusion AI for Cloudflare.';
	$lines[] = 'It does not grant write access. Checkout stays a person.';
	$lines[] = '';

	return implode( "\n", $lines );
}

/**
 * Request path with no query and no trailing slash.
 *
 * @param string $uri Request URI.
 * @return string
 */
function skyphusion_ai_llms_request_path( $uri ) {
	$uri  = (string) $uri;
	$path = '';
	if ( function_exists( 'wp_parse_url' ) ) {
		$parsed = wp_parse_url( $uri, PHP_URL_PATH );
		$path   = is_string( $parsed ) ? $parsed : '';
	}
	if ( '' === $path ) {
		$bits = explode( '?', $uri, 2 );
		$path = (string) $bits[0];
	}
	$path = rtrim( $path, '/' );
	if ( '' === $path ) {
		return '/';
	}
	if ( '/' !== substr( $path, 0, 1 ) ) {
		$path = '/' . $path;
	}
	return $path;
}

/**
 * The llms.txt file directly under the home path.
 *
 * @return string
 */
function skyphusion_ai_llms_home_file() {
	$prefix = '';
	if ( function_exists( 'home_url' ) && function_exists( 'wp_parse_url' ) ) {
		$home   = (string) home_url( '/' );
		$parsed = wp_parse_url( $home, PHP_URL_PATH );
		if ( is_string( $parsed ) ) {
			$prefix = trim( $parsed, '/' );
		}
	}
	if ( '' === $prefix ) {
		return '/llms.txt';
	}
	return '/' . $prefix . '/llms.txt';
}

/**
 * True when the request path is the llms.txt file under the home path.
 *
 * A path that only ends in /llms.txt is not a match.
 *
 * @param string $uri Request URI.
 * @return bool
 */
function skyphusion_ai_llms_path( $uri ) {
	return skyphusion_ai_llms_home_file() === skyphusion_ai_llms_request_path( $uri );
}

/**
 * Headers for the llms.txt response.
 *
 * @return array<int, string>
 */
function skyphusion_ai_llms_response_headers() {
	return array(
		'Content-Type: text/plain; charset=utf-8',
		'X-Content-Type-Options: nosniff',
	);
}

/**
 * Serve /llms.txt when the switch is on.
 *
 * @return void
 */
function skyphusion_ai_llms_serve() {
	if ( ! skyphusion_ai_llms_on() ) {
		return;
	}
	$uri = '';
	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- path check only; no storage.
		$raw = (string) $_SERVER['REQUEST_URI'];
		$uri = function_exists( 'wp_unslash' ) ? (string) wp_unslash( $raw ) : $raw;
	}
	if ( ! skyphusion_ai_llms_path( $uri ) ) {
		return;
	}
	if ( function_exists( 'nocache_headers' ) ) {
		nocache_headers();
	}
	$capture = ! empty( $GLOBALS['skyphusion_ai_llms_capture'] );
	foreach ( skyphusion_ai_llms_response_headers() as $header_line ) {
		if ( $capture ) {
			if ( ! isset( $GLOBALS['skyphusion_ai_llms_sent'] ) || ! is_array( $GLOBALS['skyphusion_ai_llms_sent'] ) ) {
				$GLOBALS['skyphusion_ai_llms_sent'] = array();
			}
			$GLOBALS['skyphusion_ai_llms_sent'][] = $header_line;
			continue;
		}
		header( $header_line );
	}
	echo skyphusion_ai_llms_body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text file for crawlers.
	if ( $capture ) {
		return;
	}
	exit;
}

/**
 * Keep llms_txt on the settings option.
 *
 * @param mixed  $value    Sanitized settings.
 * @param string $option   Option name.
 * @param mixed  $original Prior value.
 * @return mixed
 */
function skyphusion_ai_llms_sanitize_option( $value, $option = '', $original = null ) {
	unset( $option );
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( isset( $_POST['skyphusion_ai_settings'] ) && is_array( $_POST['skyphusion_ai_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php checks the settings nonce before this filter.
		$posted = wp_unslash( $_POST['skyphusion_ai_settings'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- bool only.
		if ( ! is_array( $posted ) ) {
			$posted = array();
		}
		// A settings submit with no llms_txt key is an unticked box.
		$value['llms_txt'] = ! empty( $posted['llms_txt'] );
		return $value;
	}
	if ( array_key_exists( 'llms_txt', $value ) ) {
		$value['llms_txt'] = ! empty( $value['llms_txt'] );
		return $value;
	}
	$value['llms_txt'] = is_array( $original ) && ! empty( $original['llms_txt'] );
	return $value;
}

/**
 * Apply --llms-txt from WP-CLI without touching the space-indented set map.
 *
 * @param array<string, mixed> $clean   Sanitized settings.
 * @param array<string, mixed> $assoc   CLI flags.
 * @param array<string, mixed> $current Prior settings.
 * @return array<string, mixed>
 */
function skyphusion_ai_llms_apply_cli( $clean, $assoc, $current ) {
	if ( ! is_array( $clean ) ) {
		$clean = array();
	}
	if ( array_key_exists( 'llms-txt', $assoc ) && function_exists( 'skyphusion_ai_cli_truthy' ) ) {
		$clean['llms_txt'] = skyphusion_ai_cli_truthy( $assoc['llms-txt'] );
		return $clean;
	}
	$clean['llms_txt'] = is_array( $current ) && ! empty( $current['llms_txt'] );
	return $clean;
}

/**
 * Checkbox on the display settings section.
 *
 * @return void
 */
function skyphusion_ai_field_llms_txt() {
	$settings = function_exists( 'skyphusion_ai_settings' ) ? skyphusion_ai_settings() : array();
	$on       = is_array( $settings ) && ! empty( $settings['llms_txt'] );
	echo '<input type="hidden" name="skyphusion_ai_settings[llms_txt]" value="0" />';
	echo '<label><input type="checkbox" name="skyphusion_ai_settings[llms_txt]" value="1" ' . checked( $on, true, false ) . ' /> ';
	echo esc_html__( 'Publish /llms.txt for AI crawlers', 'skyphusion-ai' );
	echo '</label>';
	echo '<p class="description">' . esc_html__( 'Off until you turn it on. The file names the site, the home URL, and the AI Search address when set. It does not edit posts.', 'skyphusion-ai' ) . '</p>';
}

/**
 * Register the llms.txt field.
 *
 * @return void
 */
function skyphusion_ai_llms_register_fields() {
	if ( ! function_exists( 'add_settings_field' ) ) {
		return;
	}
	add_settings_field(
		'llms_txt',
		__( 'llms.txt', 'skyphusion-ai' ),
		'skyphusion_ai_field_llms_txt',
		'cloudflare-ai-search',
		'display'
	);
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'template_redirect', 'skyphusion_ai_llms_serve', 0 );
	add_action( 'admin_init', 'skyphusion_ai_llms_register_fields', 40 );
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'sanitize_option_skyphusion_ai_settings', 'skyphusion_ai_llms_sanitize_option', 25, 3 );
}
