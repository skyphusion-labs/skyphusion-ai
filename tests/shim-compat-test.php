<?php
/**
 * The compatibility shim, called through the OLD names.
 *
 * A check that calls skyphusion_ai_lead_answer() stays green when this file's
 * subject is deleted. These four assertions do not: each one enters through
 * cloudflare_ai_search_*. The runner below starts a fresh process because the
 * suite's add_action stand-in records nothing, and the hook assertion has to
 * see the registry after frontend.php and the shim have both run.
 *
 * symbol: cloudflare_ai_search_lead_answer()
 * hook:   cloudflare_ai_search_render_lead and cloudflare_ai_search_lead_block
 * string: cloudflare-ai-search-lead and cloudflare-ai-search-bar
 * option: cloudflare_ai_search_settings() when only the old key is stored
 */

if (getenv('SKYPHUSION_AI_SHIM_CHILD') !== '1') {
    $shim_cmd = 'SKYPHUSION_AI_SHIM_CHILD=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    $shim_lines = array();
    $shim_code = 1;
    exec($shim_cmd . ' 2>&1', $shim_lines, $shim_code);
    $shim_text = implode("\n", $shim_lines);
    expect('shim compatibility child exits 0', $shim_code, 0);
    if ($shim_code !== 0) {
        fwrite(STDERR, $shim_text . "\n");
    }
    expect('shim child ran the symbol assertion', strpos($shim_text, 'ok symbol:') !== false, true);
    expect('shim child ran the hook assertion', strpos($shim_text, 'ok hook:') !== false, true);
    expect('shim child ran the string assertion', strpos($shim_text, 'ok string:') !== false, true);
    expect('shim child ran the option assertion', strpos($shim_text, 'ok option:') !== false, true);
    return;
}

$shim_failures = 0;

function shim_expect($label, $actual, $expected)
{
    global $shim_failures;
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$label}\n  actual: " . var_export($actual, true) . "\n  expected: " . var_export($expected, true) . "\n");
        $shim_failures++;
        return;
    }
    fwrite(STDOUT, "ok {$label}\n");
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('SKYPHUSION_AI_FILE')) {
    define('SKYPHUSION_AI_FILE', dirname(__DIR__) . '/skyphusion-ai.php');
}
if (!defined('SKYPHUSION_AI_VERSION')) {
    define('SKYPHUSION_AI_VERSION', '0.3.3');
}

$GLOBALS['shim_hooks'] = array('action' => array(), 'filter' => array());
$GLOBALS['shim_legacy'] = array(
    'endpoint' => 'https://abc123.search.ai.cloudflare.com',
    'lead_search' => true,
    'search_bar' => true,
    'hide_checkout' => false,
);

function shim_record($kind, $hook, $callback, $priority)
{
    if (!is_string($callback)) {
        return;
    }
    $GLOBALS['shim_hooks'][$kind][$hook][(int) $priority][$callback] = true;
}

function shim_drop($kind, $hook, $callback, $priority)
{
    unset($GLOBALS['shim_hooks'][$kind][$hook][(int) $priority][$callback]);
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        shim_record('action', $hook, $callback, $priority);
    }
}
if (!function_exists('remove_action')) {
    function remove_action($hook, $callback, $priority = 10)
    {
        shim_drop('action', $hook, $callback, $priority);
    }
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        shim_record('filter', $hook, $callback, $priority);
    }
}
if (!function_exists('remove_filter')) {
    function remove_filter($hook, $callback, $priority = 10)
    {
        shim_drop('filter', $hook, $callback, $priority);
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
if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        if ($name === 'cloudflare_ai_search_settings') {
            return $GLOBALS['shim_legacy'];
        }
        return $default;
    }
}
if (!function_exists('get_transient')) {
    function get_transient($key)
    {
        return array('answer' => 'shim-ok', 'sources' => array());
    }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $ttl = 0)
    {
        return true;
    }
}
if (!function_exists('is_admin')) {
    function is_admin()
    {
        return false;
    }
}
if (!function_exists('is_search')) {
    function is_search()
    {
        return true;
    }
}
if (!function_exists('is_feed')) {
    function is_feed()
    {
        return false;
    }
}
if (!function_exists('is_embed')) {
    function is_embed()
    {
        return false;
    }
}
if (!function_exists('get_search_query')) {
    function get_search_query($escaped = true)
    {
        return 'red bats';
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return (string) $url;
    }
}
if (!function_exists('wp_kses')) {
    function wp_kses($html, $allowed)
    {
        return $html;
    }
}

