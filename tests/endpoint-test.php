<?php
/**
 * Run: php tests/endpoint-test.php
 *
 * ABSPATH is defined here so includes/endpoint.php and includes/routes.php
 * can keep the direct-access guard. This is not a WordPress bootstrap.
 * Do not remove the define. Without it those files exit before the checks run.
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

/**
 * Translation stand-ins, defined BEFORE the sources are loaded.
 *
 * `includes/routes.php` carries `__()` calls since issue #15, and this
 * harness is not a WordPress bootstrap. Without these, a code path reaching a
 * translated string fatals here while working perfectly in production, which
 * is the worst direction for a harness to be wrong in.
 *
 * THESE ARE THE SAME STAND-INS `stock-test.php` USED TO DEFINE, moved up here
 * so they exist before any source is required, and reading the SAME globals
 * so that file still drives them. Defining a second, simpler passthrough here
 * would have won on `function_exists` and silently turned three of its
 * translation assertions into tests of an English passthrough. It did exactly
 * that on the first attempt.
 */
$GLOBALS['cfai_stock_test_strings'] = array();
$GLOBALS['cfai_stock_test_plural'] = null;

if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        $strings = $GLOBALS['cfai_stock_test_strings'];
        return isset($strings[$text]) ? $strings[$text] : $text;
    }
}
if (!function_exists('_n')) {
    function _n($single, $plural, $count, $domain = 'default')
    {
        if ((int) $count === 1) {
            return $single;
        }
        return $GLOBALS['cfai_stock_test_plural'] !== null
            ? $GLOBALS['cfai_stock_test_plural']
            : $plural;
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default')
    {
        return htmlspecialchars((string) __($text, $domain), ENT_QUOTES, 'UTF-8');
    }
}

require dirname(__DIR__) . '/includes/endpoint.php';
require dirname(__DIR__) . '/includes/routes.php';
// cli.php only DEFINES things at load; its add_command is guarded by
// `defined('WP_CLI')`, so requiring it here is safe and makes the accent
// colour resolver reachable without a WP-CLI process.
require dirname(__DIR__) . '/includes/cli.php';

$failures = 0;
$checks = 0;

function shown($value)
{
    if (is_string($value)) {
        return $value;
    }
    $json = json_encode($value);
    return $json === false ? gettype($value) : $json;
}

function expect($label, $actual, $expected)
{
    global $failures, $checks;
    $checks++;
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$label}\n  actual: " . shown($actual) . "\n  expected: " . shown($expected) . "\n");
        $failures++;
        return;
    }
    fwrite(STDOUT, "ok {$label}\n");
}

$good = 'https://abc123.search.ai.cloudflare.com/';
expect('lowercase host', cloudflare_ai_search_normalize_endpoint($good), 'https://abc123.search.ai.cloudflare.com');
expect('uppercase host', cloudflare_ai_search_normalize_endpoint('https://ABC123.search.ai.cloudflare.com'), 'https://abc123.search.ai.cloudflare.com');
expect('namespace host', cloudflare_ai_search_normalize_endpoint('https://ns-abc123.search.ai.cloudflare.com'), 'https://ns-abc123.search.ai.cloudflare.com');
expect('trailing space', cloudflare_ai_search_normalize_endpoint('  https://abc123.search.ai.cloudflare.com  '), 'https://abc123.search.ai.cloudflare.com');
expect('empty', cloudflare_ai_search_normalize_endpoint(''), '');
expect('http rejected', cloudflare_ai_search_normalize_endpoint('http://abc123.search.ai.cloudflare.com'), '');
expect('other host rejected', cloudflare_ai_search_normalize_endpoint('https://example.com'), '');
expect('path rejected', cloudflare_ai_search_normalize_endpoint('https://abc123.search.ai.cloudflare.com/search'), '');
expect('query rejected', cloudflare_ai_search_normalize_endpoint('https://abc123.search.ai.cloudflare.com/?q=1'), '');
expect('userinfo rejected', cloudflare_ai_search_normalize_endpoint('https://user:pass@abc123.search.ai.cloudflare.com'), '');
expect('snippet src', cloudflare_ai_search_snippet_src($good), 'https://abc123.search.ai.cloudflare.com/assets/v0.0.25/search-snippet.es.js');
expect('snippet src rejected', cloudflare_ai_search_snippet_src('https://example.com'), '');

