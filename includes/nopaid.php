<?php
/**
 * Keep paid providers out of the WordPress AI fallback list.
 *
 * The WordPress AI plugin tries Anthropic, Google, and OpenAI before
 * any other provider. This switch removes those three from that list.
 * It is on when the setting is missing. This plugin still registers
 * only the provider id cloudflare.
 *
 * AIPCF filters the same three lists in plugin.php at priority 99.
 * That filter is off unless the merchant turns it on, and it does
 * nothing until an account id and a token are both saved. This one
 * is on by default, and it does not wait for those values. A list
 * that contains only the three paid providers becomes empty. AIPCF
 * puts the paid list back in that case.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider ids the switch removes.
 *
 * @return array<int, string>
 */
function skyphusion_ai_nopaid_blocked_ids() {
	return array( 'anthropic', 'google', 'openai' );
}

/**
 * The only provider id this plugin registers.
 *
 * @return string
 */
function skyphusion_ai_nopaid_provider_id() {
	return 'cloudflare';
}

/**
 * Preferred-model filters the WordPress AI plugin applies.
 *
 * Text, image, and vision are the three fallback lists.
 *
 * @return array<int, string>
 */
function skyphusion_ai_nopaid_hooks() {
	return array(
		'wpai_preferred_text_models',
		'wpai_preferred_image_models',
		'wpai_preferred_vision_models',
	);
}

/**
 * Run after other plugins prepend their own rows.
 *
 * @return int
 */
function skyphusion_ai_nopaid_priority() {
	return 99;
}

/**
 * True when paid providers stay out of the fallback list.
 *
 * A missing setting is on. Stored 0 is off.
 *
 * @param mixed $settings Settings row, or null to read the option.
 * @return bool
 */
function skyphusion_ai_nopaid_on( $settings = null ) {
	if ( null === $settings ) {
		if ( function_exists( 'skyphusion_ai_settings' ) ) {
			$settings = skyphusion_ai_settings();
		} else {
			$settings = array();
		}
	}
	if ( ! is_array( $settings ) || ! array_key_exists( 'no_paid_fallback', $settings ) ) {
		return true;
	}
	$value = $settings['no_paid_fallback'];
	if ( false === $value || 0 === $value || '0' === $value || '' === $value ) {
		return false;
	}
	return true;
}

/**
 * Value to store for the switch.
 *
 * A settings save that omits the field keeps the previous choice.
 * A missing previous choice stays on. Posted 1 is on. Anything else is off.
 *
 * @param mixed $original Previous settings.
 * @param mixed $posted   Posted settings, or null.
 * @return string
 */
function skyphusion_ai_nopaid_stored( $original, $posted ) {
	$previous = '1';
	if ( is_array( $original ) && array_key_exists( 'no_paid_fallback', $original ) ) {
		$previous = skyphusion_ai_nopaid_on( $original ) ? '1' : '0';
	}
	if ( ! is_array( $posted ) || ! array_key_exists( 'no_paid_fallback', $posted ) ) {
		return $previous;
	}
	if ( '1' === (string) $posted['no_paid_fallback'] ) {
		return '1';
	}
	return '0';
}

/**
 * Model id to use when the fallback list has no Cloudflare row.
 *
 * The WordPress AI lists name only the three paid providers. Stripping
 * them leaves nothing. An empty list makes the client pick whichever
 * provider is registered. One Cloudflare row keeps the request here.
 *
 * @param string $job writing, image, or vision.
 * @return string
 */
function skyphusion_ai_nopaid_fallback_model( $job ) {
	if ( function_exists( 'skyphusion_ai_model_choice' ) ) {
		$chosen = skyphusion_ai_model_choice( $job );
		if ( is_string( $chosen ) && '' !== $chosen ) {
			return $chosen;
		}
	}
	$builtin = array(
		'writing' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
		'image'   => '@cf/black-forest-labs/flux-1-schnell',
		'vision'  => '@cf/mistralai/mistral-small-3.1-24b-instruct',
	);
	if ( isset( $builtin[ $job ] ) ) {
		return $builtin[ $job ];
	}
	return '';
}

/**
 * Keep Cloudflare rows. Drop every other provider.
 *
 * Each row is a provider id, then a model id. A Cloudflare model whose
 * id contains the word google stays, because the provider id is cloudflare.
 * When no Cloudflare row remains, one is added for this job. The paid
 * rows are not put back.
 *
 * @param mixed  $models   Preferred rows.
 * @param mixed  $settings Settings row, or null to read the option.
 * @param string $job      writing, image, or vision.
 * @return array<int, mixed>
 */
