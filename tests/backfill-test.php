<?php
/**
 * Empty alt text and SEO descriptions. Loaded after assistant-test.php.
 * The job is off until the admin turns it on.
 */

if (!function_exists('skyphusion_ai_backfill_run')) {
    require_once dirname(__DIR__) . '/includes/backfill.php';
}

$backfill_prev_key = getenv('CLOUDFLARE_API_KEY');
$backfill_prev_account = getenv('CLOUDFLARE_ACCOUNT_ID');
$backfill_token = 'sentinel-token-' . str_repeat('a', 30);
putenv('CLOUDFLARE_API_KEY=' . $backfill_token);
putenv('CLOUDFLARE_ACCOUNT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
$GLOBALS['cfais_settings'] = array(
    'endpoint' => 'https://abc123.search.ai.cloudflare.com',
    'lead_search' => true,
    'account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'gateway_id' => '',
);
$GLOBALS['cfais_status'] = 200;

function backfill_clear()
{
    $GLOBALS['cfais_calls'] = 0;
    unset($GLOBALS['cfais_bodies']);
    $GLOBALS['cfais_last_post'] = array('marker' => 1);
}

$backfill_text = '{"choices":[{"message":{"content":"A linen shirt."}}]}';
$backfill_blank = '{"choices":[{"message":{"content":""}}]}';

backfill_clear();
expect('the empty text job is off by default', skyphusion_ai_backfill_run(false), 'off');
expect('an off job makes no request', $GLOBALS['cfais_calls'], 0);
expect('yoast uses its description key', skyphusion_ai_backfill_meta_key('yoast'), '_yoast_wpseo_metadesc');
expect('rank math uses its description key', skyphusion_ai_backfill_meta_key('rank_math'), 'rank_math_description');
expect('seopress uses its description key', skyphusion_ai_backfill_meta_key('seopress'), '_seopress_titles_desc');
expect('an unknown plugin has no meta key', skyphusion_ai_backfill_meta_key('other'), '');
expect('no seo plugin is detected in this process', skyphusion_ai_backfill_seo_plugin(), '');

skyphusion_ai_backfill_apply(array('enabled' => '1'));
update_post_meta(7, skyphusion_ai_backfill_alt_key(), 'Already written');
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 7, 'kind' => 'alt', 'title' => 'Linen shirt'),
);
backfill_clear();
expect('filled alt text is left as it is', skyphusion_ai_backfill_run(false), 'ran');
expect('filled alt text makes no request', $GLOBALS['cfais_calls'], 0);
expect('filled alt text is unchanged', get_post_meta(7, skyphusion_ai_backfill_alt_key(), true), 'Already written');

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 6, 'kind' => 'alt', 'title' => 'Linen shirt'),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_text);
expect('an empty alt field is filled', skyphusion_ai_backfill_run(false), 'ran');
expect('an empty alt field makes one request', $GLOBALS['cfais_calls'], 1);
expect('the alt text is the model sentence', get_post_meta(6, skyphusion_ai_backfill_alt_key(), true), 'A linen shirt.');
$backfill_post_url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
expect('the text call uses the writing model', strpos($backfill_post_url, skyphusion_ai_backfill_model()) !== false, true);
expect('the text url does not carry the token', strpos($backfill_post_url, $backfill_token) === false, true);

$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 16, 'kind' => 'alt', 'title' => 'Wool shirt'),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_text);
expect('a second batch waits an hour', skyphusion_ai_backfill_run(false), 'waiting');
expect('a waiting batch makes no request', $GLOBALS['cfais_calls'], 0);
expect('a waiting batch does not write', get_post_meta(16, skyphusion_ai_backfill_alt_key(), true), '');

backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_text);
expect('a retry ignores the hour', skyphusion_ai_backfill_run(true), 'ran');
expect('a retry writes the alt text', get_post_meta(16, skyphusion_ai_backfill_alt_key(), true), 'A linen shirt.');

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = time() - 3601;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_seo'] = 'yoast';
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 8, 'kind' => 'description', 'title' => 'Linen shirt'),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_text);
skyphusion_ai_backfill_run(false);
expect('yoast receives the description', get_post_meta(8, '_yoast_wpseo_metadesc', true), 'A linen shirt.');

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
update_post_meta(12, '_yoast_wpseo_metadesc', 'Already written');
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 12, 'kind' => 'description', 'title' => 'Linen shirt'),
);
backfill_clear();
skyphusion_ai_backfill_run(false);
expect('a written yoast description makes no request', $GLOBALS['cfais_calls'], 0);
expect('a written yoast description stays', get_post_meta(12, '_yoast_wpseo_metadesc', true), 'Already written');

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_seo'] = 'rank_math';
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 9, 'kind' => 'description', 'title' => 'Linen shirt'),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_text);
skyphusion_ai_backfill_run(false);
expect('rank math receives the description', get_post_meta(9, 'rank_math_description', true), 'A linen shirt.');

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_seo'] = 'seopress';
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 10, 'kind' => 'description', 'title' => 'Linen shirt'),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_text);
skyphusion_ai_backfill_run(false);
expect('seopress receives the description', get_post_meta(10, '_seopress_titles_desc', true), 'A linen shirt.');

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_seo'] = 'aioseo';
$GLOBALS['skyphusion_ai_backfill_aioseo'] = array(12 => 'Already written');
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 11, 'kind' => 'description', 'title' => 'Linen shirt'),
    array('post_id' => 12, 'kind' => 'description', 'title' => 'Wool coat'),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_text);
