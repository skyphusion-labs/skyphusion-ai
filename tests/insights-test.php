<?php
/**
 * Search log. Loaded after related-test.php. Rows stay on this site.
 * An IP address, an account, and a browser are not stored.
 */

if (!function_exists('skyphusion_ai_insight_apply')) {
    require_once dirname(__DIR__) . '/includes/insights.php';
}

$insight_now = 1700000000;
$insight_visitor = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

function insight_event($words, $extra = array())
{
    global $insight_now, $insight_visitor;
    return array_merge(array(
        'words' => $words,
        'results' => 3,
        'logged_in' => false,
        'outcome' => 'ai',
        'visitor' => $insight_visitor,
        'bot' => false,
        'now' => $insight_now,
    ), $extra);
}

$insight_rows = skyphusion_ai_insight_apply(array(), insight_event('linen shirt', array(
    'results' => 0,
    'logged_in' => true,
    'ip' => '203.0.113.8',
    'user_id' => 4,
    'user_agent' => 'Mozilla/5.0',
)));
expect('one search is stored', count($insight_rows), 1);
expect('the words are stored', $insight_rows[0]['words'], 'linen shirt');
expect('the time is stored', $insight_rows[0]['searched_at'], $insight_now);
expect('a miss stores a zero count', $insight_rows[0]['results'], 0);
expect('logged in is a flag', $insight_rows[0]['logged_in'], 1);
expect('the outcome flag is kept', $insight_rows[0]['outcome'], 'ai');
expect('an address is not a stored key', isset($insight_rows[0]['ip']), false);
expect('an account is not a stored key', isset($insight_rows[0]['user_id']), false);
expect('a browser is not a stored key', isset($insight_rows[0]['user_agent']), false);
expect('the visitor token is not stored', isset($insight_rows[0]['visitor']), false);
expect('the dedupe value is not the visitor token', $insight_rows[0]['dedupe'] === $insight_visitor, false);

$insight_again = skyphusion_ai_insight_apply($insight_rows, insight_event('linen shirt', array(
    'now' => $insight_now + 10,
)));
expect('the same visitor is skipped inside one hour', count($insight_again), 1);

$insight_other = skyphusion_ai_insight_apply($insight_rows, insight_event('linen shirt', array(
    'visitor' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    'now' => $insight_now + 10,
)));
expect('a different visitor is stored', count($insight_other), 2);

$insight_later = skyphusion_ai_insight_apply($insight_rows, insight_event('linen shirt', array(
    'now' => $insight_now + 3601,
)));
expect('the same visitor is stored after one hour', count($insight_later), 2);

$insight_bot = skyphusion_ai_insight_apply($insight_rows, insight_event('wool coat', array(
    'bot' => true,
)));
expect('a bot is skipped', count($insight_bot), 1);

$insight_empty = skyphusion_ai_insight_apply($insight_rows, insight_event('   '));
expect('empty words are skipped', count($insight_empty), 1);

$insight_bad = skyphusion_ai_insight_apply(array(), insight_event('hats', array(
    'outcome' => 'what is a hat',
    'logged_in' => false,
)));
expect('a free text outcome is not stored', $insight_bad[0]['outcome'], '');
expect('logged out is zero', $insight_bad[0]['logged_in'], 0);

$insight_long = skyphusion_ai_insight_apply(array(), insight_event(str_repeat('a', 250)));
expect('words are cut at the cap', strlen($insight_long[0]['words']), skyphusion_ai_insight_word_cap());

$insight_old = array(array(
    'words' => 'old query',
    'searched_at' => $insight_now - skyphusion_ai_insight_keep_seconds() - 1,
    'results' => 0,
    'logged_in' => 0,
    'outcome' => '',
    'dedupe' => 'old',
    'ip' => '198.51.100.4',
));
$insight_fresh = skyphusion_ai_insight_apply($insight_old, insight_event('new query'));
expect('a row older than 90 days is removed', count($insight_fresh), 1);
expect('the new words replace the expired row', $insight_fresh[0]['words'], 'new query');
expect('an old address is not kept', isset($insight_fresh[0]['ip']), false);

$insight_kept_old = array(array(
    'words' => 'still here',
    'searched_at' => $insight_now - skyphusion_ai_insight_keep_seconds() + 10,
    'results' => 2,
    'logged_in' => 0,
    'outcome' => 'ai',
    'dedupe' => hash('sha256', "other\nstill here"),
));
$insight_both = skyphusion_ai_insight_apply($insight_kept_old, insight_event('new query'));
expect('a row inside 90 days stays', count($insight_both), 2);