require __DIR__ . '/provider-test.php';
require __DIR__ . '/external-services-test.php';
require __DIR__ . '/settings-sanitize-test.php';
require __DIR__ . '/token-source-test.php';
require __DIR__ . '/cli-accent-color-test.php';
require __DIR__ . '/stock-test.php';
// LAST on purpose: it defines WordPress stand-ins at global scope.
require __DIR__ . '/i18n-test.php';
require __DIR__ . '/lead-guard-test.php';
// LAST on purpose. cold-path-test.php sets CLOUDFLARE_API_KEY and restores
// it; running it last means no earlier file can read the value it sets.
require __DIR__ . '/cold-path-test.php';
// After cold-path-test.php: both set and restore CLOUDFLARE_API_KEY, and
// provider-guard-test.php asserts the constant is still undefined, which
// only holds while nothing in the parent run has defined it.
require __DIR__ . '/provider-guard-test.php';

/**
 * EVERY test file in this directory is actually required by this runner.
 *
 * A dropped `require` line removes a whole file's checks and the run still
 * exits 0, reporting "all passed" over a smaller set. Nothing else here can
 * see that: the remaining assertions are all still true.
 *
 * Measured rather than imagined. Deleting the `cli-accent-color-test.php`
 * line during a merge resolution gave exit 0 with 203 checks instead of 228.
 * A hand-resolved merge is exactly where that happens, because two adjacent
 * `require` lines look like two versions of one line.
 *
 * This is a denominator check: it fails when the set it runs over shrinks,
 * which is the one thing a passing suite cannot tell you.
 */
$runner_source = (string) file_get_contents(__FILE__);
$runner_missing = array();
foreach ((array) glob(__DIR__ . '/*-test.php') as $runner_path) {
    $runner_name = basename((string) $runner_path);
    if ($runner_name === basename(__FILE__)) {
        continue;
    }
    if (strpos($runner_source, "/" . $runner_name . "'") === false) {
        $runner_missing[] = $runner_name;
    }
}
expect('every test file in tests/ is required by this runner', $runner_missing, array());
expect(
    'and there are several of them, so that check is not vacuous',
    count((array) glob(__DIR__ . '/*-test.php')) >= 6,
    true
);

/*
 * A FLOOR ON THE ASSERTIONS, not only on the files. The two checks above
 * cover a dropped `require`: the file list is read from disk and compared to
 * this file's own source, so a whole file falling out of the run is caught.
 *
 * WHAT THEY CANNOT SEE is a file that is still required and no longer
 * asserts. Delete the bodies out of stock-test.php and both checks above stay
 * green: the file still exists, it is still required, and the remaining
 * assertions are all still true. The run reports "all passed" over a smaller
 * set, which is the one thing a passing suite cannot tell you.
 *
 * So the COUNT is pinned. 230 was measured on main at 63b5f27 with
 *   php tests/endpoint-test.php | grep -cE '^(ok|FAIL)'
 *
 * THE FLOOR EQUALS THAT READING AND IS NOT ONE HIGHER, which is not obvious
 * and the first draft got it wrong. `$checks` is incremented INSIDE expect(),
 * so the comparison below is evaluated while this assertion is still
 * uncounted: it sees 230, not the 231 the finished run reports. A floor of 231
 * failed on a tree with nothing wrong with it, which is the right direction
 * for a guard to be wrong in but still wrong.
 *
 * IT IS A FLOOR AND NOT AN EQUALITY on purpose. Adding tests must not red
 * this; only losing them. Lowering it is therefore a deliberate act that
 * shows up in a diff, which is what makes it a decision rather than a drift.
 */
expect(
    'the run executed its whole assertion set',
    $checks >= 230,
    true
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failed\n");
    exit(1);
}

fwrite(STDOUT, "all passed\n");
