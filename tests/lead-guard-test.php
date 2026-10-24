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
        return array('url' => $url, 'args' => $args);
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
expect('the failure prints nothing again', skyphusion_ai_lead_answer('red bats'), null);
expect('and it was NOT re-attempted', $GLOBALS['cfais_calls'], 1);

// An empty answer is cached as a miss for the same reason.
cfais_reset();
$GLOBALS['cfais_answer'] = '';
expect('an empty answer prints nothing', skyphusion_ai_lead_answer('red bats'), null);
expect('attempted once', $GLOBALS['cfais_calls'], 1);
skyphusion_ai_lead_answer('red bats');
expect('and not re-attempted', $GLOBALS['cfais_calls'], 1);

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
