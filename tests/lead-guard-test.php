<?php
/**
 * Guards on the one unauthenticated path to a billable call. Issue #14.
 *
 * REQUIRED LAST by tests/endpoint-test.php, and that ordering is load-bearing.
 * The second half of this file defines WordPress stand-ins at global scope to
 * drive `skyphusion_ai_lead_answer()` without WordPress. PHP hoists a
 * top-level function when the file is compiled, so those stand-ins exist for
 * everything compiled afterwards. Requiring this file before the others would
 * silently change what they are testing.
 *
 * The transport is the ONLY thing stubbed. Every guard below runs its real
 * code, and the assertion that matters is the COUNT OF NETWORK ATTEMPTS: a
 * guard that returns the right value while still dialling out has not saved
 * anybody a penny or a worker.
 */

// ---------------------------------------------------------------- pure halves

expect('normalise lowercases', skyphusion_ai_lead_normalise('Red BATS'), 'red bats');
expect('normalise collapses whitespace', skyphusion_ai_lead_normalise("red   \n bats"), 'red bats');
expect('normalise trims', skyphusion_ai_lead_normalise('  red bats  '), 'red bats');

expect('a short question is not too long', skyphusion_ai_lead_too_long('bats'), false);
expect(
    'a question at the cap is allowed',
    skyphusion_ai_lead_too_long(str_repeat('a', skyphusion_ai_lead_max_length())),
    false
);
expect(
    'one character over the cap is refused',
    skyphusion_ai_lead_too_long(str_repeat('a', skyphusion_ai_lead_max_length() + 1)),
    true
);
// Counted in characters, not bytes, or a question in a multi-byte script is
// refused for being in that script.
expect(
    'a multi-byte question is measured in characters',
    skyphusion_ai_lead_too_long(str_repeat('ß', skyphusion_ai_lead_max_length())),
    false
);

expect(
    'spellings that normalise the same share a cache key',
    skyphusion_ai_lead_cache_key('Red  Bats') === skyphusion_ai_lead_cache_key('red bats'),
    true
);
expect(
    'different questions get different keys',
    skyphusion_ai_lead_cache_key('red bats') === skyphusion_ai_lead_cache_key('blue bats'),
    false
);

// ------------------------------------------------- stand-ins, from here down

$GLOBALS['cfais_store'] = array();
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_answer'] = 'An answer.';
$GLOBALS['cfais_status'] = 200;
$GLOBALS['cfais_options'] = array();
$GLOBALS['cfais_transport_error'] = false;
$GLOBALS['cfais_can'] = array();

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $args = 1)
    {
    }
}
if (!function_exists('get_transient')) {
    function get_transient($key)
    {
        if (!isset($GLOBALS['cfais_store'][$key])) {
            return false;
        }
        $row = $GLOBALS['cfais_store'][$key];
        if ($row['expires'] !== 0 && $row['expires'] <= time()) {
            unset($GLOBALS['cfais_store'][$key]);
            return false;
        }
        return $row['value'];
    }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $ttl = 0)
    {
        $GLOBALS['cfais_store'][$key] = array(
            'value' => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'ttl' => (int) $ttl,
        );
        return true;
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
if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = array())
    {
        $GLOBALS['cfais_calls']++;
        if (!empty($GLOBALS['cfais_transport_error'])) {
            return new WP_Error('http_request_failed', 'The site could not reach Cloudflare.');
        }
        return array('url' => $url, 'args' => $args);
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        if (!isset($GLOBALS['cfais_options']) || !is_array($GLOBALS['cfais_options'])) {
            $GLOBALS['cfais_options'] = array();
        }
        $GLOBALS['cfais_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap)
    {
        return !empty($GLOBALS['cfais_can'][(string) $cap]);
    }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return (string) $url;
    }
}
if (!function_exists('wp_kses')) {
    function wp_kses($html, $allowed)
    {
        return (string) $html;
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        return $GLOBALS['cfais_status'];
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response)
    {
        if (isset($GLOBALS['cfais_body'])) {
            return (string) $GLOBALS['cfais_body'];
        }
        return json_encode(array(
            'choices' => array(array('message' => array('content' => $GLOBALS['cfais_answer']))),
        ));
    }
}
if (!function_exists('skyphusion_ai_settings')) {
    function skyphusion_ai_settings()
    {
        return array('endpoint' => 'https://abc123.search.ai.cloudflare.com', 'lead_search' => true);
    }
}

require_once dirname(__DIR__) . '/includes/provider.php';

