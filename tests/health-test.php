<?php
/**
 * Site Health for the connection and the Vectorize store. Issue #123.
 *
 * The WordPress AI plugin is not installed here. An unapproved connector
 * is reported before a failed model list, and a failed result does not
 * send mail.
 */

$health_account = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$health_token = 'health-sentinel-not-a-secret';
$health_saved_env = getenv('CLOUDFLARE_API_KEY');
$health_saved_account = array_key_exists('account_id', $GLOBALS['cfais_stored']) ? $GLOBALS['cfais_stored']['account_id'] : null;
$health_had_gateway = array_key_exists('gateway_id', $GLOBALS['cfais_stored']);
$health_saved_gateway = $health_had_gateway ? $GLOBALS['cfais_stored']['gateway_id'] : null;
$health_had_cap = array_key_exists('neuron_cap', $GLOBALS['cfais_stored']);
$health_saved_cap = $health_had_cap ? $GLOBALS['cfais_stored']['neuron_cap'] : null;
$health_saved_status = isset($GLOBALS['cfais_status']) ? $GLOBALS['cfais_status'] : null;
$health_had_body = array_key_exists('cfais_body', $GLOBALS);
$health_saved_body = $health_had_body ? $GLOBALS['cfais_body'] : null;
$health_had_bodies = array_key_exists('cfais_bodies', $GLOBALS);
$health_saved_bodies = $health_had_bodies ? $GLOBALS['cfais_bodies'] : null;
$health_saved_transport = !empty($GLOBALS['cfais_transport_error']);
$health_mail_before = isset($GLOBALS['cfais_mail']) ? count($GLOBALS['cfais_mail']) : 0;

require_once dirname(__DIR__) . '/includes/health.php';

function health_gets()
{
    return isset($GLOBALS['cfais_model_gets']) ? count($GLOBALS['cfais_model_gets']) : 0;
}

function health_calls()
{
    return isset($GLOBALS['cfais_calls']) ? (int) $GLOBALS['cfais_calls'] : 0;
}

function health_models($ok)
{
    set_transient(
        'skyphusion_ai_models',
        array(
            'ok' => $ok ? 1 : 0,
            'account' => $GLOBALS['health_account_id'],
            'models' => array(
                array(
                    'id' => '@cf/baai/bge-large-en-v1.5',
                    'name' => 'BGE large',
                    'task' => 'text embeddings',
                ),
            ),
        ),
        600
    );
}

function health_reset()
{
    unset($GLOBALS['skyphusion_ai_health_connector_blocked']);
    unset($GLOBALS['skyphusion_ai_models_runtime']);
    $GLOBALS['cfais_stored']['account_id'] = $GLOBALS['health_account_id'];
    unset($GLOBALS['cfais_stored']['gateway_id']);
    unset($GLOBALS['cfais_stored']['neuron_cap']);
    unset($GLOBALS['cfais_options']['skyphusion_ai_gateway_down']);
    unset($GLOBALS['cfais_options']['skyphusion_ai_vectorize']);
    unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
    unset($GLOBALS['cfais_options']['wpai_connector_approvals']);
    unset($GLOBALS['cfais_options']['connectors_ai_cloudflare_api_key']);
    unset($GLOBALS['cfais_store']['skyphusion_ai_models']);
    unset($GLOBALS['cfais_store']['skyphusion_ai_embed_store_status']);
    $GLOBALS['cfais_transport_error'] = false;
    $GLOBALS['cfais_status'] = 200;
    $GLOBALS['cfais_body'] = '';
    $GLOBALS['cfais_bodies'] = array();
    putenv('CLOUDFLARE_API_KEY=' . $GLOBALS['health_token_value']);
}

$GLOBALS['health_account_id'] = $health_account;
$GLOBALS['health_token_value'] = $health_token;

$health_src = (string) file_get_contents(dirname(__DIR__) . '/includes/health.php');
expect('health does not send mail', strpos($health_src, 'wp_mail') === false, true);
expect('health does not install the AI plugin', strpos($health_src, 'plugin-install.php') === false, true);
expect('health does not name Qdrant', strpos(strtolower($health_src), 'qdrant') === false, true);
expect('health does not name Postgres', strpos(strtolower($health_src), 'postgres') === false, true);
expect('an unloaded approval guard does not block', skyphusion_ai_health_guard_hooked(), false);