$insight_full = array();
for ($insight_i = 0; $insight_i < skyphusion_ai_insight_daily_cap(); $insight_i++) {
    $insight_full = skyphusion_ai_insight_apply($insight_full, insight_event('query ' . $insight_i, array(
        'visitor' => sprintf('%032x', $insight_i + 1),
    )));
}
expect('the daily cap is filled', count($insight_full), skyphusion_ai_insight_daily_cap());
$insight_capped = skyphusion_ai_insight_apply($insight_full, insight_event('one more', array(
    'visitor' => 'cccccccccccccccccccccccccccccccc',
)));
expect('the daily cap stops another row', count($insight_capped), skyphusion_ai_insight_daily_cap());

expect('a browser name is a bot', skyphusion_ai_insight_is_bot('Mozilla/5.0 Googlebot/2.1'), true);
expect('curl is a bot', skyphusion_ai_insight_is_bot('curl/8.0'), true);
expect('a normal browser is not a bot', skyphusion_ai_insight_is_bot('Mozilla/5.0'), false);
expect('an empty agent is not a bot', skyphusion_ai_insight_is_bot(''), false);

$GLOBALS['skyphusion_ai_insight_rows'] = array();
$insight_captured = skyphusion_ai_insight_capture(insight_event('captured miss', array('results' => 0)));
expect('capture writes the stand-in', $insight_captured[0]['words'], 'captured miss');
expect('the stand-in holds the row', $GLOBALS['skyphusion_ai_insight_rows'][0]['words'], 'captured miss');

unset($GLOBALS['skyphusion_ai_insight_rows']);
skyphusion_ai_insight_capture(insight_event('option miss', array('results' => 0)));
$insight_option = get_option('skyphusion_ai_insights', array());
expect('the option holds the words', is_array($insight_option) && $insight_option[0]['words'] === 'option miss', true);
unset($GLOBALS['cfais_options']['skyphusion_ai_insights']);

$GLOBALS['cfais_can']['manage_options'] = false;
ob_start();
skyphusion_ai_insight_render();
$insight_hidden = (string) ob_get_clean();
expect('another role sees no list', $insight_hidden, '');

$insight_screen_rows = $insight_rows;
$insight_screen_rows[0]['searched_at'] = time();
$GLOBALS['skyphusion_ai_insight_rows'] = $insight_screen_rows;
$GLOBALS['cfais_can']['manage_options'] = true;
ob_start();
skyphusion_ai_insight_render();
$insight_screen = (string) ob_get_clean();
expect('the screen names the missed words', strpos($insight_screen, 'linen shirt') !== false, true);
expect('the screen says an address is not stored', strpos($insight_screen, 'An IP address is not stored.') !== false, true);
expect('the screen does not print the planted address', strpos($insight_screen, '203.0.113.8') === false, true);
expect('the screen does not print the visitor token', strpos($insight_screen, $insight_visitor) === false, true);

$insight_src = (string) file_get_contents(dirname(__DIR__) . '/includes/insights.php');
expect('the log does not read a remote address', strpos($insight_src, 'REMOTE_ADDR') === false, true);
expect('the screen checks manage_options', strpos($insight_src, 'manage_options') !== false, true);
$insight_uninstall = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
expect('uninstall removes the search log', strpos($insight_uninstall, 'skyphusion_ai_insights') !== false, true);
$insight_provider = (string) file_get_contents(dirname(__DIR__) . '/includes/provider.php');
expect('the outcome flag is kept for this request', strpos($insight_provider, 'skyphusion_ai_search_outcome_last') !== false, true);

unset($GLOBALS['skyphusion_ai_insight_rows']);
unset($GLOBALS['cfais_can']['manage_options']);
unset($GLOBALS['skyphusion_ai_search_outcome_last']);

function insight_gap_row($words, $extra = array())
{
    global $insight_now;
    return array_merge(array(
        'words' => $words,
        'searched_at' => $insight_now - 10,
        'results' => 0,
        'logged_in' => 0,
        'outcome' => '',
        'dedupe' => 'gap',
    ), $extra);
}

function insight_live_gap($words, $extra = array())
{
    global $insight_visitor;
    return array_merge(array(
        'words' => $words,
        'searched_at' => time(),
        'results' => 0,
        'logged_in' => 0,
        'outcome' => '',
        'dedupe' => 'live',
        'ip' => '203.0.113.8',
        'user_id' => 4,
        'user_agent' => 'Mozilla/5.0',
        'visitor' => $insight_visitor,
    ), $extra);
}

