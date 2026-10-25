<?php
/**
 * Translation helper. Loaded after backfill-test.php.
 */

if (!function_exists('skyphusion_ai_translate')) {
    require_once dirname(__DIR__) . '/includes/translate.php';
}

$translate_prev_key = getenv('CLOUDFLARE_API_KEY');
$translate_prev_account = getenv('CLOUDFLARE_ACCOUNT_ID');
$translate_token = 'sentinel-token-' . str_repeat('a', 30);
putenv('CLOUDFLARE_API_KEY=' . $translate_token);
putenv('CLOUDFLARE_ACCOUNT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
$GLOBALS['cfais_settings'] = array(
    'endpoint' => 'https://abc123.search.ai.cloudflare.com',
    'lead_search' => true,
    'account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'gateway_id' => '',
);
$GLOBALS['cfais_status'] = 200;
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);

function translate_clear()
{
    $GLOBALS['cfais_calls'] = 0;
    unset($GLOBALS['cfais_bodies']);
    $GLOBALS['cfais_last_post'] = array('marker' => 1);
}

$translate_body = '{"result":{"translated_text":"camisa de lino"}}';

translate_clear();
$translate_empty = skyphusion_ai_translate('', 'es', 'en');
expect('empty text is an error', is_wp_error($translate_empty), true);
expect('empty text makes no request', $GLOBALS['cfais_calls'], 0);

translate_clear();
$translate_lang = skyphusion_ai_translate('linen shirt', 'spanish', 'en');
expect('a long language name is an error', is_wp_error($translate_lang), true);
expect('a long language name makes no request', $GLOBALS['cfais_calls'], 0);

translate_clear();
$translate_same = skyphusion_ai_translate('linen shirt', 'en', 'en');
expect('the same language returns the text', $translate_same, 'linen shirt');
expect('the same language makes no request', $GLOBALS['cfais_calls'], 0);

translate_clear();
$GLOBALS['cfais_bodies'] = array($translate_body);
$translate_ok = skyphusion_ai_translate('linen shirt', 'es');
expect('a translation returns the model text', $translate_ok, 'camisa de lino');
expect('a translation makes one request', $GLOBALS['cfais_calls'], 1);
$translate_url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
expect('the call uses the translation model', strpos($translate_url, skyphusion_ai_translate_model()) !== false, true);
expect('the url does not carry the token', strpos($translate_url, $translate_token) === false, true);
$translate_sent = isset($GLOBALS['cfais_last_post']['args']['body']) ? (string) $GLOBALS['cfais_last_post']['args']['body'] : '';
expect('an empty source omits the source field', strpos($translate_sent, 'source_lang') === false, true);
expect('the request names the target', strpos($translate_sent, 'target_lang') !== false, true);
$translate_neurons = get_option('skyphusion_ai_neurons', array());
$translate_text_calls = 0;
if (isset($translate_neurons['features']['text'])) {
    $translate_text_calls = (int) $translate_neurons['features']['text'];
}
expect('the call counts as text', $translate_text_calls, 1);

translate_clear();
$GLOBALS['cfais_status'] = 500;
$GLOBALS['cfais_bodies'] = array('{"success":false}');
$translate_fail = skyphusion_ai_translate('linen shirt', 'es', 'en');
expect('a failed call is an error', is_wp_error($translate_fail), true);
$translate_fail_message = is_wp_error($translate_fail) ? (string) $translate_fail->message : '';
expect('the error does not include the token', strpos($translate_fail_message, $translate_token) === false, true);
$GLOBALS['cfais_status'] = 200;

update_option('skyphusion_ai_neurons', array(
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 10000,
    'estimated' => 0,
    'features' => array(),
    'mailed_80' => 1,
    'mailed_100' => 1,
), false);
translate_clear();
$translate_capped = skyphusion_ai_translate('linen shirt', 'es', 'en');
expect('a spent budget is an error', is_wp_error($translate_capped), true);
expect('a spent budget makes no request', $GLOBALS['cfais_calls'], 0);
$translate_cap_message = is_wp_error($translate_capped) ? (string) $translate_capped->message : '';
expect('a spent budget names the pause', strpos($translate_cap_message, 'paused') !== false, true);
expect('a spent budget does not include the token', strpos($translate_cap_message, $translate_token) === false, true);

translate_clear();
$translate_long = skyphusion_ai_translate(str_repeat('a', 2001), 'es', 'en');
expect('a long text is an error', is_wp_error($translate_long), true);
expect('a long text makes no request', $GLOBALS['cfais_calls'], 0);

$translate_src = (string) file_get_contents(dirname(__DIR__) . '/includes/translate.php');
expect('the helper does not read a remote address', strpos($translate_src, 'REMOTE_ADDR') === false, true);
expect('the helper stops at the neuron budget', strpos($translate_src, 'skyphusion_ai_neuron_refusal') !== false, true);

unset($GLOBALS['cfais_settings']);
unset($GLOBALS['cfais_bodies']);
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
if (false === $translate_prev_key || '' === $translate_prev_key) {
    putenv('CLOUDFLARE_API_KEY');
} else {
    putenv('CLOUDFLARE_API_KEY=' . $translate_prev_key);
}
if (false === $translate_prev_account || '' === $translate_prev_account) {
    putenv('CLOUDFLARE_ACCOUNT_ID');
} else {
    putenv('CLOUDFLARE_ACCOUNT_ID=' . $translate_prev_account);
}
