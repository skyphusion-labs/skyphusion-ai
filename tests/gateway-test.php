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
expect('an unknown tool is an empty string', $cfais_gateway_visitor['tool'], '');
expect('the log does not name the plugin version', isset($cfais_gateway_visitor['version']), false);
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
expect('the header names the five fields', $cfais_gateway_keys, array('feature', 'model', 'site', 'tool', 'user'));
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
expect('the client call tags the provider tool', is_array($cfais_gateway_transport_meta) && $cfais_gateway_transport_meta['tool'] === 'provider', true);
expect('the client call does not send a version key', is_array($cfais_gateway_transport_meta) && isset($cfais_gateway_transport_meta['version']), false);
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
expect('an embeddings call tags the embeddings tool', is_array($cfais_gateway_embed_meta) && $cfais_gateway_embed_meta['tool'] === 'embeddings', true);
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

$GLOBALS['cfais_stored']['gateway_id'] = 'store';
$GLOBALS['cfais_stored']['log_prompts'] = '0';
unset($GLOBALS['cfais_stored']['gateway_route']);
$cfais_gateway_status = skyphusion_ai_gateway_status();
expect('status names the saved gateway id', $cfais_gateway_status['gateway_id'], 'store');
expect('status uses the gateway host when healthy', $cfais_gateway_status['host'], 'gateway.ai.cloudflare.com');
expect('status says fallback is off', $cfais_gateway_status['fallback_active'], false);
expect('status says prompt logging is off', $cfais_gateway_status['log_prompts'], false);
skyphusion_ai_gateway_mark($cfais_gateway_url, 'text');
$cfais_gateway_status_down = skyphusion_ai_gateway_status();
expect('status uses the direct host during fallback', $cfais_gateway_status_down['host'], 'api.cloudflare.com');
expect('status says fallback is on', $cfais_gateway_status_down['fallback_active'], true);
expect('status keeps the saved gateway id during the hour', $cfais_gateway_status_down['gateway_id'], 'store');
ob_start();
skyphusion_ai_gateway_status_html();
$cfais_gateway_html = ob_get_clean();
expect('the status panel names the saved gateway id during the hour', strpos((string) $cfais_gateway_html, 'Gateway id: store.') !== false, true);
$GLOBALS['cfais_stored']['gateway_id'] = '../escape';
$cfais_gateway_bad = skyphusion_ai_gateway_status();
expect('status drops a gateway id that is not a slug during the hour', $cfais_gateway_bad['gateway_id'], '');
$GLOBALS['cfais_stored']['gateway_id'] = 'store';
unset($GLOBALS['cfais_options']['skyphusion_ai_gateway_down']);
$GLOBALS['cfais_stored']['gateway_id'] = '';

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

