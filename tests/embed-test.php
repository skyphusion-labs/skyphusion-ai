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
        if (!isset($GLOBALS['cfais_request_urls']) || !is_array($GLOBALS['cfais_request_urls'])) {
            $GLOBALS['cfais_request_urls'] = array();
        }
        $GLOBALS['cfais_request_urls'][] = (string) $url;
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
expect('the screen says the embeddings limit stays on this job', strpos($embed_html, 'does not stop the other tools') !== false, true);
expect('the screen names the daily limit', strpos($embed_html, 'skyphusion_ai_embed_limit') !== false, true);
expect('the screen makes no request', $GLOBALS['cfais_calls'], 0);
expect('the screen does not show the token', strpos($embed_html, $embed_token) === false, true);

$embed_src = (string) file_get_contents(dirname(__DIR__) . '/includes/embed.php');
expect('this file does not name another vector store', stripos($embed_src, 'qdrant') === false && stripos($embed_src, 'postgres') === false, true);
$embed_uninstall = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
expect('uninstall removes the vectorize option', strpos($embed_uninstall, 'skyphusion_ai_vectorize') !== false, true);
expect('uninstall does not call vectorize', strpos($embed_uninstall, 'vectorize/v2') === false, true);
$embed_cli = (string) file_get_contents(dirname(__DIR__) . '/includes/cli.php');
expect('the cli command reaches the helper', strpos($embed_cli, 'skyphusion_ai_embed_cli') !== false, true);