function skyphusion_ai_nopaid_filter_models( $models, $settings = null, $job = 'writing' ) {
	if ( ! is_array( $models ) ) {
		return array();
	}
	if ( ! skyphusion_ai_nopaid_on( $settings ) ) {
		return $models;
	}
	$provider = skyphusion_ai_nopaid_provider_id();
	$kept     = array();
	foreach ( $models as $row ) {
		if ( ! is_array( $row ) || ! isset( $row[0] ) || ! is_scalar( $row[0] ) ) {
			continue;
		}
		$id = strtolower( trim( (string) $row[0] ) );
		if ( $provider !== $id ) {
			continue;
		}
		$kept[] = $row;
	}
	if ( array() !== $kept ) {
		return $kept;
	}
	$model = skyphusion_ai_nopaid_fallback_model( $job );
	if ( '' === $model ) {
		return array();
	}
	return array(
		array( $provider, $model ),
	);
}

/**
 * Text fallback list.
 *
 * @param mixed $models Preferred rows.
 * @return array<int, mixed>
 */
function skyphusion_ai_nopaid_filter_text( $models ) {
	return skyphusion_ai_nopaid_filter_models( $models, null, 'writing' );
}

/**
 * Image fallback list.
 *
 * @param mixed $models Preferred rows.
 * @return array<int, mixed>
 */
function skyphusion_ai_nopaid_filter_image( $models ) {
	return skyphusion_ai_nopaid_filter_models( $models, null, 'image' );
}

/**
 * Vision fallback list.
 *
 * @param mixed $models Preferred rows.
 * @return array<int, mixed>
 */
function skyphusion_ai_nopaid_filter_vision( $models ) {
	return skyphusion_ai_nopaid_filter_models( $models, null, 'vision' );
}

/**
 * Keep the switch on the settings option.
 *
 * The settings sanitizer rebuilds the row from its own defaults. This
 * filter puts the switch back after that rebuild. Options.php has
 * already checked the settings nonce.
 *
 * @param mixed  $value    Sanitized settings.
 * @param string $option   Option name.
 * @param mixed  $original Previous stored value.
 * @return mixed
 */
function skyphusion_ai_nopaid_sanitize_option( $value, $option = '', $original = null ) {
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
	$value['no_paid_fallback'] = skyphusion_ai_nopaid_stored( $original, $posted );
	return $value;
}

/**
 * Checkbox on the Workers AI section.
 *
 * @return void
 */
function skyphusion_ai_nopaid_field() {
	if ( ! function_exists( 'esc_html__' ) ) {
		return;
	}
	echo '<input type="hidden" name="skyphusion_ai_settings[no_paid_fallback]" value="0" />';
	echo '<label><input type="checkbox" name="skyphusion_ai_settings[no_paid_fallback]" value="1"';
	if ( skyphusion_ai_nopaid_on() ) {
		echo ' checked="checked"';
	}
	echo ' /> ' . esc_html__( 'Keep Anthropic, Google, and OpenAI out of the fallback list.', 'skyphusion-ai' ) . '</label>';
	echo '<p class="description">' . esc_html__( 'On until you turn it off.', 'skyphusion-ai' ) . '</p>';
}

/**
 * Register the checkbox.
 *
 * @return void
 */
function skyphusion_ai_nopaid_register_field() {
	if ( ! function_exists( 'add_settings_field' ) ) {
		return;
	}
	add_settings_field(
		'no_paid_fallback',
		__( 'Paid providers', 'skyphusion-ai' ),
		'skyphusion_ai_nopaid_field',
		'cloudflare-ai-search',
		'workers'
	);
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'admin_init', 'skyphusion_ai_nopaid_register_field', 30 );
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'wpai_preferred_text_models', 'skyphusion_ai_nopaid_filter_text', skyphusion_ai_nopaid_priority() );
	add_filter( 'wpai_preferred_image_models', 'skyphusion_ai_nopaid_filter_image', skyphusion_ai_nopaid_priority() );
	add_filter( 'wpai_preferred_vision_models', 'skyphusion_ai_nopaid_filter_vision', skyphusion_ai_nopaid_priority() );
	add_filter( 'sanitize_option_skyphusion_ai_settings', 'skyphusion_ai_nopaid_sanitize_option', 40, 3 );
}
