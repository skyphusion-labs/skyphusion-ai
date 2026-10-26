<?php
/**
 * One click for the recommended defaults.
 *
 * The writing model changes only when the account lists that id.
 * The daily budget is saved only when none is saved. Caching is turned
 * on. Connector approval is written only when WordPress already exposes
 * that store. Embeddings, backfill, and the assistant stay as they are.
 * This does not print a token and it does not send mail.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recommended writing model on Cloudflare Workers AI.
 *
 * @return string
 */
function skyphusion_ai_defaults_writing_id() {
	return '@cf/google/gemma-4-26b-a4b-it';
}

/**
 * True when the account list has this id as a writing model.
 *
 * The built-in fallback does not count. The account has to list the id.
 *
 * @param string $id Model id.
 * @return bool
 */
function skyphusion_ai_defaults_account_has( $id ) {
	if ( ! function_exists( 'skyphusion_ai_account_models_current' ) || ! function_exists( 'cloudflare_ai_provider_model_id' ) ) {
		return false;
	}
	$id = cloudflare_ai_provider_model_id( $id );
	if ( '' === $id ) {
		return false;
	}
	foreach ( skyphusion_ai_account_models_current() as $model ) {
		if ( ! is_array( $model ) || ! isset( $model['id'] ) || (string) $model['id'] !== $id ) {
			continue;
		}
		if ( ! function_exists( 'skyphusion_ai_account_model_kind' ) || ! function_exists( 'skyphusion_ai_model_matches' ) ) {
			return true;
		}
		$kind = skyphusion_ai_account_model_kind( isset( $model['task'] ) ? $model['task'] : '' );
		return skyphusion_ai_model_matches( 'writing', $kind[0], $kind[1] );
	}
	return false;
}

/**
 * True when prompt caching should run.
 *
 * A missing choice keeps the cache on. Zero turns it off.
 *
 * @return bool
 */
function skyphusion_ai_defaults_cache_on() {
	if ( ! function_exists( 'skyphusion_ai_settings' ) ) {
		return true;
	}
	$settings = skyphusion_ai_settings();
	if ( ! is_array( $settings ) || ! array_key_exists( 'cache', $settings ) ) {
		return true;
	}
	return '0' !== (string) $settings['cache'];
}

/**
 * Gateway cache header, or an empty array.
 *
 * Cloudflare documents cf-aig-cache-ttl and cf-aig-skip-cache at
 * https://developers.cloudflare.com/ai-gateway/features/caching/
 * A missing choice adds no header, so an older saved row stays as it was.
 *
 * @return array<string, string>
 */
function skyphusion_ai_defaults_cache_header() {
	if ( ! function_exists( 'skyphusion_ai_settings' ) ) {
		return array();
	}
	$settings = skyphusion_ai_settings();
	if ( ! is_array( $settings ) || ! isset( $settings['cache'] ) ) {
		return array();
	}
	if ( '1' === (string) $settings['cache'] ) {
		return array(
			'cf-aig-cache-ttl' => '3600',
		);
	}
	if ( '0' === (string) $settings['cache'] ) {
		return array(
			'cf-aig-skip-cache' => 'true',
		);
	}
	return array();
}

/**
 * True when the approval store is already loaded.
 *
 * This does not autoload the class and it does not install a plugin.
 * A test may set skyphusion_ai_defaults_approval_exposed. The site does not.
 *
 * @return bool
 */
function skyphusion_ai_defaults_approval_exposed() {
	if ( array_key_exists( 'skyphusion_ai_defaults_approval_exposed', $GLOBALS ) ) {
		return (bool) $GLOBALS['skyphusion_ai_defaults_approval_exposed'];
	}
	return class_exists( '\WordPress\AI\Connector_Approval\Approvals_Store', false );
}

/**
 * Approve this plugin for the Cloudflare connector, when that store exists.
 *
 * @return bool True when the approval was written.
 */
