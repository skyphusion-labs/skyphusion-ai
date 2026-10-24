<?php
/**
 * Three cold paths, measured cold rather than guessed at. Issue #3.
 *
 * Loaded by tests/endpoint-test.php, last, after every other test file, so the
 * environment variable this file sets and restores cannot change what an
 * earlier file reads.
 *
 * WHY THESE THREE AND NOT A COVERAGE SWEEP. The harness was measured with pcov
 * before anything was written here: 602 of 1123 executable lines in plugin
 * code, 53.6%, and 37 of 91 declared functions never executed. Picking the
 * three largest numbers would have produced tests of WordPress render
 * callbacks that need a bootstrap this harness deliberately is not. These
 * three were picked because each one guards a DEFECT somebody can actually
 * ship, and each was cold:
 *
 *   skyphusion_ai_cli_truthy()        a flag parser, 1 of 2 cold in cli.php
 *   skyphusion_ai_token_source_now()  cold, and see below
 *   cloudflare_ai_provider_connector_key()   cold, and must agree with the above
 *   skyphusion_ai_stock_allowed_html() cold, and see below
 *
 * A test added to raise a coverage number and incapable of failing is worse
 * than the gap it filled, so every expectation here was driven red once by
 * planting a wrong value before this file was committed.
 */

/*
 * ------------------------------------------------------------------ 1 of 3
 * The CLI flag parser.
 *
 * `skyphusion_ai_cli_truthy` decides what `--flag=value` MEANS. It
 * lowercases, trims, and compares against a four-element allowlist with
 * in_array's strict flag. A change to that set silently changes what the
 * command does for an operator typing a value that used to work, and there is
 * no error path: an unrecognised value is simply false.
 */
expect('cli truthy: one', skyphusion_ai_cli_truthy('1'), true);
expect('cli truthy: true', skyphusion_ai_cli_truthy('true'), true);
expect('cli truthy: yes', skyphusion_ai_cli_truthy('yes'), true);
expect('cli truthy: on', skyphusion_ai_cli_truthy('on'), true);
expect('cli truthy is case-insensitive', skyphusion_ai_cli_truthy('TRUE'), true);
expect('cli truthy trims', skyphusion_ai_cli_truthy("  on\t"), true);
expect('cli truthy: zero is false', skyphusion_ai_cli_truthy('0'), false);
expect('cli truthy: false is false', skyphusion_ai_cli_truthy('false'), false);
expect('cli truthy: no is false', skyphusion_ai_cli_truthy('no'), false);
expect('cli truthy: off is false', skyphusion_ai_cli_truthy('off'), false);
expect('cli truthy: empty is false', skyphusion_ai_cli_truthy(''), false);
expect('cli truthy: null is false', skyphusion_ai_cli_truthy(null), false);
/*
 * THE TWO THAT MATTER MOST, because they are the ones a looser comparison
 * would let through. `in_array($v, array(...), true)` is strict, so a
 * substring or a near-miss is false; dropping the strict flag or switching to
 * a loose truthiness test would make both of these true.
 */
expect('cli truthy: a near-miss is false', skyphusion_ai_cli_truthy('truex'), false);
expect('cli truthy: an arbitrary number is false', skyphusion_ai_cli_truthy('2'), false);
/*
 * THE INPUT THAT ACTUALLY PINS THE STRICT FLAG, and it took a mutation that
 * stayed GREEN to find it. Removing `true` from the `in_array` call left this
 * file passing, because on PHP 8 a loose comparison of two non-numeric strings
 * is a string comparison, so `truex` and `2` are false either way: the mutated
 * code did not exhibit the defect.
 *
 * `1.0` is the discriminator. Loose, both sides are numeric strings and
 * `1.0 == 1` is TRUE; strict, the string `1.0` is not in the array and it is
 * FALSE. Measured on PHP 8.5.12 before this line was written. It is also a
 * plausible thing for an operator to type.
 */
expect('cli truthy: a decimal one is false', skyphusion_ai_cli_truthy('1.0'), false);

