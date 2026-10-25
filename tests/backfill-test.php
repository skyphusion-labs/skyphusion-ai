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
    if (isset($GLOBALS['cfais_store']) && is_array($GLOBALS['cfais_store'])) {
        foreach (array_keys($GLOBALS['cfais_store']) as $backfill_cache_key) {
            if (0 === strpos((string) $backfill_cache_key, 'skyphusion_ai_pc_')) {
                unset($GLOBALS['cfais_store'][$backfill_cache_key]);
            }
        }
    }
}

$backfill_text = '{"choices":[{"message":{"content":"A linen shirt."}}]}';
$backfill_blank = '{"choices":[{"message":{"content":""}}]}';
$backfill_image = 'https://cdn.example/linen.jpg';
$backfill_decorative = '{"choices":[{"message":{"content":"decorative"}}]}';

if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url($post_id)
    {
        $map = isset($GLOBALS['skyphusion_ai_backfill_urls']) ? $GLOBALS['skyphusion_ai_backfill_urls'] : array();
        $post_id = (int) $post_id;
        return isset($map[$post_id]) ? (string) $map[$post_id] : '';
    }
}
if (!function_exists('get_attached_file')) {
    function get_attached_file($post_id)
    {
        $map = isset($GLOBALS['skyphusion_ai_backfill_files']) ? $GLOBALS['skyphusion_ai_backfill_files'] : array();
        $post_id = (int) $post_id;
        return isset($map[$post_id]) ? (string) $map[$post_id] : '';
    }
}

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
    array('post_id' => 6, 'kind' => 'alt', 'title' => 'Linen shirt', 'image' => $backfill_image),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_text);
expect('an empty alt field is filled', skyphusion_ai_backfill_run(false), 'ran');
expect('an empty alt field makes one request', $GLOBALS['cfais_calls'], 1);
expect('the alt text is the model sentence', get_post_meta(6, skyphusion_ai_backfill_alt_key(), true), 'A linen shirt.');
$backfill_post_url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
expect('the alt call uses the vision model', strpos($backfill_post_url, skyphusion_ai_backfill_vision_model()) !== false, true);
expect('the alt call does not use the writing model', strpos($backfill_post_url, skyphusion_ai_backfill_model()) === false, true);
$backfill_alt_body = isset($GLOBALS['cfais_last_post']['args']['body']) ? (string) $GLOBALS['cfais_last_post']['args']['body'] : '';
$backfill_decoded = json_decode($backfill_alt_body, true);
$backfill_sent_image = '';
if (is_array($backfill_decoded) && isset($backfill_decoded['messages'][0]['content']) && is_array($backfill_decoded['messages'][0]['content'])) {
    foreach ($backfill_decoded['messages'][0]['content'] as $backfill_part) {
        if (is_array($backfill_part) && isset($backfill_part['image_url']['url'])) {
            $backfill_sent_image = (string) $backfill_part['image_url']['url'];
        }
    }
}
expect('the alt call sends the image', $backfill_sent_image, $backfill_image);
expect('the alt call does not carry the token', strpos($backfill_alt_body, $backfill_token) === false, true);
expect('the text url does not carry the token', strpos($backfill_post_url, $backfill_token) === false, true);

$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 16, 'kind' => 'alt', 'title' => 'Wool shirt', 'image' => $backfill_image),
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
$backfill_page = 'This linen shirt is cut for warm weather and a long walk.';
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 8, 'kind' => 'description', 'title' => 'Linen shirt', 'content' => $backfill_page),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_text);
skyphusion_ai_backfill_run(false);
expect('yoast receives the description', get_post_meta(8, '_yoast_wpseo_metadesc', true), 'A linen shirt.');
$backfill_seo_url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
expect('the seo call uses the writing model', strpos($backfill_seo_url, skyphusion_ai_backfill_model()) !== false, true);

