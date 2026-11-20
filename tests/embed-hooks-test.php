<?php
/**
 * The embed save hooks, driven the way WordPress fires them.
 *
 * Issue #232. A block-editor save goes through the REST controller. It fires
 * `save_post` inside wp_insert_post(), saves the request's terms AFTER that,
 * and only then fires `wp_after_insert_post`. A sync on `save_post` therefore
 * reads the OLD categories. The suite's add_action stand-in records nothing,
 * so this file runs in its own process with a registry and a dispatcher, and
 * the assertions go through the hooks the plugin really registers.
 */

if (getenv('SKYPHUSION_AI_EMBED_HOOKS_CHILD') !== '1') {
    $hooks_cmd = 'SKYPHUSION_AI_EMBED_HOOKS_CHILD=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    $hooks_lines = array();
    $hooks_code = 1;
    exec($hooks_cmd . ' 2>&1', $hooks_lines, $hooks_code);
    $hooks_text = implode("\n", $hooks_lines);
    expect('embed hooks child exits 0', $hooks_code, 0);
    if ($hooks_code !== 0) {
        fwrite(STDERR, $hooks_text . "\n");
    }
    expect('embed hooks child ran the registration assertion', strpos($hooks_text, 'ok hooks: the embed sync') !== false, true);
    expect('embed hooks child ran the REST save assertion', strpos($hooks_text, 'ok hooks: a REST save') !== false, true);
    return;
}

// Functions below sit inside this block so they are declared only in the child.
// PHP hoists a top-level function even after an early return.
if (true) {
$hooks_failures = 0;

function hooks_expect($label, $actual, $expected)
{
    global $hooks_failures;
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$label}\n  actual: " . var_export($actual, true) . "\n  expected: " . var_export($expected, true) . "\n");
        $hooks_failures++;
        return;
    }
    fwrite(STDOUT, "ok {$label}\n");
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$GLOBALS['hooks_registry'] = array();
$GLOBALS['hooks_options'] = array();
$GLOBALS['hooks_meta'] = array();
$GLOBALS['hooks_terms'] = array();
$GLOBALS['hooks_requests'] = array();

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['hooks_registry'][$hook][] = $callback;
}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
{
}
function hooks_fire($hook, ...$args)
{
    foreach (($GLOBALS['hooks_registry'][$hook] ?? array()) as $callback) {
        $callback(...$args);
    }
}
function __($text, $domain = 'default')
{
    return $text;
}
function get_option($name, $default = false)
{
    return $GLOBALS['hooks_options'][$name] ?? $default;
}
function get_post_meta($post_id, $key, $single = false)
{
    return $GLOBALS['hooks_meta'][(int) $post_id][$key] ?? '';
}
function update_post_meta($post_id, $key, $value)
{
    $GLOBALS['hooks_meta'][(int) $post_id][$key] = $value;
    return true;
}
function delete_post_meta($post_id, $key)
{
    unset($GLOBALS['hooks_meta'][(int) $post_id][$key]);
    return true;
}
function get_post($post_id)
{
    return (object) array(
        'ID' => (int) $post_id,
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_password' => '',
        'post_title' => 'Moved post',
        'post_content' => 'Body of the moved post',
        'post_excerpt' => '',
    );
}
function get_the_terms($post_id, $taxonomy)
{
    $rows = $GLOBALS['hooks_terms'][(int) $post_id][$taxonomy] ?? array();
    if (array() === $rows) {
        return false;
    }
    $terms = array();
    foreach ($rows as $slug) {
        $terms[] = (object) array('slug' => $slug, 'name' => ucfirst($slug), 'taxonomy' => $taxonomy);
    }
    return $terms;
}
function wp_is_post_autosave($post_id)
{
    return false;
}
function wp_is_post_revision($post_id)
{
    return false;
}
function wp_json_encode($value)
{
    return json_encode($value);
}
function wp_remote_request($url, $args = array())
{
    $GLOBALS['hooks_requests'][] = (string) $url;
    return array('body' => '{"success":true,"result":{}}', 'code' => 200);
}
function wp_remote_retrieve_response_code($response)
{
    return 200;
}
function wp_remote_retrieve_body($response)
{
    return (string) $response['body'];
}
function is_wp_error($value)
{
    return $value instanceof WP_Error;
}
function cloudflare_ai_provider_connector_key()
{
    return '';
}
function cloudflare_ai_provider_resolve_credentials($key, $account)
{
    return array('account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'token' => 'hooks-test-token');
}
function cloudflare_ai_provider_account_id($account)
{
    return (string) $account;
}
function cloudflare_ai_provider_embeddings_model()
{
    return '@cf/baai/bge-large-en-v1.5';
}
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct($code = '', $message = '')
        {
        }
    }
}

require dirname(__DIR__) . '/includes/embed.php';

$hooks_registered = $GLOBALS['hooks_registry'];
hooks_expect(
    'hooks: the embed sync runs on wp_after_insert_post and not on save_post',
    array(
        in_array('skyphusion_ai_embed_on_save', $hooks_registered['wp_after_insert_post'] ?? array(), true),
        in_array('skyphusion_ai_embed_on_save', $hooks_registered['save_post'] ?? array(), true),
    ),
    array(true, false)
);

// A published post already in the index, in no excluded category.
$GLOBALS['hooks_options']['skyphusion_ai_vectorize'] = array(
    'index' => 'skyphusion-ai',
    'post_types' => array('post'),
    'fields' => array('title', 'content', 'excerpt'),
    'model' => '@cf/baai/bge-large-en-v1.5',
    'created' => 1,
    'tax_mode' => 'exclude',
    'terms' => array('private-club'),
);
$hooks_post = (array) get_post(50);
$hooks_text = skyphusion_ai_embed_text($hooks_post, array('title', 'content', 'excerpt'));
$GLOBALS['hooks_meta'][50]['skyphusion_ai_embed_hash'] = hash('sha256', $hooks_text);
$GLOBALS['hooks_meta'][50]['skyphusion_ai_embed_parts'] = 1;
$GLOBALS['hooks_terms'][50]['category'] = array('news');

// The REST order: save_post fires, THEN the terms are saved, THEN
// wp_after_insert_post fires.
hooks_fire('save_post', 50, get_post(50), true);
$GLOBALS['hooks_terms'][50]['category'] = array('private-club');
hooks_fire('wp_after_insert_post', 50, get_post(50), true, null);

$hooks_deleted = 0;
foreach ($GLOBALS['hooks_requests'] as $hooks_url) {
    if (strpos($hooks_url, 'delete_by_ids') !== false) {
        $hooks_deleted++;
    }
}
hooks_expect('hooks: a REST save into an excluded category removes the vector', $hooks_deleted, 1);
hooks_expect('hooks: and forgets the stored hash', $GLOBALS['hooks_meta'][50]['skyphusion_ai_embed_hash'] ?? '', '');

exit($hooks_failures > 0 ? 1 : 0);
}