$embed_private = embed_post(60, 'Quiet', 'Private text', array('post_status' => 'private'));
expect('a private post stays out', skyphusion_ai_embed_included($embed_private, skyphusion_ai_embed_config()), false);
$embed_private_config = skyphusion_ai_embed_config();
$embed_private_config['statuses'] = array('publish', 'private');
expect('a private post is included when chosen', skyphusion_ai_embed_included($embed_private, $embed_private_config), true);
$embed_locked_private = embed_post(61, 'Locked', 'Hidden', array('post_status' => 'private', 'post_password' => 'locked'));
expect('a password protected private post stays out', skyphusion_ai_embed_included($embed_locked_private, $embed_private_config), false);
$embed_old = embed_post(62, 'Old', 'Dated text', array('post_date_gmt' => '2020-01-01 00:00:00'));
$embed_dated = skyphusion_ai_embed_config();
$embed_dated['after'] = '2024-01-01';
expect('a post before the cutoff stays out', skyphusion_ai_embed_included($embed_old, $embed_dated), false);
$embed_match = embed_post(63, 'Kept', 'Category text', array('categories' => array('linen')));
$embed_other = embed_post(64, 'Dropped', 'Other text', array('categories' => array('wool')));
$embed_tax = skyphusion_ai_embed_config();
$embed_tax['tax_mode'] = 'include';
$embed_tax['terms'] = array('linen');
expect('an included category stays', skyphusion_ai_embed_included($embed_match, $embed_tax), true);
expect('another category stays out', skyphusion_ai_embed_included($embed_other, $embed_tax), false);
$embed_tax_out = $embed_tax;
$embed_tax_out['tax_mode'] = 'exclude';
expect('an excluded category stays out', skyphusion_ai_embed_included($embed_match, $embed_tax_out), false);
expect('a different category stays when others are excluded', skyphusion_ai_embed_included($embed_other, $embed_tax_out), true);
$embed_image = embed_post(66, 'Pic', 'Image text', array('post_type' => 'attachment', 'post_status' => 'inherit'));
expect('an image stays out until chosen', skyphusion_ai_embed_included($embed_image, skyphusion_ai_embed_config()), false);
$embed_images_on = skyphusion_ai_embed_config();
$embed_images_on['images'] = 1;
expect('a chosen image is included', skyphusion_ai_embed_included($embed_image, $embed_images_on), true);
$GLOBALS['cfais_embed_meta'][65]['skyphusion_ai_embed_exclude'] = '1';
$embed_left = embed_post(65, 'Left out', 'Editor text');
expect('an editor can leave one post out', skyphusion_ai_embed_included($embed_left, skyphusion_ai_embed_config()), false);
$GLOBALS['cfais_calls'] = 0;
$embed_choice = skyphusion_ai_embed_estimate(array($embed_long), skyphusion_ai_embed_config());
expect('the estimate counts one passage', $embed_choice['passages'], 1);
expect('the estimate names the days', $embed_choice['days'], 1);
expect('the estimate names stored dimensions', $embed_choice['stored'], 1024);
expect('the estimate does not call the model', $GLOBALS['cfais_calls'], 0);
$embed_span = skyphusion_ai_embed_config();
$embed_span['limit'] = 10;
$embed_span_est = skyphusion_ai_embed_estimate(array($embed_long), $embed_span);
expect('a large estimate is split across days', $embed_span_est['days'], 2);
$embed_query = skyphusion_ai_embed_query_args(skyphusion_ai_embed_config());
expect('the run asks for published posts', $embed_query['post_status'], array('publish'));
$embed_query_more_config = skyphusion_ai_embed_config();
$embed_query_more_config['statuses'] = array('publish', 'private');
$embed_query_more_config['images'] = 1;
$embed_query_more = skyphusion_ai_embed_query_args($embed_query_more_config);
expect('the run asks for private posts when chosen', in_array('private', $embed_query_more['post_status'], true), true);
expect('the run asks for images when chosen', in_array('attachment', $embed_query_more['post_type'], true), true);
$embed_old_day = skyphusion_ai_embed_config();
$embed_old_day['limit'] = 10;
$embed_old_day['day'] = '2000-01-01';
$embed_old_day['neurons'] = 50;
expect('yesterday does not spend today', skyphusion_ai_embed_limit_blocked($embed_old_day), false);
$embed_old_day['day'] = skyphusion_ai_neuron_today();
expect('today at the limit stops embeddings', skyphusion_ai_embed_limit_blocked($embed_old_day), true);
$embed_old_day['limit'] = 0;
expect('a zero limit does not stop embeddings', skyphusion_ai_embed_limit_blocked($embed_old_day), false);
update_option('skyphusion_ai_vectorize', array(
    'index' => 'skyphusion-ai',
    'post_types' => array('post', 'product'),
    'fields' => array('title', 'content', 'excerpt'),
    'model' => '@cf/baai/bge-large-en-v1.5',
    'created' => 1,
    'limit' => 10,
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 10,
), false);
$GLOBALS['cfais_calls'] = 0;
unset($GLOBALS['cfais_bodies']);
$embed_limited = skyphusion_ai_embed_sync_post(embed_post(70, 'Cap', 'Over the embeddings limit'));
expect('a spent embeddings limit does not call the model', $embed_limited['state'], 'limit');
expect('a spent embeddings limit makes no request', $GLOBALS['cfais_calls'], 0);
update_option('skyphusion_ai_vectorize', array(
    'index' => 'skyphusion-ai',
    'post_types' => array('post', 'product'),
    'fields' => array('title', 'content', 'excerpt'),
    'model' => '@cf/baai/bge-large-en-v1.5',
    'created' => 1,
    'limit' => 0,
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 10,
), false);
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_bodies'] = array(
    '{"result":{"data":[[0.25,0.5]]}}',
    '{"result":{"data":[[0.25,0.5]]}}',
    '{"success":true,"result":{"mutationId":"m3"}}',
);
$embed_open = skyphusion_ai_embed_sync_post(embed_post(71, 'Open', 'No own limit'));
expect('a zero embeddings limit does not stop the job', $embed_open['state'], 'embedded');
expect('a zero embeddings limit still stores the post', $GLOBALS['cfais_calls'], 2);
$embed_form = skyphusion_ai_embed_apply(array(
    'private' => '1',
    'images' => '1',
    'after' => '2024-06-01',
    'tax_mode' => 'exclude',
    'terms' => array('wool', 'no spaces'),
    'limit' => 0,
));
expect('private can be saved', in_array('private', $embed_form['statuses'], true), true);
expect('images can be saved', $embed_form['images'], 1);
expect('a date cutoff can be saved', $embed_form['after'], '2024-06-01');
expect('exclude can be saved', $embed_form['tax_mode'], 'exclude');
expect('a bad slug is dropped', $embed_form['terms'], array('wool'));
expect('a zero limit is saved', $embed_form['limit'], 0);
update_option('skyphusion_ai_vectorize', array(
    'index' => 'skyphusion-ai',
    'post_types' => array('post', 'product'),
    'fields' => array('title', 'content', 'excerpt'),
    'model' => '@cf/baai/bge-large-en-v1.5',
    'created' => 1,
    'limit' => 3000,
    'neurons' => 0,
), false);
skyphusion_ai_embed_add_neurons(4);
expect('the embeddings total keeps the call', skyphusion_ai_embed_config()['neurons'], 4);
skyphusion_ai_embed_add_neurons(1);
expect('a second call adds to today', skyphusion_ai_embed_config()['neurons'], 5);
$embed_search_fn = substr($embed_src, (int) strpos($embed_src, 'function skyphusion_ai_embed_search'), 1800);
expect('a test search does not write the index', strpos($embed_search_fn, 'upsert') === false && strpos($embed_search_fn, 'delete_by_ids') === false, true);
$embed_related = (string) file_get_contents(dirname(__DIR__) . '/includes/related.php');
$embed_links = (string) file_get_contents(dirname(__DIR__) . '/includes/links.php');
expect('related posts do not call the embeddings model', strpos($embed_related, 'cloudflare_ai_provider_embeddings') === false, true);
expect('the link box does not call the embeddings model', strpos($embed_links, 'cloudflare_ai_provider_embeddings') === false && strpos($embed_links, 'skyphusion_ai_embed_search') === false, true);
expect('the embed cli marker is present', strpos($embed_src, 'embed-cli') !== false, true);
expect('cli does not add a backfill command', strpos($embed_cli, 'function backfill') === false, true);
expect('cli does not add a translation command', strpos($embed_cli, 'function translate') === false, true);
expect('cli does not add a usage command', strpos($embed_cli, 'function usage') === false, true);

