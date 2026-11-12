<?php
/**
 * WfP op bridge for embeddings and Vectorize. Issue #193.
 *
 * The absent path runs in this process. The constant is not defined, so
 * the REST helpers stay on wp_remote_*. The present path runs in a child
 * because SKYPHUSION_AI_CF_BRIDGE cannot be removed once it is defined,
 * and the rest of this suite must keep the REST path.
 */

if (getenv('SKYPHUSION_AI_BRIDGE_CHILD') !== '1') {
    if (!function_exists('skyphusion_worker_call')) {
        function skyphusion_worker_call($json)
        {
            if (!isset($GLOBALS['bridge_calls']) || !is_array($GLOBALS['bridge_calls'])) {
                $GLOBALS['bridge_calls'] = array();
            }
            $GLOBALS['bridge_calls'][] = (string) $json;
            return '{"ok":false,"error":"bridge should not be called"}';
        }
    }
    $GLOBALS['bridge_calls'] = array();
    $bridge_off_token = 'sentinel-token-' . str_repeat('b', 30);
    $bridge_off_prev_key = getenv('CLOUDFLARE_API_KEY');
    $bridge_off_prev_account = getenv('CLOUDFLARE_ACCOUNT_ID');
    putenv('CLOUDFLARE_API_KEY=' . $bridge_off_token);
    putenv('CLOUDFLARE_ACCOUNT_ID=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
    $GLOBALS['cfais_settings'] = array(
        'endpoint' => 'https://abc123.search.ai.cloudflare.com',
        'account_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'gateway_id' => '',
    );
    update_option('skyphusion_ai_vectorize', array(
        'index' => 'skyphusion-ai',
        'model' => '@cf/baai/bge-large-en-v1.5',
        'created' => 1,
        'enabled' => 1,
    ), false);

    expect('the bridge signal is absent in the parent process', defined('SKYPHUSION_AI_CF_BRIDGE'), false);
    expect('the bridge stays off without the signal', skyphusion_ai_cf_bridge_on(), false);
    update_option('skyphusion_ai_neurons', array(
        'day' => skyphusion_ai_neuron_today(),
        'neurons' => 0,
        'estimated' => 0,
        'features' => array(),
        'tools' => array(),
        'mailed_80' => 0,
        'mailed_100' => 0,
    ), false);

    $bridge_off_creds = skyphusion_ai_embed_credentials();
    expect('absent credentials keep the real account', $bridge_off_creds['account_id'], 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
    expect('absent credentials keep the real token', $bridge_off_creds['token'], $bridge_off_token);
    expect('absent credentials are not the stand-in token', $bridge_off_creds['token'] === skyphusion_ai_bridge_token(), false);

    $GLOBALS['cfais_calls'] = 0;
    $GLOBALS['cfais_status'] = 200;
    unset($GLOBALS['cfais_body']);
    $GLOBALS['cfais_bodies'] = array(
        '{"result":{"data":[[0.25,0.5]]}}',
        '{"result":{"data":[[0.25,0.5]]}}',
    );
    $GLOBALS['cfais_last_post'] = array();
    $bridge_off_vectors = cloudflare_ai_provider_embeddings(array('red bats'));
    expect('absent embeddings return the REST vector', is_array($bridge_off_vectors) ? $bridge_off_vectors[0][0] : null, 0.25);
    expect('absent embeddings do not call the worker', $GLOBALS['bridge_calls'], array());
    expect('absent embeddings use the Workers AI REST host', strpos((string) $GLOBALS['cfais_last_post']['url'], 'https://api.cloudflare.com/') === 0, true);
    expect('absent embeddings send the bearer token', strpos((string) $GLOBALS['cfais_last_post']['args']['headers']['Authorization'], 'Bearer ' . $bridge_off_token) === 0, true);

    $bridge_off_url = skyphusion_ai_vectorize_url($bridge_off_creds['account_id'], 'skyphusion-ai', 'query');
    $GLOBALS['cfais_calls'] = 0;
    $GLOBALS['cfais_bodies'] = array('{"success":true,"result":{"matches":[]}}');
    $bridge_off_query = skyphusion_ai_vectorize_request(
        'POST',
        $bridge_off_url,
        $bridge_off_creds['token'],
        '{"vector":[0.1],"topK":1,"returnMetadata":"all"}',
        'application/json'
    );
    expect('absent query is not an error', is_wp_error($bridge_off_query), false);
    expect('absent query uses HTTP', $GLOBALS['cfais_calls'], 1);
    expect('absent query does not call the worker', $GLOBALS['bridge_calls'], array());
    expect('absent query hits the query path', strpos((string) $GLOBALS['cfais_last_request']['url'], '/query') !== false, true);
    expect(
        'absent query sends the bearer token',
        strpos((string) $GLOBALS['cfais_last_request']['args']['headers']['Authorization'], 'Bearer ' . $bridge_off_token) === 0,
        true
    );

    $bridge_off_create = skyphusion_ai_vectorize_url($bridge_off_creds['account_id'], '', '');
    $GLOBALS['cfais_calls'] = 0;
    $GLOBALS['cfais_bodies'] = array('{"success":true,"result":{"name":"skyphusion-ai"}}');
    update_option('skyphusion_ai_vectorize', array(
        'index' => 'skyphusion-ai',
        'created' => 0,
        'enabled' => 0,
        'model' => '',
    ), false);
    $bridge_off_ready = skyphusion_ai_embed_ensure(skyphusion_ai_embed_config());
    expect('absent ensure still creates over REST', $bridge_off_ready['state'], 'ready');
    expect('absent ensure makes one HTTP call', $GLOBALS['cfais_calls'], 1);
    expect('absent ensure posts to the index collection', strpos((string) $GLOBALS['cfais_last_request']['url'], '/indexes') !== false, true);
    expect('absent ensure does not call the worker', $GLOBALS['bridge_calls'], array());
    $bridge_rest_src = (string) file_get_contents(dirname(__DIR__) . '/includes/embed.php');
    expect(
        'the REST vectorize helper still sets a bearer header',
        strpos($bridge_rest_src, "'Authorization' => 'Bearer ' . \$token") !== false,
        true
    );

    if (false === $bridge_off_prev_key || '' === $bridge_off_prev_key) {
        putenv('CLOUDFLARE_API_KEY');
    } else {
        putenv('CLOUDFLARE_API_KEY=' . $bridge_off_prev_key);
    }
    if (false === $bridge_off_prev_account || '' === $bridge_off_prev_account) {
        putenv('CLOUDFLARE_ACCOUNT_ID');
    } else {
        putenv('CLOUDFLARE_ACCOUNT_ID=' . $bridge_off_prev_account);
    }
    unset($GLOBALS['cfais_settings']);
    unset($GLOBALS['cfais_bodies']);

    $bridge_cmd = 'SKYPHUSION_AI_BRIDGE_CHILD=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    $bridge_lines = array();
    $bridge_code = 1;
    exec($bridge_cmd . ' 2>&1', $bridge_lines, $bridge_code);
    $bridge_text = implode("\n", $bridge_lines);
    expect('bridge child exits 0', $bridge_code, 0);
    if ($bridge_code !== 0) {
        fwrite(STDERR, $bridge_text . "\n");
    }
    expect('bridge child saw the signal', strpos($bridge_text, 'ok signal: the bridge is on') !== false, true);
    expect('bridge child embedded without HTTP', strpos($bridge_text, 'ok embed: aiEmbed returns the vector and does not use HTTP') !== false, true);
    expect('bridge child queried without HTTP', strpos($bridge_text, 'ok query: vectorize query does not use HTTP') !== false, true);
    expect('bridge child skipped create', strpos($bridge_text, 'ok ensure: a signalled index is ready and create is not sent') !== false, true);
    expect('bridge child refused list', strpos($bridge_text, 'ok list: list is not sent to the binding') !== false, true);
    expect('bridge child kept a real token off the wire', strpos($bridge_text, 'ok token: a real token is not sent on the data plane') !== false, true);
    return;
}

$bridge_failures = 0;

function bridge_expect($label, $actual, $expected)
{
    global $bridge_failures;
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$label}\n  actual: " . var_export($actual, true) . "\n  expected: " . var_export($expected, true) . "\n");
        $bridge_failures++;
        return;
    }
    fwrite(STDOUT, "ok {$label}\n");
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('SKYPHUSION_AI_CF_BRIDGE')) {
    define('SKYPHUSION_AI_CF_BRIDGE', true);
}

