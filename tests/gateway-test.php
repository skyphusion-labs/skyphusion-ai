<?php
/**
 * AI Gateway log fields. Direct Workers AI calls stay untagged.
 */

if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        return isset($GLOBALS['cfais_user_id']) ? (int) $GLOBALS['cfais_user_id'] : 0;
    }
}
if (!function_exists('home_url')) {
    function home_url($path = '', $scheme = null)
    {
        return isset($GLOBALS['cfais_home']) ? (string) $GLOBALS['cfais_home'] : 'https://shop.example';
    }
}

$cfais_gateway_direct = 'https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/@cf/baai/bge-large-en-v1.5';
$cfais_gateway_url = 'https://gateway.ai.cloudflare.com/v1/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/store/workers-ai/@cf/baai/bge-large-en-v1.5';
$cfais_gateway_chat = 'https://gateway.ai.cloudflare.com/v1/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/store/workers-ai/v1/chat/completions';
$cfais_gateway_lookalike = 'https://api.cloudflare.com/client/v4/accounts/x/ai/run/gateway.ai.cloudflare.com';
$cfais_gateway_suffix = 'https://gateway.ai.cloudflare.com.evil.example/v1/a/b';
$cfais_gateway_prompt = 'do-not-log-this-prompt';
$cfais_gateway_token = 'gateway-test-token';

expect('the gateway host is recognized', skyphusion_ai_gateway_host($cfais_gateway_url), true);
expect('a direct Workers AI host is not the gateway', skyphusion_ai_gateway_host($cfais_gateway_direct), false);
expect('the gateway name in a path is not the host', skyphusion_ai_gateway_host($cfais_gateway_lookalike), false);
expect('a lookalike host is not the gateway', skyphusion_ai_gateway_host($cfais_gateway_suffix), false);

$GLOBALS['cfais_home'] = 'https://user:pass@shop.example/store?q=' . $cfais_gateway_prompt . '#frag';
$GLOBALS['cfais_user_id'] = 0;
$cfais_gateway_visitor = skyphusion_ai_gateway_metadata($cfais_gateway_prompt, 'not a model');
expect('a visitor user id is an empty string', $cfais_gateway_visitor['user'], '');
expect('a prompt is not kept as the feature', $cfais_gateway_visitor['feature'], 'text');
expect('a prompt is not kept as the model', $cfais_gateway_visitor['model'], '');
expect('the site drops the user, the query, and the fragment', $cfais_gateway_visitor['site'], 'https://shop.example/store');
expect('the log has five fields', count($cfais_gateway_visitor), 5);
expect('the log names the plugin version', $cfais_gateway_visitor['version'], defined('SKYPHUSION_AI_VERSION') ? (string) SKYPHUSION_AI_VERSION : '0.6.2');
expect('translation is logged as text', skyphusion_ai_gateway_feature('translate'), 'text');

$GLOBALS['cfais_user_id'] = 15;
$cfais_gateway_member = skyphusion_ai_gateway_metadata('embeddings', '@cf/baai/bge-large-en-v1.5');
expect('a logged-in user id is numeric text', $cfais_gateway_member['user'], '15');
expect('embeddings stays the feature', $cfais_gateway_member['feature'], 'embeddings');
expect('the embeddings model id is kept', $cfais_gateway_member['model'], '@cf/baai/bge-large-en-v1.5');

$cfais_gateway_direct_header = skyphusion_ai_gateway_metadata_header($cfais_gateway_direct, 'embeddings', '@cf/baai/bge-large-en-v1.5');
expect('a direct call adds no gateway header', $cfais_gateway_direct_header, array());

