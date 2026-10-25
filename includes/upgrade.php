<?php
/**
 * Upgrade routine for the settings option key.
 *
 * Phase 1 renamed the stored option and left a read fallback in
 * skyphusion_ai_settings(). register_activation_hook() does not run when
 * an already-active plugin is updated, so an install configured before
 * the rename keeps its values only under the old key until something
 * writes the new one. The settings screen writes that key. WP-CLI writes
 * it too, from includes/cli.php. Neither runs on its own just because
 * the plugin files were replaced. People configure a plugin once.
 *
 * This copy runs on plugins_loaded, so the first request after the new
 * code is on disk writes the new key. That request may be the storefront,
 * wp-admin, cron, or WP-CLI. A version watermark then makes every later
 * request return before any write. The copy is not inside
 * skyphusion_ai_settings(), which several callers hit on one page view.
 *
 * The old key is not deleted. A rollback to a release that only reads
 * the old key still finds it. The read fallback stays in this release,
 * so a request that arrives before the copy still returns the same
 * settings it returns today.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copy the legacy settings option once per plugin version.
 *
 * Priority 1 so this runs before cloudflare_ai_provider_boot(), which is
 * on the same hook at the default priority. Nothing in this plugin reads
 * settings while the files are loading. The copy still lands before init,
 * which is where the storefront and the admin read them.
 *
 * The watermark option is skyphusion_ai_db_version. It stores the plugin
 * version whose routine finished. It is not a settings value. A normal
 * request reads it, sees the current version, and returns. The write is
 * the first request after an update, plus any later request that has to
 * retry because the copy did not land. A failed copy does not stamp the
 * version, so the next request tries again. Uninstall deletes the
 * watermark with the settings keys.
 *
 * @return void
 */
function skyphusion_ai_upgrade() {
	if ( ! defined( 'SKYPHUSION_AI_VERSION' ) ) {
		return;
	}

	$seen = get_option( 'skyphusion_ai_db_version', '' );
	if ( ! is_string( $seen ) ) {
		$seen = '';
	}
	if ( SKYPHUSION_AI_VERSION === $seen ) {
		return;
	}

	if ( ! skyphusion_ai_migrate_settings_key() ) {
		return;
	}

	update_option( 'skyphusion_ai_db_version', SKYPHUSION_AI_VERSION );
}

add_action( 'plugins_loaded', 'skyphusion_ai_upgrade', 1 );

/**
 * Write skyphusion_ai_settings from the old key when the new key is absent.
 *
 * The copy uses add_option(), which does not overwrite. The settings
 * screen and WP-CLI both write the new key, and a request that lost
 * the race keeps their value instead of putting the old array back.
 *
 * The stored value is copied raw. skyphusion_ai_settings() merges
 * defaults and normalises on read, so the copy has to be the same array
 * the fallback reads today. Normalising it here would store something
 * the old key does not hold.
 *
 * @return bool True when no retry is required. False when the copy did not land.
 */
function skyphusion_ai_migrate_settings_key() {
	if ( null !== get_option( 'skyphusion_ai_settings', null ) ) {
		return true;
	}

	$legacy = get_option( 'cloudflare_ai_search_settings', null );
	if ( null === $legacy ) {
		return true;
	}

	$added = add_option( 'skyphusion_ai_settings', $legacy );
	return false !== $added;
}