putenv('CLOUDFLARE_API_KEY');
putenv('CLOUDFLARE_ACCOUNT_ID');

$GLOBALS['bridge_calls'] = array();
$GLOBALS['bridge_http'] = array();
$GLOBALS['bridge_fail'] = false;
$GLOBALS['bridge_options'] = array();
$GLOBALS['bridge_settings'] = array(
    'account_id' => '',
    'gateway_id' => '',
    'endpoint' => '',
);

if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default')
    {
        return $text;
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public $code;
        public $message;
        public function __construct($code = '', $message = '')
        {
            $this->code = $code;
            $this->message = $message;
        }
        public function get_error_code()
        {
            return $this->code;
        }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data)
    {
        return json_encode($data);
    }
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $args = 1)
    {
        return true;
    }
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $args = 1)
    {
        return true;
    }
}
if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        if (isset($GLOBALS['bridge_options'][$name])) {
            return $GLOBALS['bridge_options'][$name];
        }
        return $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        $GLOBALS['bridge_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('skyphusion_ai_settings')) {
    function skyphusion_ai_settings()
    {
        return $GLOBALS['bridge_settings'];
    }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1)
    {
        if (-1 === $component) {
            return parse_url((string) $url);
        }
        return parse_url((string) $url, $component);
    }
}
if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = array())
    {
        $GLOBALS['bridge_http'][] = array('url' => (string) $url, 'args' => $args);
        return array('body' => '{"success":true,"result":{}}');
    }
}
if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = array())
    {
        $GLOBALS['bridge_http'][] = array('url' => (string) $url, 'args' => $args);
        return array('body' => '{"result":{"data":[[9,9]]}}');
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        return 200;
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response)
    {
        if (is_array($response) && isset($response['body'])) {
            return (string) $response['body'];
        }
        return '';
    }
}
if (!function_exists('skyphusion_worker_call')) {
    function skyphusion_worker_call($json)
    {
        $GLOBALS['bridge_calls'][] = (string) $json;
        if (!empty($GLOBALS['bridge_fail'])) {
            return '{"ok":false,"error":"no"}';
        }
        $message = json_decode((string) $json, true);
        if (is_array($message) && isset($message['aiEmbed'])) {
            $texts = array();
            if (isset($message['aiEmbed']['text']) && is_array($message['aiEmbed']['text'])) {
                $texts = $message['aiEmbed']['text'];
            }
            $data = array();
            foreach ($texts as $unused) {
                $data[] = array(0.25, 0.5);
            }
            return (string) json_encode(array('ok' => true, 'data' => $data));
        }
        if (is_array($message) && isset($message['vectorize']['op'])) {
            $op = (string) $message['vectorize']['op'];
            if ('query' === $op) {
                return (string) json_encode(array(
                    'ok' => true,
                    'matches' => array(array(
                        'id' => 'p6',
                        'score' => 0.5,
                        'metadata' => array(
                            'title' => 'Run',
                            'post_id' => '6',
                            'url' => 'https://shop.example/run',
                        ),
                    )),
                ));
            }
            if ('getByIds' === $op) {
                return (string) json_encode(array(
                    'ok' => true,
                    'vectors' => array(array(
                        'id' => 'p6',
                        'values' => array(0.25, 0.5),
                        'metadata' => array('title' => 'Run'),
                    )),
                ));
            }
            return '{"ok":true}';
        }
        return '{"ok":false,"error":"unknown"}';
    }
}