$GLOBALS['skyphusion_ai_models_runtime'] = array(
    array('id' => '@cf/example/light-writer', 'name' => 'Light', 'task' => 'text generation'),
);
if (!isset($GLOBALS['cfais_stored']) || !is_array($GLOBALS['cfais_stored'])) {
    $GLOBALS['cfais_stored'] = array();
}
$backfill_light_was = array_key_exists('model_light', $GLOBALS['cfais_stored']) ? $GLOBALS['cfais_stored']['model_light'] : null;
$GLOBALS['cfais_stored']['model_light'] = '@cf/example/light-writer';
expect('a saved light model is the seo model', skyphusion_ai_backfill_model(), '@cf/example/light-writer');
$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = time() - 3601;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 18, 'kind' => 'description', 'title' => 'Linen shirt', 'content' => $backfill_page),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_text);
skyphusion_ai_backfill_run(false);
$backfill_light_url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
expect('the seo call uses the light model', strpos($backfill_light_url, '@cf/example/light-writer') !== false, true);
expect('the seo call leaves the writing model', strpos($backfill_light_url, 'llama-3.3-70b') === false, true);
if (null === $backfill_light_was) {
    unset($GLOBALS['cfais_stored']['model_light']);
} else {
    $GLOBALS['cfais_stored']['model_light'] = $backfill_light_was;
}
unset($GLOBALS['skyphusion_ai_models_runtime']);

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
    array('post_id' => 9, 'kind' => 'description', 'title' => 'Linen shirt', 'content' => $backfill_page),
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
    array('post_id' => 10, 'kind' => 'description', 'title' => 'Linen shirt', 'content' => $backfill_page),
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
    array('post_id' => 11, 'kind' => 'description', 'title' => 'Linen shirt', 'content' => $backfill_page),
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
    $backfill_many[] = array('post_id' => $backfill_i, 'kind' => 'alt', 'title' => 'Shirt ' . $backfill_i, 'image' => $backfill_image);
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
$backfill_saved['last_at'] = time() - 3601;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_candidates'] = $backfill_many;
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_text);
skyphusion_ai_backfill_run(false);
expect('the next hour continues the backlog', get_post_meta(25, skyphusion_ai_backfill_alt_key(), true), 'A linen shirt.');
expect('the next hour sends one request', $GLOBALS['cfais_calls'], 1);

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 30, 'kind' => 'alt', 'title' => 'Linen shirt', 'image' => $backfill_image),
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
    array('post_id' => 31, 'kind' => 'alt', 'title' => 'Linen shirt', 'image' => $backfill_image),
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
expect('the screen offers the daily limit', strpos($backfill_screen, 'name="skyphusion_ai_backfill_limit"') !== false, true);
expect('the screen says the limit stays on this job', strpos($backfill_screen, 'This limit does not stop the other tools.') !== false, true);
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

expect(
    'a title-only seo prompt stays the old sentence',
    skyphusion_ai_backfill_prompt('description', 'Linen shirt'),
    'Write one SEO description for this page. Title: Linen shirt'
);
expect(
    'alt text ignores page text',
    skyphusion_ai_backfill_prompt('alt', 'Linen shirt', $backfill_page),
    'Write one alt sentence for this image. Title: Linen shirt'
);
$backfill_marked = '<p>' . $backfill_page . '</p>[gallery id="1"]';
$backfill_filled = skyphusion_ai_backfill_prompt('description', 'Linen shirt', $backfill_marked);
expect('page text is appended', strpos($backfill_filled, "\n\nContent:\n" . $backfill_page) !== false, true);
expect('tags are not sent', strpos($backfill_filled, '<p>') === false, true);
expect('a shortcode is not sent', strpos($backfill_filled, 'gallery') === false, true);
expect(
    'a short fragment is not appended',
    skyphusion_ai_backfill_prompt('description', 'Linen shirt', 'Too short.'),
    'Write one SEO description for this page. Title: Linen shirt'
);
$backfill_cut = skyphusion_ai_backfill_prompt('description', 'Linen shirt', str_repeat('a', 6001));
$backfill_at = strpos($backfill_cut, "Content:\n");
$backfill_sent = (false === $backfill_at) ? '' : substr($backfill_cut, $backfill_at + 9);
expect('page text stops at 6000 characters', strlen($backfill_sent), 6000);

