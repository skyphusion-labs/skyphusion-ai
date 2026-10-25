<?php
/**
 * Vectorize sync. Loaded after sources-test.php. The network stand-ins
 * already exist. This file adds wp_remote_request and post meta.
 */

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default')
    {
        return esc_html($text);
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = array())
    {
        $GLOBALS['cfais_calls']++;
        $GLOBALS['cfais_last_request'] = array('url' => $url, 'args' => $args);
        if (!empty($GLOBALS['cfais_transport_error'])) {
            return new WP_Error('http_request_failed', 'The site could not reach Cloudflare.');
        }
        return array('url' => $url, 'args' => $args);
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false)
    {
        $post_id = (int) $post_id;
        if (!isset($GLOBALS['cfais_embed_meta'][$post_id][$key])) {
            return '';
        }
        return $GLOBALS['cfais_embed_meta'][$post_id][$key];
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value)
    {
        $GLOBALS['cfais_embed_meta'][(int) $post_id][$key] = $value;
        return true;
    }
}
if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $key)
    {
        unset($GLOBALS['cfais_embed_meta'][(int) $post_id][$key]);
        return true;
    }
}
if (!function_exists('get_post')) {
    function get_post($post_id)
    {
        $GLOBALS['cfais_embed_loads'] = (int) ($GLOBALS['cfais_embed_loads'] ?? 0) + 1;
        if (isset($GLOBALS['cfais_embed_post']) && is_array($GLOBALS['cfais_embed_post'])) {
            return $GLOBALS['cfais_embed_post'];
        }
        return null;
    }
}
if (!function_exists('wp_is_post_autosave')) {
    function wp_is_post_autosave($post_id)
    {
        return !empty($GLOBALS['cfais_embed_autosave']);
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return $value;
    }
}

function embed_post($id, $title, $content, $extra = array())
{
    return array_merge(array(
        'ID' => $id,
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_password' => '',
        'post_title' => $title,
        'post_content' => $content,
        'post_excerpt' => '',
    ), $extra);
}

function embed_ready()
{
    update_option('skyphusion_ai_vectorize', array(
        'index' => 'skyphusion-ai',
        'post_types' => array('post', 'product'),
        'fields' => array('title', 'content', 'excerpt'),
        'model' => '@cf/baai/bge-large-en-v1.5',
        'created' => 1,
    ), false);
}