skyphusion_ai_backfill_run(false);
expect('aioseo receives the description', $GLOBALS['skyphusion_ai_backfill_aioseo'][11], 'A linen shirt.');
expect('a written aioseo description stays', $GLOBALS['skyphusion_ai_backfill_aioseo'][12], 'Already written');
expect('a written aioseo description makes one request', $GLOBALS['cfais_calls'], 1);

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
unset($GLOBALS['skyphusion_ai_backfill_seo']);
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 13, 'kind' => 'description', 'title' => 'Linen shirt'),
);
backfill_clear();
skyphusion_ai_backfill_run(false);
expect('no seo plugin makes no request', $GLOBALS['cfais_calls'], 0);
$backfill_skips = skyphusion_ai_backfill_config();
expect('no seo plugin records a skip', $backfill_skips['skips'][0]['reason'], 'missing');
expect('a skip has no address field', isset($backfill_skips['skips'][0]['ip']), false);

$backfill_many = array();
$backfill_i = 20;
while ($backfill_i < 26) {
    $backfill_many[] = array('post_id' => $backfill_i, 'kind' => 'alt', 'title' => 'Shirt');
    $backfill_i++;
}
$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
$backfill_saved['skips'] = array();
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_candidates'] = $backfill_many;
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_text, $backfill_text, $backfill_text, $backfill_text, $backfill_text, $backfill_text);
skyphusion_ai_backfill_run(false);
expect('one batch stops at five requests', $GLOBALS['cfais_calls'], 5);
expect('the sixth image is not written', get_post_meta(25, skyphusion_ai_backfill_alt_key(), true), '');

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 30, 'kind' => 'alt', 'title' => 'Linen shirt'),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_blank);
skyphusion_ai_backfill_run(false);
expect('an empty model reply makes one request', $GLOBALS['cfais_calls'], 1);
expect('an empty model reply does not write', get_post_meta(30, skyphusion_ai_backfill_alt_key(), true), '');
$backfill_skips = skyphusion_ai_backfill_config();
$backfill_empty_reason = '';
foreach ($backfill_skips['skips'] as $backfill_row) {
    if (30 === (int) $backfill_row['post_id']) {
        $backfill_empty_reason = $backfill_row['reason'];
    }
}
expect('an empty model reply is recorded', $backfill_empty_reason, 'empty');

update_option('skyphusion_ai_neurons', array(
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 10000,
    'estimated' => 0,
    'features' => array(),
    'mailed_80' => 1,
    'mailed_100' => 1,
), false);
$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 31, 'kind' => 'alt', 'title' => 'Linen shirt'),
);
backfill_clear();
skyphusion_ai_backfill_run(false);
expect('a spent budget makes no request', $GLOBALS['cfais_calls'], 0);
expect('a spent budget does not write', get_post_meta(31, skyphusion_ai_backfill_alt_key(), true), '');
$backfill_skips = skyphusion_ai_backfill_config();
$backfill_budget_reason = '';
foreach ($backfill_skips['skips'] as $backfill_row) {
    if (31 === (int) $backfill_row['post_id']) {
        $backfill_budget_reason = $backfill_row['reason'];
    }
}
expect('a spent budget is recorded', $backfill_budget_reason, 'budget');
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);

$GLOBALS['cfais_can']['manage_options'] = true;
$GLOBALS['skyphusion_ai_backfill_seo'] = 'yoast';
ob_start();
skyphusion_ai_backfill_render();
$backfill_screen = (string) ob_get_clean();
expect('the screen offers the switch', strpos($backfill_screen, 'name="skyphusion_ai_backfill_enabled"') !== false, true);
expect('the screen says text you wrote stays', strpos($backfill_screen, 'It does not replace text you wrote.') !== false, true);
expect('the screen offers a retry', strpos($backfill_screen, 'Retry skipped items') !== false, true);
expect('the screen names yoast', strpos($backfill_screen, 'Writes Yoast.') !== false, true);
expect('the screen does not show the token', strpos($backfill_screen, $backfill_token) === false, true);
expect('the screen names the skipped post', strpos($backfill_screen, '31') !== false, true);

$backfill_src = (string) file_get_contents(dirname(__DIR__) . '/includes/backfill.php');
expect('the job does not read a remote address', strpos($backfill_src, 'REMOTE_ADDR') === false, true);
expect('the job stops at the neuron budget', strpos($backfill_src, 'skyphusion_ai_neuron_refusal') !== false, true);
expect('the job knows the aioseo table', strpos($backfill_src, 'aioseo_posts') !== false, true);
$backfill_uninstall = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
expect('uninstall removes the empty text option', strpos($backfill_uninstall, 'skyphusion_ai_backfill') !== false, true);

unset($GLOBALS['cfais_settings']);
unset($GLOBALS['cfais_bodies']);
unset($GLOBALS['skyphusion_ai_backfill_candidates']);
unset($GLOBALS['skyphusion_ai_backfill_seo']);
unset($GLOBALS['skyphusion_ai_backfill_aioseo']);
unset($GLOBALS['cfais_options']['skyphusion_ai_backfill']);
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
unset($GLOBALS['cfais_can']['manage_options']);
if (false === $backfill_prev_key || '' === $backfill_prev_key) {
    putenv('CLOUDFLARE_API_KEY');
} else {
    putenv('CLOUDFLARE_API_KEY=' . $backfill_prev_key);
}
if (false === $backfill_prev_account || '' === $backfill_prev_account) {
    putenv('CLOUDFLARE_ACCOUNT_ID');
} else {
    putenv('CLOUDFLARE_ACCOUNT_ID=' . $backfill_prev_account);
}
