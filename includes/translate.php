<?php
/**
 * Translate a string with a Workers AI translation model.
 *
 * English into one of 22 Indian languages uses IndicTrans2. Every other
 * pair uses M2M100. The helper does not send the translation prompt.
 * A spent neuron budget does not leave the site. The error text does
 * not include the token.
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
 * Model id for IndicTrans2.
 *
 * This model translates English into the 22 scheduled languages of India.
 * It is not a writing model.
 *
 * @return string
 */
function skyphusion_ai_translate_indic_model() {
	return '@cf/ai4bharat/indictrans2-en-indic-1B';
}

/**
 * Language code to IndicTrans2 code for the 22 scheduled languages.
 *
 * English is not in this list. Kashmiri uses Devanagari. Sindhi uses
 * Devanagari. Manipuri uses Bengali. Konkani uses the code kok.
 *
 * @return array<string, string>
 */
function skyphusion_ai_translate_indic_codes() {
	return array(
		'as'  => 'asm_Beng',
		'bn'  => 'ben_Beng',
		'brx' => 'brx_Deva',
		'doi' => 'doi_Deva',
		'gu'  => 'guj_Gujr',
		'hi'  => 'hin_Deva',
		'kn'  => 'kan_Knda',
		'kok' => 'gom_Deva',
		'ks'  => 'kas_Deva',
		'mai' => 'mai_Deva',
		'ml'  => 'mal_Mlym',
		'mni' => 'mni_Beng',
		'mr'  => 'mar_Deva',
		'ne'  => 'npi_Deva',
		'or'  => 'ory_Orya',
		'pa'  => 'pan_Guru',
		'sa'  => 'san_Deva',
		'sat' => 'sat_Olck',
		'sd'  => 'snd_Deva',
		'ta'  => 'tam_Taml',
		'te'  => 'tel_Telu',
		'ur'  => 'urd_Arab',
	);
}

/**
 * Model id for one language pair.
 *
 * An empty source counts as English. IndicTrans2 is used only then,
 * and only when the target is one of the 22 languages.
 *
 * @param string $target Target code.
 * @param string $source Source code, or empty.
 * @return string
 */
function skyphusion_ai_translate_model_for( $target, $source ) {
	$codes = skyphusion_ai_translate_indic_codes();
	if ( isset( $codes[ $target ] ) && ( '' === $source || 'en' === $source ) ) {
		return skyphusion_ai_translate_indic_model();
	}
	return skyphusion_ai_translate_model();
}

/**
 * Request body for one translation model.
 *
 * IndicTrans2 takes target_language. M2M100 takes target_lang.
 * The text is the caller string. The translation prompt is not added.
 *
 * @param string $model  Model id.
 * @param string $text   Text to translate.
 * @param string $target Target code.
 * @param string $source Source code, or empty.
 * @return array<string, string>
 */