$cfais_gateway_header = skyphusion_ai_gateway_metadata_header($cfais_gateway_url, 'embeddings', '@cf/baai/bge-large-en-v1.5');
expect('a gateway call adds the metadata and the log header', count($cfais_gateway_header), 2);
$cfais_gateway_json = isset($cfais_gateway_header['cf-aig-metadata']) ? $cfais_gateway_header['cf-aig-metadata'] : '';
$cfais_gateway_decoded = json_decode((string) $cfais_gateway_json, true);
$cfais_gateway_keys = is_array($cfais_gateway_decoded) ? array_keys($cfais_gateway_decoded) : array();
sort($cfais_gateway_keys);
expect('the header names the five fields', $cfais_gateway_keys, array('feature', 'model', 'site', 'user', 'version'));
expect('the header does not carry the prompt', strpos((string) $cfais_gateway_json, $cfais_gateway_prompt), false);
expect('the header does not carry the token', strpos((string) $cfais_gateway_json, $cfais_gateway_token), false);
foreach ($cfais_gateway_keys as $cfais_gateway_key) {
    expect('a log field does not use a reserved prefix', strpos((string) $cfais_gateway_key, 'cf.'), false);
}

unset($GLOBALS['skyphusion_ai_neuron_call']);
cfais_neuron_reset();
$cfais_gateway_closed = skyphusion_ai_neuron_closed_message(
    $cfais_gateway_chat,
    array(
        'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        'messages' => array(array('content' => $cfais_gateway_prompt)),
    )
);
expect('the tagged call is allowed under the budget', $cfais_gateway_closed, '');
$cfais_gateway_transport = skyphusion_ai_gateway_transport_headers($cfais_gateway_chat, $cfais_gateway_token);
expect('the client call still sends the bearer', isset($cfais_gateway_transport['Authorization']), true);
$cfais_gateway_transport_json = isset($cfais_gateway_transport['cf-aig-metadata']) ? $cfais_gateway_transport['cf-aig-metadata'] : '';
$cfais_gateway_transport_meta = json_decode((string) $cfais_gateway_transport_json, true);
expect('the client call tags the text feature', is_array($cfais_gateway_transport_meta) && $cfais_gateway_transport_meta['feature'] === 'text', true);
expect('the client call tags the model', is_array($cfais_gateway_transport_meta) && $cfais_gateway_transport_meta['model'] === '@cf/meta/llama-3.3-70b-instruct-fp8-fast', true);
expect('the client tag does not carry the prompt', strpos((string) $cfais_gateway_transport_json, $cfais_gateway_prompt), false);
$cfais_gateway_direct_transport = skyphusion_ai_gateway_transport_headers($cfais_gateway_direct, $cfais_gateway_token);
expect('a direct client call has no gateway tag', isset($cfais_gateway_direct_transport['cf-aig-metadata']), false);
unset($GLOBALS['skyphusion_ai_neuron_call']);

cfais_neuron_reset();
$GLOBALS['cfais_body'] = json_encode(array('usage' => array('neurons' => 3)));
$cfais_gateway_embed_text = 'unique-embedding-text-gateway';
$cfais_gateway_embed = skyphusion_ai_neuron_embeddings_post(
    $cfais_gateway_url,
    $cfais_gateway_token,
    array('text' => array($cfais_gateway_embed_text))
);
$cfais_gateway_embed_headers = is_array($cfais_gateway_embed) && isset($cfais_gateway_embed['args']['headers']) ? $cfais_gateway_embed['args']['headers'] : array();
$cfais_gateway_embed_json = isset($cfais_gateway_embed_headers['cf-aig-metadata']) ? $cfais_gateway_embed_headers['cf-aig-metadata'] : '';
$cfais_gateway_embed_meta = json_decode((string) $cfais_gateway_embed_json, true);
expect('an embeddings call through the gateway is tagged', is_array($cfais_gateway_embed_meta) && $cfais_gateway_embed_meta['feature'] === 'embeddings', true);
expect('the embeddings tag does not carry the text', strpos((string) $cfais_gateway_embed_json, $cfais_gateway_embed_text), false);
expect('the embeddings body still carries the text', strpos((string) $cfais_gateway_embed['args']['body'], $cfais_gateway_embed_text) !== false, true);