function embed_cli_lines()
{
    return implode("\n", $GLOBALS['cfais_cli_log']);
}

function embed_cli_off()
{
    update_option('skyphusion_ai_vectorize', array(
        'index' => 'skyphusion-ai',
        'post_types' => array('post', 'product'),
        'fields' => array('title', 'content', 'excerpt'),
        'model' => '@cf/baai/bge-large-en-v1.5',
        'created' => 1,
        'enabled' => 0,
    ), false);
}

embed_cli_off();
$GLOBALS['skyphusion_ai_embed_posts'] = array(embed_post(90, 'Off', 'Do not send'));
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_cli_log'] = array();
$GLOBALS['cfais_last_post'] = array('marker' => 1);
unset($GLOBALS['cfais_bodies']);
skyphusion_ai_embed_cli(array('run'), array('yes' => true));
expect('run refuses while embeddings are off', strpos(embed_cli_lines(), 'embed_state=off') !== false, true);
expect('run while off does not call the model', $GLOBALS['cfais_calls'], 0);
expect('run while off does not post', $GLOBALS['cfais_last_post'], array('marker' => 1));
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('reconcile'), array());
expect('reconcile refuses while embeddings are off', strpos(embed_cli_lines(), 'embed_state=off') !== false, true);
expect('reconcile while off makes no request', $GLOBALS['cfais_calls'], 0);
$GLOBALS['cfais_embed_meta'][90]['skyphusion_ai_embed_hash'] = 'keep-this-hash';
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('rebuild'), array());
expect('rebuild refuses while embeddings are off', strpos(embed_cli_lines(), 'embed_state=off') !== false, true);
expect('rebuild while off makes no request', $GLOBALS['cfais_calls'], 0);
expect('rebuild while off keeps the hash', $GLOBALS['cfais_embed_meta'][90]['skyphusion_ai_embed_hash'], 'keep-this-hash');