function skyphusion_ai_defaults_approve() {
	if ( ! skyphusion_ai_defaults_approval_exposed() || ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
		return false;
	}
	$basename = 'skyphusion-ai/skyphusion-ai.php';
	if ( defined( 'SKYPHUSION_AI_FILE' ) && function_exists( 'plugin_basename' ) ) {
		$basename = (string) plugin_basename( SKYPHUSION_AI_FILE );
	}
	$approvals = get_option( 'wpai_connector_approvals', array() );
	if ( ! is_array( $approvals ) ) {
		$approvals = array();
	}
	if ( ! isset( $approvals[ $basename ] ) || ! is_array( $approvals[ $basename ] ) ) {
		$approvals[ $basename ] = array();
	}
	$approvals[ $basename ]['cloudflare'] = true;
	update_option( 'wpai_connector_approvals', $approvals, false );
	return true;
}

/**
 * Apply the recommended defaults and return what changed.
 *
 * The return value has no token.
 *
 * @return array{writing: string, budget: int, cache: int, approval: int}
 */
function skyphusion_ai_defaults_apply() {
	$settings = array();
	if ( function_exists( 'skyphusion_ai_settings' ) ) {
		$settings = skyphusion_ai_settings();
	}
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}
	$result  = array(
		'writing'  => '',
		'budget'   => 0,
		'cache'    => 1,
		'approval' => 0,
	);
	$writing = skyphusion_ai_defaults_writing_id();
	if ( skyphusion_ai_defaults_account_has( $writing ) ) {
		$settings['model_writing'] = $writing;
		$result['writing']         = $writing;
	}
	$saved = function_exists( 'skyphusion_ai_neuron_cap_saved' ) && skyphusion_ai_neuron_cap_saved();
	if ( ! $saved ) {
		$settings['neuron_cap'] = 10000;
		$result['budget']       = 10000;
	}
	$settings['cache'] = '1';
	if ( function_exists( 'update_option' ) ) {
		$GLOBALS['skyphusion_ai_defaults_apply'] = $result;
		update_option( 'skyphusion_ai_settings', $settings, false );
		unset( $GLOBALS['skyphusion_ai_defaults_apply'] );
	}
	if ( skyphusion_ai_defaults_approve() ) {
		$result['approval'] = 1;
	}
	return $result;
}

/**
 * Keep the one-click values after the settings rebuild.
 *
 * The settings sanitizer drops keys it does not know. The model filter
 * then stores every built-in id when the form did not send one. This
 * filter runs after those, puts the writing model and the cache back,
 * and removes model ids this click did not choose.
 *
 * @param mixed  $value    Sanitized settings.
 * @param string $option   Option name.
 * @param mixed  $original Previous stored value.
 * @return mixed
 */
function skyphusion_ai_defaults_sanitize_option( $value, $option = '', $original = null ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( '' !== $option && 'skyphusion_ai_settings' !== $option ) {
		return $value;
	}
	$plan = array();
	if ( isset( $GLOBALS['skyphusion_ai_defaults_apply'] ) && is_array( $GLOBALS['skyphusion_ai_defaults_apply'] ) ) {
		$plan = $GLOBALS['skyphusion_ai_defaults_apply'];
	}
	if ( array() === $plan ) {
		if ( is_array( $original ) && array_key_exists( 'cache', $original ) ) {
			$value['cache'] = ( '0' === (string) $original['cache'] ) ? '0' : '1';
		}
		return $value;
	}
	$value['cache'] = '1';
	if ( ! empty( $plan['writing'] ) ) {
		$value['model_writing'] = (string) $plan['writing'];
	} elseif ( is_array( $original ) && isset( $original['model_writing'] ) ) {
		$value['model_writing'] = $original['model_writing'];
	} else {
		unset( $value['model_writing'] );
	}
	$kept = array( 'model_vision', 'model_image', 'model_speech', 'model_embeddings', 'model_light' );
	foreach ( $kept as $key ) {
		if ( is_array( $original ) && array_key_exists( $key, $original ) ) {
			$value[ $key ] = $original[ $key ];
		} else {
			unset( $value[ $key ] );
		}
	}
	if ( ! empty( $plan['budget'] ) ) {
		$value['neuron_cap'] = 10000;
	} elseif ( is_array( $original ) && array_key_exists( 'neuron_cap', $original ) ) {
		$value['neuron_cap'] = $original['neuron_cap'];
	} else {
		unset( $value['neuron_cap'] );
	}
	if ( is_array( $original ) && array_key_exists( 'neuron_over', $original ) ) {
		$value['neuron_over'] = $original['neuron_over'];
	} else {
		unset( $value['neuron_over'] );
	}
	if ( is_array( $original ) && array_key_exists( 'tool_caps', $original ) ) {
		$value['tool_caps'] = $original['tool_caps'];
	} else {
		unset( $value['tool_caps'] );
	}
	return $value;
}

