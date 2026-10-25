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
expect('M2M100 does not send the translation prompt', strpos($translate_sent, 'Translate the text') === false, true);
expect('Spanish stays on M2M100', strpos($translate_url, 'indictrans') === false, true);
$translate_neurons = get_option('skyphusion_ai_neurons', array());
$translate_text_calls = 0;
if (isset($translate_neurons['features']['text'])) {
    $translate_text_calls = (int) $translate_neurons['features']['text'];
}
expect('the call counts as text', $translate_text_calls, 1);

$translate_indic_codes = array(
    'as' => 'asm_Beng',
    'bn' => 'ben_Beng',
    'brx' => 'brx_Deva',
    'doi' => 'doi_Deva',
    'gu' => 'guj_Gujr',
    'hi' => 'hin_Deva',
    'kn' => 'kan_Knda',
    'kok' => 'gom_Deva',
    'ks' => 'kas_Deva',
    'mai' => 'mai_Deva',
    'ml' => 'mal_Mlym',
    'mni' => 'mni_Beng',
    'mr' => 'mar_Deva',
    'ne' => 'npi_Deva',
    'or' => 'ory_Orya',
    'pa' => 'pan_Guru',
    'sa' => 'san_Deva',
    'sat' => 'sat_Olck',
    'sd' => 'snd_Deva',
    'ta' => 'tam_Taml',
    'te' => 'tel_Telu',
    'ur' => 'urd_Arab',
);
expect('IndicTrans2 covers 22 languages', skyphusion_ai_translate_indic_codes(), $translate_indic_codes);
$translate_pair_miss = array();
foreach ($translate_indic_codes as $translate_iso => $translate_flores) {
    if (skyphusion_ai_translate_model_for($translate_iso, 'en') !== skyphusion_ai_translate_indic_model()) {
        $translate_pair_miss[] = $translate_iso;
    }
    if (skyphusion_ai_translate_model_for($translate_iso, '') !== skyphusion_ai_translate_indic_model()) {
        $translate_pair_miss[] = $translate_iso . '-empty';
    }
    if (skyphusion_ai_translate_model_for($translate_iso, 'fr') !== skyphusion_ai_translate_model()) {
        $translate_pair_miss[] = $translate_iso . '-fr';
    }
}
expect('English into each Indian language uses IndicTrans2', $translate_pair_miss, array());
expect('Hindi into English stays on M2M100', skyphusion_ai_translate_model_for('en', 'hi'), skyphusion_ai_translate_model());
expect('the text job has no model', skyphusion_ai_model_choice('text'), '');
$translate_catalog_hit = array();
foreach (cloudflare_ai_provider_catalog() as $translate_row) {
    $translate_row_id = isset($translate_row['id']) ? (string) $translate_row['id'] : '';
    if ($translate_row_id === skyphusion_ai_translate_model() || $translate_row_id === skyphusion_ai_translate_indic_model()) {
        $translate_catalog_hit[] = $translate_row_id;
    }
}
expect('neither translation model is in the writing catalog', $translate_catalog_hit, array());

unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
translate_clear();
$GLOBALS['cfais_bodies'] = array('{"result":{"translations":["linan shirt"]}}');
$translate_hi = skyphusion_ai_translate('linen shirt', 'hi', 'en');
expect('Hindi returns the IndicTrans2 text', $translate_hi, 'linan shirt');
expect('Hindi makes one request', $GLOBALS['cfais_calls'], 1);
$translate_hi_url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
expect('Hindi uses IndicTrans2', strpos($translate_hi_url, skyphusion_ai_translate_indic_model()) !== false, true);
expect('the Hindi url does not carry the token', strpos($translate_hi_url, $translate_token) === false, true);
$translate_hi_sent = isset($GLOBALS['cfais_last_post']['args']['body']) ? (string) $GLOBALS['cfais_last_post']['args']['body'] : '';
$translate_hi_json = json_decode($translate_hi_sent, true);
$translate_hi_json = is_array($translate_hi_json) ? $translate_hi_json : array();
expect('Hindi sends target_language', isset($translate_hi_json['target_language']) ? $translate_hi_json['target_language'] : '', 'hin_Deva');
expect('Hindi does not send target_lang', isset($translate_hi_json['target_lang']), false);
expect('Hindi does not send source_lang', isset($translate_hi_json['source_lang']), false);
expect('Hindi sends the caller text', strpos($translate_hi_sent, 'linen shirt') !== false, true);
expect('Hindi does not send the translation prompt', strpos($translate_hi_sent, 'Translate the text') === false, true);
$translate_hi_neurons = get_option('skyphusion_ai_neurons', array());
$translate_hi_text = 0;
if (isset($translate_hi_neurons['features']['text'])) {
    $translate_hi_text = (int) $translate_hi_neurons['features']['text'];
}
expect('the Hindi call counts as text', $translate_hi_text, 1);

unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
translate_clear();
$GLOBALS['cfais_bodies'] = array('{"result":{"translations":["bodo line"]}}');
$translate_bodo = skyphusion_ai_translate('linen shirt', 'brx');
expect('a three letter Indian code is accepted', $translate_bodo, 'bodo line');
$translate_bodo_sent = isset($GLOBALS['cfais_last_post']['args']['body']) ? (string) $GLOBALS['cfais_last_post']['args']['body'] : '';
expect('Bodo uses brx_Deva', strpos($translate_bodo_sent, 'brx_Deva') !== false, true);
expect('an empty source still uses IndicTrans2', strpos((string) $GLOBALS['cfais_last_post']['url'], skyphusion_ai_translate_indic_model()) !== false, true);

translate_clear();
$translate_xyz = skyphusion_ai_translate('linen shirt', 'xyz', 'en');
expect('an unknown three letter code is an error', is_wp_error($translate_xyz), true);
expect('an unknown three letter code makes no request', $GLOBALS['cfais_calls'], 0);

unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
translate_clear();
$GLOBALS['cfais_bodies'] = array($translate_body);
$translate_via = skyphusion_ai_translate('linen shirt', 'hi', 'fr');
expect('Hindi from French returns the M2M100 text', $translate_via, 'camisa de lino');
$translate_via_url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
expect('Hindi from French stays on M2M100', strpos($translate_via_url, skyphusion_ai_translate_model()) !== false, true);
expect('Hindi from French does not use IndicTrans2', strpos($translate_via_url, 'indictrans') === false, true);
$translate_via_sent = isset($GLOBALS['cfais_last_post']['args']['body']) ? (string) $GLOBALS['cfais_last_post']['args']['body'] : '';
expect('Hindi from French names the source', strpos($translate_via_sent, '"source_lang":"fr"') !== false, true);
expect('Hindi from French names target_lang', strpos($translate_via_sent, '"target_lang":"hi"') !== false, true);
expect('Hindi from French does not send the translation prompt', strpos($translate_via_sent, 'Translate the text') === false, true);

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
expect('the helper does not fill the translation prompt', strpos($translate_src, 'skyphusion_ai_prompt_fill') === false, true);
expect('the helper does not apply the translation prompt', strpos($translate_src, 'skyphusion_ai_prompt_apply') === false, true);

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
