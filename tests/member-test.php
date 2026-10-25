<?php
/**
 * Member search. Loaded after assistant-test.php.
 * Logged-out visitors spend nothing.
 */

if (!function_exists('skyphusion_ai_member_results')) {
    require_once dirname(__DIR__) . '/includes/member.php';
}

$member_defaults = skyphusion_ai_member_config();
expect('member search starts with no roles', $member_defaults['roles'], array());
expect('member search does not lead by default', $member_defaults['lead'], 0);
expect('the member cache lasts one hour', skyphusion_ai_member_cache_ttl(), 3600);

$GLOBALS['cfais_user_id'] = 0;
$GLOBALS['skyphusion_ai_member_roles'] = array('customer');
$GLOBALS['cfais_calls'] = 0;
$member_out = skyphusion_ai_member_results('linen');
expect('a logged-out visitor is not searched', $member_out['state'], 'closed');
expect('a logged-out visitor makes no request', $GLOBALS['cfais_calls'], 0);
expect('the shortcode is empty for a logged-out visitor', skyphusion_ai_member_shortcode(array('query' => 'linen')), '');

$GLOBALS['cfais_user_id'] = 4;
$GLOBALS['skyphusion_ai_member_roles'] = array('subscriber');
skyphusion_ai_member_apply(array('roles' => 'customer'));
$GLOBALS['cfais_calls'] = 0;
$member_other = skyphusion_ai_member_results('linen');
expect('another role is not searched', $member_other['state'], 'closed');
expect('another role makes no request', $GLOBALS['cfais_calls'], 0);

$GLOBALS['skyphusion_ai_member_roles'] = array('customer');
expect('a chosen role may search', skyphusion_ai_member_allowed(), true);
expect('leading is off until it is saved', skyphusion_ai_member_lead_rows('linen'), array());

$member_prev_key = getenv('CLOUDFLARE_API_KEY');
$member_prev_account = getenv('CLOUDFLARE_ACCOUNT_ID');
putenv('CLOUDFLARE_API_KEY=sentinel-token-' . str_repeat('a', 30));
putenv('CLOUDFLARE_ACCOUNT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
if (!isset($GLOBALS['cfais_settings']) || !is_array($GLOBALS['cfais_settings'])) {
    $GLOBALS['cfais_settings'] = array();
}
$GLOBALS['cfais_settings']['account_id'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$GLOBALS['cfais_settings']['gateway_id'] = '';
embed_ready();
$GLOBALS['cfais_status'] = 200;
$GLOBALS['cfais_calls'] = 0;
$member_embed = '{"result":{"data":[[0.25,0.5]]}}';
$GLOBALS['cfais_bodies'] = array(
    $member_embed,
    $member_embed,
    '{"success":true,"result":{"matches":[{"id":"p6","score":0.5,"metadata":{"title":"Linen shirt","post_id":"6","url":"https://shop.example/linen"}}]}}',
);
$member_hit = skyphusion_ai_member_results('linen');
expect('a member search returns the title', $member_hit[0]['title'], 'Linen shirt');
expect('a member search returns the address', $member_hit[0]['url'], 'https://shop.example/linen');
expect('a member search calls the index', $GLOBALS['cfais_calls'], 2);
$member_url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
expect('a member search does not call the text model', strpos($member_url, 'llama') === false, true);
expect('a member search does not ask for a sentence', strpos($member_url, 'chat/completions') === false, true);
$member_html = skyphusion_ai_member_html($member_hit);
expect('the list names the page', strpos($member_html, 'Linen shirt') !== false, true);
expect('the list has no generated sentence', strpos($member_html, 'The shirt is linen') === false, true);

$GLOBALS['cfais_calls'] = 0;
$member_again = skyphusion_ai_member_results('linen');
expect('a repeated member search is remembered', $member_again[0]['title'], 'Linen shirt');
expect('a repeated member search makes no request', $GLOBALS['cfais_calls'], 0);
expect('the cache name hides the question', strpos(skyphusion_ai_member_cache_key('linen'), 'linen') === false, true);

$GLOBALS['cfais_user_id'] = 0;
$GLOBALS['cfais_calls'] = 0;
$member_after = skyphusion_ai_member_results('linen');
expect('a logged-out visitor still spends nothing', $member_after['state'], 'closed');
expect('a logged-out visitor still makes no request', $GLOBALS['cfais_calls'], 0);

$GLOBALS['cfais_user_id'] = 4;
skyphusion_ai_member_apply(array('lead' => '1'));
$member_led = skyphusion_ai_member_lead_rows('linen');
expect('a saved lead uses the remembered pages', $member_led[0]['title'], 'Linen shirt');

$GLOBALS['cfais_can']['manage_options'] = true;
ob_start();
skyphusion_ai_member_render();
$member_screen = (string) ob_get_clean();
expect('the screen names the shortcode', strpos($member_screen, 'skyphusion_ai_member_search') !== false, true);
expect('the screen says logged-out visitors spend nothing', strpos($member_screen, 'spend nothing') !== false, true);
expect('the screen has no address field', strpos($member_screen, 'REMOTE_ADDR') === false, true);

$member_src = (string) file_get_contents(dirname(__DIR__) . '/includes/member.php');
expect('member search does not read a remote address', strpos($member_src, 'REMOTE_ADDR') === false, true);
expect('member search does not generate an answer', strpos($member_src, 'skyphusion_ai_query') === false, true);
expect('member search can lead the normal search', strpos($member_src, 'pre_get_posts') !== false, true);
$member_uninstall = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
expect('uninstall removes the member search option', strpos($member_uninstall, 'skyphusion_ai_member') !== false, true);

if (false === $member_prev_key || '' === $member_prev_key) {
    putenv('CLOUDFLARE_API_KEY');
} else {
    putenv('CLOUDFLARE_API_KEY=' . $member_prev_key);
}
if (false === $member_prev_account || '' === $member_prev_account) {
    putenv('CLOUDFLARE_ACCOUNT_ID');
} else {
    putenv('CLOUDFLARE_ACCOUNT_ID=' . $member_prev_account);
}
unset($GLOBALS['cfais_user_id']);
unset($GLOBALS['skyphusion_ai_member_roles']);
unset($GLOBALS['cfais_options']['skyphusion_ai_member']);
unset($GLOBALS['cfais_can']['manage_options']);
unset($GLOBALS['skyphusion_ai_member_lead']);