require dirname(__DIR__) . '/includes/routes.php';
require dirname(__DIR__) . '/includes/provider.php';

function bridge_calls()
{
    return count($GLOBALS['bridge_calls']);
}

function bridge_http()
{
    return count($GLOBALS['bridge_http']);
}

function bridge_last_message()
{
    $raw = $GLOBALS['bridge_calls'][count($GLOBALS['bridge_calls']) - 1];
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : array();
}

bridge_expect('signal: the bridge is on', skyphusion_ai_cf_bridge_on(), true);

$bridge_creds = skyphusion_ai_embed_credentials();
bridge_expect('credentials: no real token uses the stand-in', $bridge_creds['token'], skyphusion_ai_bridge_token());
bridge_expect('credentials: an empty account becomes the stand-in account', $bridge_creds['account_id'], skyphusion_ai_bridge_account_id());

$GLOBALS['bridge_calls'] = array();
$GLOBALS['bridge_http'] = array();
update_option('skyphusion_ai_vectorize', array(
    'index' => 'skyphusion-ai',
    'created' => 0,
    'enabled' => 0,
    'model' => '',
), false);
$bridge_ready = skyphusion_ai_embed_ensure(skyphusion_ai_embed_config());
bridge_expect('ensure: a signalled index is ready and create is not sent', $bridge_ready['state'], 'ready');
bridge_expect('ensure: create does not call the worker', bridge_calls(), 0);
bridge_expect('ensure: create does not use HTTP', bridge_http(), 0);
bridge_expect('ensure: the index is marked created', skyphusion_ai_embed_config()['created'], 1);
bridge_expect('ensure: the stored model is the large preset', skyphusion_ai_embed_config()['model'], '@cf/baai/bge-large-en-v1.5');