$cfais_gateway_id_was = array_key_exists('gateway_id', $GLOBALS['cfais_stored']) ? $GLOBALS['cfais_stored']['gateway_id'] : null;
$cfais_gateway_route_now = array_key_exists('gateway_route', $GLOBALS['cfais_stored']) ? $GLOBALS['cfais_stored']['gateway_route'] : null;
$GLOBALS['cfais_stored']['gateway_id'] = 'store';
unset($GLOBALS['cfais_stored']['gateway_route']);
expect('a blank route has no dynamic model', skyphusion_ai_gateway_dynamic_model(), '');
$GLOBALS['cfais_stored']['gateway_route'] = 'bad route';
expect('an invalid route is not saved by the reader', skyphusion_ai_gateway_route(), '');
expect('an invalid route has no dynamic model', skyphusion_ai_gateway_dynamic_model(), '');
$GLOBALS['cfais_stored']['gateway_route'] = '../escape';
expect('a route with a slash is rejected', skyphusion_ai_gateway_route(), '');
$GLOBALS['cfais_stored']['gateway_id'] = '';
$GLOBALS['cfais_stored']['gateway_route'] = 'route1';
expect('a route without a gateway id stays off', skyphusion_ai_gateway_dynamic_model(), '');
$GLOBALS['cfais_stored']['gateway_id'] = 'store';
expect('a saved route builds the dynamic model', skyphusion_ai_gateway_dynamic_model(), 'dynamic/route1');
$cfais_gateway_text_url = skyphusion_ai_gateway_text_url(
    'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'store',
    '@cf/meta/llama-3.3-70b-instruct-fp8-fast'
);
expect('a saved route uses the chat path', $cfais_gateway_text_url, $cfais_gateway_chat);
$cfais_gateway_routed = skyphusion_ai_gateway_apply_route(array(
    'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
    'messages' => array(array('role' => 'user', 'content' => $cfais_gateway_prompt)),
));
expect('a saved route sets the request model', $cfais_gateway_routed['model'], 'dynamic/route1');
expect('a saved route does not put the prompt in the model', $cfais_gateway_routed['model'] === $cfais_gateway_prompt, false);
$cfais_gateway_image = cloudflare_ai_provider_run_url(
    'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'store',
    '@cf/black-forest-labs/flux-1-schnell'
);
expect('an image call keeps the model path', strpos($cfais_gateway_image, '/workers-ai/@cf/black-forest-labs/flux-1-schnell') !== false, true);
expect('an image call does not use the dynamic model', strpos($cfais_gateway_image, 'dynamic/') === false, true);
$cfais_gateway_speech = cloudflare_ai_provider_run_url(
    'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'store',
    '@cf/deepgram/aura-1'
);
expect('a speech call keeps the model path', strpos($cfais_gateway_speech, '/workers-ai/@cf/deepgram/aura-1') !== false, true);
$cfais_gateway_embed_url = cloudflare_ai_provider_run_url(
    'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'store',
    '@cf/baai/bge-large-en-v1.5'
);
expect('an embeddings call keeps the model path', strpos($cfais_gateway_embed_url, '/workers-ai/@cf/baai/bge-large-en-v1.5') !== false, true);
$cfais_gateway_translate = (string) file_get_contents(dirname(__DIR__) . '/includes/translate.php');
expect('translation stays on the model path', strpos($cfais_gateway_translate, 'skyphusion_ai_gateway_text_url') === false, true);
expect('translation still builds a run url', strpos($cfais_gateway_translate, 'cloudflare_ai_provider_run_url') !== false, true);
expect('translation names the translate tool', strpos($cfais_gateway_translate, "'translate'") !== false, true);
$cfais_gateway_tool_files = array(
    'includes/assistant.php' => 'assistant',
    'includes/backfill.php' => 'backfill',
    'includes/prompts.php' => 'prompts',
    'includes/links.php' => 'links',
    'includes/insights.php' => 'insights',
    'includes/budget.php' => 'embeddings',
    'includes/gateway.php' => 'provider',
);
foreach ($cfais_gateway_tool_files as $cfais_gateway_tool_file => $cfais_gateway_tool_name) {
    $cfais_gateway_tool_src = (string) file_get_contents(dirname(__DIR__) . '/' . $cfais_gateway_tool_file);
    expect(
        $cfais_gateway_tool_file . ' passes the ' . $cfais_gateway_tool_name . ' tool',
        strpos($cfais_gateway_tool_src, "'" . $cfais_gateway_tool_name . "'") !== false
            && strpos($cfais_gateway_tool_src, 'skyphusion_ai_gateway_metadata_header') !== false,
        true
    );
}
$cfais_gateway_known = skyphusion_ai_gateway_metadata('text', '@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'assistant');
expect('assistant is a metadata tool', $cfais_gateway_known['tool'], 'assistant');
expect('a known tool still leaves five fields', count($cfais_gateway_known), 5);
$cfais_gateway_unknown = skyphusion_ai_gateway_metadata('text', '@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'version');
expect('version is not a tool name', $cfais_gateway_unknown['tool'], '');
expect('version is not a sixth field', isset($cfais_gateway_unknown['version']), false);
$GLOBALS['cfais_options']['skyphusion_ai_gateway_down'] = time() + 3600;
expect('a refused gateway does not use the dynamic model', skyphusion_ai_gateway_dynamic_model(), '');
unset($GLOBALS['cfais_options']['skyphusion_ai_gateway_down']);
$cfais_gateway_help = (string) file_get_contents(dirname(__DIR__) . '/includes/gateway.php');
expect('the route help names the chat path', strpos($cfais_gateway_help, 'Text and chat calls use this route on the chat path.') !== false, true);
expect('the route help names the dynamic model', strpos($cfais_gateway_help, 'The model id is dynamic/ plus this route.') !== false, true);
expect('the route help keeps image, speech, and embeddings on the model path', strpos($cfais_gateway_help, 'Image, speech, and embeddings stay on the model path.') !== false, true);
if (null === $cfais_gateway_id_was) {
    unset($GLOBALS['cfais_stored']['gateway_id']);
} else {
    $GLOBALS['cfais_stored']['gateway_id'] = $cfais_gateway_id_was;
}
if (null === $cfais_gateway_route_now) {
    unset($GLOBALS['cfais_stored']['gateway_route']);
} else {
    $GLOBALS['cfais_stored']['gateway_route'] = $cfais_gateway_route_now;
}

$cfais_retry_keys = array('gateway_timeout', 'gateway_attempts', 'gateway_delay', 'gateway_backoff', 'gateway_retry_media');
$cfais_retry_prev = array();
foreach ($cfais_retry_keys as $cfais_retry_key) {
    $cfais_retry_prev[$cfais_retry_key] = array_key_exists($cfais_retry_key, $GLOBALS['cfais_stored']) ? $GLOBALS['cfais_stored'][$cfais_retry_key] : null;
}
foreach ($cfais_retry_keys as $cfais_retry_key) {
    unset($GLOBALS['cfais_stored'][$cfais_retry_key]);
}
expect('blank retry settings send no header', skyphusion_ai_gateway_retry_headers($cfais_gateway_url, 'text'), array());
expect('a direct url sends no retry header', skyphusion_ai_gateway_retry_headers($cfais_gateway_direct, 'text'), array());
$GLOBALS['cfais_stored']['gateway_timeout'] = '5000';
$GLOBALS['cfais_stored']['gateway_attempts'] = '2';
$GLOBALS['cfais_stored']['gateway_delay'] = '250';
$GLOBALS['cfais_stored']['gateway_backoff'] = 'constant';
$GLOBALS['cfais_stored']['gateway_retry_media'] = '0';
$cfais_retry_text = skyphusion_ai_gateway_retry_headers($cfais_gateway_url, 'text');
expect('text sends the timeout', isset($cfais_retry_text['cf-aig-request-timeout']) ? $cfais_retry_text['cf-aig-request-timeout'] : '', '5000');
expect('text sends the attempts', isset($cfais_retry_text['cf-aig-max-attempts']) ? $cfais_retry_text['cf-aig-max-attempts'] : '', '2');
expect('text sends the delay', isset($cfais_retry_text['cf-aig-retry-delay']) ? $cfais_retry_text['cf-aig-retry-delay'] : '', '250');
expect('text sends the backoff', isset($cfais_retry_text['cf-aig-backoff']) ? $cfais_retry_text['cf-aig-backoff'] : '', 'constant');
expect('image sends no retry headers', skyphusion_ai_gateway_retry_headers($cfais_gateway_url, 'image'), array());
expect('speech sends no retry headers', skyphusion_ai_gateway_retry_headers($cfais_gateway_url, 'speech'), array());
expect('a direct call still sends no retry headers', skyphusion_ai_gateway_retry_headers($cfais_gateway_direct, 'text'), array());
$cfais_retry_joined = skyphusion_ai_gateway_metadata_header($cfais_gateway_url, 'text', '@cf/meta/llama-3.3-70b-instruct-fp8-fast');
expect('the metadata header also carries the timeout', isset($cfais_retry_joined['cf-aig-request-timeout']) ? $cfais_retry_joined['cf-aig-request-timeout'] : '', '5000');
$cfais_retry_direct_joined = skyphusion_ai_gateway_metadata_header($cfais_gateway_direct, 'text', '@cf/meta/llama-3.3-70b-instruct-fp8-fast');
expect('a direct metadata call still has no timeout', isset($cfais_retry_direct_joined['cf-aig-request-timeout']), false);
$GLOBALS['cfais_stored']['gateway_retry_media'] = '1';
$cfais_retry_image = skyphusion_ai_gateway_retry_headers($cfais_gateway_url, 'image');
expect('image sends attempts after the opt in', isset($cfais_retry_image['cf-aig-max-attempts']) ? $cfais_retry_image['cf-aig-max-attempts'] : '', '2');
$GLOBALS['cfais_stored']['gateway_attempts'] = '9';
expect('an attempts value above 5 is ignored', isset(skyphusion_ai_gateway_retry_headers($cfais_gateway_url, 'text')['cf-aig-max-attempts']), false);
$GLOBALS['cfais_stored']['gateway_timeout'] = '120001';
expect('a timeout above 120000 is ignored', isset(skyphusion_ai_gateway_retry_headers($cfais_gateway_url, 'text')['cf-aig-request-timeout']), false);
$_POST['skyphusion_ai_settings'] = array(
    'gateway_timeout' => '8000',
    'gateway_attempts' => '9',
    'gateway_delay' => '',
    'gateway_backoff' => 'linear',
    'gateway_retry_media' => '1',
);
$cfais_retry_saved = skyphusion_ai_gateway_sanitize_option(
    array('endpoint' => ''),
    'skyphusion_ai_settings',
    array('gateway_attempts' => '2', 'gateway_delay' => '250', 'gateway_retry_media' => '0')
);
expect('the screen stores a valid timeout', $cfais_retry_saved['gateway_timeout'], '8000');
expect('a bad attempts value keeps the old one', $cfais_retry_saved['gateway_attempts'], '2');
expect('an empty delay clears the delay', $cfais_retry_saved['gateway_delay'], '');
expect('the screen stores linear backoff', $cfais_retry_saved['gateway_backoff'], 'linear');
expect('the screen can opt in image and speech', $cfais_retry_saved['gateway_retry_media'], '1');
unset($_POST['skyphusion_ai_settings']);
$cfais_retry_kept = skyphusion_ai_gateway_sanitize_option(
    array('endpoint' => ''),
    'skyphusion_ai_settings',
    array('gateway_timeout' => '8000', 'gateway_backoff' => 'exponential')
);
expect('an omitted timeout keeps the old one', $cfais_retry_kept['gateway_timeout'], '8000');
expect('an omitted backoff keeps the old one', $cfais_retry_kept['gateway_backoff'], 'exponential');
$cfais_retry_help = (string) file_get_contents(dirname(__DIR__) . '/includes/gateway.php');
expect('the retry help says blank fields send no header', strpos($cfais_retry_help, 'Blank fields send no header.') !== false, true);
expect('the retry help keeps image and speech off', strpos($cfais_retry_help, 'Image and speech stay off until you turn this on.') !== false, true);
expect('the retry help keeps the one hour fallback', strpos($cfais_retry_help, 'A refused gateway still uses the direct API for one hour.') !== false, true);
foreach ($cfais_retry_keys as $cfais_retry_key) {
    if (null === $cfais_retry_prev[$cfais_retry_key]) {
        unset($GLOBALS['cfais_stored'][$cfais_retry_key]);
    } else {
        $GLOBALS['cfais_stored'][$cfais_retry_key] = $cfais_retry_prev[$cfais_retry_key];
    }
}

$cfais_gateway_models = file_get_contents(dirname(__DIR__) . '/includes/models.php');
expect('the account model list does not add a gateway tag', strpos((string) $cfais_gateway_models, 'cf-aig-metadata'), false);
$cfais_gateway_settings = file_get_contents(dirname(__DIR__) . '/includes/settings.php');
expect('the connection check does not add a gateway tag', strpos((string) $cfais_gateway_settings, 'cf-aig-metadata'), false);

unset($GLOBALS['cfais_user_id']);
unset($GLOBALS['cfais_home']);
unset($GLOBALS['skyphusion_ai_neuron_call']);