cfais_neuron_reset();
$GLOBALS['cfais_body'] = json_encode(array('usage' => array('neurons' => 2)));
$cfais_gateway_embed_direct = skyphusion_ai_neuron_embeddings_post(
    $cfais_gateway_direct,
    $cfais_gateway_token,
    array('text' => array($cfais_gateway_embed_text))
);
$cfais_gateway_embed_direct_headers = is_array($cfais_gateway_embed_direct) && isset($cfais_gateway_embed_direct['args']['headers']) ? $cfais_gateway_embed_direct['args']['headers'] : array();
expect('a direct embeddings call is not tagged', isset($cfais_gateway_embed_direct_headers['cf-aig-metadata']), false);

$cfais_gateway_search = file_get_contents(dirname(__DIR__) . '/includes/provider.php');
$cfais_gateway_search_at = strpos((string) $cfais_gateway_search, 'function skyphusion_ai_query');
$cfais_gateway_search_fn = '';
if (false !== $cfais_gateway_search_at) {
    $cfais_gateway_search_fn = substr((string) $cfais_gateway_search, $cfais_gateway_search_at, 900);
}
expect('the public search call does not add a gateway tag', strpos($cfais_gateway_search_fn, 'cf-aig-metadata'), false);

if (!isset($GLOBALS['cfais_stored']) || !is_array($GLOBALS['cfais_stored'])) {
    $GLOBALS['cfais_stored'] = array();
}
$cfais_gateway_log_was = array_key_exists('log_prompts', $GLOBALS['cfais_stored']) ? $GLOBALS['cfais_stored']['log_prompts'] : null;
$cfais_gateway_route_was = array_key_exists('gateway_route', $GLOBALS['cfais_stored']) ? $GLOBALS['cfais_stored']['gateway_route'] : null;
$GLOBALS['cfais_stored']['log_prompts'] = '1';
unset($GLOBALS['cfais_stored']['gateway_route']);
$cfais_gateway_log_on = skyphusion_ai_gateway_metadata_header($cfais_gateway_url, 'text', '@cf/meta/llama-3.3-70b-instruct-fp8-fast');
expect('text logging is on', isset($cfais_gateway_log_on['cf-aig-collect-log-payload']) ? $cfais_gateway_log_on['cf-aig-collect-log-payload'] : '', 'true');
$cfais_gateway_log_embed = skyphusion_ai_gateway_metadata_header($cfais_gateway_url, 'embeddings', '@cf/baai/bge-large-en-v1.5');
expect('embeddings logging stays off', isset($cfais_gateway_log_embed['cf-aig-collect-log-payload']) ? $cfais_gateway_log_embed['cf-aig-collect-log-payload'] : '', 'false');
$GLOBALS['cfais_stored']['log_prompts'] = '0';
$cfais_gateway_log_off = skyphusion_ai_gateway_metadata_header($cfais_gateway_url, 'text', '@cf/meta/llama-3.3-70b-instruct-fp8-fast');
expect('logging off sends false', isset($cfais_gateway_log_off['cf-aig-collect-log-payload']) ? $cfais_gateway_log_off['cf-aig-collect-log-payload'] : '', 'false');
$cfais_gateway_log_direct = skyphusion_ai_gateway_metadata_header($cfais_gateway_direct, 'text', '@cf/meta/llama-3.3-70b-instruct-fp8-fast');
expect('a direct call does not log prompts', isset($cfais_gateway_log_direct['cf-aig-collect-log-payload']), false);
expect('a direct call still has no metadata', isset($cfais_gateway_log_direct['cf-aig-metadata']), false);