update_option('skyphusion_ai_vectorize', array(
    'index' => 'skyphusion-ai',
    'created' => 0,
    'model' => '',
), false);
$GLOBALS['bridge_settings']['model_embeddings'] = '@cf/example/not-a-preset';
$bridge_blocked = skyphusion_ai_embed_ensure(skyphusion_ai_embed_config());
bridge_expect('ensure: a model with no preset stays blocked', $bridge_blocked['state'], 'blocked');
bridge_expect('ensure: a blocked model does not call the worker', bridge_calls(), 0);
bridge_expect('ensure: a blocked model does not use HTTP', bridge_http(), 0);
unset($GLOBALS['bridge_settings']['model_embeddings']);

$bridge_creds = skyphusion_ai_embed_credentials();
$bridge_query_url = skyphusion_ai_vectorize_url($bridge_creds['account_id'], 'skyphusion-ai', 'query');
$GLOBALS['bridge_calls'] = array();
$GLOBALS['bridge_http'] = array();
$bridge_query = skyphusion_ai_vectorize_request(
    'POST',
    $bridge_query_url,
    $bridge_creds['token'],
    '{"vector":[0.1,0.2],"topK":5,"returnMetadata":"all"}',
    'application/json'
);
$bridge_query_message = bridge_last_message();
bridge_expect('query: vectorize query does not use HTTP', bridge_http(), 0);
bridge_expect('query: one worker call', bridge_calls(), 1);
bridge_expect('query: the op key is vectorize', isset($bridge_query_message['vectorize']), true);
bridge_expect('query: the binding method is query', $bridge_query_message['vectorize']['op'], 'query');
bridge_expect('query: topK is kept', $bridge_query_message['vectorize']['topK'], 5);
bridge_expect('query: the reply keeps matches', $bridge_query['result']['matches'][0]['id'], 'p6');
bridge_expect('query: the stand-in token is not in the message', strpos((string) $GLOBALS['bridge_calls'][0], skyphusion_ai_bridge_token()) === false, true);
bridge_expect('query: the message has no bearer header', strpos((string) $GLOBALS['bridge_calls'][0], 'Bearer') === false, true);

$bridge_upsert_url = skyphusion_ai_vectorize_url($bridge_creds['account_id'], 'skyphusion-ai', 'upsert');
$bridge_line = '{"id":"p6","values":[0.25,0.5],"metadata":{"title":"Run"}}';
$GLOBALS['bridge_calls'] = array();
$bridge_upsert = skyphusion_ai_vectorize_request('POST', $bridge_upsert_url, $bridge_creds['token'], $bridge_line . "\n", 'application/x-ndjson');
$bridge_upsert_message = bridge_last_message();
bridge_expect('upsert: accepted', is_wp_error($bridge_upsert), false);
bridge_expect('upsert: the binding method is upsert', $bridge_upsert_message['vectorize']['op'], 'upsert');
bridge_expect('upsert: the vector id is kept', $bridge_upsert_message['vectorize']['vectors'][0]['id'], 'p6');
bridge_expect('upsert: does not use HTTP', bridge_http(), 0);

$bridge_get_url = skyphusion_ai_vectorize_url($bridge_creds['account_id'], 'skyphusion-ai', 'get_by_ids');
$GLOBALS['bridge_calls'] = array();
$bridge_got = skyphusion_ai_vectorize_request('POST', $bridge_get_url, $bridge_creds['token'], '{"ids":["p6"]}', 'application/json');
$bridge_get_message = bridge_last_message();
bridge_expect('getByIds: the binding method is getByIds', $bridge_get_message['vectorize']['op'], 'getByIds');
bridge_expect('getByIds: the id is kept', $bridge_get_message['vectorize']['ids'], array('p6'));
bridge_expect('getByIds: the reply is a vector list', $bridge_got['result'][0]['values'][0], 0.25);