$embed_prev_key = getenv('CLOUDFLARE_API_KEY');
$embed_prev_account = getenv('CLOUDFLARE_ACCOUNT_ID');
$embed_token = 'sentinel-token-' . str_repeat('a', 30);
putenv('CLOUDFLARE_API_KEY=' . $embed_token);
putenv('CLOUDFLARE_ACCOUNT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
$GLOBALS['cfais_settings'] = array(
    'endpoint' => 'https://abc123.search.ai.cloudflare.com',
    'lead_search' => true,
    'account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'gateway_id' => '',
);
$GLOBALS['cfais_embed_meta'] = array();
$GLOBALS['cfais_status'] = 200;
$GLOBALS['cfais_calls'] = 0;

expect('the test token is the sentinel length', strlen($embed_token), 45);
expect('a bad index name is empty', skyphusion_ai_embed_index_name('Bad Name'), '');
expect('a slug index name is kept', skyphusion_ai_embed_index_name('skyphusion-ai'), 'skyphusion-ai');

$embed_body = skyphusion_ai_embed_create_body('skyphusion-ai', '@cf/baai/bge-large-en-v1.5');
expect('create uses the large preset', $embed_body['config']['preset'], '@cf/baai/bge-large-en-v1.5');
expect('create does not invent dimensions', isset($embed_body['config']['dimensions']), false);
expect('an unknown model has no preset', skyphusion_ai_embed_create_body('skyphusion-ai', '@cf/example/other-embed'), null);

$embed_password = embed_post(2, 'Hidden', 'Secret text', array('post_password' => 'locked'));
expect('a password protected post is excluded', skyphusion_ai_embed_included($embed_password, skyphusion_ai_embed_config()), false);

$embed_long = embed_post(1, '', str_repeat('A', 4000));
$embed_estimate = skyphusion_ai_embed_estimate(array($embed_long, $embed_password), skyphusion_ai_embed_config());
expect('the estimate counts the open post', $embed_estimate['posts'], 1);
expect('four thousand characters are one thousand tokens', $embed_estimate['tokens'], 1000);
expect('the embeddings rate pins the neuron estimate', $embed_estimate['neurons'], 19);
expect('the estimate does not call the model', $GLOBALS['cfais_calls'], 0);

$embed_saved = skyphusion_ai_embed_apply(array(
    'index' => 'Bad Name',
    'post_types' => array('product', 'no spaces'),
    'fields' => array('title', 'sku'),
));
expect('a bad index name is not stored', $embed_saved['index'], 'skyphusion-ai');
expect('a product type is kept', $embed_saved['post_types'], array('product'));
expect('an unknown field is dropped', $embed_saved['fields'], array('title'));

update_option('skyphusion_ai_vectorize', array(
    'index' => 'skyphusion-ai',
    'post_types' => array('post', 'product'),
    'fields' => array('title', 'content', 'excerpt'),
    'model' => '@cf/baai/bge-large-en-v1.5',
    'created' => 1,
), false);
$embed_kept = skyphusion_ai_embed_apply(array('index' => 'other-name'));
expect('an existing index keeps its name', $embed_kept['index'], 'skyphusion-ai');

$GLOBALS['cfais_calls'] = 0;
update_option('skyphusion_ai_vectorize', array(
    'index' => 'skyphusion-ai',
    'post_types' => array('post', 'product'),
    'fields' => array('title', 'content', 'excerpt'),
    'model' => '',
    'created' => 0,
), false);
$embed_early = skyphusion_ai_embed_sync_post(embed_post(3, 'Early', 'Not yet'));
expect('a save before the first run does not call the model', $embed_early['state'], 'not_created');
expect('that save made no request', $GLOBALS['cfais_calls'], 0);

embed_ready();
$embed_same = embed_post(4, 'Hello', 'Same text');
$GLOBALS['cfais_embed_meta'][4]['skyphusion_ai_embed_hash'] = hash('sha256', skyphusion_ai_embed_text($embed_same, array('title', 'content', 'excerpt')));
$GLOBALS['cfais_calls'] = 0;
$embed_unchanged = skyphusion_ai_embed_sync_post($embed_same);
expect('an unchanged post is not sent', $embed_unchanged['state'], 'unchanged');
expect('an unchanged post makes no request', $GLOBALS['cfais_calls'], 0);

$embed_changed = embed_post(5, 'Hello', 'New text');
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_bodies'] = array(
    '{"result":{"data":[[0.25,0.5]]}}',
    '{"result":{"data":[[0.25,0.5]]}}',
    '{"success":true,"result":{"mutationId":"m1"}}',
);
$embed_synced = skyphusion_ai_embed_sync_post($embed_changed);
expect('a changed post is stored', $embed_synced['state'], 'embedded');
expect('a changed post calls the model and the index', $GLOBALS['cfais_calls'], 2);
expect('the hash is remembered', isset($GLOBALS['cfais_embed_meta'][5]['skyphusion_ai_embed_hash']), true);
$embed_upsert = json_decode(trim((string) $GLOBALS['cfais_last_request']['args']['body']), true);
expect('the upsert keeps the title', $embed_upsert['metadata']['title'], 'Hello');
expect('the upsert omits the post body', isset($embed_upsert['metadata']['post_content']), false);
expect('the upsert is ndjson', $GLOBALS['cfais_last_request']['args']['headers']['Content-Type'], 'application/x-ndjson');
expect('the index url is vectorize', strpos((string) $GLOBALS['cfais_last_request']['url'], '/vectorize/v2/indexes/') !== false, true);
expect('the index url is not the gateway', strpos((string) $GLOBALS['cfais_last_request']['url'], 'gateway.ai.cloudflare.com') === false, true);
$embed_header = (string) $GLOBALS['cfais_last_request']['args']['headers']['Authorization'];
expect('the header names the bearer scheme', strpos($embed_header, 'Bearer ') === 0, true);
expect('the header token is the sentinel length', strlen($embed_header), 52);
$embed_url_leak = strpos((string) $GLOBALS['cfais_last_request']['url'], $embed_token) !== false;
expect('the vectorize url does not carry the token', $embed_url_leak, false);

$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_bodies'] = array('{"success":true,"result":{}}');
$embed_locked = skyphusion_ai_embed_sync_post(embed_post(5, 'Hello', 'New text', array('post_password' => 'locked')));
expect('a password protected post is removed', $embed_locked['state'], 'removed');
expect('removal is one index call', $GLOBALS['cfais_calls'], 1);
expect('the hash is forgotten', isset($GLOBALS['cfais_embed_meta'][5]['skyphusion_ai_embed_hash']), false);
expect('removal uses delete by id', strpos((string) $GLOBALS['cfais_last_request']['url'], '/delete_by_ids') !== false, true);

$GLOBALS['cfais_calls'] = 0;
unset($GLOBALS['cfais_bodies']);
$embed_dry = skyphusion_ai_embed_run(array($embed_long), false);
expect('a dry run stays an estimate', $embed_dry['state'], 'estimate');
expect('a dry run does not call the model', $GLOBALS['cfais_calls'], 0);

update_option('skyphusion_ai_vectorize', array(
    'index' => 'skyphusion-ai',
    'post_types' => array('post', 'product'),
    'fields' => array('title', 'content', 'excerpt'),
    'model' => '',
    'created' => 0,
), false);
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_bodies'] = array(
    '{"success":true,"result":{"name":"skyphusion-ai"}}',
    '{"result":{"data":[[0.25,0.5]]}}',
    '{"result":{"data":[[0.25,0.5]]}}',
    '{"success":true,"result":{"mutationId":"m2"}}',
);
$embed_ran = skyphusion_ai_embed_run(array(embed_post(6, 'Run', 'First')), true);
expect('a confirmed run stores the post', $embed_ran['state'], 'ran');
expect('a confirmed run creates the index and stores the post', $GLOBALS['cfais_calls'], 3);
expect('the index is marked created', skyphusion_ai_embed_config()['created'], 1);
expect('the index records the model', skyphusion_ai_embed_config()['model'], '@cf/baai/bge-large-en-v1.5');