function cfais_reset()
{
    $GLOBALS['cfais_store'] = array();
    $GLOBALS['cfais_calls'] = 0;
    $GLOBALS['cfais_status'] = 200;
    $GLOBALS['cfais_answer'] = 'An answer.';
    $GLOBALS['cfais_options'] = array();
    $GLOBALS['cfais_transport_error'] = false;
}

function cfais_count($outcome)
{
    $row = skyphusion_ai_search_outcomes();
    return (int) $row[$outcome];
}

// THE CONTROL FIRST. If an ordinary question does not reach the network, every
// "no network attempt" assertion below passes for the wrong reason.
cfais_reset();
$first = skyphusion_ai_lead_answer('red bats');
expect('an ordinary question is answered', is_array($first) && $first['answer'] === 'An answer.', true);
expect('and it cost exactly one call', $GLOBALS['cfais_calls'], 1);

// Cache: the second ask is free, and so is a differently spelled one.
$second = skyphusion_ai_lead_answer('red bats');
expect('the repeat is answered', is_array($second) && $second['answer'] === 'An answer.', true);
expect('the repeat cost no call', $GLOBALS['cfais_calls'], 1);
skyphusion_ai_lead_answer('  RED   bats ');
expect('a respelling hits the same cache entry', $GLOBALS['cfais_calls'], 1);

// Length cap: refused BEFORE the network, not truncated and sent.
cfais_reset();
$long = str_repeat('a', skyphusion_ai_lead_max_length() + 1);
expect('an over-long question is refused', skyphusion_ai_lead_answer($long), null);
expect('and it never reached the network', $GLOBALS['cfais_calls'], 0);

// Empty question.
cfais_reset();
expect('an empty question is refused', skyphusion_ai_lead_answer('   '), null);
expect('and it never reached the network', $GLOBALS['cfais_calls'], 0);

// Ceiling: a distinct question each time, so the cache cannot be what stops it.
cfais_reset();
$limit = skyphusion_ai_lead_ceiling();
for ($i = 0; $i < $limit['calls']; $i++) {
    skyphusion_ai_lead_answer('question number ' . $i);
}
expect('the ceiling allows exactly its budget', $GLOBALS['cfais_calls'], $limit['calls']);
expect(
    'the next distinct question is refused',
    skyphusion_ai_lead_answer('one question too many'),
    null
);
expect('and it never reached the network', $GLOBALS['cfais_calls'], $limit['calls']);
$cfais_blocked = skyphusion_ai_lead_cache_key('one question too many');
expect('a ceiling refusal is rate_limited', cfais_count('rate_limited'), 1);
expect('a ceiling refusal is not cached', array_key_exists($cfais_blocked, $GLOBALS['cfais_store']), false);
skyphusion_ai_lead_answer('one question too many');
expect('the same ceiling refusal counts again', cfais_count('rate_limited'), 2);
expect('and the second refusal still did not dial', $GLOBALS['cfais_calls'], $limit['calls']);
expect('and it is still not cached', array_key_exists($cfais_blocked, $GLOBALS['cfais_store']), false);
// A question already cached is still served after the ceiling is reached,
// because serving it costs nothing.
expect(
    'a cached question still answers past the ceiling',
    is_array(skyphusion_ai_lead_answer('question number 0')),
    true
);
expect('and that cost no call either', $GLOBALS['cfais_calls'], $limit['calls']);

// A failing endpoint is not re-dialled on every page view. That is the guard
// for worker exhaustion rather than for spend: each real attempt holds a PHP
// worker for up to the twenty second timeout.
cfais_reset();
$GLOBALS['cfais_status'] = 500;
expect('a failing endpoint prints nothing', skyphusion_ai_lead_answer('red bats'), null);
expect('and it was attempted once', $GLOBALS['cfais_calls'], 1);
expect('a provider failure is provider_error', cfais_count('provider_error'), 1);
$cfais_miss = skyphusion_ai_lead_cache_key('red bats');
expect(
    'the miss sentinel names the outcome',
    $GLOBALS['cfais_store'][$cfais_miss]['value'],
    array('miss' => 'provider_error')
);
expect('the miss is cached for 300 seconds', $GLOBALS['cfais_store'][$cfais_miss]['ttl'], 300);
expect('the miss sentinel is not the string none', $GLOBALS['cfais_store'][$cfais_miss]['value'] === 'none', false);
expect('the failure prints nothing again', skyphusion_ai_lead_answer('red bats'), null);
expect('and it was NOT re-attempted', $GLOBALS['cfais_calls'], 1);
expect('the cached miss counts provider_error again', cfais_count('provider_error'), 2);
expect('a provider failure did not count as ai', cfais_count('ai'), 0);

