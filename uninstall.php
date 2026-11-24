<?php
/**
 * Remove the saved settings, the search counts, the neuron row, and the model list when the plugin is deleted.
 *
 * @package Skyphusion_AI
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'skyphusion_ai_settings' );
delete_option( 'cloudflare_ai_search_settings' );
delete_option( 'skyphusion_ai_db_version' );
delete_option( 'skyphusion_ai_search_outcomes' );
delete_option( 'skyphusion_ai_neurons' );
delete_option( 'skyphusion_ai_vectorize' );
delete_option( 'skyphusion_ai_embed_estimate' );
delete_option( 'skyphusion_ai_embed_count' );
// One running count option for each admin (issue 231).
global $wpdb;
if ( isset( $wpdb ) && is_object( $wpdb ) ) {
	$embed_count_rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'skyphusion_ai_embed_count_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the list exists so uninstall can delete the rows.
	foreach ( (array) $embed_count_rows as $embed_count_row ) {
		delete_option( (string) $embed_count_row );
	}
}
delete_option( 'skyphusion_ai_insights' );
delete_option( 'skyphusion_ai_assistant' );
delete_option( 'skyphusion_ai_member' );
delete_option( 'skyphusion_ai_worker' );
delete_option( 'skyphusion_ai_assistant_chats' );
delete_option( 'skyphusion_ai_backfill' );
delete_option( 'skyphusion_ai_prompts' );
delete_option( 'skyphusion_ai_prompt_last' );
delete_option( 'skyphusion_ai_activity' );
delete_option( 'skyphusion_ai_model_licences' );
delete_transient( 'skyphusion_ai_write_next' );
delete_transient( 'skyphusion_ai_link_linked' );
delete_transient( 'skyphusion_ai_models' );
