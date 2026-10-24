<?php
/**
 * includes/frontend.php is LOADED, so it has a coverage denominator, and its
 * hook callbacks are proven to resolve. Issue 3, the frontend load step.
 *
 * Loaded by tests/endpoint-test.php LAST, after every file that defines a
 * WordPress stand-in, because this one requires a plugin source rather than
 * only driving functions already in memory.
 *
 * WHY LOAD-ONLY, AS ITS OWN STEP. Measured with pcov before this file existed:
 * frontend.php was never required by the harness, so it had no coverage
 * DENOMINATOR at all rather than zero coverage, and pcov cannot count lines in
 * a file it never compiled. Any argument about whether to test the render
 * paths was therefore an argument about a file with no figures. Requiring it
 * produces the figures, and the twelve-stand-in question for the render paths
 * becomes arithmetic instead of speculation.
 *
 * ONE NEW STAND-IN, AND THAT WAS MEASURED RATHER THAN HOPED. Tokenising the
 * file found 37 distinct bare calls, of which 11 were already available here
 * and 13 were not. Only ONE of the missing 13 is reached at REQUIRE time:
 * add_shortcode, on the single add_shortcode line at the top level. The other
 * twelve are inside function bodies, so they are needed to DRIVE the render
 * paths and not to load the file. That is why this step costs one no-op.
 *
 * NOTHING HERE DRIVES A RENDER FUNCTION, deliberately. The twelve remaining
 * stand-ins include wp_kses, whose behaviour would become the subject of any
 * assertion about escaping, and six context predicates whose return values
 * select which branch runs and are therefore test DESIGN rather than setup.
 * Those are decided separately with the figures this step produces.
 */

/*
 * add_shortcode: the one stand-in. A no-op, like the add_action and add_filter
 * stand-ins the harness already carries, and guarded the same way so load
 * order cannot make this file fight an earlier one for the definition.
 */
if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback)
    {
    }
}

/*
 * ------------------------------------------------------------------- the load
 * THE ASSERTION IS THAT ONE STAND-IN IS ENOUGH, and it is falsifiable: a new
 * top-level call to any unstubbed WordPress function makes this require fatal,
 * which takes the whole run down with a non-zero exit. So this reds the day
 * frontend.php grows a load-time dependency, which on a real site is a load
 * order that white-screens the storefront.
 */
$fe_before = get_defined_functions();
$fe_before_map = array();
foreach ($fe_before['user'] as $fe_name) {
    $fe_before_map[$fe_name] = true;
}

require_once dirname(__DIR__) . '/includes/frontend.php';

$fe_after = get_defined_functions();
$fe_added = array();
foreach ($fe_after['user'] as $fe_name) {
    if (!isset($fe_before_map[$fe_name])) {
        $fe_added[] = $fe_name;
    }
}
sort($fe_added);

expect('frontend.php loads with one added stand-in and defines functions', count($fe_added) >= 13, true);

/*
 * ------------------------------------------------- the hook callbacks resolve
 * THE DEFECT THIS CATCHES IS SILENT AND IT IS NOT HYPOTHETICAL.
 *
 * frontend.php registers ten callbacks and every one of them names its
 * function AS A STRING: add_action('wp_body_open', 'skyphusion_ai_
 * render_bar', 5). A string is not a reference, so renaming the function
 * without editing the string is accepted by PHP, accepted by php -l, accepted
 * by phpcs, and breaks only when WordPress fires the hook on a live page.
 * Nothing in this repo checked that those strings resolve.
 *
 * This is a source-text read and it is labelled as one: the claim is about the
 * registration lines, so reading them is the instrument rather than a proxy
 * for it. endpoint-test.php already reads its own source to check its require
 * lines, so the shape is established here.
 */
$fe_src = (string) file_get_contents(dirname(__DIR__) . '/includes/frontend.php');
$fe_registered = array();
preg_match_all(
    '/add_(?:action|filter|shortcode)\(\s*\'[^\']*\'\s*,\s*\'([A-Za-z0-9_]+)\'/',
    $fe_src,
    $fe_hits
);
foreach ((array) $fe_hits[1] as $fe_callback) {
    $fe_registered[$fe_callback] = true;
}
$fe_registered = array_keys($fe_registered);
sort($fe_registered);

/*
 * NOT VACUOUS, asserted first. A regex that matched nothing would make the
 * resolution check below pass over an empty list, which is the shape this
 * whole issue is about: a check that cannot observe its subject reports the
 * reassuring answer. Ten registration lines were counted by hand on the file;
 * nine distinct callbacks, because render_lead is registered on two hooks.
 */
expect(
    'the registration lines were found, so the resolution check has subjects',
    count($fe_registered) >= 9,
    true
);

$fe_unresolved = array();
foreach ($fe_registered as $fe_callback) {
    if (!function_exists($fe_callback)) {
        $fe_unresolved[] = $fe_callback;
    }
}

expect(
    'every function frontend.php registers by string actually exists',
    $fe_unresolved,
    array()
);

/*
 * ------------------------------------------------------ the shortcode handler
 * Its callback is reached through add_shortcode rather than add_action, and the
 * stand-in above is a no-op that records nothing, so the registration cannot
 * be observed behaviourally from here. The string-resolution check above
 * covers it the same way it covers the other nine. Stated so the no-op does
 * not read as an assertion.
 */
expect(
    'the shortcode handler is one of the resolved callbacks',
    in_array('skyphusion_ai_shortcode', $fe_registered, true),
    true
);