expect(
    'an empty approval matrix does not approve this plugin',
    skyphusion_ai_health_caller_approved(array(), 'skyphusion-ai/skyphusion-ai.php'),
    false
);
expect(
    'approval for cloudflare allows this plugin',
    skyphusion_ai_health_caller_approved(
        array('skyphusion-ai/skyphusion-ai.php' => array('cloudflare' => true)),
        'skyphusion-ai/skyphusion-ai.php'
    ),
    true
);
expect(
    'another connector id does not approve this plugin',
    skyphusion_ai_health_caller_approved(
        array('skyphusion-ai/skyphusion-ai.php' => array('cloudflare-workers-ai' => true)),
        'skyphusion-ai/skyphusion-ai.php'
    ),
    false
);
expect('bge large is 1024 numbers', skyphusion_ai_health_embed_dimensions('@cf/baai/bge-large-en-v1.5'), 1024);
expect('bge small is 384 numbers', skyphusion_ai_health_embed_dimensions('@cf/baai/bge-small-en-v1.5'), 384);
expect('an unknown model has no preset length', skyphusion_ai_health_embed_dimensions('@cf/example/other'), 0);

health_reset();
unset($GLOBALS['skyphusion_ai_health_connector_blocked']);
$GLOBALS['cfais_options']['wpai_connector_approvals'] = array();
expect('a missing guard is not an unapproved connector', skyphusion_ai_health_connector_blocked(), false);

putenv('CLOUDFLARE_API_KEY');
$GLOBALS['cfais_stored']['account_id'] = '';
$health_missing = skyphusion_ai_health_connection();
expect('a missing account is recommended', $health_missing['status'], 'recommended');
expect('a missing account is not called a revoked token', strpos($health_missing['label'] . $health_missing['description'], 'revoked') === false, true);
expect('a missing account does not point at Connector Approvals', strpos($health_missing['description'], 'Connector Approvals') === false, true);

health_reset();
health_models(false);
$GLOBALS['skyphusion_ai_health_connector_blocked'] = true;
$health_gets_before = health_gets();
$health_calls_before = health_calls();
$health_blocked = skyphusion_ai_health_connection();
expect('an unapproved connector is critical', $health_blocked['status'], 'critical');
expect('an unapproved connector is named before the token', strpos($health_blocked['label'], 'connector approval') !== false, true);
expect('an unapproved connector points at Tools, then Connector Approvals', strpos($health_blocked['actions'], 'Tools, then Connector Approvals') !== false, true);
expect('an unapproved connector does not blame the token', strpos($health_blocked['label'] . $health_blocked['description'], 'token') === false, true);
expect('an unapproved connector does not read the model list', health_gets(), $health_gets_before);
expect('an unapproved connector does not call Vectorize', health_calls(), $health_calls_before);
expect('an unapproved result does not contain the sentinel', strpos(json_encode($health_blocked), $health_token) === false, true);

health_reset();
health_models(false);
$health_gets_before = health_gets();
$health_rejected = skyphusion_ai_health_connection();
expect('a rejected model list is critical', $health_rejected['status'], 'critical');
expect('a rejected model list names the token', strpos($health_rejected['label'], 'token') !== false, true);
expect('a rejected model list does not point at Connector Approvals', strpos($health_rejected['description'], 'Connector Approvals') === false, true);
expect('a rejected model list uses the cached failure', health_gets(), $health_gets_before);

health_reset();
health_models(true);
$GLOBALS['cfais_stored']['gateway_id'] = 'store';
$GLOBALS['cfais_options']['skyphusion_ai_gateway_down'] = time() + 3600;
$health_gateway = skyphusion_ai_health_connection();
expect('a bypassed gateway is recommended', $health_gateway['status'], 'recommended');
expect('a bypassed gateway names the gateway', strpos($health_gateway['label'], 'AI Gateway') !== false, true);
expect('a bypassed gateway does not blame the token', strpos($health_gateway['label'], 'token') === false, true);

health_reset();
health_models(true);
$GLOBALS['cfais_options']['skyphusion_ai_neurons'] = array(
    'day' => gmdate('Y-m-d'),
    'neurons' => 10000,
    'estimated' => 0,
    'features' => array(),
    'tools' => array(),
    'mailed_80' => 0,
    'mailed_100' => 0,
);
$health_free_spent = skyphusion_ai_health_connection();
expect('a spent free allowance is recommended', $health_free_spent['status'], 'recommended');
expect('a spent free allowance still says free', strpos($health_free_spent['label'], 'free') !== false, true);
expect('a spent free allowance does not send mail', count($GLOBALS['cfais_mail']), $health_mail_before);

health_reset();
health_models(true);
$GLOBALS['cfais_stored']['neuron_cap'] = 50;
$GLOBALS['cfais_options']['skyphusion_ai_neurons'] = array(
    'day' => gmdate('Y-m-d'),
    'neurons' => 50,
    'estimated' => 0,
    'features' => array(),
    'tools' => array(),
    'mailed_80' => 0,
    'mailed_100' => 0,
);
$health_budget = skyphusion_ai_health_connection();
expect('a spent saved budget is recommended', $health_budget['status'], 'recommended');
expect('a spent saved budget does not call the label free', strpos($health_budget['label'], 'free') === false, true);

