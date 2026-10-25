<?php
/**
 * Remove the saved settings, the search counts, and the neuron row when the plugin is deleted.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('skyphusion_ai_settings');
delete_option( 'cloudflare_ai_search_settings' );
delete_option( 'skyphusion_ai_db_version' );
delete_option( 'skyphusion_ai_search_outcomes' );
delete_option( 'skyphusion_ai_neurons' );
