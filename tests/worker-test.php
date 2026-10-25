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
);
$worker_deployed = skyphusion_ai_worker_deploy();
expect('a deploy saves the workers.dev address', $worker_deployed['url'], 'https://skyphusion-ai.example-sub.workers.dev');
expect('the saved address is readable', skyphusion_ai_worker_url(), 'https://skyphusion-ai.example-sub.workers.dev');
expect('deploy uploads, reads the name, and enables it', $GLOBALS['cfais_calls'], 3);
$worker_body = isset($GLOBALS['cfais_last_request']['args']['body']) ? (string) $GLOBALS['cfais_last_request']['args']['body'] : '';
expect('the enable call turns the subdomain on', strpos($worker_body, '"enabled":true') !== false, true);

$GLOBALS['cfais_can']['manage_options'] = true;
ob_start();
skyphusion_ai_worker_render();
$worker_screen = (string) ob_get_clean();
expect('the screen names the command', strpos($worker_screen, 'wp skyphusion-ai worker deploy') !== false, true);
expect('the screen shows the workers.dev address', strpos($worker_screen, 'example-sub.workers.dev') !== false, true);
expect('the screen does not show the token', strpos($worker_screen, $worker_token) === false, true);

$GLOBALS['cfais_user_id'] = 4;
$GLOBALS['cfais_can']['read'] = true;
skyphusion_ai_assistant_apply(array('enabled' => '1'));
$worker_edge = skyphusion_ai_assistant_shortcode();
expect('the page calls the worker', strpos($worker_edge, 'https://skyphusion-ai.example-sub.workers.dev') !== false, true);
expect('the page does not show the token', strpos($worker_edge, $worker_token) === false, true);
expect('the page does not post the question to PHP', strpos($worker_edge, 'admin-post.php') === false, true);

$worker_before = skyphusion_ai_neuron_state();
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_status'] = 200;
$GLOBALS['cfais_bodies'] = array("data: {\"text\":\"The \"}\n\ndata: {\"text\":\"shirt\",\"usage\":{\"neurons\":1}}\n\ndata: {\"text\":\" is linen.\",\"usage\":{\"neurons\":4}}\n\n");
$worker_answer = skyphusion_ai_assistant_text('Is the shirt linen?');
$worker_after = skyphusion_ai_neuron_state();
expect('an sse body is one answer', $worker_answer, 'The shirt is linen.');
expect('an sse body records one text call', $worker_after['features']['text'], $worker_before['features']['text'] + 1);

$worker_uninstall = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
expect('uninstall removes the worker option', strpos($worker_uninstall, 'skyphusion_ai_worker') !== false, true);
$worker_pin = (string) file_get_contents(dirname(__DIR__) . '/includes/endpoint.php');
expect('the snippet pin stays', strpos($worker_pin, "define('SKYPHUSION_AI_SNIPPET', '0.0.25')") !== false, true);

unset($GLOBALS['cfais_options']['skyphusion_ai_worker']);
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
