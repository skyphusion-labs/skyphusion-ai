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
	$name = isset( $parts['name'] ) ? (string) $parts['name'] : '';
	$home = isset( $parts['home'] ) ? (string) $parts['home'] : '';
	$desc = isset( $parts['description'] ) ? (string) $parts['description'] : '';
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
 * True when the request path is /llms.txt.
 *
 * @param string $uri Request URI.
 * @return bool
 */
function skyphusion_ai_llms_path( $uri ) {
	$path = (string) parse_url( (string) $uri, PHP_URL_PATH );
	if ( '' === $path ) {
		$path = (string) $uri;
	}
	$path = rtrim( $path, '/' );
	return '/llms.txt' === $path || 'llms.txt' === $path || substr( $path, -9 ) === '/llms.txt';
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
	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo skyphusion_ai_llms_body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text file for crawlers.
	exit;
}

/**
 * Keep llms_txt on the settings option.
 *
 * @param mixed $value    Sanitized settings.
 * @param string $option  Option name.
 * @param mixed $original Prior value.
 * @return mixed
 */
function skyphusion_ai_llms_sanitize_option( $value, $option = '', $original = null ) {
	unset( $option );
	if ( ! is_array( $value ) ) {
		return $value;
	}
	$posted = array();
	if ( isset( $_POST['skyphusion_ai_settings'] ) && is_array( $_POST['skyphusion_ai_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- settings screen nonce already checked.
		$posted = wp_unslash( $_POST['skyphusion_ai_settings'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- bool only.
	}
	if ( array_key_exists( 'llms_txt', $posted ) ) {
		$on = ! empty( $posted['llms_txt'] );
	} elseif ( array_key_exists( 'llms_txt', $value ) ) {
		$on = ! empty( $value['llms_txt'] );
	} elseif ( is_array( $original ) && ! empty( $original['llms_txt'] ) ) {
		$on = true;
	} else {
		$on = false;
	}
	$value['llms_txt'] = $on;
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