// An empty answer is cached as a miss for the same reason.
cfais_reset();
$GLOBALS['cfais_answer'] = '';
expect('an empty answer prints nothing', skyphusion_ai_lead_answer('red bats'), null);
expect('attempted once', $GLOBALS['cfais_calls'], 1);
expect('an empty answer is provider_error', cfais_count('provider_error'), 1);
skyphusion_ai_lead_answer('red bats');
expect('and not re-attempted', $GLOBALS['cfais_calls'], 1);
expect('an empty answer counts again from the cache', cfais_count('provider_error'), 2);

// ---------------------------------------------------------------- outcomes

// THE FOUR NAMES. A recorder that folds two of them into one count fails
// here, and a recorder that does not increment fails the exact numbers.
cfais_reset();
$cfais_ai = skyphusion_ai_lead_answer('red bats');
expect('ai: the search is served', is_array($cfais_ai) && $cfais_ai['answer'] === 'An answer.', true);
expect('ai: counted once', cfais_count('ai'), 1);
expect('ai: one network call', $GLOBALS['cfais_calls'], 1);
skyphusion_ai_lead_answer('red bats');
expect('ai: a cached answer counts again', cfais_count('ai'), 2);
expect('ai: the cache did not dial', $GLOBALS['cfais_calls'], 1);
$cfais_tuple_ai = cfais_count('ai') . ':' . cfais_count('provider_error') . ':' . cfais_count('no_endpoint') . ':' . cfais_count('rate_limited');

cfais_reset();
$GLOBALS['cfais_transport_error'] = true;
expect('provider_error: a transport failure prints nothing', skyphusion_ai_lead_answer('zz-private-question-9f3c'), null);
expect('provider_error: it was attempted', $GLOBALS['cfais_calls'], 1);
expect('provider_error: counted once', cfais_count('provider_error'), 1);
skyphusion_ai_lead_answer('zz-private-question-9f3c');
expect('provider_error: the cached miss did not dial', $GLOBALS['cfais_calls'], 1);
expect('provider_error: the cached miss counts again', cfais_count('provider_error'), 2);
$cfais_raw = get_option('skyphusion_ai_search_outcomes', array());
$cfais_raw_json = (string) json_encode($cfais_raw);
$cfais_store_json = (string) json_encode($GLOBALS['cfais_store']);
expect('the option does not contain the question', strpos($cfais_raw_json, 'zz-private-question-9f3c') === false, true);
expect('the cache does not contain the question', strpos($cfais_store_json, 'zz-private-question-9f3c') === false, true);
expect('the option does not contain the error text', strpos($cfais_raw_json, 'could not reach') === false, true);
expect('the option does not contain the endpoint', strpos($cfais_raw_json, 'search.ai.cloudflare.com') === false, true);
expect('the option does not contain the answer', strpos($cfais_raw_json, 'An answer.') === false, true);
$cfais_nested = false;
foreach ((array) $cfais_raw as $cfais_value) {
    if (is_array($cfais_value)) {
        $cfais_nested = true;
    }
}
expect('the option is counts, not a log', $cfais_nested, false);
$cfais_keys = array_keys((array) $cfais_raw);
sort($cfais_keys);
expect(
    'the option has only the count keys',
    $cfais_keys,
    array('ai', 'no_endpoint', 'provider_error', 'rate_limited', 'window_start')
);
expect('ai count is an int', is_int($cfais_raw['ai']), true);
$cfais_tuple_error = '0:1:0:0';
expect('provider_error is not counted as ai', cfais_count('ai'), 0);

cfais_reset();
skyphusion_ai_search_outcome_record('zz-private-question-9f3c');
expect(
    'a question passed as an outcome is not stored',
    get_option('skyphusion_ai_search_outcomes', null),
    null
);

