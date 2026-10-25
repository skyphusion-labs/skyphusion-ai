<?php
/**
 * The settings key is copied once, on update, and not on every read.
 *
 * Issue #65. Phase 1 renamed the option and kept a read fallback. Nothing
 * in that change writes the new key, and register_activation_hook() does
 * not run when an already-active plugin is updated. An install configured
 * before the rename therefore holds its settings only under the old key
 * until a person saves the screen or WP-CLI writes them. Deleting the
 * fallback after that, which is what a later cleanup of the old prefix
 * looks like, would load defaults: empty endpoint, storefront search
 * stops, no error.
 *
 * THESE ASSERTIONS ENTER THROUGH THE UPGRADE ROUTINE, not through
 * skyphusion_ai_settings(). A check that only reads the fallback stays
 * green when the copy is deleted, which is how the missing write survived
 * the rename. The copy assertion below does not: with the routine gone,
 * the new key is never stored and the comparison fails.
 *
 * Own process. The runner's get_option() is already defined by
 * settings-sanitize-test.php as a read of one fixture, and this file has
 * to see writes. It also has to load skyphusion-ai.php, which the runner
 * never loads, because a function that nothing registers does not migrate
 * any install.
 */

if (getenv('SKYPHUSION_AI_MIGRATE_CHILD') !== '1') {
    $mig_cmd = 'SKYPHUSION_AI_MIGRATE_CHILD=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    $mig_lines = array();
    $mig_code = 1;
    exec($mig_cmd . ' 2>&1', $mig_lines, $mig_code);
    $mig_text = implode("\n", $mig_lines);
    expect('settings migration child exits 0', $mig_code, 0);
    if ($mig_code !== 0) {
        fwrite(STDERR, $mig_text . "\n");
    }
    /*
     * The strings are the load-bearing labels, not the shared prefix.
     * `ok copy:` also matches `ok copy: the old key is left in place`,
     * and that one stays green when the routine writes nothing, because
     * the old key was already in the stand-in. A prefix check would
     * report the copy assertion as present on a run that never copied.
     */
    expect('migration child ran the hook assertion', strpos($mig_text, 'ok hook: plugins_loaded runs skyphusion_ai_upgrade') !== false, true);
    expect('migration child ran the fallback assertion', strpos($mig_text, 'ok fallback: skyphusion_ai_settings() still reads the old key when the new key is absent') !== false, true);
    expect('migration child ran the reader assertion', strpos($mig_text, 'ok reader: skyphusion_ai_settings() does not write') !== false, true);
    expect('migration child ran the copy assertion', strpos($mig_text, 'ok copy: old key only is written to skyphusion_ai_settings unchanged') !== false, true);
    expect('migration child ran the guard assertion', strpos($mig_text, 'ok guard: a second run writes nothing') !== false, true);
    expect('migration child ran the keep assertion', strpos($mig_text, 'ok keep: an existing new key is not replaced by the old key') !== false, true);
    expect('migration child ran the absent assertion', strpos($mig_text, 'ok absent: neither key writes no settings option') !== false, true);
    expect('migration child ran the retry assertion', strpos($mig_text, 'ok retry: a failed copy does not stamp the version') !== false, true);
    return;
}

$mig_failures = 0;

function mig_expect($label, $actual, $expected)
{
    global $mig_failures;
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$label}\n  actual: " . var_export($actual, true) . "\n  expected: " . var_export($expected, true) . "\n");
        $mig_failures++;
        return;
    }
    fwrite(STDOUT, "ok {$label}\n");
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$GLOBALS['mig_options'] = array();
$GLOBALS['mig_writes'] = array();
$GLOBALS['mig_settings_write_fails'] = false;
$GLOBALS['mig_hooks'] = array();

/**
 * Record a settings write, and optionally refuse to persist it.
 *
 * Both add_option and update_option come through here. The failure flag
 * applies to the settings key only, so a routine that stamps the version
 * watermark after a failed copy is visible: the watermark lands, the
 * settings key does not, and the retry assertion goes red.
 *
 * @param string $op   add or update.
 * @param string $name Option name.
 * @param mixed  $value
 * @return bool
 */