/**
 * Notice text. It does not include a token.
 *
 * @param array{writing: string, budget: int, cache: int, approval: int} $result Apply result.
 * @return string
 */
function skyphusion_ai_defaults_notice( $result ) {
	$parts = array();
	if ( ! empty( $result['writing'] ) ) {
		$parts[] = __( 'The writing model is set.', 'skyphusion-ai' );
	}
	if ( ! empty( $result['budget'] ) ) {
		$parts[] = __( 'The daily budget is 10000 neurons.', 'skyphusion-ai' );
	}
	if ( ! empty( $result['cache'] ) ) {
		$parts[] = __( 'Caching is on.', 'skyphusion-ai' );
	}
	if ( ! empty( $result['approval'] ) ) {
		$parts[] = __( 'This plugin may use the Cloudflare connector.', 'skyphusion-ai' );
	}
	if ( array() === $parts ) {
		return __( 'Recommended defaults are saved.', 'skyphusion-ai' );
	}
	return implode( ' ', $parts );
}

/**
 * Button for an admin who can manage the site.
 *
 * @return void
 */
function skyphusion_ai_defaults_render() {
	if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! function_exists( 'esc_html' ) || ! function_exists( 'esc_url' ) || ! function_exists( 'admin_url' ) ) {
		return;
	}
	echo '<h2>' . esc_html__( 'Recommended defaults', 'skyphusion-ai' ) . '</h2>';
	echo '<p>' . esc_html__( 'One click sets the writing model when this account lists it, saves a daily budget of 10000 neurons when none is saved, and turns caching on.', 'skyphusion-ai' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="skyphusion_ai_defaults" />';
	if ( function_exists( 'wp_nonce_field' ) ) {
		wp_nonce_field( 'skyphusion_ai_defaults' );
	}
	echo '<button type="submit" class="button button-primary">' . esc_html__( 'Apply recommended defaults', 'skyphusion-ai' ) . '</button>';
	echo '</form>';
}

/**
 * Save the defaults after the admin clicks the button.
 *
 * @return void
 */
function skyphusion_ai_defaults_handle() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		if ( function_exists( 'wp_die' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'skyphusion-ai' ), '', array( 'response' => 403 ) );
		}
		return;
	}
	if ( function_exists( 'check_admin_referer' ) ) {
		check_admin_referer( 'skyphusion_ai_defaults' );
	}
	$result = skyphusion_ai_defaults_apply();
	if ( function_exists( 'set_transient' ) && function_exists( 'get_current_user_id' ) ) {
		set_transient(
			'skyphusion_ai_check_' . get_current_user_id(),
			array(
				'ok'      => true,
				'message' => skyphusion_ai_defaults_notice( $result ),
			),
			60
		);
	}
	if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'admin_url' ) ) {
		wp_safe_redirect( admin_url( 'options-general.php?page=cloudflare-ai-search' ) );
		exit;
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'admin_post_skyphusion_ai_defaults', 'skyphusion_ai_defaults_handle' );
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'sanitize_option_skyphusion_ai_settings', 'skyphusion_ai_defaults_sanitize_option', 30, 3 );
}