embed_ready();
$GLOBALS['skyphusion_ai_embed_posts'] = array(embed_post(91, 'Estimate', 'Four words here'));
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_cli_log'] = array();
$GLOBALS['cfais_last_post'] = array('marker' => 1);
skyphusion_ai_embed_cli(array('estimate'), array('yes' => true));
expect('estimate does not run when the yes flag is set', strpos(embed_cli_lines(), 'embed_called=0') !== false, true);
expect('estimate names passages', strpos(embed_cli_lines(), 'embed_passages=1') !== false, true);
expect('estimate makes no request', $GLOBALS['cfais_calls'], 0);
expect('estimate does not post', $GLOBALS['cfais_last_post'], array('marker' => 1));

$GLOBALS['cfais_embed_meta'][91]['skyphusion_ai_embed_hash'] = hash('sha256', skyphusion_ai_embed_text(embed_post(91, 'Estimate', 'Four words here'), array('title', 'content', 'excerpt')));
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('status'), array());
expect('status counts the stored post', strpos(embed_cli_lines(), 'embed_embedded=1') !== false, true);
expect('status says embeddings are on', strpos(embed_cli_lines(), 'embed_on=1') !== false, true);
expect('status makes no request', $GLOBALS['cfais_calls'], 0);

$GLOBALS['cfais_bodies'] = array('{"success":true,"result":{"vectorCount":4}}');
$GLOBALS['cfais_request_urls'] = array();
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('status'), array('store' => true));
expect('status with store prints the index count', strpos(embed_cli_lines(), 'embed_vectors=4') !== false, true);
expect('status with store reads index info', strpos((string) $GLOBALS['cfais_last_request']['url'], '/info') !== false, true);

$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_request_urls'] = array();
$GLOBALS['cfais_last_post'] = array('marker' => 1);
$GLOBALS['cfais_bodies'] = array(
    '{"result":{"data":[[0.25,0.5]]}}',
    '{"result":{"data":[[0.25,0.5]]}}',
    '{"success":true,"result":{"matches":[{"id":"p91","score":0.5,"metadata":{"title":"Estimate","post_id":"91"}}]}}',
);
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('search', 'red', 'bats'), array());
expect('search prints the title', strpos(embed_cli_lines(), 'Estimate') !== false, true);
$embed_search_wrote = false;
foreach ($GLOBALS['cfais_request_urls'] as $embed_search_url) {
    if (strpos($embed_search_url, 'upsert') !== false || strpos($embed_search_url, 'delete_by_ids') !== false || strpos($embed_search_url, '/insert') !== false) {
        $embed_search_wrote = true;
    }
}
expect('search does not write the index', $embed_search_wrote, false);

$embed_kept = embed_post(92, 'Kept', 'Still here');
$embed_dropped = embed_post(93, 'Dropped', 'Leave now', array('post_password' => 'locked'));
$GLOBALS['cfais_embed_meta'][92]['skyphusion_ai_embed_hash'] = hash('sha256', skyphusion_ai_embed_text($embed_kept, array('title', 'content', 'excerpt')));
$GLOBALS['cfais_embed_meta'][93]['skyphusion_ai_embed_hash'] = 'old-hash';
$GLOBALS['skyphusion_ai_embed_posts'] = array($embed_kept, $embed_dropped);
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_last_post'] = array('marker' => 1);
$GLOBALS['cfais_bodies'] = array('{"success":true,"result":{}}');
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('reconcile'), array());
expect('reconcile removes a post that no longer belongs', strpos(embed_cli_lines(), 'embed_removed=1') !== false, true);
expect('reconcile does not call the model', $GLOBALS['cfais_last_post'], array('marker' => 1));
expect('reconcile deletes by id', strpos((string) $GLOBALS['cfais_last_request']['url'], '/delete_by_ids') !== false, true);
expect('reconcile says the model was not called', strpos(embed_cli_lines(), 'embed_called=0') !== false, true);