$bridge_delete_url = skyphusion_ai_vectorize_url($bridge_creds['account_id'], 'skyphusion-ai', 'delete_by_ids');
$GLOBALS['bridge_calls'] = array();
$bridge_deleted = skyphusion_ai_vectorize_request('POST', $bridge_delete_url, $bridge_creds['token'], '{"ids":["p6"]}', 'application/json');
$bridge_delete_message = bridge_last_message();
bridge_expect('deleteByIds: accepted', is_wp_error($bridge_deleted), false);
bridge_expect('deleteByIds: the binding method is deleteByIds', $bridge_delete_message['vectorize']['op'], 'deleteByIds');

$GLOBALS['bridge_calls'] = array();
$GLOBALS['bridge_http'] = array();
$bridge_create_url = skyphusion_ai_vectorize_url($bridge_creds['account_id'], '', '');
$bridge_create = skyphusion_ai_vectorize_request('POST', $bridge_create_url, $bridge_creds['token'], '{}', 'application/json');
bridge_expect('create: create is not sent to the binding', is_wp_error($bridge_create), true);
bridge_expect('create: create does not call the worker', bridge_calls(), 0);
bridge_expect('create: create does not use HTTP', bridge_http(), 0);

$bridge_list = skyphusion_ai_vectorize_request('GET', $bridge_create_url, $bridge_creds['token'], '', 'application/json');
bridge_expect('list: list is not sent to the binding', is_wp_error($bridge_list), true);
bridge_expect('list: list does not call the worker', bridge_calls(), 0);
bridge_expect('list: list does not use HTTP', bridge_http(), 0);

$bridge_info_url = skyphusion_ai_vectorize_url($bridge_creds['account_id'], 'skyphusion-ai', 'info');
$bridge_info = skyphusion_ai_vectorize_request('GET', $bridge_info_url, $bridge_creds['token'], '', 'application/json');
bridge_expect('info: info is not a binding op', is_wp_error($bridge_info), true);
bridge_expect('info: info does not call the worker', bridge_calls(), 0);
bridge_expect('info: info does not use HTTP', bridge_http(), 0);

$bridge_index_delete = skyphusion_ai_vectorize_request('DELETE', skyphusion_ai_vectorize_url($bridge_creds['account_id'], 'skyphusion-ai', ''), $bridge_creds['token'], '', 'application/json');
bridge_expect('delete index: index delete is not a binding op', is_wp_error($bridge_index_delete), true);
bridge_expect('delete index: index delete does not call the worker', bridge_calls(), 0);

$GLOBALS['bridge_calls'] = array();
$GLOBALS['bridge_http'] = array();
$bridge_vectors = cloudflare_ai_provider_embeddings(array('red bats'));
$bridge_embed_message = bridge_last_message();
bridge_expect('embed: aiEmbed returns the vector and does not use HTTP', is_array($bridge_vectors) ? $bridge_vectors[0][0] : null, 0.25);
bridge_expect('embed: no HTTP', bridge_http(), 0);
bridge_expect('embed: the op key is aiEmbed', isset($bridge_embed_message['aiEmbed']), true);
bridge_expect('embed: the model is the large preset', $bridge_embed_message['aiEmbed']['model'], '@cf/baai/bge-large-en-v1.5');
bridge_expect('embed: the text is sent', $bridge_embed_message['aiEmbed']['text'], array('red bats'));
bridge_expect('embed: the stand-in token is not in the message', strpos((string) $GLOBALS['bridge_calls'][0], skyphusion_ai_bridge_token()) === false, true);
bridge_expect('embed: api.cloudflare.com is not in the message', strpos((string) $GLOBALS['bridge_calls'][0], 'api.cloudflare.com') === false, true);

$GLOBALS['bridge_fail'] = true;
$GLOBALS['bridge_calls'] = array();
$bridge_failed = skyphusion_ai_vectorize_request('POST', $bridge_query_url, $bridge_creds['token'], '{"vector":[0.1]}', 'application/json');
bridge_expect('failure: a refused op is an error', is_wp_error($bridge_failed), true);
bridge_expect('failure: a refused op does not use HTTP', bridge_http(), 0);
$GLOBALS['bridge_fail'] = false;