$GLOBALS['cfais_calls'] = 0;
expect('an empty list has no topic', skyphusion_ai_insight_gaps(array(), $insight_now), array());
expect('an empty list makes no request', $GLOBALS['cfais_calls'], 0);
$GLOBALS['skyphusion_ai_insight_rows'] = array();
expect('an empty stand-in has no topic', skyphusion_ai_insight_gaps(skyphusion_ai_insight_load(), $insight_now), array());
expect('an empty stand-in makes no request', $GLOBALS['cfais_calls'], 0);

$insight_same = skyphusion_ai_insight_gaps(array(
    insight_gap_row('Linen Shirt'),
    insight_gap_row('linen shirt'),
), $insight_now);
expect('the same words are one topic', count($insight_same), 1);
expect('the topic counts both searches', $insight_same[0]['count'], 2);
expect('the topic folds case', strtolower($insight_same[0]['words']), 'linen shirt');

$insight_two = skyphusion_ai_insight_gaps(array(
    insight_gap_row('Linen Shirt'),
    insight_gap_row('linen shirt'),
    insight_gap_row('wool coat'),
), $insight_now);
$insight_two_labels = array();
foreach ($insight_two as $insight_group) {
    $insight_two_labels[] = strtolower($insight_group['words']) . ':' . $insight_group['count'];
}
sort($insight_two_labels);
expect('the topics are linen and wool', $insight_two_labels, array('linen shirt:2', 'wool coat:1'));

$insight_spaces = skyphusion_ai_insight_gaps(array(
    insight_gap_row('linen shirt'),
    insight_gap_row('linen  shirt'),
), $insight_now);
expect('spaces stay exact', count($insight_spaces), 2);
expect('each spacing is one search', $insight_spaces[0]['count'] === 1 && $insight_spaces[1]['count'] === 1, true);

$insight_found = skyphusion_ai_insight_gaps(array(
    insight_gap_row('wool coat', array('results' => 3, 'outcome' => 'ai')),
    insight_gap_row('hats', array('results' => 2, 'outcome' => 'provider_error')),
    insight_gap_row('caps', array('results' => 2, 'outcome' => 'no_endpoint')),
    insight_gap_row('bags', array('results' => 2, 'outcome' => 'rate_limited')),
), $insight_now);
expect('a found page is not a topic', $insight_found, array());

$insight_miss_ai = skyphusion_ai_insight_gaps(array(
    insight_gap_row('linen shirt', array('outcome' => 'ai')),
), $insight_now);
expect('a miss stays a topic when the outcome is ai', count($insight_miss_ai) === 1 && $insight_miss_ai[0]['count'] === 1, true);
$insight_miss_blank = skyphusion_ai_insight_gaps(array(
    insight_gap_row('linen shirt', array('outcome' => '')),
), $insight_now);
expect('a miss stays a topic when the outcome is blank', count($insight_miss_blank) === 1 && $insight_miss_blank[0]['count'] === 1, true);

$insight_aged = skyphusion_ai_insight_gaps(array(array(
    'words' => 'old query',
    'searched_at' => $insight_now - skyphusion_ai_insight_keep_seconds() - 1,
    'results' => 0,
    'logged_in' => 0,
    'outcome' => '',
    'dedupe' => 'old',
)), $insight_now);
expect('a row older than 90 days is not a topic', $insight_aged, array());
expect('an old row makes no request', $GLOBALS['cfais_calls'], 0);
$insight_edge = skyphusion_ai_insight_gaps(array(
    insight_gap_row('edge query', array('searched_at' => $insight_now - skyphusion_ai_insight_keep_seconds())),
), $insight_now);
expect('a row at 90 days is not a topic', $insight_edge, array());
$insight_kept_gap = skyphusion_ai_insight_gaps(array(
    insight_gap_row('still here', array('searched_at' => $insight_now - skyphusion_ai_insight_keep_seconds() + 10)),
), $insight_now);
expect('a row inside 90 days is a topic', count($insight_kept_gap) === 1 && $insight_kept_gap[0]['words'] === 'still here', true);

expect('the repeat window stays one hour', skyphusion_ai_insight_repeat_seconds(), 3600);
expect('the daily cap stays 100', skyphusion_ai_insight_daily_cap(), 100);
expect('rows stay for 90 days', skyphusion_ai_insight_keep_seconds(), 90 * 86400);