health_reset();
health_models(true);
$health_good = skyphusion_ai_health_connection();
expect('a connected site inside the allowance is good', $health_good['status'], 'good');
expect('a connected site with no saved budget says free', strpos($health_good['label'], 'free') !== false, true);
expect('a connected site says this plugin adds no fee', strpos($health_good['description'], 'no fee') !== false, true);
expect('the good result links to the settings screen', strpos($health_good['actions'], 'page=cloudflare-ai-search') !== false, true);

health_reset();
$health_registered = skyphusion_ai_health_tests(array());
expect('the connection test is registered', isset($health_registered['direct']['skyphusion_ai_connection']), true);
expect('the embeddings test stays off until the index exists', isset($health_registered['direct']['skyphusion_ai_embeddings']), false);
expect(
    'the connection callback is the health function',
    $health_registered['direct']['skyphusion_ai_connection']['test'],
    'skyphusion_ai_health_connection'
);
$health_repaired = skyphusion_ai_health_tests(null);
expect('a bad test list still gains the connection', isset($health_repaired['direct']['skyphusion_ai_connection']), true);

$GLOBALS['cfais_options']['skyphusion_ai_vectorize'] = array(
    'created' => 1,
    'index' => 'shop',
    'model' => '@cf/baai/bge-large-en-v1.5',
);
$health_on = skyphusion_ai_health_tests(array());
expect('the embeddings test is registered once the index exists', isset($health_on['direct']['skyphusion_ai_embeddings']), true);

health_reset();
$GLOBALS['skyphusion_ai_health_connector_blocked'] = true;
$GLOBALS['cfais_options']['skyphusion_ai_vectorize'] = array(
    'created' => 1,
    'index' => 'shop',
    'model' => '@cf/baai/bge-large-en-v1.5',
);
$health_calls_before = health_calls();
$health_embed_blocked = skyphusion_ai_health_embeddings();
expect('embeddings name connector approval first', $health_embed_blocked['status'], 'critical');
expect('embeddings point at Tools, then Connector Approvals', strpos($health_embed_blocked['description'], 'Tools, then Connector Approvals') !== false, true);
expect('embeddings do not blame the token when unapproved', strpos($health_embed_blocked['label'] . $health_embed_blocked['description'], 'token') === false, true);
expect('embeddings do not call Vectorize when unapproved', health_calls(), $health_calls_before);

health_reset();
$GLOBALS['cfais_options']['skyphusion_ai_vectorize'] = array(
    'created' => 1,
    'index' => 'shop',
    'model' => '@cf/baai/bge-small-en-v1.5',
);
$health_calls_before = health_calls();
$health_mismatch = skyphusion_ai_health_embeddings();
expect('a different embeddings model is critical', $health_mismatch['status'], 'critical');
expect('a different embeddings model does not call Vectorize', health_calls(), $health_calls_before);

health_reset();
$GLOBALS['cfais_options']['skyphusion_ai_vectorize'] = array(
    'created' => 1,
    'index' => 'shop',
    'model' => '@cf/baai/bge-large-en-v1.5',
    'limit' => 3000,
    'neurons' => 0,
);
$GLOBALS['cfais_status'] = 200;
$GLOBALS['cfais_body'] = json_encode(array(
    'success' => true,
    'result' => array(
        'config' => array('dimensions' => 1024),
    ),
));
$health_calls_before = health_calls();
$health_store = skyphusion_ai_health_embeddings();
expect('a matching Vectorize index is good', $health_store['status'], 'good');
expect('a matching index names Vectorize', strpos($health_store['description'], 'Vectorize') !== false, true);
expect('a matching index names its length', strpos($health_store['description'], '1024') !== false, true);
expect('the first store read calls Vectorize', health_calls(), $health_calls_before + 1);
expect('the store status is cached for one minute', $GLOBALS['cfais_store']['skyphusion_ai_embed_store_status']['ttl'], 60);
$health_calls_before = health_calls();
$health_store_again = skyphusion_ai_health_embeddings();
expect('a second store read uses the cache', health_calls(), $health_calls_before);
expect('the cached store result stays good', $health_store_again['status'], 'good');
expect('the store result does not contain the sentinel', strpos(json_encode($health_store), $health_token) === false, true);

