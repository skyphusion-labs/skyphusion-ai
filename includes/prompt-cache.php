<?php
/**
 * Remember a repeated prompt for ten minutes.
 *
 * The key is a hash of the feature, the model, and the prompt. It does
 * not contain an IP address. The public search box does not use this cache.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How long a remembered answer stays, in seconds.
 *
 * @return int
 */
function skyphusion_ai_prompt_cache_ttl() {
	return 600;
}

/**
 * Cache key. The prompt is hashed. An IP address is not part of the key.
 *
 * @param string $feature Feature name.
 * @param string $model   Model id.
 * @param string $prompt  Prompt text.
 * @param string $extra   Extra text that changes the answer, such as an image address.
 * @return string
 */
function skyphusion_ai_prompt_cache_key( $feature, $model, $prompt, $extra = '' ) {
	$raw = (string) $feature . "\n" . (string) $model . "\n" . (string) $prompt . "\n" . (string) $extra;
	return 'skyphusion_ai_pc_' . hash( 'sha256', $raw );
}

/**
 * A remembered answer, or null.
 *
 * A regenerate request does not read the cache.
 *
 * @param string $feature    Feature name.
 * @param string $model      Model id.
 * @param string $prompt     Prompt text.
 * @param string $extra      Extra text.
 * @param bool   $regenerate Whether this call asks for a new answer.
 * @return string|null
 */
function skyphusion_ai_prompt_cache_read( $feature, $model, $prompt, $extra = '', $regenerate = false ) {
	if ( function_exists( 'skyphusion_ai_defaults_cache_on' ) && ! skyphusion_ai_defaults_cache_on() ) {
		return null;
	}
	if ( $regenerate || ! function_exists( 'get_transient' ) ) {
		return null;
	}
	$row = get_transient( skyphusion_ai_prompt_cache_key( $feature, $model, $prompt, $extra ) );
	if ( ! is_array( $row ) || ! isset( $row['text'] ) || ! is_string( $row['text'] ) || '' === $row['text'] ) {
		return null;
	}
	return $row['text'];
}

/**
 * Remember one answer.
 *
 * @param string $feature Feature name.
 * @param string $model   Model id.
 * @param string $prompt  Prompt text.
 * @param string $extra   Extra text.
 * @param string $text    Answer text.
 * @return void
 */
function skyphusion_ai_prompt_cache_write( $feature, $model, $prompt, $extra, $text ) {
	if ( function_exists( 'skyphusion_ai_defaults_cache_on' ) && ! skyphusion_ai_defaults_cache_on() ) {
		return;
	}
	$text = trim( (string) $text );
	if ( '' === $text || ! function_exists( 'set_transient' ) ) {
		return;
	}
	set_transient(
		skyphusion_ai_prompt_cache_key( $feature, $model, $prompt, $extra ),
		array( 'text' => $text ),
		skyphusion_ai_prompt_cache_ttl()
	);
}