$GLOBALS['bridge_options']['skyphusion_ai_neurons'] = array(
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 10000,
    'estimated' => 0,
    'features' => array(),
    'tools' => array(),
    'mailed_80' => 1,
    'mailed_100' => 1,
);
$GLOBALS['bridge_calls'] = array();
$bridge_capped = cloudflare_ai_provider_embeddings(array('red bats'));
bridge_expect('cap: a spent budget does not call the worker', bridge_calls(), 0);
bridge_expect('cap: the error is the neuron cap', is_wp_error($bridge_capped) ? $bridge_capped->get_error_code() : '', 'skyphusion_ai_neuron_cap');
$GLOBALS['bridge_options']['skyphusion_ai_neurons'] = array(
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 0,
    'estimated' => 0,
    'features' => array(),
    'tools' => array(),
);

$GLOBALS['bridge_calls'] = array();
$GLOBALS['bridge_http'] = array();
update_option('skyphusion_ai_vectorize', array(
    'index' => 'skyphusion-ai',
    'model' => '@cf/baai/bge-large-en-v1.5',
    'created' => 1,
    'enabled' => 1,
), false);
$bridge_found = skyphusion_ai_embed_search('red bats', 5);
bridge_expect('search: the title comes back', is_array($bridge_found) ? $bridge_found[0]['title'] : '', 'Run');
bridge_expect('search: search does not use HTTP', bridge_http(), 0);
bridge_expect('search: search calls the worker twice', bridge_calls(), 2);
$bridge_search_ops = array();
foreach ($GLOBALS['bridge_calls'] as $bridge_raw) {
    $bridge_one = json_decode((string) $bridge_raw, true);
    if (isset($bridge_one['aiEmbed'])) {
        $bridge_search_ops[] = 'aiEmbed';
    }
    if (isset($bridge_one['vectorize']['op'])) {
        $bridge_search_ops[] = (string) $bridge_one['vectorize']['op'];
    }
}
bridge_expect('search: the ops are aiEmbed then query', $bridge_search_ops, array('aiEmbed', 'query'));

putenv('CLOUDFLARE_API_KEY=real-token-value');
putenv('CLOUDFLARE_ACCOUNT_ID=cccccccccccccccccccccccccccccccc');
$GLOBALS['bridge_settings']['account_id'] = 'cccccccccccccccccccccccccccccccc';
$bridge_real = skyphusion_ai_embed_credentials();
bridge_expect('token: a stored token is still returned', $bridge_real['token'], 'real-token-value');
$bridge_real_url = skyphusion_ai_vectorize_url($bridge_real['account_id'], 'skyphusion-ai', 'query');
$GLOBALS['bridge_calls'] = array();
$GLOBALS['bridge_http'] = array();
$bridge_real_query = skyphusion_ai_vectorize_request(
    'POST',
    $bridge_real_url,
    $bridge_real['token'],
    '{"vector":[0.1],"topK":1}',
    'application/json'
);
bridge_expect('token: a real token is not sent on the data plane', is_wp_error($bridge_real_query), false);
bridge_expect('token: the data plane still uses the worker', bridge_calls(), 1);
bridge_expect('token: the data plane does not use HTTP', bridge_http(), 0);
bridge_expect('token: the real token is not in the worker message', strpos((string) $GLOBALS['bridge_calls'][0], 'real-token-value') === false, true);

$bridge_other_url = skyphusion_ai_vectorize_url($bridge_real['account_id'], 'other-index', 'query');
$GLOBALS['bridge_calls'] = array();
$GLOBALS['bridge_http'] = array();
$bridge_other = skyphusion_ai_vectorize_request(
    'POST',
    $bridge_other_url,
    $bridge_real['token'],
    '{"vector":[0.1],"topK":1}',
    'application/json'
);
bridge_expect('other index: a different index stays on REST', is_wp_error($bridge_other), false);
bridge_expect('other index: REST was used', bridge_http(), 1);
bridge_expect('other index: the worker was not used', bridge_calls(), 0);
bridge_expect('other index: the REST url is the other index', strpos($GLOBALS['bridge_http'][0]['url'], '/other-index/query') !== false, true);

if ($bridge_failures > 0) {
    fwrite(STDERR, "{$bridge_failures} failed\n");
    exit(1);
}
exit(0);