function mig_persist($op, $name, $value)
{
    $GLOBALS['mig_writes'][] = array($op, $name);
    if (!empty($GLOBALS['mig_settings_write_fails']) && $name === 'skyphusion_ai_settings') {
        return false;
    }
    // add_option() refuses to replace a key that is already there. That is
    // the whole of the keep assertion: an update_option() of the same name
    // would replace it, and this stand-in does too when the op is update.
    if ($op === 'add' && array_key_exists($name, $GLOBALS['mig_options'])) {
        return false;
    }
    $GLOBALS['mig_options'][$name] = $value;
    return true;
}

function mig_wrote($name)
{
    foreach ($GLOBALS['mig_writes'] as $write) {
        if ($write[1] === $name) {
            return true;
        }
    }
    return false;
}

function mig_reset($options)
{
    $GLOBALS['mig_options'] = $options;
    $GLOBALS['mig_writes'] = array();
    $GLOBALS['mig_settings_write_fails'] = false;
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        if (array_key_exists($name, $GLOBALS['mig_options'])) {
            return $GLOBALS['mig_options'][$name];
        }
        return $default;
    }
}
if (!function_exists('add_option')) {
    function add_option($name, $value = '', $deprecated = '', $autoload = 'yes')
    {
        return mig_persist('add', $name, $value);
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        return mig_persist('update', $name, $value);
    }
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['mig_hooks'][$hook][(int) $priority][] = $callback;
    }
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
    {
    }
}
if (!function_exists('remove_action')) {
    function remove_action($hook, $callback, $priority = 10)
    {
    }
}
if (!function_exists('remove_filter')) {
    function remove_filter($hook, $callback, $priority = 10)
    {
    }
}
if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback)
    {
    }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($file)
    {
        return basename((string) $file);
    }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback)
    {
    }
}

require dirname(__DIR__) . '/skyphusion-ai.php';

$mig_legacy = array(
    'endpoint' => 'https://ABC123.search.ai.cloudflare.com/',
    'chat' => 1,
    'max_results' => 3,
);
$mig_current = array(
    'endpoint' => 'https://new.search.ai.cloudflare.com',
    'chat' => 0,
);

$mig_hooked = false;
if (!empty($GLOBALS['mig_hooks']['plugins_loaded'])) {
    foreach ($GLOBALS['mig_hooks']['plugins_loaded'] as $mig_callbacks) {
        foreach ($mig_callbacks as $mig_callback) {
            if ($mig_callback === 'skyphusion_ai_upgrade') {
                $mig_hooked = true;
            }
        }
    }
}
mig_expect('hook: plugins_loaded runs skyphusion_ai_upgrade', $mig_hooked, true);

/*
 * The read fallback is still the load-bearing path until the copy has
 * run. Line 32 of includes/settings.php stays in this change on purpose.
 * Removing it here, before any install has been copied, is the failure
 * this routine exists so that a later change can make safely.
 */
mig_reset(array('cloudflare_ai_search_settings' => $mig_legacy));
$mig_read = function_exists('skyphusion_ai_settings') ? skyphusion_ai_settings() : array('endpoint' => 'missing');
mig_expect(
    'fallback: skyphusion_ai_settings() still reads the old key when the new key is absent',
    isset($mig_read['endpoint']) ? $mig_read['endpoint'] : $mig_read,
    'https://abc123.search.ai.cloudflare.com'
);
mig_expect('reader: skyphusion_ai_settings() does not write', $GLOBALS['mig_writes'], array());

/*
 * Raw copy, not the normalised settings array. The stored endpoint keeps
 * its uppercase host and its trailing slash, and placeholder is absent,
 * because that key was not in the old option. skyphusion_ai_settings()
 * would have lowercased the host, stripped the slash, and merged defaults.
 * Writing that shape would make the new key differ from the old one, so
 * deleting the fallback later would change what a read returns.
 *
 * The old key stays. A rollback to a release that only reads it still
 * finds the values.
 */