$GLOBALS['cfais_calls'] = 0;
unset($GLOBALS['cfais_bodies']);
$embed_again = skyphusion_ai_embed_run(array(embed_post(6, 'Run', 'First')), true);
expect('a second run skips unchanged posts', $embed_again['embedded'], 0);
expect('a second run makes no request', $GLOBALS['cfais_calls'], 0);

$GLOBALS['cfais_calls'] = 0;
$embed_keep_index = skyphusion_ai_embed_delete_index(false);
expect('delete waits for the admin', $embed_keep_index['state'], 'blocked');
expect('delete makes no request yet', $GLOBALS['cfais_calls'], 0);
expect('the index is still marked created', skyphusion_ai_embed_config()['created'], 1);
$GLOBALS['cfais_bodies'] = array('{"success":true,"result":{}}');
$embed_deleted = skyphusion_ai_embed_delete_index(true);
expect('the admin can delete the index', $embed_deleted['state'], 'deleted');
expect('the saved choice forgets the index', skyphusion_ai_embed_config()['created'], 0);

embed_ready();
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_bodies'] = array(
    '{"result":{"data":[[0.25,0.5]]}}',
    '{"result":{"data":[[0.25,0.5]]}}',
    '{"success":true,"result":{"matches":[{"id":"p6","score":0.5,"metadata":{"title":"Run","post_id":"6","url":"https://shop.example/run"},"values":[9,9]}]}}',
);
$embed_found = skyphusion_ai_embed_search('red bats', 5);
expect('search returns the title', $embed_found[0]['title'], 'Run');
expect('search does not return the vector', isset($embed_found[0]['values']), false);
expect('search asks the index', strpos((string) $GLOBALS['cfais_last_request']['url'], '/query') !== false, true);

update_option('skyphusion_ai_neurons', array(
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 10000,
    'estimated' => 0,
    'features' => array(),
    'mailed_80' => 1,
    'mailed_100' => 1,
), false);
$GLOBALS['cfais_calls'] = 0;
unset($GLOBALS['cfais_bodies']);
$embed_capped = skyphusion_ai_embed_sync_post(embed_post(7, 'Later', 'After the cap'));
expect('a spent budget does not call the model', $embed_capped['state'], 'blocked');
expect('a spent budget makes no request', $GLOBALS['cfais_calls'], 0);
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);

$GLOBALS['cfais_embed_autosave'] = true;
$GLOBALS['cfais_embed_loads'] = 0;
$GLOBALS['cfais_calls'] = 0;
skyphusion_ai_embed_on_save(8);
expect('an autosave does not load the post', $GLOBALS['cfais_embed_loads'], 0);
expect('an autosave makes no request', $GLOBALS['cfais_calls'], 0);
$GLOBALS['cfais_embed_autosave'] = false;

$GLOBALS['skyphusion_ai_embed_posts'] = array($embed_long);
$GLOBALS['cfais_calls'] = 0;
unset($GLOBALS['cfais_bodies']);
ob_start();
skyphusion_ai_embed_render();
$embed_html = (string) ob_get_clean();
expect('the screen shows the neuron estimate', strpos($embed_html, '19') !== false, true);
expect('the screen says the model was not called', strpos($embed_html, 'has not been called') !== false, true);
expect('the screen makes no request', $GLOBALS['cfais_calls'], 0);
expect('the screen does not show the token', strpos($embed_html, $embed_token) === false, true);

$embed_src = (string) file_get_contents(dirname(__DIR__) . '/includes/embed.php');
expect('this file does not name another vector store', stripos($embed_src, 'qdrant') === false && stripos($embed_src, 'postgres') === false, true);
$embed_uninstall = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
expect('uninstall removes the vectorize option', strpos($embed_uninstall, 'skyphusion_ai_vectorize') !== false, true);
expect('uninstall does not call vectorize', strpos($embed_uninstall, 'vectorize/v2') === false, true);
$embed_cli = (string) file_get_contents(dirname(__DIR__) . '/includes/cli.php');
expect('the cli command reaches the helper', strpos($embed_cli, 'skyphusion_ai_embed_cli') !== false, true);

unset($GLOBALS['cfais_settings']);
unset($GLOBALS['cfais_bodies']);
unset($GLOBALS['skyphusion_ai_embed_posts']);
unset($GLOBALS['cfais_embed_post']);
unset($GLOBALS['cfais_embed_autosave']);
if (false === $embed_prev_key || '' === $embed_prev_key) {
    putenv('CLOUDFLARE_API_KEY');
} else {
    putenv('CLOUDFLARE_API_KEY=' . $embed_prev_key);
}
if (false === $embed_prev_account || '' === $embed_prev_account) {
    putenv('CLOUDFLARE_ACCOUNT_ID');
} else {
    putenv('CLOUDFLARE_ACCOUNT_ID=' . $embed_prev_account);
}