$GLOBALS['cfais_embed_post'] = array('ID' => 1, 'post_content' => $backfill_page);
expect('a different post is not read', skyphusion_ai_backfill_page_text(40), '');
$GLOBALS['cfais_embed_post'] = array('ID' => 40, 'post_content' => $backfill_marked);
expect('the matching post is read', skyphusion_ai_backfill_page_text(40), $backfill_marked);
unset($GLOBALS['cfais_embed_post']);

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
$backfill_saved['skips'] = array();
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_seo'] = 'yoast';
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 40, 'kind' => 'description', 'title' => 'Linen shirt', 'content' => 'Too short.'),
);
backfill_clear();
expect('a short page is skipped', skyphusion_ai_backfill_run(false), 'ran');
expect('a short page makes no request', $GLOBALS['cfais_calls'], 0);
expect('a short page is not written', get_post_meta(40, '_yoast_wpseo_metadesc', true), '');
$backfill_skips = skyphusion_ai_backfill_config();
$backfill_short_reason = '';
foreach ($backfill_skips['skips'] as $backfill_row) {
    if (40 === (int) $backfill_row['post_id']) {
        $backfill_short_reason = $backfill_row['reason'];
    }
}
expect('a short page records the reason', $backfill_short_reason, 'short');
expect(
    'a short page says why',
    skyphusion_ai_backfill_skip_label(array('post_id' => 40, 'kind' => 'description', 'reason' => 'short')),
    'The SEO description was skipped. The page text is under 40 characters.'
);

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
$backfill_saved['skips'] = array();
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 41, 'kind' => 'description', 'title' => 'Linen shirt', 'content' => $backfill_marked),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_text);
expect('a long page is filled', skyphusion_ai_backfill_run(false), 'ran');
expect('a long page makes one request', $GLOBALS['cfais_calls'], 1);
$backfill_body = isset($GLOBALS['cfais_last_post']['args']['body']) ? (string) $GLOBALS['cfais_last_post']['args']['body'] : '';
expect('the request sends the page text', strpos($backfill_body, 'Content:') !== false, true);
expect('the request does not send the tag', strpos($backfill_body, '<p>') === false, true);
expect('the request does not send the shortcode', strpos($backfill_body, 'gallery') === false, true);
expect('the request does not carry the token', strpos($backfill_body, $backfill_token) === false, true);

expect('a public image url is allowed', skyphusion_ai_backfill_image_allowed($backfill_image), true);
expect('a script url is refused', skyphusion_ai_backfill_image_allowed('javascript:alert(1)'), false);
expect('a file path is refused', skyphusion_ai_backfill_image_allowed('/tmp/linen.jpg'), false);
expect('the saved alt sentence stays first', strpos(skyphusion_ai_backfill_alt_prompt('Linen shirt'), 'Write one alt sentence for this image. Title: Linen shirt') === 0, true);
expect('decorative is the whole reply only', skyphusion_ai_backfill_is_decorative('Decorative.'), true);
expect('a real sentence is not decorative', skyphusion_ai_backfill_is_decorative('A decorative linen shirt.'), false);

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
$backfill_saved['skips'] = array();
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 50, 'kind' => 'alt', 'title' => 'Linen shirt'),
);
backfill_clear();
expect('an image with no source is skipped', skyphusion_ai_backfill_run(false), 'ran');
expect('an image with no source makes no request', $GLOBALS['cfais_calls'], 0);
$backfill_skips = skyphusion_ai_backfill_config();
$backfill_image_reason = '';
foreach ($backfill_skips['skips'] as $backfill_row) {
    if (50 === (int) $backfill_row['post_id']) {
        $backfill_image_reason = $backfill_row['reason'];
    }
}
expect('an image with no source records the reason', $backfill_image_reason, 'noimage');
expect(
    'an image with no source says why',
    skyphusion_ai_backfill_skip_label(array('post_id' => 50, 'kind' => 'alt', 'reason' => 'noimage')),
    'Alt text was skipped. The image could not be read.'
);

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
$backfill_saved['skips'] = array();
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 51, 'kind' => 'alt', 'title' => 'Linen shirt', 'image' => $backfill_image),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_decorative);
expect('a decorative image is marked', skyphusion_ai_backfill_run(false), 'ran');
expect('a decorative image makes one request', $GLOBALS['cfais_calls'], 1);
$backfill_usage = get_option('skyphusion_ai_neurons', array());
$backfill_vision_count = (is_array($backfill_usage) && isset($backfill_usage['features']['vision'])) ? (int) $backfill_usage['features']['vision'] : 0;
expect('an alt read counts as vision', $backfill_vision_count > 0, true);
expect('a decorative image leaves alt empty', get_post_meta(51, skyphusion_ai_backfill_alt_key(), true), '');
expect('a decorative image stores the mark', get_post_meta(51, skyphusion_ai_backfill_decorative_key(), true), '1');