function skyphusion_ai_translate_payload( $model, $text, $target, $source ) {
	if ( skyphusion_ai_translate_indic_model() === $model ) {
		$codes = skyphusion_ai_translate_indic_codes();
		$code  = isset( $codes[ $target ] ) ? $codes[ $target ] : '';
		return array(
			'text'            => $text,
			'target_language' => $code,
		);
	}
	$payload = array(
		'text'        => $text,
		'target_lang' => $target,
	);
	if ( '' !== $source ) {
		$payload['source_lang'] = $source;
	}
	return $payload;
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
 * A language code, or an empty string.
 *
 * Two letters are accepted for M2M100. A three letter code is accepted
 * only when it is one of the 22 IndicTrans2 languages.
 *
 * @param string $code Language code.
 * @return string
 */
function skyphusion_ai_translate_lang( $code ) {
	$code = strtolower( trim( (string) $code ) );
	if ( preg_match( '/^[a-z]{2}$/', $code ) ) {
		return $code;
	}
	$indic = skyphusion_ai_translate_indic_codes();
	if ( isset( $indic[ $code ] ) ) {
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
	if ( isset( $decoded['result']['translations'][0] ) && is_string( $decoded['result']['translations'][0] ) ) {
		return trim( $decoded['result']['translations'][0] );
	}
	if ( isset( $decoded['translations'][0] ) && is_string( $decoded['translations'][0] ) ) {
		return trim( $decoded['translations'][0] );
	}
	return '';
}

/**
 * Translate one string.
 *
 * An empty source means English. The same source and target returns
 * the original string and does not call the model. The request does
 * not include the translation prompt.
 *
 * @param string $text        Text to translate.
 * @param string $target      Two letter target code.
 * @param string $source      Two letter source code, or empty.
 * @param bool   $regenerate  Whether this call asks for a new answer.
 * @return string|WP_Error
 */
function skyphusion_ai_translate( $text, $target, $source = '', $regenerate = false ) {
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
		return new WP_Error( 'skyphusion_ai_translate', __( 'The target language needs a language code.', 'skyphusion-ai' ) );
	}
	$source_in = trim( (string) $source );
	$source    = '';
	if ( '' !== $source_in ) {
		$source = skyphusion_ai_translate_lang( $source_in );
		if ( '' === $source ) {
			return new WP_Error( 'skyphusion_ai_translate', __( 'The source language needs a language code.', 'skyphusion-ai' ) );
		}
	}
	if ( '' !== $source && $source === $target ) {
		return $text;
	}
	$model        = skyphusion_ai_translate_model_for( $target, $source );
	$cache_prompt = $source . "\n" . $target . "\n" . $text;
	if ( function_exists( 'skyphusion_ai_prompt_cache_read' ) ) {
		$cached = skyphusion_ai_prompt_cache_read( 'translate', $model, $cache_prompt, '', $regenerate );
		if ( is_string( $cached ) ) {
			if ( function_exists( 'skyphusion_ai_neuron_record' ) ) {
				skyphusion_ai_neuron_record( 'text', $model, array(), 'translate' );
			}
			return $cached;
		}
	}
	if ( function_exists( 'skyphusion_ai_neuron_refusal' ) ) {
		$refusal = skyphusion_ai_neuron_refusal();
		if ( is_wp_error( $refusal ) ) {
			return $refusal;
		}
	}
	if ( function_exists( 'skyphusion_ai_tool_refusal' ) ) {
		$tool_refusal = skyphusion_ai_tool_refusal( 'translate' );
		if ( is_wp_error( $tool_refusal ) ) {
			return $tool_refusal;
		}
	}
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
	$payload = skyphusion_ai_translate_payload( $model, $text, $target, $source );
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
		if ( function_exists( 'skyphusion_ai_gateway_note' ) ) {
			skyphusion_ai_gateway_note( $url, 'text', $response );
		}
		if ( function_exists( 'skyphusion_ai_neuron_record' ) ) {
			skyphusion_ai_neuron_record( 'text', $model, $response, 'translate' );
		}
		return new WP_Error( 'skyphusion_ai_translate', __( 'The text could not be translated.', 'skyphusion-ai' ) );
	}
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( function_exists( 'skyphusion_ai_neuron_record' ) ) {
		skyphusion_ai_neuron_record( 'text', $model, $decoded, 'translate' );
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		if ( function_exists( 'skyphusion_ai_gateway_note' ) ) {
			skyphusion_ai_gateway_note( $url, 'text', $response );
		}
		return new WP_Error( 'skyphusion_ai_translate', __( 'The text could not be translated.', 'skyphusion-ai' ) );
	}
	$translated = skyphusion_ai_translate_read( $decoded );
	if ( '' === $translated ) {
		return new WP_Error( 'skyphusion_ai_translate', __( 'The text could not be translated.', 'skyphusion-ai' ) );
	}
	if ( function_exists( 'skyphusion_ai_prompt_cache_write' ) ) {
		skyphusion_ai_prompt_cache_write( 'translate', $model, $cache_prompt, '', $translated );
	}
	return $translated;
}