unset($GLOBALS['cfais_options']['skyphusion_ai_gateway_down']);
skyphusion_ai_gateway_mark($cfais_gateway_direct, 'text');
expect('a direct refusal does not start the hour', skyphusion_ai_gateway_fallback_active(), false);
skyphusion_ai_gateway_mark($cfais_gateway_url, 'text');
expect('a gateway refusal starts the hour', skyphusion_ai_gateway_fallback_active(), true);
$cfais_gateway_until = skyphusion_ai_gateway_fallback_until();
$cfais_gateway_direct_run = cloudflare_ai_provider_run_url('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'store', '@cf/meta/llama-3.3-70b-instruct-fp8-fast');
expect('the hour uses the direct API', strpos($cfais_gateway_direct_run, 'api.cloudflare.com') !== false, true);
expect('the hour leaves the gateway host', strpos($cfais_gateway_direct_run, 'gateway.ai.cloudflare.com') === false, true);
expect('the notice names the direct API', strpos(skyphusion_ai_gateway_notice_text(), 'api.cloudflare.com') !== false, true);
skyphusion_ai_gateway_mark($cfais_gateway_url, 'text');
expect('a second refusal does not end the hour sooner', skyphusion_ai_gateway_fallback_until(), $cfais_gateway_until);
unset($GLOBALS['cfais_options']['skyphusion_ai_gateway_down']);
expect('the notice is empty when the gateway is in use', skyphusion_ai_gateway_notice_text(), '');

$GLOBALS['cfais_stored']['gateway_route'] = 'route1';
skyphusion_ai_gateway_mark($cfais_gateway_url, 'image');
expect('an image failure on a saved route does not start the hour', skyphusion_ai_gateway_fallback_active(), false);
unset($GLOBALS['cfais_stored']['gateway_route']);
skyphusion_ai_gateway_mark($cfais_gateway_url, 'image');
expect('an image failure with no route starts the hour', skyphusion_ai_gateway_fallback_active(), true);
unset($GLOBALS['cfais_options']['skyphusion_ai_gateway_down']);

$_POST['skyphusion_ai_settings'] = array('log_prompts' => '1', 'gateway_route' => 'route1');
$cfais_gateway_saved = skyphusion_ai_gateway_sanitize_option(array('endpoint' => ''), 'skyphusion_ai_settings', array());
expect('the screen stores prompt logging', $cfais_gateway_saved['log_prompts'], '1');
expect('the screen stores the route', $cfais_gateway_saved['gateway_route'], 'route1');
unset($_POST['skyphusion_ai_settings']);
$cfais_gateway_kept = skyphusion_ai_gateway_sanitize_option(array('endpoint' => ''), 'skyphusion_ai_settings', array('log_prompts' => '1', 'gateway_route' => 'route1'));
expect('an omitted log switch keeps the choice', $cfais_gateway_kept['log_prompts'], '1');
$_POST['skyphusion_ai_settings'] = array('log_prompts' => '0');
$cfais_gateway_cleared = skyphusion_ai_gateway_sanitize_option(array('endpoint' => ''), 'skyphusion_ai_settings', array('log_prompts' => '1', 'gateway_route' => 'route1'));
expect('a posted zero turns logging off', $cfais_gateway_cleared['log_prompts'], '0');
unset($_POST['skyphusion_ai_settings']);
if (null === $cfais_gateway_log_was) {
    unset($GLOBALS['cfais_stored']['log_prompts']);
} else {
    $GLOBALS['cfais_stored']['log_prompts'] = $cfais_gateway_log_was;
}
if (null === $cfais_gateway_route_was) {
    unset($GLOBALS['cfais_stored']['gateway_route']);
} else {
    $GLOBALS['cfais_stored']['gateway_route'] = $cfais_gateway_route_was;
}
unset($GLOBALS['cfais_options']['skyphusion_ai_gateway_down']);
$cfais_gateway_models = file_get_contents(dirname(__DIR__) . '/includes/models.php');
expect('the account model list does not add a gateway tag', strpos((string) $cfais_gateway_models, 'cf-aig-metadata'), false);
$cfais_gateway_settings = file_get_contents(dirname(__DIR__) . '/includes/settings.php');
expect('the connection check does not add a gateway tag', strpos((string) $cfais_gateway_settings, 'cf-aig-metadata'), false);

unset($GLOBALS['cfais_user_id']);
unset($GLOBALS['cfais_home']);
unset($GLOBALS['skyphusion_ai_neuron_call']);
