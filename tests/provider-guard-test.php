<?php
/**
 * The provider's absent-dependency guards, and the one promise its docblock
 * makes about the token. Issue #3, the provider.php piece of the split.
 *
 * Loaded by tests/endpoint-test.php after lead-guard-test.php, whose
 * stand-ins this file uses rather than defining its own: WP_Error,
 * is_wp_error, wp_remote_post (which COUNTS calls), wp_json_encode and
 * add_action all already exist by the time this runs. Defining a second set
 * would win or lose on function_exists depending on load order, which is the
 * trap the harness header already describes for the translation stand-ins.
 *
 * WHY THESE THREE FUNCTIONS. Measured with pcov on current main, they were the
 * only cold functions left in includes/provider.php: boot() 12 executable
 * lines, embeddings() 24, register() 7. Nothing here stubs the WordPress AI
 * Client, and that is the point rather than a shortcut: all three are GUARD
 * CHAINS whose interesting branch is the one where the SDK is ABSENT. That is
 * also the branch a site actually hits, because the AI Client is optional, and
 * a plugin that fatals when an optional dependency is missing white-screens
 * the site it is installed on.
 *
 * Stubbing the SDK to reach the other branch was considered and declined: a
 * stub of a third-party class hierarchy encodes an assumption about an
 * external package, and when that package changes shape the test keeps
 * passing against a shape the SDK no longer has. includes/provider-models.php
 * stays uncovered for the same reason, recorded in its own header.
 */

/*
 * -------------------------------------------------------------- preconditions
 * Both classes must be ABSENT for any of the guard assertions below to mean
 * what they say. If the AI Client is ever vendored into this repo these fail
 * loudly instead of quietly testing the other branch.
 */
expect(
    'the WordPress AI Client is absent, so the guard branch is the one under test',
    class_exists('WordPress\\AiClient\\AiClient'),
    false
);
expect(
    'the provider class is absent too',
    class_exists('Cloudflare_AI_Provider'),
    false
);

/*
 * ------------------------------------------------------------------ 1. boot()
 * With the AI Client absent, boot() must return before it requires
 * provider-models.php.
 *
 * THAT FILE IS THE WHOLE RISK. It declares signatures against ModelMetadata
 * and AbstractApiBasedModel, types that only exist when the SDK does, so
 * pulling it in on a site without the SDK is how this plugin would break a
 * dashboard. get_included_files() is the instrument: PHP's own record of what
 * was required, rather than a reading of the require line.
 *
 * TWO LIMITS, both measured rather than guessed.
 *
 * It cannot see whether boot() registered an action. The add_action stand-in
 * in lead-guard-test.php is a no-op that records nothing, and redefining it
 * here would either lose on function_exists or change what an earlier file
 * sees.
 *
 * AND THE ASSERTION BELOW IS NOT WHAT CATCHES THE MUTATION. Removing both
 * guards makes the require run, and requiring provider-models.php on a machine
 * without the SDK is a FATAL, so PHP dies before this expect() is reached. The
 * run exits 255 rather than 1 and no FAIL line is printed. So the evidence is
 * reachability, not a comparison: this case proves the require is unreachable
 * without the SDK, and the in_array check is what would catch a require that
 * loaded cleanly. Removing either guard ALONE is observably green here,
 * because they are layered; that is a property of the guards, not of this
 * file.
 */
$pg_before = get_included_files();
cloudflare_ai_provider_boot();
$pg_after = get_included_files();
$pg_added = array_values(array_diff($pg_after, $pg_before));

$pg_models = dirname(__DIR__) . '/includes/provider-models.php';
expect(
    'boot() does not require provider-models.php when the AI Client is absent',
    in_array($pg_models, $pg_after, true),
    false
);
expect(
    'and it requires nothing else either',
    $pg_added,
    array()
);

/*
 * --------------------------------------------------------------- 2. register()
 * Guarded the same way, and the honest description is narrower than it looks.
 * With the guard removed, the next line calls
 * WordPress\AiClient\AiClient::defaultRegistry() on a class that does not
 * exist, which is a FATAL: measured, the run exits 255 and this expect() never
 * executes. So this case detects that somebody dropped the dependency check,
 * and it detects it by PHP dying rather than by an assertion comparing
 * anything. That is weaker than a FAIL line and it is still worth having,
 * because the alternative is nothing noticing at all.
 */
