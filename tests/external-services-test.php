<?php
/**
 * The published position on the one real offload. Issue #16.
 *
 * The plugin's core feature loads EXECUTABLE JavaScript from a third-party
 * host at runtime, and the Plugin Check sniff meant to catch that cannot see
 * it: the host arrives from the database, and the only literals in the source
 * are a path fragment and a constant. So the clean report on that sniff comes
 * from the sniff being blind, NOT from the condition being absent.
 *
 * `readme.txt` is the reviewer-facing answer to that, and a paragraph nothing
 * checks is a paragraph that drifts. These assertions keep it true.
 *
 * THE ONE THAT EARNS ITS PLACE is the pin: `SKYPHUSION_AI_SNIPPET` is a
 * literal a human bumps, and the readme names the same build. Bump one without
 * the other and the published position describes a build the plugin no longer
 * loads. That is the straightedge#376 shape in a documentation file.
 */

$es_root = dirname(__DIR__);
$es_readme = (string) file_get_contents($es_root . '/readme.txt');

/** The `== External services ==` section only, not the whole file. */
function es_section($readme)
{
    $start = strpos($readme, '== External services ==');
    if ($start === false) {
        return '';
    }
    $next = strpos($readme, "\n== ", $start + 10);
    return $next === false ? substr($readme, $start) : substr($readme, $start, $next - $start);
}

$es_section = es_section($es_readme);
expect('readme.txt has an External services section', $es_section !== '', true);

// Scoped to the section. A whole-file search would pass on the word appearing
// anywhere, which is how a region check quietly stops checking a region.
expect(
    'the section says the plugin loads a JavaScript file',
    strpos($es_section, 'JavaScript file') !== false,
    true
);
expect(
    'and that the browser runs it',
    strpos($es_section, 'Your browser runs that file.') !== false,
    true
);
expect(
    'and names the file',
    strpos($es_section, 'search-snippet.es.js') !== false,
    true
);
expect(
    'and says no script loads before an endpoint is pasted',
    strpos($es_section, 'loads no script until you paste an endpoint') !== false,
    true
);

// THE DRIFT GUARD. The readme must name the build the plugin actually pins.
expect(
    'the readme names the pinned snippet build',
    strpos($es_section, 'build ' . SKYPHUSION_AI_SNIPPET) !== false,
    true
);

// CONTROLS. Without these the section checks pass for any text that happens to
// contain the words, and the pin check passes on a readme naming any build.
expect(
    'the section check can miss',
    strpos(es_section("== External services ==\nNothing here.\n"), 'search-snippet.es.js') !== false,
    false
);
expect(
    'an absent section reads as absent',
    es_section("== Installation ==\nnope\n"),
    ''
);
expect(
    'the section scan stops at the next heading',
    strpos(es_section("== External services ==\nA.\n\n== Installation ==\nsearch-snippet.es.js\n"), 'search-snippet.es.js') !== false,
    false
);
expect(
    'a readme naming the wrong build fails the pin check',
    strpos('pinned to snippet build 9.9.9.', 'build ' . SKYPHUSION_AI_SNIPPET) !== false,
    false
);
