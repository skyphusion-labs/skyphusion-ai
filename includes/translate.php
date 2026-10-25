<?php
/**
 * Translate a string with the Workers AI translation model.
 *
 * Other themes and plugins call skyphusion_ai_translate(). A spent neuron
 * budget does not leave the site. The error text does not include the token.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model id for translation.
 *
 * @return string
 */
function skyphusion_ai_translate_model() {
	return '@cf/meta/m2m100-1.2b';
}

/**
 * Longest text this helper sends, in characters.
 *
 * @return int
 */
function skyphusion_ai_translate_cap() {
	return 2000;
}

/**
 * A two letter language code, or an empty string.
 *
 * @param string $code Language code.
 * @return string
 */
function skyphusion_ai_translate_lang( $code ) {
	$code = strtolower( trim( (string) $code ) );
	if ( preg_match( '/^[a-z]{2}$/', $code ) ) {
		return $code;
	}
	return '';
}

/**
 * Translated text from a Workers AI body.
 *
 * @param mixed $decoded Response JSON.
 * @return string
 */
function skyphusion_ai_translate_read( $decoded ) {
	if ( ! is_array( $decoded ) ) {
		return '';
	}
	if ( isset( $decoded['result']['translated_text'] ) ) {
		return trim( (string) $decoded['result']['translated_text'] );
	}
	if ( isset( $decoded['translated_text'] ) ) {
		return trim( (string) $decoded['translated_text'] );
	}
	return '';
}

/**
 * Translate one string.
 *
 * An empty source uses the model default. The same source and target
 * returns the original string and does not call the model.
 *
 * @param string $text   Text to translate.
 * @param string $target Two letter target code.
 * @param string $source Two letter source code, or empty.
 * @return string|WP_Error
 */
function skyphusion_ai_translate( $text, $target, $source = '' ) {
	$text = trim( (string) $text );
	if ( '' === $text ) {
		return new WP_Error( 'skyphusion_ai_translate', __( 'The text is empty.', 'skyphusion-ai' ) );
	}
	if ( function_exists( 'mb_strlen' ) ) {
		$length = mb_strlen( $text );
	} else {
		$length = strlen( $text );
	}
	if ( $length > skyphusion_ai_translate_cap() ) {
		return new WP_Error( 'skyphusion_ai_translate', __( 'The text is too long.', 'skyphusion-ai' ) );
	}
	$target = skyphusion_ai_translate_lang( $target );
	if ( '' === $target ) {
		return new WP_Error( 'skyphusion_ai_translate', __( 'The target language needs a two letter code.', 'skyphusion-ai' ) );
	}
	$source_in = trim( (string) $source );
	$source    = '';
	if ( '' !== $source_in ) {
		$source = skyphusion_ai_translate_lang( $source_in );
		if ( '' === $source ) {
			return new WP_Error( 'skyphusion_ai_translate', __( 'The source language needs a two letter code.', 'skyphusion-ai' ) );
		}
	}
	if ( '' !== $source && $source === $target ) {
		return $text;
	}
	if ( function_exists( 'skyphusion_ai_neuron_refusal' ) ) {
		$refusal = skyphusion_ai_neuron_refusal();
		if ( is_wp_error( $refusal ) ) {
			return $refusal;
		}
	}
	$model       = skyphusion_ai_translate_model();
	$credentials = array(
		'account_id' => '',
		'token'      => '',
	);
	if ( function_exists( 'skyphusion_ai_embed_credentials' ) ) {
		$credentials = skyphusion_ai_embed_credentials();
	}
	$gateway = '';
	if ( function_exists( 'skyphusion_ai_settings' ) ) {
		$settings = skyphusion_ai_settings();
		if ( isset( $settings['gateway_id'] ) ) {
			$gateway = (string) $settings['gateway_id'];
		}
	}
	$url = '';
	if ( function_exists( 'cloudflare_ai_provider_run_url' ) && is_array( $credentials ) ) {
		$account = isset( $credentials['account_id'] ) ? (string) $credentials['account_id'] : '';
		$url     = cloudflare_ai_provider_run_url( $account, $gateway, $model );
	}
	$token = ( is_array( $credentials ) && isset( $credentials['token'] ) ) ? (string) $credentials['token'] : '';
	if ( '' === $url || '' === $token || ! function_exists( 'wp_remote_post' ) ) {
		return new WP_Error( 'skyphusion_ai_translate', __( 'The text could not be translated.', 'skyphusion-ai' ) );
	}
	$payload = array(
		'text'        => $text,
		'target_lang' => $target,
	);
	if ( '' !== $source ) {
		$payload['source_lang'] = $source;
	}
	$headers = array(
		'Content-Type'  => 'application/json',
		'Authorization' => 'Bearer ' . $token,
	);
	if ( function_exists( 'skyphusion_ai_gateway_metadata_header' ) ) {
		$headers = array_merge( $headers, skyphusion_ai_gateway_metadata_header( $url, 'text', $model ) );
	}
	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 30,
			'headers' => $headers,
			'body'    => wp_json_encode( $payload ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'skyphusion_ai_translate', __( 'The text could not be translated.', 'skyphusion-ai' ) );
	}
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( function_exists( 'skyphusion_ai_neuron_record' ) ) {
		skyphusion_ai_neuron_record( 'text', $model, $decoded );
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return new WP_Error( 'skyphusion_ai_translate', __( 'The text could not be translated.', 'skyphusion-ai' ) );
	}
	$translated = skyphusion_ai_translate_read( $decoded );
	if ( '' === $translated ) {
		return new WP_Error( 'skyphusion_ai_translate', __( 'The text could not be translated.', 'skyphusion-ai' ) );
	}
	return $translated;
}