$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_bodies'] = array($backfill_text);
$backfill_cached = skyphusion_ai_backfill_call('Cache this linen prompt.', skyphusion_ai_backfill_model(), 'text', '');
expect('the first call returns the sentence', $backfill_cached, 'A linen shirt.');
expect('the first call reaches the model', $GLOBALS['cfais_calls'], 1);
$backfill_neurons_before = (int) skyphusion_ai_neuron_state()['neurons'];
$GLOBALS['cfais_calls'] = 0;
$backfill_again = skyphusion_ai_backfill_call('Cache this linen prompt.', skyphusion_ai_backfill_model(), 'text', '');
expect('a repeat returns the same sentence', $backfill_again, 'A linen shirt.');
expect('a repeat does not call the model', $GLOBALS['cfais_calls'], 0);
expect('a repeat adds no neurons', (int) skyphusion_ai_neuron_state()['neurons'], $backfill_neurons_before);
$backfill_key = skyphusion_ai_prompt_cache_key('text', skyphusion_ai_backfill_model(), 'Cache this linen prompt.', '');
expect('the cache key hides the prompt', strpos($backfill_key, 'Cache this linen prompt') === false, true);
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_bodies'] = array('{"choices":[{"message":{"content":"A fresh line."}}]}');
$backfill_fresh = skyphusion_ai_backfill_call('Cache this linen prompt.', skyphusion_ai_backfill_model(), 'text', '', true);
expect('a regenerate bypasses the cache', $backfill_fresh, 'A fresh line.');
expect('a regenerate calls the model', $GLOBALS['cfais_calls'], 1);
$backfill_provider = (string) file_get_contents(dirname(__DIR__) . '/includes/provider.php');
$backfill_query_at = strpos($backfill_provider, 'function skyphusion_ai_query');
$backfill_query_fn = (false === $backfill_query_at) ? '' : substr($backfill_provider, $backfill_query_at, 4000);
expect('search does not use the prompt cache', strpos($backfill_query_fn, 'skyphusion_ai_prompt_cache_') === false, true);
$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
backfill_clear();
expect('a marked image is not asked again', skyphusion_ai_backfill_run(false), 'ran');
expect('a marked image makes no request', $GLOBALS['cfais_calls'], 0);

$backfill_gif = sys_get_temp_dir() . '/sai-alt-linen.gif';
file_put_contents($backfill_gif, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
$GLOBALS['skyphusion_ai_backfill_urls'] = array(71 => $backfill_image, 72 => 'javascript:alert(1)');
$GLOBALS['skyphusion_ai_backfill_files'] = array(70 => $backfill_gif, 71 => $backfill_gif, 72 => $backfill_gif);
$backfill_data = skyphusion_ai_backfill_image_ref(70);
expect('a local image becomes a data url', strpos($backfill_data, 'data:image/gif;base64,') === 0, true);
expect('a public url is preferred', skyphusion_ai_backfill_image_ref(71), $backfill_image);
$backfill_fallback = skyphusion_ai_backfill_image_ref(72);
expect('a script url is not sent', strpos($backfill_fallback, 'javascript:') === false, true);
expect('a script url falls back to the file', strpos($backfill_fallback, 'data:image/gif;base64,') === 0, true);
$backfill_big = sys_get_temp_dir() . '/sai-alt-big.bin';
$backfill_handle = fopen($backfill_big, 'wb');
if (is_resource($backfill_handle)) {
    ftruncate($backfill_handle, skyphusion_ai_backfill_image_cap() + 1);
    fclose($backfill_handle);
}
$GLOBALS['skyphusion_ai_backfill_urls'][73] = '';
$GLOBALS['skyphusion_ai_backfill_files'][73] = $backfill_big;
expect('a huge file is not read', skyphusion_ai_backfill_image_file($backfill_big), '');
unlink($backfill_gif);
unlink($backfill_big);

expect('the default backfill limit is 3000', skyphusion_ai_backfill_daily_default(), 3000);
expect('zero is a saved backfill limit', skyphusion_ai_backfill_limit_value('0'), 0);
expect('a word is not a backfill limit', skyphusion_ai_backfill_limit_value('many'), null);

$backfill_priced = '{"choices":[{"message":{"content":"A linen shirt."}}],"usage":{"neurons":2}}';
$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
$backfill_saved['skips'] = array();
$backfill_saved['limit'] = 2;
$backfill_saved['day'] = skyphusion_ai_neuron_today();
$backfill_saved['neurons'] = 0;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
update_post_meta(79, skyphusion_ai_backfill_alt_key(), 'Already written');
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 79, 'kind' => 'alt', 'title' => 'Linen shirt', 'image' => $backfill_image),
    array('post_id' => 80, 'kind' => 'alt', 'title' => 'Linen shirt', 'image' => $backfill_image),
    array('post_id' => 81, 'kind' => 'alt', 'title' => 'Wool shirt', 'image' => $backfill_image),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_priced, $backfill_priced);
