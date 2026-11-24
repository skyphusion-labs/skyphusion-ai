<?php
/**
 * Assistant Worker deploy. Loaded after member-test.php.
 * A failed deploy does not save a URL.
 */

if (!function_exists('skyphusion_ai_worker_deploy')) {
    require_once dirname(__DIR__) . '/includes/worker.php';
}

expect('the worker address starts empty', skyphusion_ai_worker_url(), '');
expect('an http address is rejected', skyphusion_ai_worker_url_clean('http://skyphusion-ai.example.workers.dev'), '');
expect('the shop host is rejected', skyphusion_ai_worker_url_clean('https://shop.example/'), '');
expect('a query string is rejected', skyphusion_ai_worker_url_clean('https://skyphusion-ai.example.workers.dev/?a=1'), '');
expect('a workers.dev address is kept', skyphusion_ai_worker_url_clean('https://skyphusion-ai.example.workers.dev'), 'https://skyphusion-ai.example.workers.dev');

$worker_sse = "data: {\"response\":\"The \"}\n\ndata: {\"response\":\"shirt\",\"usage\":{\"neurons\":2}}\n\ndata: [DONE]\n\n";
expect('sse chunks join into one answer', skyphusion_ai_worker_sse_text($worker_sse), 'The shirt');
expect('sse neurons are read once from the last chunk', skyphusion_ai_worker_sse_neurons($worker_sse), 2);

$worker_script = (string) file_get_contents(dirname(__DIR__) . '/worker/index.js');
expect('the script uses the AI binding', strpos($worker_script, 'env.AI.run') !== false, true);
expect('the script streams', strpos($worker_script, 'stream: true') !== false, true);
expect('the script has no bearer', strpos($worker_script, 'Bearer') === false, true);
expect('the script does not call Skyphusion', strpos($worker_script, 'skyphusion.org') === false, true);

// Issue #230. The Worker is JavaScript, so its hourly backfill runs under node
// against a stand-in AI binding. No node is a FAILURE here, never a skip: a
// skipped check reads like a passed one. CI's ubuntu-latest image has node.
$worker_node_lines = array();
$worker_node_code = 1;
exec('node ' . escapeshellarg(__DIR__ . '/worker-backfill.mjs') . ' 2>&1', $worker_node_lines, $worker_node_code);
$worker_node_text = implode("\n", $worker_node_lines);
expect('node ran the Worker backfill check', strpos($worker_node_text, 'ok worker backfill: a response reply is still saved') !== false, true);
expect('the Worker backfill check exits 0', $worker_node_code, 0);
if ($worker_node_code !== 0) {
    fwrite(STDERR, $worker_node_text . "\n");
}

$worker_src = (string) file_get_contents(dirname(__DIR__) . '/includes/worker.php');
expect('the command is worker deploy', strpos($worker_src, 'skyphusion-ai worker deploy') !== false, true);
expect('deploy does not add a shop route', strpos($worker_src, 'workers/routes') === false, true);