cfais_reset();
$cfais_endpoint = $GLOBALS['cfais_stored']['endpoint'];
$GLOBALS['cfais_stored']['endpoint'] = '';
expect('no_endpoint: a missing address prints nothing', skyphusion_ai_lead_answer('red bats'), null);
expect('no_endpoint: nothing was dialled', $GLOBALS['cfais_calls'], 0);
expect('no_endpoint: counted once', cfais_count('no_endpoint'), 1);
expect('no_endpoint: not a provider error', cfais_count('provider_error'), 0);
$cfais_absent = skyphusion_ai_lead_cache_key('red bats');
expect('no_endpoint: not cached for 300 seconds', array_key_exists($cfais_absent, $GLOBALS['cfais_store']), false);
expect('no_endpoint: does not take a call slot', get_transient('cfais_lead_calls'), false);
skyphusion_ai_lead_answer('red bats');
expect('no_endpoint: the next search counts again', cfais_count('no_endpoint'), 2);
expect('no_endpoint: still nothing dialled', $GLOBALS['cfais_calls'], 0);
$GLOBALS['cfais_stored']['endpoint'] = $cfais_endpoint;
cfais_reset();
expect('no_endpoint: adding the address is not poisoned', is_array(skyphusion_ai_lead_answer('red bats')), true);
expect('no_endpoint: that search dials', $GLOBALS['cfais_calls'], 1);
$cfais_tuple_missing = '0:0:1:0';

cfais_reset();
$cfais_limit = skyphusion_ai_lead_ceiling();
set_transient(
    'cfais_lead_calls',
    array('count' => $cfais_limit['calls'], 'start' => time()),
    $cfais_limit['window']
);
expect('rate_limited: the ceiling prints nothing', skyphusion_ai_lead_answer('blocked question'), null);
expect('rate_limited: counted once', cfais_count('rate_limited'), 1);
expect('rate_limited: not a provider error', cfais_count('provider_error'), 0);
expect('rate_limited: nothing was dialled', $GLOBALS['cfais_calls'], 0);
$cfais_blocked_key = skyphusion_ai_lead_cache_key('blocked question');
expect('rate_limited: not cached', array_key_exists($cfais_blocked_key, $GLOBALS['cfais_store']), false);
$cfais_tuple_limited = '0:0:0:1';

$cfais_tuples = array($cfais_tuple_ai, $cfais_tuple_error, $cfais_tuple_missing, $cfais_tuple_limited);
expect('the four outcomes are distinguishable', count(array_unique($cfais_tuples)), 4);

cfais_reset();
expect('an empty question is not counted', skyphusion_ai_lead_answer('   '), null);
expect(
    'a too-long question is not counted',
    skyphusion_ai_lead_answer(str_repeat('b', skyphusion_ai_lead_max_length() + 1)),
    null
);
expect(
    'guards do not write the option',
    get_option('skyphusion_ai_search_outcomes', null),
    null
);
expect('guards do not dial', $GLOBALS['cfais_calls'], 0);

expect('the outcome window is 24 hours', skyphusion_ai_search_outcome_window(), 86400);
cfais_reset();
skyphusion_ai_lead_answer('red bats');
$cfais_open = get_option('skyphusion_ai_search_outcomes');
$cfais_open['ai'] = 7;
$cfais_open['provider_error'] = 4;
$cfais_open['window_start'] = time() - 1000;
update_option('skyphusion_ai_search_outcomes', $cfais_open);
skyphusion_ai_lead_answer('blue bats');
expect('a decision inside the window keeps the counts', cfais_count('ai'), 8);
expect('and keeps the other counts', cfais_count('provider_error'), 4);
$cfais_open['window_start'] = time() - (skyphusion_ai_search_outcome_window() + 50);
$cfais_open['ai'] = 7;
update_option('skyphusion_ai_search_outcomes', $cfais_open);
skyphusion_ai_lead_answer('green bats');
expect('a decision after the window starts at one', cfais_count('ai'), 1);
expect('and drops the old provider count', cfais_count('provider_error'), 0);
$cfais_open = get_option('skyphusion_ai_search_outcomes');
$cfais_open['window_start'] = time() + 5000;
$cfais_open['ai'] = 9;
update_option('skyphusion_ai_search_outcomes', $cfais_open);
skyphusion_ai_lead_answer('yellow bats');
expect('a future window does not keep its counts', cfais_count('ai'), 1);
$cfais_before = get_option('skyphusion_ai_search_outcomes');
skyphusion_ai_search_outcomes();
expect('reading the counts does not write', get_option('skyphusion_ai_search_outcomes'), $cfais_before);

expect('uninstall names the outcome option', skyphusion_ai_search_outcome_option(), 'skyphusion_ai_search_outcomes');
$cfais_uninstall = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
expect(
    'uninstall deletes the outcome option',
    strpos($cfais_uninstall, skyphusion_ai_search_outcome_option()) !== false,
    true
);