unset($GLOBALS['cfais_store']['skyphusion_ai_embed_store_status']);
$GLOBALS['cfais_body'] = json_encode(array(
    'success' => true,
    'result' => array(
        'config' => array('dimensions' => 384),
    ),
));
$health_dims = skyphusion_ai_health_embeddings();
expect('a short index is critical', $health_dims['status'], 'critical');
expect('a short index names both lengths', strpos($health_dims['description'], '384') !== false && strpos($health_dims['description'], '1024') !== false, true);

unset($GLOBALS['cfais_store']['skyphusion_ai_embed_store_status']);
$GLOBALS['cfais_status'] = 404;
$health_missing_index = skyphusion_ai_health_embeddings();
expect('a missing index is critical', $health_missing_index['status'], 'critical');
expect('a missing index stays on Vectorize', strpos($health_missing_index['description'], 'Vectorize') !== false, true);

unset($GLOBALS['cfais_store']['skyphusion_ai_embed_store_status']);
$GLOBALS['cfais_status'] = 200;
$GLOBALS['cfais_transport_error'] = true;
$health_down = skyphusion_ai_health_embeddings();
expect('an unreachable index is recommended', $health_down['status'], 'recommended');
$GLOBALS['cfais_transport_error'] = false;

unset($GLOBALS['cfais_store']['skyphusion_ai_embed_store_status']);
$GLOBALS['cfais_body'] = json_encode(array(
    'success' => true,
    'result' => array(
        'config' => array('dimensions' => 1024),
    ),
));
$GLOBALS['cfais_options']['skyphusion_ai_vectorize']['limit'] = 10;
$GLOBALS['cfais_options']['skyphusion_ai_vectorize']['day'] = gmdate('Y-m-d');
$GLOBALS['cfais_options']['skyphusion_ai_vectorize']['neurons'] = 10;
$health_limit = skyphusion_ai_health_embeddings();
expect('a spent embeddings limit is recommended', $health_limit['status'], 'recommended');
expect('a spent embeddings limit leaves other tools running', strpos($health_limit['description'], 'Other tools keep running') !== false, true);

health_reset();
$health_info = skyphusion_ai_health_debug(array());
$health_info_text = (string) json_encode($health_info);
expect('debug info does not contain the sentinel', strpos($health_info_text, $health_token) === false, true);
expect('debug info says the site is connected', $health_info['skyphusion-ai']['fields']['connected']['value'], 'Yes');
expect('debug info says approval is not blocking', $health_info['skyphusion-ai']['fields']['approval']['value'], 'Not blocking');
expect('debug info keeps the word free when no budget is saved', strpos($health_info['skyphusion-ai']['fields']['neurons']['value'], 'free') !== false, true);

expect('health sent no mail', count($GLOBALS['cfais_mail']), $health_mail_before);

unset($GLOBALS['skyphusion_ai_health_connector_blocked']);
unset($GLOBALS['skyphusion_ai_models_runtime']);
unset($GLOBALS['cfais_store']['skyphusion_ai_models']);
unset($GLOBALS['cfais_store']['skyphusion_ai_embed_store_status']);
unset($GLOBALS['cfais_options']['skyphusion_ai_gateway_down']);
unset($GLOBALS['cfais_options']['skyphusion_ai_vectorize']);
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
unset($GLOBALS['cfais_options']['wpai_connector_approvals']);
unset($GLOBALS['cfais_options']['connectors_ai_cloudflare_api_key']);
if ($health_saved_account === null) {
    unset($GLOBALS['cfais_stored']['account_id']);
} else {
    $GLOBALS['cfais_stored']['account_id'] = $health_saved_account;
}
if ($health_had_gateway) {
    $GLOBALS['cfais_stored']['gateway_id'] = $health_saved_gateway;
} else {
    unset($GLOBALS['cfais_stored']['gateway_id']);
}
if ($health_had_cap) {
    $GLOBALS['cfais_stored']['neuron_cap'] = $health_saved_cap;
} else {
    unset($GLOBALS['cfais_stored']['neuron_cap']);
}
$GLOBALS['cfais_transport_error'] = $health_saved_transport;
if ($health_saved_status === null) {
    unset($GLOBALS['cfais_status']);
} else {
    $GLOBALS['cfais_status'] = $health_saved_status;
}
if ($health_had_body) {
    $GLOBALS['cfais_body'] = $health_saved_body;
} else {
    unset($GLOBALS['cfais_body']);
}
if ($health_had_bodies) {
    $GLOBALS['cfais_bodies'] = $health_saved_bodies;
} else {
    unset($GLOBALS['cfais_bodies']);
}
if (is_string($health_saved_env) && $health_saved_env !== '') {
    putenv('CLOUDFLARE_API_KEY=' . $health_saved_env);
} else {
    putenv('CLOUDFLARE_API_KEY');
}
