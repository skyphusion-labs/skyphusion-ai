<?php
/**
 * wp skyphusion-ai. Same settings as Settings, then Skyphusion AI.
 */

if (!defined('ABSPATH')) {
    exit;
}

function skyphusion_ai_cli_truthy($value)
{
    $value = strtolower(trim((string) $value));
    return in_array($value, array('1', 'true', 'yes', 'on'), true);
}

/**
 * Resolve the accent colour from the parsed flags and the raw arguments.
 *
 * `--accent-color` is the DECLARED flag and WP-CLI parses it normally.
 * `--color` shipped first and still works, but WP-CLI reserves `--color` as a
 * global flag and consumes it before this command runs, so its value has to be
 * recovered from the raw arguments. That recovery is the reason this issue
 * exists; it is now a compatibility shim rather than the only way the feature
 * works.
 *
 * PRECEDENCE IS EXPLICIT because both can be passed: the declared flag wins.
 * Pure, and argv is a parameter rather than read from the superglobal, so the
 * precedence is testable without a process.
 *
 * @param array $assoc Parsed associative arguments.
 * @param array $argv  Raw process arguments, already sanitized.
 * @return array{value: string|null, deprecated: bool}
 */
function skyphusion_ai_cli_accent_color($assoc, $argv)
{
    if (array_key_exists('accent-color', $assoc)) {
        return array('value' => (string) $assoc['accent-color'], 'deprecated' => false);
    }
    // Reached only if WP-CLI ever stops reserving the global flag. Harmless
    // and cheaper than discovering it the hard way.
    if (array_key_exists('color', $assoc)) {
        return array('value' => (string) $assoc['color'], 'deprecated' => true);
    }
    foreach ($argv as $arg) {
        $arg = (string) $arg;
        if (strncmp($arg, '--color=', 8) === 0) {
            return array('value' => substr($arg, 8), 'deprecated' => true);
        }
    }
    return array('value' => null, 'deprecated' => false);
}

class Skyphusion_AI_CLI
{
    /**
     * Print the saved settings.
     *
     * ## EXAMPLES
     *
     *     wp skyphusion-ai status
     *
     * @param array $args
     * @param array $assoc
     */
    public function status($args, $assoc)
    {
        $settings = skyphusion_ai_settings();
        foreach ($settings as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            WP_CLI::log($key . '=' . $value);
        }
		foreach ( skyphusion_ai_search_outcome_status_lines() as $line ) {
			WP_CLI::log( $line );
		}
    }

    /**
     * Change settings. Omitted flags stay as they are.
     *
     * ## OPTIONS
     *
     * [--endpoint=<url>]
     * : Public address, https://something.search.ai.cloudflare.com/
     *
     * [--search-bar=<0|1>]
     * : Show the search box.
     *
     * [--modal=<0|1>]
     * : Open search on Ctrl+K or Command+K.
     *
     * [--chat=<0|1>]
     * : Show the chat button. This uses more of the Cloudflare allowance.
     *
     * [--placement=<top|shortcode>]
     * : top puts the box on every page. shortcode waits for [cloudflare_ai_search].
     *
     * [--hide-checkout=<0|1>]
     * : Hide the box and the chat button on the cart and checkout.
     *
     * [--placeholder=<text>]
     * : Text shown in the empty search box.
     *
     * [--accent-color=<hex>]
     * : Accent color, such as #1B3A32.
     *
     * [--color=<hex>]
     * : Deprecated alias for --accent-color. WP-CLI reserves --color as a global
     * : flag and consumes it, so the value is recovered from the raw arguments.
     * : Kept working because it shipped. Prefer --accent-color.
     *
     * [--theme=<auto|light|dark>]
     * : auto, light, or dark.
     *
     * [--max-results=<n>]
     * : From 1 to 20.
     *
     * [--hide-branding=<0|1>]
     * : Hide the Cloudflare name on the search box.
     *
     * [--account-id=<id>]
     * : Cloudflare account ID, 32 hex characters. Not the API token.
     *
     * [--gateway-id=<slug>]
     * : Optional AI Gateway slug. Blank calls Workers AI directly.
     *
     * [--lead-search=<0|1>]
     * : Print an AI Search answer above WordPress and WooCommerce search results.
     *
     * ## EXAMPLES
     *
     *     wp skyphusion-ai set --endpoint=https://abc.search.ai.cloudflare.com/ --accent-color=#1B3A32 --chat=0
     *     wp skyphusion-ai set --account-id=0123456789abcdef0123456789abcdef --gateway-id=store --lead-search=0
     *
     * @param array $args
     * @param array $assoc
     */
    public function set($args, $assoc)
    {
        $current = skyphusion_ai_settings();
        $input = array(
            'endpoint' => $current['endpoint'],
            'search_bar' => $current['search_bar'] ? '1' : '',
            'modal' => $current['modal'] ? '1' : '',
            'chat' => $current['chat'] ? '1' : '',
            'hide_checkout' => $current['hide_checkout'] ? '1' : '',
            'hide_branding' => $current['hide_branding'] ? '1' : '',
            'placement' => $current['placement'],
            'placeholder' => $current['placeholder'],
            'color' => $current['color'],
            'theme' => $current['theme'],
            'max_results' => (string) $current['max_results'],
            'account_id' => $current['account_id'],
            'gateway_id' => $current['gateway_id'],
            'lead_search' => $current['lead_search'] ? '1' : '',
        );

        $map = array(
            'endpoint' => 'endpoint',
            'search-bar' => 'search_bar',
            'modal' => 'modal',
            'chat' => 'chat',
            'placement' => 'placement',
            'hide-checkout' => 'hide_checkout',
            'placeholder' => 'placeholder',
            'accent-color' => 'color',
            'theme' => 'theme',
            'max-results' => 'max_results',
            'hide-branding' => 'hide_branding',
            'account-id' => 'account_id',
            'gateway-id' => 'gateway_id',
            'lead-search' => 'lead_search',
        );
        $bools = array('search_bar', 'modal', 'chat', 'hide_checkout', 'hide_branding', 'lead_search');

        $argv = array();
        if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- array_keys only supplies indexes. Each value is unslashed and sanitized here.
            foreach (array_keys($_SERVER['argv']) as $index) {
                if (isset($_SERVER['argv'][$index])) {
                    $argv[] = sanitize_text_field(wp_unslash($_SERVER['argv'][$index]));
                }
            }
        }
        $accent = skyphusion_ai_cli_accent_color($assoc, $argv);
        if ($accent['value'] !== null) {
            $assoc['accent-color'] = $accent['value'];
            unset($assoc['color']);
        }
        if ($accent['deprecated']) {
            WP_CLI::warning(__('--color is deprecated. Use --accent-color, which WP-CLI parses normally. --color still works.', 'skyphusion-ai'));
        }