/*
 * ------------------------------------------------------------------ 2 of 3
 * The two functions that must name the same token source.
 *
 * `includes/provider.php` says it in its own comment: *"The settings screen
 * reports this same decision via skyphusion_ai_token_source(). A change
 * to either function has to land in both, or the screen will name a source
 * this function does not return."* That is a stated invariant with nothing
 * asserting it, and BOTH functions were measured cold.
 *
 * WHY token-source-test.php DECLINED THIS, and why it is safe here. That file
 * says it does not call `token_source_now()` because *"that reads the process
 * environment, and a machine that exports CLOUDFLARE_API_KEY would make the
 * result depend on the machine."* The objection is correct and the repo
 * already solves it one file over: `provider-test.php` sets a variable with
 * `putenv('VAR=value')`, asserts, then clears it with `putenv('VAR')`. Setting
 * the variable this test reads is what makes the result independent of the
 * machine, because the ambient value no longer decides the answer.
 *
 * BOTH BRANCHES ARE DRIVEN, and the second one is here because a precondition
 * assertion in the first draft of this file FAILED and taught me something.
 *
 * I had written that only the environment branch was reachable, on the
 * reasoning that `connector_key()` calls `get_option()` UNGUARDED where
 * `token_source_now()` guards the same call with `function_exists`, so the
 * fall-through would fatal in a harness that is not a WordPress bootstrap.
 * That reasoning about the asymmetry is correct and the conclusion was wrong:
 * `settings-sanitize-test.php` already defines a `get_option` stand-in, which
 * returns the default for any key it does not recognise. So the path does not
 * fatal, and both functions read the SAME option key
 * (`connectors_ai_cloudflare_api_key`), which makes the fall-through
 * deterministic rather than dependent on a sibling file's globals.
 *
 * The assertion that caught it is kept below, inverted to assert the state
 * that actually holds. A precondition worth writing is one that can fail.
 *
 * THE ASYMMETRY ITSELF IS NOT FIXED HERE and is reported on issue #3: in
 * WordPress `get_option` always exists, so the missing guard is a testability
 * difference rather than a production defect, and adding a guard to shipped
 * code to suit a harness is not a change this file should make.
 */
$cold_path_saved_key = getenv('CLOUDFLARE_API_KEY');

/*
 * PRECONDITIONS, asserted so neither expectation below can pass vacuously.
 * A defined constant would change which branch runs, and an existing
 * `get_option` would change what the stored value is.
 */
expect(
    'the token constant is undefined, so the environment branch is the one under test',
    defined('CLOUDFLARE_API_KEY'),
    false
);
expect(
    'a get_option stand-in is present, so the fall-through branch runs rather than fatalling',
    function_exists('get_option'),
    true
);
expect(
    'and it reports no stored token, so the fall-through is deterministic',
    get_option('connectors_ai_cloudflare_api_key', ''),
    ''
);

$cold_path_token = 'cold-path-sentinel-token';
putenv('CLOUDFLARE_API_KEY=' . $cold_path_token);

expect(
    'the screen names the environment as the source',
    skyphusion_ai_token_source_now(),
    'env'
);
expect(
    'and the provider returns the value from that same source',
    cloudflare_ai_provider_connector_key(),
    $cold_path_token
);
/*
 * THE AGREEMENT ITSELF, as one assertion rather than two readings a reader has
 * to compare. If `connector_key()` ever ranked the constant or the option
 * above the environment, this fails even where both expectations above were
 * individually updated to match a new behaviour.
 */
expect(
    'the label and the value agree about which source won',
    skyphusion_ai_token_source_now() === 'env'
        && cloudflare_ai_provider_connector_key() === getenv('CLOUDFLARE_API_KEY'),
    true
);

/*
 * ------------------------------------------------------- the other branch
 * Nothing set anywhere: the label says none and the value is empty.
 *
 * This is `connector_key()`'s fall-through, the part that reads the option,
 * and `token_source_now()`'s `none`. Clearing the variable rather than setting
 * it is what makes this branch run, and the constant is asserted undefined
 * above, so there is no third source to fall through to.
 */
putenv('CLOUDFLARE_API_KEY');

expect(
    'with nothing set the screen names no source',
    skyphusion_ai_token_source_now(),
    'none'
);
expect(
    'and the provider returns an empty value rather than a false or a null',
    cloudflare_ai_provider_connector_key(),
    ''
);
expect(
    'the label and the value agree that nothing is set',
    skyphusion_ai_token_source_now() === 'none'
        && cloudflare_ai_provider_connector_key() === '',
    true
);