$pg_register_returned = false;
cloudflare_ai_provider_register();
$pg_register_returned = true;
expect(
    'register() returns instead of fatalling when the provider class is absent',
    $pg_register_returned,
    true
);

/*
 * ------------------------------------------------------------- 3. embeddings()
 * Two refusals, and the second is the one that matters.
 *
 * `wp_remote_post` in lead-guard-test.php increments $GLOBALS['cfais_calls'],
 * so the call count IS the instrument for "did this reach the network". An
 * unconfigured plugin must refuse BEFORE the request, not after it.
 */
$pg_calls_before = isset($GLOBALS['cfais_calls']) ? (int) $GLOBALS['cfais_calls'] : 0;

$pg_not_a_list = cloudflare_ai_provider_embeddings('a string, not a list');
expect('embeddings() refuses a non-array', is_wp_error($pg_not_a_list), true);
expect(
    'and refuses it under the documented error code',
    $pg_not_a_list->code,
    'cloudflare_ai_embeddings'
);
/*
 * THE CODE ALONE CANNOT SAY WHICH BRANCH RAN. Both refusals in this function
 * carry 'cloudflare_ai_embeddings', so asserting the code would stay green if
 * the non-array guard were removed and the call fell through to the
 * not-configured refusal instead. The message is what identifies the branch.
 */
expect(
    'and it is the LIST refusal, not the configuration one',
    $pg_not_a_list->message,
    'Embeddings need a list of texts.'
);

/*
 * THE TOKEN SENTINEL. provider.php's docblock promises: "On failure the return
 * is a WP_Error and the message does not include the token." Nothing asserted
 * that. A sentinel in the environment plus a forced failure is what turns the
 * promise into a check, and it fails the day somebody interpolates the token
 * into a diagnostic message.
 *
 * The account id is empty in this harness, so run_url() yields an empty string
 * and the refusal fires before any request is built.
 */
$pg_saved_key = getenv('CLOUDFLARE_API_KEY');
$pg_sentinel = 'sentinel-token-must-not-appear-in-any-message';
putenv('CLOUDFLARE_API_KEY=' . $pg_sentinel);

$pg_unconfigured = cloudflare_ai_provider_embeddings(array('one text'));
expect('embeddings() refuses when it is not configured', is_wp_error($pg_unconfigured), true);
expect(
    'and it is the CONFIGURATION refusal, not the list one',
    $pg_unconfigured->message,
    'Add the Cloudflare account ID and API token before embedding text.'
);
/*
 * SUBSUMED TODAY, AND KEPT ON PURPOSE. The exact-message assertion above fails
 * on ANY change to that string, a leaked token included, so a mutation cannot
 * make this line fail on its own and it is redundant against today's code.
 *
 * It earns its place because of what happens NEXT. The day somebody rewords
 * that message they will update the equality assertion to match, and at that
 * moment this is the only line still asserting the docblock's actual promise:
 * "the message does not include the token". A mutation cannot demonstrate
 * that, because the scenario requires a human editing the test rather than the
 * shipped code, so it is written down instead of claimed as proven.
 */
expect(
    'the refusal message does NOT contain the token',
    strpos((string) $pg_unconfigured->message, $pg_sentinel),
    false
);

$pg_calls_after = isset($GLOBALS['cfais_calls']) ? (int) $GLOBALS['cfais_calls'] : 0;
expect(
    'and it refused BEFORE reaching the network, not after',
    $pg_calls_after,
    $pg_calls_before
);

if (is_string($pg_saved_key)) {
    putenv('CLOUDFLARE_API_KEY=' . $pg_saved_key);
} else {
    putenv('CLOUDFLARE_API_KEY');
}

/*
 * ------------------------------------------------- 4. the constant, out of process
 * The constant beats the saved option for the Connectors key.
 *
 * A CONSTANT CANNOT BE UNDEFINED, so this cannot run in the parent: defining
 * CLOUDFLARE_API_KEY here would change what every environment-branch assertion
 * above and in cold-path-test.php means. tests/connector-order.php is the same
 * shape tests/account-order.php already uses for the account-id ordering, and
 * it clears the environment itself so an ambient value on the machine cannot
 * decide the answer.
 */
$pg_order = shell_exec(
    escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg(__DIR__ . '/connector-order.php')
    . ' ' . escapeshellarg(dirname(__DIR__) . '/includes/provider.php')
);
expect(
    'the constant beats the saved option for the connector key',
    trim((string) $pg_order),
    'constant-value-wins'
);