        foreach ($map as $flag => $key) {
            if (!array_key_exists($flag, $assoc)) {
                continue;
            }
            if (in_array($key, $bools, true)) {
                $input[$key] = skyphusion_ai_cli_truthy($assoc[$flag]) ? '1' : '';
            } else {
                $input[$key] = $assoc[$flag];
            }
        }

        if (array_key_exists('endpoint', $assoc)) {
            $typed = trim((string) $assoc['endpoint']);
            if ($typed !== '' && skyphusion_ai_normalize_endpoint($typed) === '') {
                WP_CLI::error(__('That address has to be an https link ending in search.ai.cloudflare.com.', 'skyphusion-ai')); // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- names the required host. It does not load a file.
            }
        }
        if (array_key_exists('accent-color', $assoc)) {
            // FAILS CLOSED. The old form made the validator's own availability
            // a CONDITION of the outer if, which skipped the whole check when
            // it was missing and
            // let the command report "Saved." having changed nothing. A
            // validation that disappears with its validator permits exactly the
            // case nobody tested, so absence is an error rather than a pass.
            //
            // THE OLD FORM IS DESCRIBED AND NOT QUOTED, deliberately.
            // tests/settings-sanitize-test.php greps THIS FILE for it, and a
            // text search cannot tell code from a comment about code: quoting
            // it here puts the defect back in the scanner's search space and
            // reds the guard on a correct tree. Measured, twice in one day on
            // two repos.
            //
            // THE KEY IS `accent-color` AND NOT `color` (PR #48). The shim in
            // skyphusion_ai_cli_accent_color() runs earlier, normalises
            // `--color` into `accent-color` and unsets `color`, so by here the
            // deprecated flag has already become the declared one. Fixing the
            // fail-open on the OLD key, which is what this change originally
            // did, would have left the live path unguarded.
            if (!function_exists('sanitize_hex_color')) {
                WP_CLI::error(__('Cannot validate the accent color: sanitize_hex_color() is unavailable.', 'skyphusion-ai'));
            }
            if (!sanitize_hex_color((string) $assoc['accent-color'])) {
                WP_CLI::error(__('Accent color has to be a hex color such as #1B3A32.', 'skyphusion-ai'));
            }
        }
        if (array_key_exists('account-id', $assoc)) {
            $typed = trim((string) $assoc['account-id']);
            if ($typed !== '' && cloudflare_ai_provider_account_id($typed) === '') {
                WP_CLI::error(__('Account ID has to be 32 hex characters.', 'skyphusion-ai'));
            }
        }
        if (array_key_exists('gateway-id', $assoc)) {
            $typed = trim((string) $assoc['gateway-id']);
            if ($typed !== '' && cloudflare_ai_provider_gateway_id($typed) === '') {
                WP_CLI::error(__('Gateway ID has to be a slug of letters, numbers, hyphens, or underscores.', 'skyphusion-ai'));
            }
        }

        $clean = skyphusion_ai_sanitize($input);
        update_option('skyphusion_ai_settings', $clean);
        WP_CLI::success(__('Saved. Settings, then Skyphusion AI, shows the same values.', 'skyphusion-ai'));
    }
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('skyphusion-ai', 'Skyphusion_AI_CLI');
}