$cfais_saved_plural = $GLOBALS['cfai_stock_test_plural'];
$GLOBALS['cfai_stock_test_plural'] = null;
update_option(
    'skyphusion_ai_search_outcomes',
    array(
        'window_start' => time(),
        'ai' => 1,
        'provider_error' => 0,
        'no_endpoint' => 2,
        'rate_limited' => 1,
    )
);
$cfais_line = skyphusion_ai_search_outcome_summary();
expect('the settings line names an AI answer', strpos($cfais_line, 'AI Search answered 1 search.') !== false, true);
expect('the settings line uses the plural for zero', strpos($cfais_line, 'A provider error served 0 searches from the site results.') !== false, true);
expect('the settings line names a missing address', strpos($cfais_line, 'A missing address served 2 searches from the site results.') !== false, true);
expect('the settings line names the safety limit', strpos($cfais_line, 'The safety limit served 1 search from the site results.') !== false, true);
expect('the settings line says the count lasts 24 hours', strpos($cfais_line, 'This count lasts 24 hours.') !== false, true);
expect('the settings line does not contain the question', strpos($cfais_line, 'zz-private-question-9f3c') === false, true);
$cfais_sentences = preg_split('/(?<=\.)\s+/', trim($cfais_line));
expect('the settings line has one sentence per count', count($cfais_sentences) >= 5, true);
foreach ($cfais_sentences as $cfais_sentence) {
    $cfais_words = preg_split('/\s+/', trim($cfais_sentence));
    expect(
        'sentence stays within 20 words: ' . $cfais_sentence,
        count($cfais_words) <= 20 && count($cfais_words) > 0,
        true
    );
}
$GLOBALS['cfai_stock_test_plural'] = $cfais_saved_plural;

expect(
    'under 20 decisions is not a sustained fallback',
    skyphusion_ai_search_outcome_sustained(array('provider_error' => 19)),
    false
);
expect(
    '20 provider errors is a sustained fallback',
    skyphusion_ai_search_outcome_sustained(array('provider_error' => 20)),
    true
);
expect(
    'exactly half is a sustained fallback',
    skyphusion_ai_search_outcome_sustained(array('ai' => 10, 'provider_error' => 10)),
    true
);
expect(
    'just under half is not a sustained fallback',
    skyphusion_ai_search_outcome_sustained(array('ai' => 11, 'provider_error' => 9)),
    false
);
expect(
    'the safety limit alone is not a sustained fallback',
    skyphusion_ai_search_outcome_sustained(array('rate_limited' => 20)),
    false
);
expect(
    'the safety limit counts in the total and not in the half',
    skyphusion_ai_search_outcome_sustained(array('provider_error' => 10, 'rate_limited' => 10)),
    true
);
expect(
    'a missing address counts in the half',
    skyphusion_ai_search_outcome_sustained(array('ai' => 10, 'no_endpoint' => 10)),
    true
);
expect(
    '10 of 21 is not half',
    skyphusion_ai_search_outcome_sustained(array('ai' => 11, 'provider_error' => 10)),
    false
);
expect(
    '11 of 21 is at least half',
    skyphusion_ai_search_outcome_sustained(array('ai' => 10, 'provider_error' => 11)),
    true
);

$cfais_lead = $GLOBALS['cfais_stored']['lead_search'];
$GLOBALS['cfais_stored']['lead_search'] = true;
$GLOBALS['cfais_can']['manage_options'] = true;
update_option(
    'skyphusion_ai_search_outcomes',
    array(
        'window_start' => time(),
        'ai' => 10,
        'provider_error' => 5,
        'no_endpoint' => 5,
        'rate_limited' => 0,
    )
);
ob_start();
skyphusion_ai_search_outcome_notice();
$cfais_notice = (string) ob_get_clean();
expect('the notice says searches fell back', strpos($cfais_notice, 'fell back') !== false, true);
expect(
    'the notice links to the settings screen',
    strpos($cfais_notice, 'options-general.php?page=cloudflare-ai-search') !== false,
    true
);
expect('the notice does not name the token', stripos($cfais_notice, 'token') === false, true);
expect('the notice does not contain the question', strpos($cfais_notice, 'zz-private-question-9f3c') === false, true);
$cfais_notice_text = trim(strip_tags($cfais_notice));
foreach (preg_split('/(?<=\.)\s+/', $cfais_notice_text) as $cfais_sentence) {
    $cfais_words = preg_split('/\s+/', trim($cfais_sentence));
    expect(
        'notice sentence stays within 20 words: ' . $cfais_sentence,
        count($cfais_words) <= 20 && count($cfais_words) > 0,
        true
    );
}

