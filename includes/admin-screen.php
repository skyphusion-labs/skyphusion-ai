<?php
/**
 * Settings screen layout.
 *
 * The fields stay where they are. This file only groups them.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load the settings stylesheet on this screen only.
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function skyphusion_ai_admin_screen_assets( $hook ) {
	if ( 'settings_page_cloudflare-ai-search' !== $hook ) {
		return;
	}
	if ( ! defined( 'SKYPHUSION_AI_FILE' ) || ! defined( 'SKYPHUSION_AI_VERSION' ) ) {
		return;
	}
	wp_enqueue_style(
		'skyphusion-ai-admin',
		plugins_url( 'assets/admin.css', SKYPHUSION_AI_FILE ),
		array(),
		SKYPHUSION_AI_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'skyphusion_ai_admin_screen_assets' );

/**
 * Open one panel.
 *
 * @param string $id Panel id.
 * @return void
 */
function skyphusion_ai_admin_panel_open( $id ) {
	echo '<section class="skyphusion-ai-panel" id="' . esc_attr( $id ) . '">';
}

/**
 * Close one panel.
 *
 * @return void
 */
function skyphusion_ai_admin_panel_close() {
	echo '</section>';
}

/**
 * Jump links for the panels on this page.
 *
 * @return void
 */
function skyphusion_ai_admin_nav() {
	$links = array(
		'skyphusion-ai-connection' => __( 'Connection', 'skyphusion-ai' ),
		'skyphusion-ai-check'      => __( 'Check', 'skyphusion-ai' ),
		'skyphusion-ai-defaults'   => __( 'Defaults', 'skyphusion-ai' ),
		'skyphusion-ai-activity'   => __( 'Activity', 'skyphusion-ai' ),
		'skyphusion-ai-embeddings' => __( 'Embeddings', 'skyphusion-ai' ),
		'skyphusion-ai-topics'     => __( 'Topics', 'skyphusion-ai' ),
		'skyphusion-ai-assistant'  => __( 'Assistant', 'skyphusion-ai' ),
		'skyphusion-ai-members'    => __( 'Members', 'skyphusion-ai' ),
		'skyphusion-ai-worker'     => __( 'Worker', 'skyphusion-ai' ),
		'skyphusion-ai-empty'      => __( 'Empty text', 'skyphusion-ai' ),
		'skyphusion-ai-prompts'    => __( 'Prompts', 'skyphusion-ai' ),
		'skyphusion-ai-reset'      => __( 'Reset', 'skyphusion-ai' ),
	);
	echo '<nav class="skyphusion-ai-nav" aria-label="' . esc_attr__( 'Settings sections', 'skyphusion-ai' ) . '">';
	foreach ( $links as $id => $label ) {
		echo '<a href="#' . esc_attr( $id ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</nav>';
}

/**
 * Print the settings screen.
 *
 * @return void
 */
function skyphusion_ai_admin_screen() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$user_id  = get_current_user_id();
	$check_key = 'skyphusion_ai_check_' . $user_id;
	$check     = get_transient( $check_key );
	if ( is_array( $check ) ) {
		delete_transient( $check_key );
	}

	echo '<div class="wrap skyphusion-ai-screen">';
	echo '<h1>' . esc_html__( 'Skyphusion AI for Cloudflare', 'skyphusion-ai' ) . '</h1>';
	echo '<p class="skyphusion-ai-lead">' . esc_html__( 'Workers AI, AI Gateway, and AI Search. Visitors can still search this site in their own words.', 'skyphusion-ai' ) . '</p>';
	skyphusion_ai_render_product_links();
	skyphusion_ai_admin_nav();
	skyphusion_ai_render_search_outcomes();

	if ( is_array( $check ) ) {
		$class = ! empty( $check['ok'] ) ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( (string) $check['message'] ) . '</p></div>';
	}
	settings_errors( 'cloudflare_ai_search' );

	skyphusion_ai_admin_panel_open( 'skyphusion-ai-connection' );
	echo '<form method="post" action="options.php">';
	settings_fields( 'cloudflare_ai_search' );
	do_settings_sections( 'cloudflare-ai-search' );
	submit_button( __( 'Save changes', 'skyphusion-ai' ) );
	echo '</form>';
	skyphusion_ai_admin_panel_close();

	skyphusion_ai_admin_panel_open( 'skyphusion-ai-check' );
	echo '<h2>' . esc_html__( 'Check the connection', 'skyphusion-ai' ) . '</h2>';
	echo '<p>' . esc_html__( 'Save first. This asks Cloudflare one question and tells you whether the address answers. An empty answer still counts as connected while Cloudflare is still reading the site.', 'skyphusion-ai' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'skyphusion_ai_test' );
	echo '<input type="hidden" name="action" value="skyphusion_ai_test" />';
	submit_button( __( 'Check connection', 'skyphusion-ai' ), 'secondary', 'submit', false );
	echo '</form>';
	skyphusion_ai_admin_panel_close();

	if ( ! function_exists( 'skyphusion_ai_embed_render' ) ) {
		require_once __DIR__ . '/embed.php';
	}

	$panels = array(
		'skyphusion-ai-defaults'   => 'skyphusion_ai_defaults_render',
		'skyphusion-ai-activity'   => 'skyphusion_ai_activity_render',
		'skyphusion-ai-embeddings' => 'skyphusion_ai_embed_render',
		'skyphusion-ai-topics'     => 'skyphusion_ai_insight_render',
		'skyphusion-ai-assistant'  => 'skyphusion_ai_assistant_render',
		'skyphusion-ai-members'    => 'skyphusion_ai_member_render',
		'skyphusion-ai-worker'     => 'skyphusion_ai_worker_render',
		'skyphusion-ai-empty'      => 'skyphusion_ai_backfill_render',
		'skyphusion-ai-prompts'    => 'skyphusion_ai_prompt_render',
		'skyphusion-ai-reset'      => 'skyphusion_ai_reset_render',
	);
	foreach ( $panels as $id => $callback ) {
		if ( ! function_exists( $callback ) ) {
			continue;
		}
		skyphusion_ai_admin_panel_open( $id );
		call_user_func( $callback );
		skyphusion_ai_admin_panel_close();
	}

	echo '</div>';
}