mig_reset(array('cloudflare_ai_search_settings' => $mig_legacy));
if (function_exists('skyphusion_ai_upgrade')) {
    skyphusion_ai_upgrade();
}
$mig_copied = array_key_exists('skyphusion_ai_settings', $GLOBALS['mig_options'])
    ? $GLOBALS['mig_options']['skyphusion_ai_settings']
    : 'absent';
mig_expect('copy: old key only is written to skyphusion_ai_settings unchanged', $mig_copied, $mig_legacy);
$mig_old = array_key_exists('cloudflare_ai_search_settings', $GLOBALS['mig_options'])
    ? $GLOBALS['mig_options']['cloudflare_ai_search_settings']
    : 'absent';
mig_expect('copy: the old key is left in place', $mig_old, $mig_legacy);
mig_expect(
    'copy: the version watermark is the current plugin version',
    array_key_exists('skyphusion_ai_db_version', $GLOBALS['mig_options']) ? $GLOBALS['mig_options']['skyphusion_ai_db_version'] : 'absent',
    SKYPHUSION_AI_VERSION
);

$GLOBALS['mig_writes'] = array();
if (function_exists('skyphusion_ai_upgrade')) {
    skyphusion_ai_upgrade();
}
mig_expect('guard: a second run writes nothing', $GLOBALS['mig_writes'], array());

/*
 * Both writers of the new key (the settings screen and WP-CLI) have to
 * win a race against this copy. The version watermark is cleared first,
 * so the routine actually enters the copy. If it used update_option(),
 * the stand-in would replace the saved array and this would fail.
 */
mig_reset(
    array(
        'cloudflare_ai_search_settings' => $mig_legacy,
        'skyphusion_ai_settings' => $mig_current,
    )
);
if (function_exists('skyphusion_ai_upgrade')) {
    skyphusion_ai_upgrade();
}
$mig_kept = array_key_exists('skyphusion_ai_settings', $GLOBALS['mig_options'])
    ? $GLOBALS['mig_options']['skyphusion_ai_settings']
    : 'absent';
mig_expect('keep: an existing new key is not replaced by the old key', $mig_kept, $mig_current);
mig_expect('keep: the routine ran and did not write the settings key', mig_wrote('skyphusion_ai_db_version') && !mig_wrote('skyphusion_ai_settings'), true);

/*
 * Nothing to copy is not a reason to invent defaults. Activation owns
 * the first write of defaults, and only when both keys are absent.
 */
mig_reset(array());
if (function_exists('skyphusion_ai_upgrade')) {
    skyphusion_ai_upgrade();
}
mig_expect(
    'absent: neither key writes no settings option',
    array_key_exists('skyphusion_ai_settings', $GLOBALS['mig_options']),
    false
);
mig_expect('absent: the routine still stamps the version, so it does not repeat', mig_wrote('skyphusion_ai_db_version'), true);

/*
 * A copy that does not land must not stamp the version. Stamping first
 * would make the guard skip every later request, and the install would
 * stay on the old key forever. The failure flag refuses the settings
 * write and lets the watermark through, which is the bad order.
 */
mig_reset(array('cloudflare_ai_search_settings' => $mig_legacy));
$GLOBALS['mig_settings_write_fails'] = true;
if (function_exists('skyphusion_ai_upgrade')) {
    skyphusion_ai_upgrade();
}
mig_expect(
    'retry: a failed copy does not write the new key',
    array_key_exists('skyphusion_ai_settings', $GLOBALS['mig_options']),
    false
);
mig_expect(
    'retry: a failed copy does not stamp the version',
    array_key_exists('skyphusion_ai_db_version', $GLOBALS['mig_options']),
    false
);

exit($mig_failures > 0 ? 1 : 0);
