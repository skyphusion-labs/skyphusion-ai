<?php
/**
 * Remove the saved settings when the plugin is deleted from the dashboard.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('skyphusion_ai_settings');
delete_option( 'cloudflare_ai_search_settings' );
