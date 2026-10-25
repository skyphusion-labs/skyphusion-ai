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
expect('the log has four fields', count($cfais_gateway_visitor), 4);

$GLOBALS['cfais_user_id'] = 15;
$cfais_gateway_member = skyphusion_ai_gateway_metadata('embeddings', '@cf/baai/bge-large-en-v1.5');
expect('a logged-in user id is numeric text', $cfais_gateway_member['user'], '15');
expect('embeddings stays the feature', $cfais_gateway_member['feature'], 'embeddings');
expect('the embeddings model id is kept', $cfais_gateway_member['model'], '@cf/baai/bge-large-en-v1.5');

$cfais_gateway_direct_header = skyphusion_ai_gateway_metadata_header($cfais_gateway_direct, 'embeddings', '@cf/baai/bge-large-en-v1.5');
expect('a direct call adds no gateway header', $cfais_gateway_direct_header, array());

$cfais_gateway_header = skyphusion_ai_gateway_metadata_header($cfais_gateway_url, 'embeddings', '@cf/baai/bge-large-en-v1.5');
expect('a gateway call adds one header', count($cfais_gateway_header), 1);
$cfais_gateway_json = isset($cfais_gateway_header['cf-aig-metadata']) ? $cfais_gateway_header['cf-aig-metadata'] : '';
$cfais_gateway_decoded = json_decode((string) $cfais_gateway_json, true);
$cfais_gateway_keys = is_array($cfais_gateway_decoded) ? array_keys($cfais_gateway_decoded) : array();
sort($cfais_gateway_keys);
expect('the header names the four fields', $cfais_gateway_keys, array('feature', 'model', 'site', 'user'));
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
$cfais_gateway_models = file_get_contents(dirname(__DIR__) . '/includes/models.php');
expect('the account model list does not add a gateway tag', strpos((string) $cfais_gateway_models, 'cf-aig-metadata'), false);
$cfais_gateway_settings = file_get_contents(dirname(__DIR__) . '/includes/settings.php');
expect('the connection check does not add a gateway tag', strpos((string) $cfais_gateway_settings, 'cf-aig-metadata'), false);

unset($GLOBALS['cfais_user_id']);
unset($GLOBALS['cfais_home']);
unset($GLOBALS['skyphusion_ai_neuron_call']);