/*
 * RESTORED, not left set. `putenv` with no `=` clears the variable, which is
 * what provider-test.php does. Restoring a value that was present keeps this
 * file side-effect free for anything that runs after it.
 */
if (is_string($cold_path_saved_key)) {
    putenv('CLOUDFLARE_API_KEY=' . $cold_path_saved_key);
} else {
    putenv('CLOUDFLARE_API_KEY');
}

/*
 * ------------------------------------------------------------------ 3 of 3
 * The stock markup and the allowlist that filters it must not drift apart.
 *
 * `skyphusion_ai_stock_allowed_html()` is the tag allowlist the stock
 * section is passed through, and it was measured cold. Nothing asserted that
 * the markup the plugin actually emits survives it.
 *
 * THE DEFECT THIS CATCHES IS SILENT. Add a tag to the markup without adding it
 * to the allowlist and `wp_kses` strips it in production: no error, no warning,
 * no failing test, just a storefront section that renders wrong. The reverse
 * drift is harmless, so this asserts one direction only.
 *
 * The rows are the same fixture shape `stock-test.php` drives, so the markup
 * under test is real output and not a hand-written string.
 */
$cold_path_markup = skyphusion_ai_stock_html(array(
    array(
        'name' => 'Cold Path Widget',
        'stock_status' => 'instock',
        'stock_quantity' => 4,
    ),
    array(
        'name' => 'Second Widget',
        'stock_status' => 'outofstock',
        'stock_quantity' => 0,
    ),
));

/*
 * NOT VACUOUS, asserted first. An empty markup string would make the tag
 * comparison below pass over nothing, which is the shape this whole issue is
 * about: a check that cannot observe its subject reports the reassuring
 * answer.
 */
expect('the stock fixture produced markup', $cold_path_markup !== '', true);

$cold_path_tags = array();
preg_match_all('/<([a-z][a-z0-9]*)\b/i', (string) $cold_path_markup, $cold_path_found);
foreach ((array) $cold_path_found[1] as $cold_path_tag) {
    $cold_path_tags[strtolower((string) $cold_path_tag)] = true;
}
$cold_path_tags = array_keys($cold_path_tags);
sort($cold_path_tags);

expect(
    'and it produced more than one distinct tag, so the comparison has something to compare',
    count($cold_path_tags) >= 2,
    true
);

$cold_path_allowed = skyphusion_ai_stock_allowed_html();
$cold_path_unpermitted = array();
foreach ($cold_path_tags as $cold_path_tag) {
    if (!array_key_exists($cold_path_tag, $cold_path_allowed)) {
        $cold_path_unpermitted[] = $cold_path_tag;
    }
}

expect(
    'every tag the stock markup emits is permitted by the allowlist that filters it',
    $cold_path_unpermitted,
    array()
);

/*
 * ------------------------------------------------------------ one structural
 * The two token functions must read the SAME option key.
 *
 * This is a source-text check and it is labelled as one rather than dressed up
 * as behaviour. A behavioural version was attempted first and could not be
 * made to fail: pointing `token_source_now()` at a different option key left
 * every assertion above passing, because the stand-in returns a non-string for
 * the substituted key and `token_source()` treats a non-string as absent, so
 * the label did not move. Reaching the behaviour would mean writing into a
 * fixture global that `settings-sanitize-test.php` owns, from a file that runs
 * after it, which trades a real drift guard for a cross-file coupling.
 *
 * So the invariant is asserted as what it is. The claim is about the text:
 * both files name one key, and if either is edited to name another this fails.
 * It cannot see a renamed key changed in BOTH places, which is a deliberate
 * change rather than a drift, and that is the limit of a text check.
 *
 * `endpoint-test.php` already reads its own source to check its require lines,
 * so a structural assertion is an established shape in this harness.
 */
$cold_path_option_key = "'connectors_ai_cloudflare_api_key'";
$cold_path_settings_src = (string) file_get_contents(dirname(__DIR__) . '/includes/settings.php');
$cold_path_provider_src = (string) file_get_contents(dirname(__DIR__) . '/includes/provider.php');

expect(
    'includes/settings.php names the connector option key',
    strpos($cold_path_settings_src, $cold_path_option_key) !== false,
    true
);
expect(
    'includes/provider.php names the SAME connector option key',
    strpos($cold_path_provider_src, $cold_path_option_key) !== false,
    true
);