require dirname(__DIR__) . '/includes/endpoint.php';
require dirname(__DIR__) . '/includes/routes.php';
require dirname(__DIR__) . '/includes/settings.php';
require dirname(__DIR__) . '/includes/frontend.php';
require dirname(__DIR__) . '/includes/provider.php';

$shim_file = dirname(__DIR__) . '/includes/deprecated.php';
if (is_file($shim_file)) {
    require $shim_file;
}

if (!function_exists('cloudflare_ai_search_lead_answer')) {
    shim_expect('symbol: cloudflare_ai_search_lead_answer() returns the renamed answer', 'missing', 'shim-ok');
} else {
    $shim_result = cloudflare_ai_search_lead_answer('red bats');
    $shim_answer = is_array($shim_result) && isset($shim_result['answer']) ? $shim_result['answer'] : $shim_result;
    shim_expect('symbol: cloudflare_ai_search_lead_answer() returns the renamed answer', $shim_answer, 'shim-ok');
}

$shim_hook_ok = !empty($GLOBALS['shim_hooks']['action']['loop_start'][5]['cloudflare_ai_search_render_lead'])
    && !empty($GLOBALS['shim_hooks']['action']['woocommerce_before_shop_loop'][5]['cloudflare_ai_search_render_lead'])
    && !empty($GLOBALS['shim_hooks']['filter']['render_block'][10]['cloudflare_ai_search_lead_block'])
    && empty($GLOBALS['shim_hooks']['action']['loop_start'][5]['skyphusion_ai_render_lead'])
    && empty($GLOBALS['shim_hooks']['action']['woocommerce_before_shop_loop'][5]['skyphusion_ai_render_lead'])
    && empty($GLOBALS['shim_hooks']['filter']['render_block'][10]['skyphusion_ai_lead_block']);
shim_expect(
    'hook: cloudflare_ai_search_render_lead and cloudflare_ai_search_lead_block stay registered',
    $shim_hook_ok,
    true
);

$shim_lead = '';
$shim_bar = '';
if (function_exists('cloudflare_ai_search_render_lead')) {
    ob_start();
    cloudflare_ai_search_render_lead();
    $shim_lead = (string) ob_get_clean();
}
if (function_exists('cloudflare_ai_search_render_bar')) {
    ob_start();
    cloudflare_ai_search_render_bar();
    $shim_bar = (string) ob_get_clean();
}
shim_expect(
    'string: old render names still emit cloudflare-ai-search-lead and cloudflare-ai-search-bar',
    strpos($shim_lead, 'cloudflare-ai-search-lead') !== false && strpos($shim_bar, 'cloudflare-ai-search-bar') !== false,
    true
);

if (!function_exists('cloudflare_ai_search_settings')) {
    shim_expect('option: cloudflare_ai_search_settings() reads the old option key', 'missing', 'https://abc123.search.ai.cloudflare.com');
} else {
    $shim_settings = cloudflare_ai_search_settings();
    shim_expect(
        'option: cloudflare_ai_search_settings() reads the old option key',
        is_array($shim_settings) ? $shim_settings['endpoint'] : $shim_settings,
        'https://abc123.search.ai.cloudflare.com'
    );
}

exit($shim_failures > 0 ? 1 : 0);
