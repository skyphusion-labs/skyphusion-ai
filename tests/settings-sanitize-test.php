<?php
/**
 * What `skyphusion_ai_sanitize()` STORES and what it SAYS. Issue #5.
 *
 * A BEHAVIOURAL test, not a text assertion. The real function runs against
 * real inputs and the notices it raises are captured, because the defect here
 * was never about what the code looks like: the value fell back correctly and
 * the admin was told nothing, and every existing check was still true.
 *
 * REQUIRED LAST-BUT-ONE, before the files that define WordPress stand-ins at
 * global scope, because this one needs `add_settings_error` to be a RECORDER
 * rather than a no-op, and `sanitize_hex_color` to behave like WordPress.
 *
 * The line this pins, from the helper's own docblock: a notice is owed when
 * the stored value is UNRELATED to what was submitted, and not owed when it
 * is the NEAREST PERMITTED one. A clamp keeps the direction of the intent; a
 * fallback discards it.
 */

$GLOBALS['cfais_notices'] = array();
$GLOBALS['cfais_stored'] = array(
    'endpoint' => 'https://old.search.ai.cloudflare.com',
    'color' => '#111111',
    'theme' => 'dark',
    'placement' => 'shortcode',
    'placeholder' => 'Old',
    'max_results' => 8,
    'account_id' => '',
    'gateway_id' => '',
    'search_bar' => true,
    'modal' => false,
    'chat' => false,
    'hide_checkout' => true,
    'hide_branding' => false,
    'lead_search' => false,
);

if (!function_exists('add_settings_error')) {
    function add_settings_error($setting, $code, $message, $type = 'error')
    {
        $GLOBALS['cfais_notices'][] = $code;
    }
}
if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        // Settings stay on this fixture. Other keys, including the search
        // outcome counts, are written by update_option() into cfais_options.
        // lead-guard-test.php defines that writer. This function is already
        // defined by then, so the store has to live here.
        if ($name === 'skyphusion_ai_settings') {
            return $GLOBALS['cfais_stored'];
        }
        if (isset($GLOBALS['cfais_options']) && is_array($GLOBALS['cfais_options']) && array_key_exists($name, $GLOBALS['cfais_options'])) {
            return $GLOBALS['cfais_options'][$name];
        }
        return $default;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value)
    {
        return trim(strip_tags((string) $value));
    }
}
if (!function_exists('sanitize_hex_color')) {
    // WordPress's own rule: #rgb or #rrggbb, else null.
    function sanitize_hex_color($color)
    {
        $color = (string) $color;
        return preg_match('/^#([A-Fa-f0-9]{3}){1,2}$/', $color) ? $color : null;
    }
}
if (!function_exists('add_action')) {
    // Same no-op shape lead-guard-test.php defines. Whichever file is loaded
    // first wins on function_exists, and both are no-ops, so order is safe.
    function add_action($hook, $cb, $priority = 10, $args = 1)
    {
    }
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $cb, $priority = 10, $args = 1)
    {
    }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($file)
    {
        return basename((string) $file);
    }
}
if (!defined('SKYPHUSION_AI_FILE')) {
    define('SKYPHUSION_AI_FILE', dirname(__DIR__) . '/skyphusion-ai.php');
}

require_once dirname(__DIR__) . '/includes/settings.php';

/** Run the real sanitizer and return [stored, notice codes]. */
function cfais_sanitize($input)
{
    $GLOBALS['cfais_notices'] = array();
    $clean = skyphusion_ai_sanitize($input);
    return array($clean, $GLOBALS['cfais_notices']);
}

/** A submission with every field valid, so one field can be varied at a time. */
function cfais_valid()
{
    return array(
        'endpoint' => 'https://abc123.search.ai.cloudflare.com',
        'color' => '#1B3A32',
        'theme' => 'light',
        'placement' => 'top',
        'placeholder' => 'Find',
        'max_results' => 8,
        'account_id' => '',
        'gateway_id' => '',
    );
}

// THE CONTROL FIRST. If a wholly valid submission raised a notice, every
// "raises no notice" assertion below would pass for the wrong reason.
list($clean, $notices) = cfais_sanitize(cfais_valid());
expect('a valid submission raises no notice', $notices, array());
expect('and stores what was submitted', $clean['color'], '#1B3A32');
expect('and the theme', $clean['theme'], 'light');
expect('and the placement', $clean['placement'], 'top');

// --- THE DEFECT: a bad colour was silent ------------------------------------

$in = cfais_valid();
$in['color'] = 'green';
list($clean, $notices) = cfais_sanitize($in);
expect('a bad colour keeps the previous one', $clean['color'], '#111111');
expect('and SAYS SO', $notices, array('color'));

// Shorthand hex is valid and must not be reported.
$in = cfais_valid();
$in['color'] = '#abc';
list($clean, $notices) = cfais_sanitize($in);
expect('shorthand hex is accepted', $clean['color'], '#abc');
expect('and raises no notice', $notices, array());

// An EMPTY field is not a rejected value. Clearing a box is not an error.
$in = cfais_valid();
$in['color'] = '';
list($clean, $notices) = cfais_sanitize($in);
expect('an empty colour keeps the previous one', $clean['color'], '#111111');
expect('and stays silent', $notices, array());

// --- the same asymmetry in theme and placement ------------------------------

$in = cfais_valid();
$in['theme'] = 'neon';
list($clean, $notices) = cfais_sanitize($in);
expect('an unknown theme falls back to auto', $clean['theme'], 'auto');
expect('and says so', $notices, array('theme'));

