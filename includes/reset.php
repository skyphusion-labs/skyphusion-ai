<?php
/**
 * Reset this plugin without removing text already stored on posts.
 *
 * Settings, prompt edits, usage, backfill progress, caches, and search
 * logs are deleted. Meta descriptions, alt text, and the embed hash
 * stay. The Vectorize index is not deleted. A credential in wp-config
 * stays. This does not print a secret and it does not send mail.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option names this reset deletes.
 *
 * The connector credential option is not in this list.
 *
 * @return string[]
 */
function skyphusion_ai_reset_option_names() {
	return array(
		'skyphusion_ai_settings',
		'cloudflare_ai_search_settings',
		'skyphusion_ai_db_version',
		'skyphusion_ai_search_outcomes',
		'skyphusion_ai_neurons',
		'skyphusion_ai_vectorize',
		'skyphusion_ai_insights',
		'skyphusion_ai_assistant',
		'skyphusion_ai_assistant_chats',
		'skyphusion_ai_member',
		'skyphusion_ai_worker',
		'skyphusion_ai_backfill',
		'skyphusion_ai_prompts',
		'skyphusion_ai_prompt_last',
		'skyphusion_ai_gateway_down',
	);
}

/**
 * Transient names this reset always deletes.
 *
 * Hashed prompt-cache names are deleted by the prefix sweep.
 *
 * @return string[]
 */
function skyphusion_ai_reset_transient_names() {
	return array(
		'skyphusion_ai_models',
		'skyphusion_ai_write_next',
		'skyphusion_ai_embed_store_status',
	);
}

/**
 * Post meta this reset never deletes.
 *
 * These values are text already stored on posts, or a record that the
 * text is already in the index.
 *
 * @return string[]
 */
function skyphusion_ai_reset_kept_meta_keys() {
	return array(
		'_yoast_wpseo_metadesc',
		'rank_math_description',
		'_seopress_titles_desc',
		'_wp_attachment_image_alt',
		'skyphusion_ai_embed_hash',
		'_skyphusion_ai_decorative_alt',
	);
}

/**
 * True when the admin ticked the confirm box.
 *
 * @param mixed $posted Posted fields.
 * @return bool
 */
function skyphusion_ai_reset_confirmed( $posted ) {
	if ( ! is_array( $posted ) ) {
		return false;
	}
	if ( ! isset( $posted['skyphusion_ai_reset_confirm'] ) ) {
		return false;
	}
	return '1' === (string) $posted['skyphusion_ai_reset_confirm'];
}

/**
 * Delete one option when WordPress can.
 *
 * @param string $name Option name.
 * @return void
 */
function skyphusion_ai_reset_delete_option( $name ) {
	if ( function_exists( 'delete_option' ) ) {
		delete_option( $name );
	}
}

/**
 * Delete one transient when WordPress can.
 *
 * @param string $name Transient name.
 * @return void
 */
function skyphusion_ai_reset_delete_transient( $name ) {
	if ( function_exists( 'delete_transient' ) ) {
		delete_transient( $name );
	}
}

/**
 * Delete transients whose names start with skyphusion_ai_.
 *
 * A database sweep runs when WordPress exposes $wpdb. The in-memory
 * store is the test stand-in and is also cleared.
 *
 * @return int
 */
function skyphusion_ai_reset_sweep_transients() {
	$deleted = 0;
	foreach ( skyphusion_ai_reset_transient_names() as $name ) {
		skyphusion_ai_reset_delete_transient( $name );
		++$deleted;
	}
	if ( isset( $GLOBALS['cfais_store'] ) && is_array( $GLOBALS['cfais_store'] ) ) {
		foreach ( array_keys( $GLOBALS['cfais_store'] ) as $name ) {
			$name = (string) $name;
			if ( 0 !== strpos( $name, 'skyphusion_ai_' ) ) {
				continue;
			}
			skyphusion_ai_reset_delete_transient( $name );
			++$deleted;
		}
	}
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'prepare' ) || ! isset( $wpdb->options ) ) {
		return $deleted;
	}
	$like = $wpdb->esc_like( '_transient_skyphusion_ai_' ) . '%';
	// Transient names are not a cached query. The list exists so reset can delete them.
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( ! is_array( $names ) ) {
		return $deleted;
	}
	foreach ( $names as $row_name ) {
		$row_name = (string) $row_name;
		$prefix   = '_transient_';
		if ( 0 !== strpos( $row_name, $prefix ) ) {
			continue;
		}
		skyphusion_ai_reset_delete_transient( substr( $row_name, strlen( $prefix ) ) );
		++$deleted;
	}
	return $deleted;
}