$worker_prev_key = getenv('CLOUDFLARE_API_KEY');
$worker_prev_account = getenv('CLOUDFLARE_ACCOUNT_ID');
$worker_token = 'sentinel-token-' . str_repeat('a', 30);
putenv('CLOUDFLARE_API_KEY=' . $worker_token);
putenv('CLOUDFLARE_ACCOUNT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
if (!isset($GLOBALS['cfais_settings']) || !is_array($GLOBALS['cfais_settings'])) {
    $GLOBALS['cfais_settings'] = array();
}
$GLOBALS['cfais_settings']['account_id'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$GLOBALS['cfais_settings']['gateway_id'] = '';
$GLOBALS['cfais_status'] = 200;
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_bodies'] = array('{"success":false,"errors":[{"message":"Authentication error"}]}');
$worker_failed = skyphusion_ai_worker_deploy();
expect('a failed deploy is an error', is_wp_error($worker_failed), true);
expect('a failed deploy does not show the token', strpos($worker_failed->message, $worker_token) === false, true);
expect('a failed deploy leaves the address empty', skyphusion_ai_worker_url(), '');

$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_bodies'] = array(
    '{"success":true,"result":{"id":"skyphusion-ai"}}',
    '{"success":true,"result":{"subdomain":"example-sub"}}',
    '{"success":true,"result":{"enabled":true}}',
    '{"success":true,"result":{"schedules":[{"cron":"0 * * * *"}]}}',
);
$worker_deployed = skyphusion_ai_worker_deploy();
expect('a deploy saves the workers.dev address', $worker_deployed['url'], 'https://skyphusion-ai.example-sub.workers.dev');
expect('the saved address is readable', skyphusion_ai_worker_url(), 'https://skyphusion-ai.example-sub.workers.dev');
expect('deploy uploads, enables the name, and sets the hour', $GLOBALS['cfais_calls'], 4);
$worker_body = isset($GLOBALS['cfais_last_request']['args']['body']) ? (string) $GLOBALS['cfais_last_request']['args']['body'] : '';
expect('the hourly call is minute 0', strpos($worker_body, '0 * * * *') !== false, true);

$GLOBALS['cfais_can']['manage_options'] = true;
ob_start();
skyphusion_ai_worker_render();
$worker_screen = (string) ob_get_clean();
expect('the screen names the command', strpos($worker_screen, 'wp skyphusion-ai worker deploy') !== false, true);
expect('the screen shows the workers.dev address', strpos($worker_screen, 'example-sub.workers.dev') !== false, true);
expect('the screen does not show the token', strpos($worker_screen, $worker_token) === false, true);
$worker_saved = get_option('skyphusion_ai_worker', array());
expect('the screen does not show the worker secret', strpos($worker_screen, (string) $worker_saved['secret']) === false, true);
$worker_pack = skyphusion_ai_worker_multipart('export default {}', 'https://shop.example', '@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'skyphusion-ai', 'https://shop.example', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
expect('the upload binds Vectorize', strpos($worker_pack, 'vectorize') !== false, true);
expect('the upload does not contain the token', strpos($worker_pack, $worker_token) === false, true);
$worker_gw_pack = skyphusion_ai_worker_multipart('export default {}', 'https://shop.example', '@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'skyphusion-ai', 'https://shop.example', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', array(
    'id' => 'store',
    'route' => 'route1',
    'cache' => '3600',
    'collect' => 'true',
));
$worker_gw_at = strpos($worker_gw_pack, "\r\n\r\n");
$worker_gw_end = strpos($worker_gw_pack, "\r\n--", $worker_gw_at + 4);
$worker_gw_meta = json_decode(substr($worker_gw_pack, $worker_gw_at + 4, $worker_gw_end - ($worker_gw_at + 4)), true);
$worker_gw_bind = array();
if (is_array($worker_gw_meta) && isset($worker_gw_meta['bindings']) && is_array($worker_gw_meta['bindings'])) {
    foreach ($worker_gw_meta['bindings'] as $worker_gw_row) {
        if (is_array($worker_gw_row) && isset($worker_gw_row['name'])) {
            $worker_gw_bind[$worker_gw_row['name']] = $worker_gw_row;
        }
    }
}
expect('the upload keeps the AI binding', isset($worker_gw_bind['AI']['type']) ? $worker_gw_bind['AI']['type'] : '', 'ai');
expect('the upload keeps Vectorize', isset($worker_gw_bind['VECTORIZE']['type']) ? $worker_gw_bind['VECTORIZE']['type'] : '', 'vectorize');
expect('the upload sends the gateway id', isset($worker_gw_bind['GATEWAY_ID']['text']) ? $worker_gw_bind['GATEWAY_ID']['text'] : '', 'store');
expect('the upload sends the route', isset($worker_gw_bind['GATEWAY_ROUTE']['text']) ? $worker_gw_bind['GATEWAY_ROUTE']['text'] : '', 'route1');
expect('the upload sends the cache ttl', isset($worker_gw_bind['GATEWAY_CACHE']['text']) ? $worker_gw_bind['GATEWAY_CACHE']['text'] : '', '3600');
expect('the upload sends prompt logging', isset($worker_gw_bind['GATEWAY_COLLECT']['text']) ? $worker_gw_bind['GATEWAY_COLLECT']['text'] : '', 'true');
expect('the gateway upload does not contain the token', strpos($worker_gw_pack, $worker_token) === false, true);
$worker_blank_at = strpos($worker_pack, "\r\n\r\n");
$worker_blank_end = strpos($worker_pack, "\r\n--", $worker_blank_at + 4);
$worker_blank_meta = json_decode(substr($worker_pack, $worker_blank_at + 4, $worker_blank_end - ($worker_blank_at + 4)), true);
$worker_blank_id = '';
if (is_array($worker_blank_meta) && isset($worker_blank_meta['bindings']) && is_array($worker_blank_meta['bindings'])) {
    foreach ($worker_blank_meta['bindings'] as $worker_blank_row) {
        if (is_array($worker_blank_row) && isset($worker_blank_row['name']) && 'GATEWAY_ID' === $worker_blank_row['name']) {
            $worker_blank_id = (string) $worker_blank_row['text'];
        }
    }
}
expect('a blank gateway id is an empty binding', $worker_blank_id, '');
expect('the script queries Vectorize', strpos($worker_script, 'env.VECTORIZE.query') !== false, true);
expect('the script passes a gateway object', strpos($worker_script, 'gateway: gateway') !== false, true);
expect('a blank gateway id skips the gateway option', strpos($worker_script, 'if (!gateway)') !== false, true);
expect('text calls can use a dynamic route', strpos($worker_script, "'dynamic/' + route") !== false, true);
expect('embeddings stay on the embedding model', strpos($worker_script, 'skyphusionAiRun(env, EMBED_MODEL') !== false, true);
expect('the worker can log the Workers AI model', strpos($worker_script, 'logModel') !== false, true);
expect('the script keeps the large embedding model', strpos($worker_script, '@cf/baai/bge-large-en-v1.5') !== false, true);
expect('the script has an hourly task', strpos($worker_script, 'async scheduled') !== false, true);

$GLOBALS['cfais_user_id'] = 4;
$GLOBALS['cfais_can']['read'] = true;
skyphusion_ai_assistant_apply(array('enabled' => '1'));
if (!defined('SKYPHUSION_AI_VERSION')) {
    define('SKYPHUSION_AI_VERSION', '0.0.0-test');
}
if (!function_exists('plugins_url')) {
    function plugins_url($path = '', $plugin = '')
    {
        return 'https://example.test/wp-content/plugins/skyphusion-ai/' . ltrim($path, '/');
    }
}
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $args = array())
    {
        $GLOBALS['cfais_enqueued_scripts'][$handle] = array('src' => $src, 'args' => $args);
    }
}
$GLOBALS['cfais_enqueued_scripts'] = array();
$worker_edge = skyphusion_ai_assistant_shortcode();
expect('the page calls the worker', strpos($worker_edge, 'https://skyphusion-ai.example-sub.workers.dev') !== false, true);
expect('the page does not show the token', strpos($worker_edge, $worker_token) === false, true);
expect('the page does not post the question to PHP', strpos($worker_edge, 'admin-post.php') === false, true);
expect('the edge page keeps a transcript', strpos($worker_edge, 'skyphusion-ai-assistant-transcript') !== false, true);
expect('the edge page sends the turn window', strpos($worker_edge, 'data-keep="10"') !== false, true);
expect('the worker summarizes older turns', strpos($worker_script, 'Summarize these earlier turns in one short block.') !== false, true);
expect('the worker labels the summary', strpos($worker_script, 'Earlier talk:') !== false, true);
expect('the worker does not read an address', strpos($worker_script, 'cf-connecting-ip') === false, true);
expect('the worker cites a product sku', strpos($worker_script, 'SKU ') !== false, true);
expect('the worker cites a managed count', strpos($worker_script, ' available.') !== false, true);
expect('the edge page prints no script tag', stripos($worker_edge, '<script') === false, true);
expect('the edge page enqueues the edge script', isset($GLOBALS['cfais_enqueued_scripts']['skyphusion-ai-assistant-edge']), true);
expect('the edge page draws a product cite', strpos((string) file_get_contents(dirname(__DIR__) . '/assets/js/assistant-edge.js'), 'parsed.citations') !== false, true);

