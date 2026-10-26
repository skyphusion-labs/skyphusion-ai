<?php
/**
 * Plugin Name: Skyphusion AI for Cloudflare
 * Plugin URI: https://github.com/skyphusion-labs/skyphusion-ai
 * Description: Workers AI, AI Gateway, and AI Search for WordPress and WooCommerce. Connect models from Settings, then Connectors, and search from Settings, then Skyphusion AI.
 * Version: 0.5.3
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Conrad Rockenhaus (skyphusion)
 * Author URI: https://skyphusion.org
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: skyphusion-ai
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SKYPHUSION_AI_VERSION', '0.5.3');
define('SKYPHUSION_AI_FILE', __FILE__);

require_once __DIR__ . '/includes/endpoint.php';
require_once __DIR__ . '/includes/routes.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/defaults.php';
require_once __DIR__ . '/includes/upgrade.php';
require_once __DIR__ . '/includes/stock.php';
require_once __DIR__ . '/includes/search-stock.php';
require_once __DIR__ . '/includes/frontend.php';
require_once __DIR__ . '/includes/cli.php';
require_once __DIR__ . '/includes/provider.php';
require_once __DIR__ . '/includes/health.php';
require_once __DIR__ . '/includes/insights.php';
require_once __DIR__ . '/includes/assistant.php';
require_once __DIR__ . '/includes/backfill.php';
require_once __DIR__ . '/includes/translate.php';
require_once __DIR__ . '/includes/links.php';
require_once __DIR__ . '/includes/prompts.php';
require_once __DIR__ . '/includes/member.php';
require_once __DIR__ . '/includes/worker.php';
require_once __DIR__ . '/includes/deprecated.php';

function skyphusion_ai_activate()
{
    if (get_option('skyphusion_ai_settings', null) === null && get_option( 'cloudflare_ai_search_settings', null ) === null) {
        add_option('skyphusion_ai_settings', skyphusion_ai_defaults());
    }
}
register_activation_hook(__FILE__, 'skyphusion_ai_activate');