skyphusion_ai_backfill_run(false);
expect('a priced item is written', get_post_meta(80, skyphusion_ai_backfill_alt_key(), true), 'A linen shirt.');
expect('the next item stops at the backfill limit', get_post_meta(81, skyphusion_ai_backfill_alt_key(), true), '');
expect('the backfill limit allows one priced call', $GLOBALS['cfais_calls'], 1);
expect('text an admin wrote stays under the limit', get_post_meta(79, skyphusion_ai_backfill_alt_key(), true), 'Already written');
$backfill_skips = skyphusion_ai_backfill_config();
$backfill_limit_reason = '';
foreach ($backfill_skips['skips'] as $backfill_row) {
    if (81 === (int) $backfill_row['post_id']) {
        $backfill_limit_reason = $backfill_row['reason'];
    }
}
expect('a backfill limit records the reason', $backfill_limit_reason, 'limit');
expect('today records the priced neurons', skyphusion_ai_backfill_used($backfill_skips), 2);
$backfill_site = skyphusion_ai_neuron_state();
expect('a backfill limit leaves the site budget open', (int) $backfill_site['neurons'] < skyphusion_ai_neuron_cap(), true);
expect('another tool is not refused', skyphusion_ai_neuron_refusal() === null, true);
expect(
    'a backfill limit says why',
    skyphusion_ai_backfill_skip_label(array('post_id' => 81, 'kind' => 'alt', 'reason' => 'limit')),
    "Alt text was skipped. Today's backfill limit stopped this item."
);

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = time();
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 82, 'kind' => 'alt', 'title' => 'Linen shirt', 'image' => $backfill_image),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_priced);
expect('a retry still stops at the backfill limit', skyphusion_ai_backfill_run(true), 'ran');
expect('a retry at the limit makes no request', $GLOBALS['cfais_calls'], 0);
expect('a retry at the limit does not write', get_post_meta(82, skyphusion_ai_backfill_alt_key(), true), '');

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
$backfill_saved['skips'] = array();
$backfill_saved['limit'] = 0;
$backfill_saved['day'] = skyphusion_ai_neuron_today();
$backfill_saved['neurons'] = 9000;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 83, 'kind' => 'alt', 'title' => 'Linen shirt', 'image' => $backfill_image),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_text);
skyphusion_ai_backfill_run(false);
expect('a zero limit does not stop the job', $GLOBALS['cfais_calls'], 1);
expect('a zero limit still writes', get_post_meta(83, skyphusion_ai_backfill_alt_key(), true), 'A linen shirt.');

$backfill_saved = skyphusion_ai_backfill_config();
$backfill_saved['last_at'] = 0;
$backfill_saved['skips'] = array();
$backfill_saved['limit'] = 3000;
$backfill_saved['day'] = '2000-01-01';
$backfill_saved['neurons'] = 3000;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 84, 'kind' => 'alt', 'title' => 'Linen shirt', 'image' => $backfill_image),
);
backfill_clear();
$GLOBALS['cfais_bodies'] = array($backfill_text);
skyphusion_ai_backfill_run(false);
expect('a new day continues the backlog', $GLOBALS['cfais_calls'], 1);
expect('a new day writes the field', get_post_meta(84, skyphusion_ai_backfill_alt_key(), true), 'A linen shirt.');
$backfill_skips = skyphusion_ai_backfill_config();
expect('a new day does not keep yesterday', skyphusion_ai_backfill_used($backfill_skips), 0);

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
$backfill_saved['skips'] = array();
$backfill_saved['limit'] = 3000;
$backfill_saved['day'] = skyphusion_ai_neuron_today();
$backfill_saved['neurons'] = 0;
update_option('skyphusion_ai_backfill', $backfill_saved, false);
$GLOBALS['skyphusion_ai_backfill_candidates'] = array(
    array('post_id' => 85, 'kind' => 'alt', 'title' => 'Linen shirt', 'image' => $backfill_image),
);
backfill_clear();
skyphusion_ai_backfill_run(false);
expect('the site budget still stops a backfill call', $GLOBALS['cfais_calls'], 0);
expect('the site budget still does not write', get_post_meta(85, skyphusion_ai_backfill_alt_key(), true), '');
$backfill_skips = skyphusion_ai_backfill_config();
$backfill_budget_reason = '';
foreach ($backfill_skips['skips'] as $backfill_row) {
    if (85 === (int) $backfill_row['post_id']) {
        $backfill_budget_reason = $backfill_row['reason'];
    }
}
expect('the site budget is still the recorded reason', $backfill_budget_reason, 'budget');

unset($GLOBALS['cfais_settings']);
unset($GLOBALS['cfais_bodies']);
unset($GLOBALS['skyphusion_ai_backfill_candidates']);
unset($GLOBALS['skyphusion_ai_backfill_seo']);
unset($GLOBALS['skyphusion_ai_backfill_aioseo']);
unset($GLOBALS['cfais_options']['skyphusion_ai_backfill']);
unset($GLOBALS['skyphusion_ai_backfill_urls']);
unset($GLOBALS['skyphusion_ai_backfill_files']);
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