$worker_before = skyphusion_ai_neuron_state();
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_status'] = 200;
$GLOBALS['cfais_bodies'] = array("data: {\"text\":\"The \"}\n\ndata: {\"text\":\"shirt\",\"usage\":{\"neurons\":1}}\n\ndata: {\"text\":\" is linen.\",\"usage\":{\"neurons\":4}}\n\n");
$worker_answer = skyphusion_ai_assistant_text('Is the shirt linen?');
$worker_after = skyphusion_ai_neuron_state();
expect('an sse body is one answer', $worker_answer, 'The shirt is linen.');
expect('an sse body records one text call', $worker_after['features']['text'], $worker_before['features']['text'] + 1);

$GLOBALS['skyphusion_ai_member_roles'] = array('customer');
skyphusion_ai_member_apply(array('roles' => 'customer'));
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_bodies'] = array('{"pages":[{"title":"Linen shirt","url":"https://shop.example/linen"}]}');
$worker_pages = skyphusion_ai_member_results('linen hop');
expect('a member search uses one worker call', $GLOBALS['cfais_calls'], 1);
expect('a member search returns the worker page', $worker_pages[0]['title'], 'Linen shirt');
$worker_hop_url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
expect('the member call goes to the worker', strpos($worker_hop_url, 'workers.dev') !== false, true);
expect('the member call does not embed from PHP', strpos($worker_hop_url, 'bge-large') === false, true);
$GLOBALS['cfais_user_id'] = 0;
$GLOBALS['cfais_calls'] = 0;
$worker_closed = skyphusion_ai_member_results('linen hop');
expect('a logged-out visitor still spends nothing', $worker_closed['state'], 'closed');
expect('a logged-out visitor still makes no request', $GLOBALS['cfais_calls'], 0);
$GLOBALS['cfais_user_id'] = 4;
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
update_option('skyphusion_ai_backfill', array('enabled' => 1), false);
$GLOBALS['skyphusion_ai_backfill_seo'] = 'yoast';
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 6, 'kind' => 'description', 'title' => 'Linen shirt', 'content' => 'Soft linen cloth. Soft linen cloth. Soft linen cloth. Soft linen cloth.'),
);
expect('a wrong secret does not match', skyphusion_ai_worker_secret_matches('nope'), false);
expect('the saved secret matches', skyphusion_ai_worker_secret_matches((string) $worker_saved['secret']), true);
$GLOBALS['cfais_calls'] = 0;
$worker_job = skyphusion_ai_worker_backfill_offer();
expect('the site offers one text job', $worker_job['state'], 'ready');
expect('the job prompt names the page', strpos($worker_job['prompt'], 'Linen shirt') !== false, true);
expect('the offer does not call the model', $GLOBALS['cfais_calls'], 0);
$GLOBALS['cfais_calls'] = 0;
skyphusion_ai_backfill_on_hourly();
expect('the site hourly job does not call the model', $GLOBALS['cfais_calls'], 0);