$GLOBALS['cfais_embed_meta'][94]['skyphusion_ai_embed_failed'] = '1';
$GLOBALS['skyphusion_ai_embed_posts'] = array(embed_post(94, 'Retry', 'Try again'));
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_bodies'] = array(
    '{"result":{"data":[[0.25,0.5]]}}',
    '{"result":{"data":[[0.25,0.5]]}}',
    '{"success":true,"result":{"mutationId":"retry1"}}',
);
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('retry'), array());
expect('retry stores the failed post', strpos(embed_cli_lines(), 'embed_retried=1') !== false, true);
expect('retry clears the failed flag', isset($GLOBALS['cfais_embed_meta'][94]['skyphusion_ai_embed_failed']), false);

$GLOBALS['cfais_embed_meta'][95]['skyphusion_ai_embed_hash'] = 'stale';
$GLOBALS['skyphusion_ai_embed_posts'] = array(embed_post(95, 'Rebuild', 'Fresh text'));
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_bodies'] = array(
    '{"result":{"data":[[0.25,0.5]]}}',
    '{"result":{"data":[[0.25,0.5]]}}',
    '{"success":true,"result":{"mutationId":"rebuild1"}}',
);
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('rebuild'), array());
expect('rebuild stores the post again', strpos(embed_cli_lines(), 'embed_state=ran') !== false, true);
expect('rebuild called the model', $GLOBALS['cfais_calls'] >= 2, true);

$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_bodies'] = array('{"success":true,"result":{}}');
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('purge'), array());
expect('purge deletes the index', strpos(embed_cli_lines(), 'embed_delete=deleted') !== false, true);
expect('purge turns embeddings off', skyphusion_ai_embed_on(skyphusion_ai_embed_config()), false);
expect('purge does not call the model', strpos(embed_cli_lines(), 'embed_called=0') !== false, true);

embed_ready();
$embed_move_post = embed_post(96, 'Move', 'Copy this');
$GLOBALS['cfais_embed_meta'][96]['skyphusion_ai_embed_hash'] = hash('sha256', skyphusion_ai_embed_text($embed_move_post, array('title', 'content', 'excerpt')));
$GLOBALS['skyphusion_ai_embed_posts'] = array($embed_move_post);
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_request_urls'] = array();
$GLOBALS['cfais_last_post'] = array('marker' => 1);
$GLOBALS['cfais_bodies'] = array(
    '{"success":true,"result":{"name":"shop-copy"}}',
    '{"success":true,"result":[{"id":"p96","values":[0.25,0.5],"metadata":{"title":"Move"}}]}',
    '{"success":true,"result":{"mutationId":"move1"}}',
);
$embed_neurons_before = (int) skyphusion_ai_embed_config()['neurons'];
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('move'), array('index' => 'shop-copy'));
expect('move copies the vector', strpos(embed_cli_lines(), 'embed_moved=1') !== false, true);
expect('move does not call the model', $GLOBALS['cfais_last_post'], array('marker' => 1));
expect('move spends no neurons', (int) skyphusion_ai_embed_config()['neurons'], $embed_neurons_before);
expect('move says the model was not called', strpos(embed_cli_lines(), 'embed_called=0') !== false, true);
$embed_move_upsert = false;
$embed_move_model = false;
foreach ($GLOBALS['cfais_request_urls'] as $embed_move_url) {
    if (strpos($embed_move_url, '/shop-copy/upsert') !== false) {
        $embed_move_upsert = true;
    }
    if (strpos($embed_move_url, '/ai/run/') !== false) {
        $embed_move_model = true;
    }
}
expect('move writes the other index', $embed_move_upsert, true);
expect('move does not use the embeddings route', $embed_move_model, false);
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('backfill'), array());
expect('backfill is not an embed command', strpos(embed_cli_lines(), 'embed_state=unknown') !== false, true);
expect('an unknown command makes no request', $GLOBALS['cfais_calls'], 0);

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