$in = cfais_valid();
$in['theme'] = '';
list($clean, $notices) = cfais_sanitize($in);
expect('an empty theme falls back silently', $clean['theme'], 'auto');
expect('with no notice', $notices, array());

$in = cfais_valid();
$in['placement'] = 'sidebar';
list($clean, $notices) = cfais_sanitize($in);
expect('an unknown placement falls back to top', $clean['placement'], 'top');
expect('and says so', $notices, array('placement'));

// Before this change ANY non-shortcode value became 'top' with no complaint,
// so this is the case that previously could not be distinguished from valid.
$in = cfais_valid();
$in['placement'] = 'shortcode';
list($clean, $notices) = cfais_sanitize($in);
expect('shortcode is still accepted', $clean['placement'], 'shortcode');
expect('and raises no notice', $notices, array());

// --- the ones that stay SILENT on purpose -----------------------------------
// Each keeps the submission and moves it to the nearest allowed value, so the
// direction of the intent survives. These assertions are the decision.

$in = cfais_valid();
$in['max_results'] = 500;
list($clean, $notices) = cfais_sanitize($in);
expect('too many results clamps to 20', $clean['max_results'], 20);
expect('and stays silent, because a clamp keeps the intent', $notices, array());

$in = cfais_valid();
$in['max_results'] = 0;
list($clean, $notices) = cfais_sanitize($in);
expect('too few clamps to 1', $clean['max_results'], 1);
expect('and stays silent', $notices, array());

$in = cfais_valid();
$in['placeholder'] = str_repeat('a', 200);
list($clean, $notices) = cfais_sanitize($in);
expect('a long placeholder is truncated to 80', strlen($clean['placeholder']), 80);
expect('and stays silent, because a prefix keeps the intent', $notices, array());

// --- the fields that already reported, still reporting ----------------------

$in = cfais_valid();
$in['endpoint'] = 'http://example.com';
list($clean, $notices) = cfais_sanitize($in);
expect('a bad endpoint keeps the previous one', $clean['endpoint'], 'https://old.search.ai.cloudflare.com');
expect('and still says so', $notices, array('endpoint'));

$in = cfais_valid();
$in['account_id'] = 'not-an-account';
list($clean, $notices) = cfais_sanitize($in);
expect('a bad account id says so', $notices, array('account_id'));

$in = cfais_valid();
$in['gateway_id'] = '../escape';
list($clean, $notices) = cfais_sanitize($in);
expect('a bad gateway id says so', $notices, array('gateway_id'));

// --- several at once ---------------------------------------------------------
// One notice per rejected field, so the admin learns everything in one save
// rather than one error per round trip.

$in = cfais_valid();
$in['color'] = 'green';
$in['theme'] = 'neon';
$in['endpoint'] = 'http://example.com';
list($clean, $notices) = cfais_sanitize($in);
sort($notices);
expect('three bad fields raise three notices', $notices, array('color', 'endpoint', 'theme'));

// --- a non-array input --------------------------------------------------------

list($clean, $notices) = cfais_sanitize('not an array');
expect('a non-array input returns the stored settings', $clean['color'], '#111111');
expect('and raises no notice', $notices, array());

// --- the CLI half of the same issue ------------------------------------------

// A TEXT ASSERTION, and weak evidence about behaviour by construction: driving
// `set()` needs WP_CLI, `update_option` and a loaded WordPress. It is the right
// instrument anyway, because the claim being pinned is a DIRECTION. The old
// condition was `array_key_exists(...) && function_exists('sanitize_hex_color')`,
// which skipped the whole validation when the validator was missing and let the
// command print "Saved." having changed nothing. A validation that disappears
// with its validator permits exactly the case nobody tested.
$cfais_cli = (string) file_get_contents(dirname(__DIR__) . '/includes/cli.php');
/*
 * KEY-AGNOSTIC ON PURPOSE, and the keyed version was measured going vacuous.
 *
 * This searched for `array_key_exists('color', $assoc) && function_exists(...)`,
 * naming the flag. PR #48 renamed the flag to `accent-color` and the #48/#53
 * merge moved this validation onto the new key, so the keyed needle stopped
 * describing the live code path. MEASURED on the merged tree: reintroducing the
 * fail-open as
 *
 *   if (array_key_exists('accent-color', $assoc) && function_exists('sanitize_hex_color')) {
 *
 * gave exit 0 with BOTH assertions green and the fail-open fully restored. The
 * hard error was still present in the file, and unreachable, because the outer
 * condition short-circuits on exactly the case it exists to catch. A guard that
 * names a key cannot survive that key being renamed, and it fails silently in
 * the reassuring direction.
 *
 * So the needle is the DEFECT SHAPE rather than the flag: the validator's
 * availability must never be a condition for running the check, whatever the
 * option is called.
 */
$cfais_open = "&& function_exists('sanitize_hex_color')";
$cfais_closed = "if (!function_exists('sanitize_hex_color')) {";

expect('the colour check no longer fails open', strpos($cfais_cli, $cfais_open) !== false, false);
expect('a missing validator is an error', strpos($cfais_cli, $cfais_closed) !== false, true);

// Controls. Without these both assertions pass on any file lacking the
// strings, including an empty one. The planted form uses the CURRENT key, so
// a future rename of the flag does not quietly stop this control exercising
// the matcher.
$cfais_planted = "if (array_key_exists('accent-color', \$assoc) " . $cfais_open . ") {\n"
    . "    if (!sanitize_hex_color(1)) {}\n}";
expect('the check can see the fail-open form', strpos($cfais_planted, $cfais_open) !== false, true);
expect('and sees the hard error missing', strpos($cfais_planted, $cfais_closed) !== false, false);