/**
 * Drop only this plugin's Cloudflare approval. Other rows stay.
 *
 * @return void
 */
function skyphusion_ai_reset_approval() {
	if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
		return;
	}
	$approvals = get_option( 'wpai_connector_approvals', null );
	if ( ! is_array( $approvals ) ) {
		return;
	}
	$basename = 'skyphusion-ai/skyphusion-ai.php';
	if ( defined( 'SKYPHUSION_AI_FILE' ) && function_exists( 'plugin_basename' ) ) {
		$basename = (string) plugin_basename( SKYPHUSION_AI_FILE );
	}
	if ( ! isset( $approvals[ $basename ] ) || ! is_array( $approvals[ $basename ] ) ) {
		return;
	}
	if ( ! array_key_exists( 'cloudflare', $approvals[ $basename ] ) ) {
		return;
	}
	unset( $approvals[ $basename ]['cloudflare'] );
	if ( array() === $approvals[ $basename ] ) {
		unset( $approvals[ $basename ] );
	}
	update_option( 'wpai_connector_approvals', $approvals, false );
}

/**
 * Clear plugin state. Post text and the Vectorize index stay.
 *
 * The return value has no secret.
 *
 * @return array{options: int, transients: int, index: string}
 */
function skyphusion_ai_reset_apply() {
	$count = 0;
	foreach ( skyphusion_ai_reset_option_names() as $name ) {
		skyphusion_ai_reset_delete_option( $name );
		++$count;
	}
	$transients = skyphusion_ai_reset_sweep_transients();
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'skyphusion_ai_backfill_hourly' );
	}
	skyphusion_ai_reset_approval();
	return array(
		'options'    => $count,
		'transients' => $transients,
		'index'      => 'kept',
	);
}

/**
 * Notice text. It does not include a secret.
 *
 * @return string
 */
function skyphusion_ai_reset_notice() {
	return __( 'Settings, prompts, usage, and search logs are cleared. Text already stored on posts is kept. The Vectorize index is kept.', 'skyphusion-ai' );
}

/**
 * The confirm box was missing.
 *
 * @return string
 */
function skyphusion_ai_reset_skipped_notice() {
	return __( 'Reset was not run. Tick the box first.', 'skyphusion-ai' );
}

/**
 * Button for an admin who can manage the site.
 *
 * @return void
 */
function skyphusion_ai_reset_render() {
	if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! function_exists( 'esc_html' ) || ! function_exists( 'esc_url' ) || ! function_exists( 'admin_url' ) ) {
		return;
	}
	echo '<h2>' . esc_html__( 'Reset this plugin', 'skyphusion-ai' ) . '</h2>';
	echo '<p>' . esc_html( skyphusion_ai_reset_notice() ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="skyphusion_ai_reset" />';
	if ( function_exists( 'wp_nonce_field' ) ) {
		wp_nonce_field( 'skyphusion_ai_reset' );
	}
	echo '<p><label><input type="checkbox" name="skyphusion_ai_reset_confirm" value="1" /> ';
	echo esc_html__( 'Clear the saved plugin state.', 'skyphusion-ai' ) . '</label></p>';
	echo '<button type="submit" class="button">' . esc_html__( 'Reset this plugin', 'skyphusion-ai' ) . '</button>';
	echo '</form>';
}

/**
 * Run the reset after the admin confirms it.
 *
 * @return void
 */
function skyphusion_ai_reset_handle() {
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
		if ( function_exists( 'wp_die' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'skyphusion-ai' ), '', array( 'response' => 403 ) );
		}
		return;
	}
	if ( function_exists( 'check_admin_referer' ) ) {
		check_admin_referer( 'skyphusion_ai_reset' );
	}
	$posted = array();
	if ( isset( $_POST['skyphusion_ai_reset_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer ran above.
		$posted['skyphusion_ai_reset_confirm'] = (string) sanitize_text_field( wp_unslash( $_POST['skyphusion_ai_reset_confirm'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer ran above.
	}
	$message = skyphusion_ai_reset_skipped_notice();
	if ( skyphusion_ai_reset_confirmed( $posted ) ) {
		skyphusion_ai_reset_apply();
		$message = skyphusion_ai_reset_notice();
	}
	if ( function_exists( 'set_transient' ) && function_exists( 'get_current_user_id' ) ) {
		set_transient(
			'skyphusion_ai_check_' . get_current_user_id(),
			array(
				'ok'      => true,
				'message' => $message,
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
	add_action( 'admin_post_skyphusion_ai_reset', 'skyphusion_ai_reset_handle' );
}