if (!function_exists('skyphusion_ai_prompt_fill')) {
    require_once dirname(__DIR__) . '/includes/prompts.php';
}
expect('the prompt keeps the gaps marker', strpos(skyphusion_ai_prompt_definitions()['write_next']['default'], '{gaps}') !== false, true);
$insight_filled = skyphusion_ai_prompt_fill('write_next', array('gaps' => 'linen shirt'));
expect('the prompt receives the empty searches', strpos($insight_filled, 'linen shirt') !== false, true);
expect('the prompt fills the gaps marker', strpos($insight_filled, '{gaps}') === false, true);

$insight_prev_key = getenv('CLOUDFLARE_API_KEY');
$insight_prev_account = getenv('CLOUDFLARE_ACCOUNT_ID');
$insight_ask_token = 'sentinel-token-' . str_repeat('b', 30);
putenv('CLOUDFLARE_API_KEY=' . $insight_ask_token);
putenv('CLOUDFLARE_ACCOUNT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
$insight_prev_stored = isset($GLOBALS['cfais_stored']) ? $GLOBALS['cfais_stored'] : null;
$insight_stored = is_array($insight_prev_stored) ? $insight_prev_stored : array();
$insight_stored['account_id'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$insight_stored['gateway_id'] = '';
unset($insight_stored['tool_caps']);
$GLOBALS['cfais_stored'] = $insight_stored;
$GLOBALS['cfais_settings'] = array(
    'endpoint' => 'https://abc123.search.ai.cloudflare.com',
    'lead_search' => true,
    'account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'gateway_id' => '',
);
$insight_prev_neurons = isset($GLOBALS['cfais_options']['skyphusion_ai_neurons']) ? $GLOBALS['cfais_options']['skyphusion_ai_neurons'] : null;
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
$GLOBALS['cfais_status'] = 200;
$GLOBALS['cfais_transport_error'] = false;
$insight_reply = '{"choices":[{"message":{"content":"Write a linen page."}}],"usage":{"neurons":4}}';
$GLOBALS['cfais_bodies'] = array($insight_reply, $insight_reply);
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_can']['manage_options'] = true;
$GLOBALS['skyphusion_ai_insight_rows'] = array(insight_live_gap('linen shirt'));
$insight_answer = skyphusion_ai_insight_write_next();
expect('one ask returns the topic', $insight_answer, 'Write a linen page.');
expect('one ask makes one request', $GLOBALS['cfais_calls'], 1);
$insight_url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
expect('one ask calls the writing model', strpos($insight_url, skyphusion_ai_prompt_model()) !== false, true);
$insight_sent = isset($GLOBALS['cfais_last_post']['args']['body']) ? (string) $GLOBALS['cfais_last_post']['args']['body'] : '';
expect('one ask sends the empty search', stripos($insight_sent, 'linen shirt') !== false, true);
expect('one ask does not send an address', strpos($insight_sent, '203.0.113.8') === false, true);
expect('one ask does not send a browser', strpos($insight_sent, 'Mozilla') === false, true);
expect('one ask does not send the visitor', strpos($insight_sent, $insight_visitor) === false, true);
expect('one ask does not send an account', strpos($insight_sent, 'user_id') === false, true);
expect('one ask does not send the sentinel', strpos($insight_sent, $insight_ask_token) === false, true);
expect('the ask url does not include the sentinel', strpos($insight_url, $insight_ask_token) === false, true);
$insight_text_state = skyphusion_ai_neuron_state();
expect('one ask counts as text', (int) $insight_text_state['features']['text'] >= 1, true);
expect('one ask counts the writing tool', (int) $insight_text_state['tools']['text'] >= 1, true);

$insight_again_answer = skyphusion_ai_insight_write_next();
expect('a second ask returns the topic', $insight_again_answer, 'Write a linen page.');
expect('a second ask makes a second request', $GLOBALS['cfais_calls'], 2);

$GLOBALS['cfais_calls'] = 0;
ob_start();
skyphusion_ai_insight_render();
$insight_view = (string) ob_get_clean();
expect('the page view makes no request', $GLOBALS['cfais_calls'], 0);
expect('the page view offers the ask', strpos($insight_view, 'skyphusion_ai_insight_write_next') !== false, true);
expect('the page view shows the topic', strpos($insight_view, 'Write a linen page.') !== false, true);
expect('the page view does not show the sentinel', strpos($insight_view, $insight_ask_token) === false, true);

$GLOBALS['cfais_calls'] = 0;
$GLOBALS['skyphusion_ai_insight_rows'] = array(insight_live_gap('wool coat', array('results' => 3, 'outcome' => 'ai')));
$insight_hit_only = skyphusion_ai_insight_write_next();
expect('a found page makes no request', $GLOBALS['cfais_calls'], 0);
expect('a found page is not a model reply', is_wp_error($insight_hit_only), true);
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['skyphusion_ai_insight_rows'] = array();
$insight_none = skyphusion_ai_insight_write_next();
expect('an empty log makes no request', $GLOBALS['cfais_calls'], 0);
expect('an empty log is not a model reply', is_wp_error($insight_none), true);

$GLOBALS['skyphusion_ai_insight_rows'] = array(insight_live_gap('linen shirt'));
$GLOBALS['cfais_can']['manage_options'] = false;
$GLOBALS['cfais_calls'] = 0;
skyphusion_ai_insight_handle_write_next();
expect('another role makes no request', $GLOBALS['cfais_calls'], 0);
$insight_denied = skyphusion_ai_insight_write_next();
expect('another role cannot ask', is_wp_error($insight_denied), true);
expect('another role still makes no request', $GLOBALS['cfais_calls'], 0);
$GLOBALS['cfais_can']['manage_options'] = true;

update_option('skyphusion_ai_neurons', array(
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 10000,
    'estimated' => 0,
    'features' => array(),
    'mailed_80' => 1,
    'mailed_100' => 1,
), false);
$GLOBALS['cfais_calls'] = 0;
$insight_paused = skyphusion_ai_insight_write_next();
expect('a spent budget pauses the topic list', is_wp_error($insight_paused) && strpos($insight_paused->message, 'paused') !== false, true);
expect('a spent budget makes no topic request', $GLOBALS['cfais_calls'], 0);
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
$GLOBALS['cfais_stored']['tool_caps'] = array('text' => 1);
update_option('skyphusion_ai_neurons', array(
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 0,
    'estimated' => 0,
    'features' => array(),
    'tools' => array('text' => 1),
    'mailed_80' => 0,
    'mailed_100' => 0,
), false);
$GLOBALS['cfais_calls'] = 0;
$insight_tool = skyphusion_ai_insight_write_next();
expect('a spent writing limit stops the topic list', is_wp_error($insight_tool) && strpos($insight_tool->message, 'daily limit') !== false, true);
expect('a spent writing limit makes no topic request', $GLOBALS['cfais_calls'], 0);

expect('the ask checks the nonce when that function exists', strpos($insight_src, "function_exists( 'check_admin_referer' )") !== false, true);
expect('the ask uses the write next nonce', strpos($insight_src, "check_admin_referer( 'skyphusion_ai_insight_write_next' )") !== false, true);
expect('the ask is one admin post', substr_count($insight_src, 'admin_post_skyphusion_ai_insight_write_next'), 1);
expect('the ask is not open to logged out visitors', strpos($insight_src, 'admin_post_nopriv_skyphusion_ai_insight_write_next') === false, true);
expect('the page view still records on shutdown', strpos($insight_src, 'skyphusion_ai_insight_on_shutdown') !== false, true);
expect('uninstall removes the search log', strpos($insight_uninstall, 'skyphusion_ai_insights') !== false, true);

if (null === $insight_prev_stored) {
    unset($GLOBALS['cfais_stored']);
} else {
    $GLOBALS['cfais_stored'] = $insight_prev_stored;
}
if (null === $insight_prev_neurons) {
    unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
} else {
    $GLOBALS['cfais_options']['skyphusion_ai_neurons'] = $insight_prev_neurons;
}
unset($GLOBALS['cfais_settings']);
unset($GLOBALS['cfais_bodies']);
unset($GLOBALS['skyphusion_ai_insight_rows']);
unset($GLOBALS['cfais_can']['manage_options']);
unset($GLOBALS['cfais_store']['skyphusion_ai_write_next']);
if (false === $insight_prev_key || '' === $insight_prev_key) {
    putenv('CLOUDFLARE_API_KEY');
} else {
    putenv('CLOUDFLARE_API_KEY=' . $insight_prev_key);
}
if (false === $insight_prev_account || '' === $insight_prev_account) {
    putenv('CLOUDFLARE_ACCOUNT_ID');
} else {
    putenv('CLOUDFLARE_ACCOUNT_ID=' . $insight_prev_account);
}