if (!isset($GLOBALS['cfais_stored']) || !is_array($GLOBALS['cfais_stored'])) {
    $GLOBALS['cfais_stored'] = array();
}
$worker_gw_keys = array('gateway_id', 'gateway_route', 'cache', 'log_prompts');
$worker_gw_prev = array();
foreach ($worker_gw_keys as $worker_gw_key) {
    $worker_gw_prev[$worker_gw_key] = array_key_exists($worker_gw_key, $GLOBALS['cfais_stored']) ? $GLOBALS['cfais_stored'][$worker_gw_key] : null;
}
$GLOBALS['cfais_stored']['gateway_id'] = 'store';
$GLOBALS['cfais_stored']['gateway_route'] = 'route1';
$GLOBALS['cfais_stored']['cache'] = '1';
$GLOBALS['cfais_stored']['log_prompts'] = '1';
$GLOBALS['cfais_options']['skyphusion_ai_gateway_down'] = time() + 3600;
expect('the worker keeps the gateway id during the direct hour', skyphusion_ai_worker_gateway_id(), 'store');
expect('php hides the gateway id during the direct hour', cloudflare_ai_provider_gateway_id('store'), '');
unset($GLOBALS['cfais_options']['skyphusion_ai_gateway_down']);
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_status'] = 200;
$GLOBALS['cfais_bodies'] = array(
    '{"success":true,"result":{"id":"skyphusion-ai"}}',
    '{"success":true,"result":{"subdomain":"example-sub"}}',
    '{"success":true,"result":{"enabled":true}}',
    '{"success":true,"result":{"schedules":[{"cron":"0 * * * *"}]}}',
);
$worker_gw_deployed = skyphusion_ai_worker_deploy();
expect('a gateway deploy still saves the address', $worker_gw_deployed['url'], 'https://skyphusion-ai.example-sub.workers.dev');
$worker_gw_saved = get_option('skyphusion_ai_worker', array());
expect('deploy stores the gateway id', isset($worker_gw_saved['gateway_id']) ? $worker_gw_saved['gateway_id'] : '', 'store');
expect('deploy stores the route', isset($worker_gw_saved['gateway_route']) ? $worker_gw_saved['gateway_route'] : '', 'route1');
expect('deploy stores the cache mode', isset($worker_gw_saved['gateway_cache']) ? $worker_gw_saved['gateway_cache'] : '', '3600');
expect('deploy stores prompt logging', isset($worker_gw_saved['gateway_collect']) ? $worker_gw_saved['gateway_collect'] : '', 'true');
expect('the worker matches the saved gateway', skyphusion_ai_worker_stale(), false);
$GLOBALS['cfais_stored']['gateway_route'] = 'other';
expect('a route change marks the worker stale', skyphusion_ai_worker_stale(), true);
ob_start();
skyphusion_ai_worker_render();
$worker_stale_screen = (string) ob_get_clean();
expect('the screen says the worker is behind', strpos($worker_stale_screen, 'The live Worker is behind the saved gateway settings.') !== false, true);
expect('the stale screen does not show the token', strpos($worker_stale_screen, $worker_token) === false, true);
$GLOBALS['cfais_stored']['gateway_route'] = 'route1';
$GLOBALS['cfais_stored']['cache'] = '0';
expect('a cache change marks the worker stale', skyphusion_ai_worker_stale(), true);
$GLOBALS['cfais_stored']['cache'] = '1';
$GLOBALS['cfais_stored']['log_prompts'] = '0';
expect('a logging change marks the worker stale', skyphusion_ai_worker_stale(), true);
$GLOBALS['cfais_stored']['log_prompts'] = '1';
$GLOBALS['cfais_stored']['gateway_id'] = '';
expect('a blank gateway id marks the worker stale', skyphusion_ai_worker_stale(), true);
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_bodies'] = array(
    '{"success":true,"result":{"id":"skyphusion-ai"}}',
    '{"success":true,"result":{"subdomain":"example-sub"}}',
    '{"success":true,"result":{"enabled":true}}',
    '{"success":true,"result":{"schedules":[{"cron":"0 * * * *"}]}}',
);
skyphusion_ai_worker_deploy();
$worker_cleared = get_option('skyphusion_ai_worker', array());
expect('a blank gateway clears the stored id', isset($worker_cleared['gateway_id']) ? $worker_cleared['gateway_id'] : 'missing', '');
expect('a blank gateway is current after deploy', skyphusion_ai_worker_stale(), false);
expect('a blank gateway clears the route snapshot', isset($worker_cleared['gateway_route']) ? $worker_cleared['gateway_route'] : 'missing', '');
foreach ($worker_gw_keys as $worker_gw_key) {
    if (null === $worker_gw_prev[$worker_gw_key]) {
        unset($GLOBALS['cfais_stored'][$worker_gw_key]);
    } else {
        $GLOBALS['cfais_stored'][$worker_gw_key] = $worker_gw_prev[$worker_gw_key];
    }
}

