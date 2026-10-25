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

$GLOBALS['skyphusion_ai_insight_rows'] = $insight_rows;
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