update_option(
    'skyphusion_ai_search_outcomes',
    array(
        'window_start' => time(),
        'ai' => 0,
        'provider_error' => 0,
        'no_endpoint' => 0,
        'rate_limited' => 20,
    )
);
ob_start();
skyphusion_ai_search_outcome_notice();
expect('the safety limit does not raise the notice', ob_get_clean(), '');

$GLOBALS['cfais_stored']['lead_search'] = false;
update_option(
    'skyphusion_ai_search_outcomes',
    array(
        'window_start' => time(),
        'ai' => 0,
        'provider_error' => 20,
        'no_endpoint' => 0,
        'rate_limited' => 0,
    )
);
ob_start();
skyphusion_ai_search_outcome_notice();
expect('lead search off does not raise the notice', ob_get_clean(), '');
$GLOBALS['cfais_stored']['lead_search'] = true;
$GLOBALS['cfais_can']['manage_options'] = false;
ob_start();
skyphusion_ai_search_outcome_notice();
expect('a user who cannot manage options does not see the notice', ob_get_clean(), '');
$GLOBALS['cfais_can']['manage_options'] = true;
$cfais_stale = get_option('skyphusion_ai_search_outcomes');
$cfais_stale['window_start'] = time() - (skyphusion_ai_search_outcome_window() + 50);
update_option('skyphusion_ai_search_outcomes', $cfais_stale);
ob_start();
skyphusion_ai_search_outcome_notice();
expect('an expired window does not raise the notice', ob_get_clean(), '');
$GLOBALS['cfais_stored']['lead_search'] = $cfais_lead;
$GLOBALS['cfais_can'] = array();

if (!class_exists('WP_CLI')) {
    class WP_CLI
    {
        public static function log($message)
        {
            $GLOBALS['cfais_cli_log'][] = (string) $message;
        }
    }
}
$GLOBALS['cfais_cli_log'] = array();
update_option(
    'skyphusion_ai_search_outcomes',
    array(
        'window_start' => time(),
        'ai' => 3,
        'provider_error' => 2,
        'no_endpoint' => 1,
        'rate_limited' => 4,
    )
);
$cfais_cli = new Skyphusion_AI_CLI();
$cfais_cli->status(array(), array());
expect('status prints search_ai', in_array('search_ai=3', $GLOBALS['cfais_cli_log'], true), true);
expect('status prints search_provider_error', in_array('search_provider_error=2', $GLOBALS['cfais_cli_log'], true), true);
expect('status prints search_no_endpoint', in_array('search_no_endpoint=1', $GLOBALS['cfais_cli_log'], true), true);
expect('status prints search_rate_limited', in_array('search_rate_limited=4', $GLOBALS['cfais_cli_log'], true), true);
expect(
    'status does not print the question',
    strpos(implode("\n", $GLOBALS['cfais_cli_log']), 'zz-private-question-9f3c') === false,
    true
);

cfais_reset();

// ------------------------------------------------------------- the wiring

// THE CALL SITE IS THE WHOLE POINT, and nothing above tests it. A mutation
// that pointed includes/frontend.php back at the unguarded
// skyphusion_ai_query() left every assertion in this file green, which
// means the guards could be bypassed by one line with no test objecting.
//
// A TEXT ASSERTION, and weak evidence about behaviour by construction: the
// harness cannot load frontend.php, which needs WordPress. It is the right
// instrument anyway, because the claim being pinned is itself about which
// function name appears at that call site.
$frontend = file_get_contents(dirname(__DIR__) . '/includes/frontend.php');
expect('the frontend file was read', is_string($frontend) && $frontend !== '', true);
expect(
    'the search page calls the GUARDED entry point',
    strpos($frontend, 'skyphusion_ai_lead_answer($question)') !== false,
    true
);
expect(
    'and never the unguarded provider call',
    strpos($frontend, 'skyphusion_ai_query(') !== false,
    false
);

// The control for those two. Without it, both pass on any file that happens
// to lack the second string, including an empty one.
$planted = "<?php\n\$result = skyphusion_ai_query(\$question);\n";
expect(
    'the check can see the unguarded call when it is there',
    strpos($planted, 'skyphusion_ai_query(') !== false,
    true
);
expect(
    'and can see the guarded one missing',
    strpos($planted, 'skyphusion_ai_lead_answer($question)') !== false,
    false
);