$worker_uninstall = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
expect('uninstall removes the worker option', strpos($worker_uninstall, 'skyphusion_ai_worker') !== false, true);
$worker_pin = (string) file_get_contents(dirname(__DIR__) . '/includes/endpoint.php');
expect('the snippet pin stays', strpos($worker_pin, "define( 'SKYPHUSION_AI_SNIPPET', '0.0.25' )") !== false, true);

unset($GLOBALS['cfais_options']['skyphusion_ai_worker']);
unset($GLOBALS['cfais_options']['skyphusion_ai_backfill']);
unset($GLOBALS['skyphusion_ai_backfill_seo']);
unset($GLOBALS['skyphusion_ai_backfill_candidates']);
unset($GLOBALS['skyphusion_ai_member_roles']);
unset($GLOBALS['cfais_options']['skyphusion_ai_member']);
unset($GLOBALS['cfais_user_id']);
unset($GLOBALS['cfais_can']['read']);
unset($GLOBALS['cfais_can']['manage_options']);
if (false === $worker_prev_key || '' === $worker_prev_key) {
    putenv('CLOUDFLARE_API_KEY');
} else {
    putenv('CLOUDFLARE_API_KEY=' . $worker_prev_key);
}
if (false === $worker_prev_account || '' === $worker_prev_account) {
    putenv('CLOUDFLARE_ACCOUNT_ID');
} else {
    putenv('CLOUDFLARE_ACCOUNT_ID=' . $worker_prev_account);
}
