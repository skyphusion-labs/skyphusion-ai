<?php
/**
 * The translation surface, checked rather than assumed. Issue #15.
 *
 * Two things this asserts, and the first is the one that fails silently.
 *
 * 1. EVERY TRANSLATION CALL TAKES LITERALS. The WordPress string extractor
 *    reads SOURCE, so `__($text, 'skyphusion-ai')` resolves fine at runtime
 *    and is invisible to `makepot`. The string would look translated, the
 *    plugin would behave correctly in English, and the POT file would simply
 *    not contain it. Nothing at runtime can tell you that happened.
 *
 * 2. EVERY CALL USES THE DECLARED DOMAIN. A typo in the domain is the same
 *    failure wearing a different hat: the call is extracted into a project
 *    nobody translates.
 *
 * It also holds a per-file FLOOR, so a file that has been through the pass
 * cannot quietly lose its calls again. The floor is a denominator, not an
 * allowlist: a file absent from the list is simply not claimed to be done,
 * and the report prints every file either way.
 */

$i18n_root = dirname(__DIR__);

/** Files claimed to be translated, and the minimum each must carry. */
$i18n_floors = array(
    // 47 and not 41 once the settings screen names the token source.
    // Measured with this file's own i18n_calls() after that change. Six
    // sentences: the environment, the environment while a saved token is
    // unused, the constant, the constant while a saved token is unused, the
    // Connectors option, and no token. A floor is a minimum, so 41 would
    // still have PASSED while no longer covering those sentences.
    'includes/settings.php' => 47,
    // 7 and not 6 since the #53/#48 merge, measured with this file's own
    // i18n_calls(). Resolving the accent-color validation fail-closed added
    // one call, the "Cannot validate the accent color" error. A floor is a
    // minimum, so 6 would still have PASSED while no longer covering the
    // call the resolution introduced.
    'includes/cli.php' => 7,
    'includes/provider.php' => 6,
    'includes/budget.php' => 10,
    'includes/models.php' => 8,
    'includes/provider-models.php' => 8,
    'includes/provider-speech.php' => 2,
    'includes/stock.php' => 6,
    'includes/routes.php' => 1,
);

/** The domain declared in the plugin header. Read, not hardcoded twice. */
function i18n_declared_domain($root)
{
    $header = file_get_contents($root . '/skyphusion-ai.php');
    if (!is_string($header)) {
        return '';
    }
    return preg_match('/^\s*\*\s*Text Domain:\s*(\S+)\s*$/m', $header, $m) ? $m[1] : '';
}

/**
 * Every translation call in a source string, as [function, args-tokens].
 *
 * THROUGH PHP's OWN TOKENIZER, not a regex over the text. The regex version
 * of this matched `__()` inside a docblock that was explaining why arguments
 * must be literals, reported it as a call with no literal and no domain, and
 * failed two assertions against code that was correct. A mention is not a
 * call, and `token_get_all` is the thing that already knows the difference.
 */
function i18n_calls($src)
{
    $fns = array('__', '_e', '_x', '_n', '_nx', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e');
    $tokens = @token_get_all($src);
    $out = array();
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING || !in_array($t[1], $fns, true)) {
            continue;
        }
        // Not a method or property call, and not a declaration.
        $prev = null;
        for ($k = $i - 1; $k >= 0; $k--) {
            if (is_array($tokens[$k]) && in_array($tokens[$k][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }
            $prev = $tokens[$k];
            break;
        }
        if (is_array($prev) && in_array($prev[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION), true)) {
            continue;
        }
        // The next meaningful token must open the call.
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $count || $tokens[$j] !== '(') {
            continue;
        }
        $depth = 0;
        $args = array();
        for ($k = $j; $k < $count; $k++) {
            $tok = $tokens[$k];
            if ($tok === '(') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            }
            if ($tok === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $args[] = $tok;
        }
        $out[] = array($t[1], $args);
    }
    return $out;
}

/** True when the first argument is a quoted literal in the source. */
function i18n_first_arg_is_literal($args)
{
    foreach ($args as $tok) {
        if (is_array($tok) && $tok[0] === T_WHITESPACE) {
            continue;
        }
        return is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING;
    }
    return false;
}

/** The domain, which is the LAST string literal in the argument list. */
function i18n_domain_of($args)
{
    $last = null;
    foreach ($args as $tok) {
        if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
            $last = $tok[1];
        }
    }
    if ($last === null) {
        return null;
    }
    return trim($last, "'\"");
}

/** A short, readable form of an argument list, for a failure message. */
function i18n_shown($args)
{
    $out = '';
    foreach ($args as $tok) {
        $out .= is_array($tok) ? $tok[1] : $tok;
        if (strlen($out) > 60) {
            break;
        }
    }
    return substr(trim(preg_replace('/\s+/', ' ', $out)), 0, 60);
}

$i18n_domain = i18n_declared_domain($i18n_root);
expect('the plugin header declares a text domain', $i18n_domain, 'skyphusion-ai');

$i18n_files = array();
foreach (array_merge(array_keys($i18n_floors), array(
    'skyphusion-ai.php',
    'includes/endpoint.php',
    'includes/frontend.php',
    'includes/routes.php',
    'includes/search-stock.php',
    'includes/embed.php',
    'includes/related.php',
    'includes/insights.php',
    'uninstall.php',
)) as $rel) {
    $i18n_files[$rel] = (string) file_get_contents($i18n_root . '/' . $rel);
}

$i18n_total = 0;
$i18n_bad_literal = array();
$i18n_bad_domain = array();
foreach ($i18n_files as $rel => $src) {
    foreach (i18n_calls($src) as $call) {
        list($fn, $args) = $call;
        $i18n_total++;
        if (!i18n_first_arg_is_literal($args)) {
            $i18n_bad_literal[] = $rel . ': ' . $fn . '(' . i18n_shown($args);
        }
        $domain = i18n_domain_of($args);
        if ($domain !== $i18n_domain) {
            $i18n_bad_domain[] = $rel . ': ' . $fn . '(' . i18n_shown($args);
        }
    }
}

expect('every translation call takes a literal first argument', $i18n_bad_literal, array());
expect('every translation call uses the declared domain', $i18n_bad_domain, array());
expect('the plugin carries translation calls at all', $i18n_total > 0, true);

foreach ($i18n_floors as $rel => $floor) {
    $found = count(i18n_calls($i18n_files[$rel]));
    expect(
        'at least ' . $floor . ' translation calls in ' . $rel,
        $found >= $floor,
        true
    );
}

// CONTROLS. Without these, the three assertions above pass on any file set
// that happens to contain no calls at all, which is the state this issue was
// filed about.
$i18n_planted_variable = '<?php __($text, \'skyphusion-ai\');';
$i18n_planted_domain = '<?php __(\'Hello.\', \'wrong-domain\');';
$i18n_planted_good = '<?php __(\'Hello.\', \'skyphusion-ai\');';

$c = i18n_calls($i18n_planted_variable);
expect('the scan finds a planted call', count($c), 1);
expect('and sees a variable first argument', i18n_first_arg_is_literal($c[0][1]), false);

$c = i18n_calls($i18n_planted_good);
expect('a literal first argument reads as literal', i18n_first_arg_is_literal($c[0][1]), true);
expect('and the domain is read back', i18n_domain_of($c[0][1]), 'skyphusion-ai');

$c = i18n_calls($i18n_planted_domain);
expect('a wrong domain is seen', i18n_domain_of($c[0][1]), 'wrong-domain');

// A shape with a sprintf placeholder and an escaped quote, which is where a
// naive scanner stops early and under-reports.
$c = i18n_calls('<?php esc_html__(\'It\\\'s %s.\', \'skyphusion-ai\');');
expect('an escaped quote does not truncate the scan', count($c), 1);
expect('and that call reads as literal', i18n_first_arg_is_literal($c[0][1]), true);

// THE CONTROL FOR THE BUG THIS SCANNER HAD. A docblock explaining why
// arguments must be literals contains the text `__()`, and the regex version
// counted it as a call with no literal and no domain.
$i18n_planted_comment = "<?php\n/**\n * A variable passed to __() is invisible to makepot.\n */\n\$x = 1;\n";
expect('a call named only in a comment is not counted', count(i18n_calls($i18n_planted_comment)), 0);
$i18n_planted_both = "<?php\n// mentions __() in a comment\n__('Real.', 'skyphusion-ai');\n";
expect('but a real call beside that comment is', count(i18n_calls($i18n_planted_both)), 1);

// ------------------------------------------------- the packaging decision

// SETTLED HERE RATHER THAN LEFT IMPLIED. This plugin ships NO `languages/`
// directory and relies on translate.wordpress.org language packs, which
// WordPress 4.6 and later load on its behalf. So it needs neither a
// `Domain Path` header nor a `load_plugin_textdomain()` call, and adding
// either today would be a claim about a directory that does not exist.
//
// The rule that keeps that true is conditional rather than absolute: the
// moment a `languages/` directory IS shipped, WordPress will not find it
// without `Domain Path`, and this assertion turns that from something a
// future author must remember into something the suite says.
$i18n_has_languages = is_dir($i18n_root . '/languages');
$i18n_header = (string) file_get_contents($i18n_root . '/skyphusion-ai.php');
$i18n_has_domain_path = strpos($i18n_header, 'Domain Path:') !== false;
expect(
    'a shipped languages/ directory is declared with Domain Path',
    $i18n_has_languages ? $i18n_has_domain_path : true,
    true
);
expect(
    'and with no languages/ directory the header stays out',
    $i18n_has_languages || !$i18n_has_domain_path,
    true
);

// The control for the pair above, so neither passes merely because the
// directory is absent today.
expect('the languages directory is absent right now', $i18n_has_languages, false);
expect('and Domain Path is correspondingly absent', $i18n_has_domain_path, false);

// ------------------------------------- translator comments reach their call

/**
 * Every `translators:` comment whose call is not on the NEXT LINE.
 *
 * gettext attaches a translator comment to the call on the line immediately
 * following it. LINE adjacency, not token adjacency: `return sprintf(__(...))`
 * on the next line is attached perfectly well, and an earlier version of this
 * check demanded the very next TOKEN be the call and flagged four correct
 * sites in this repo. The rule below is the one gettext actually applies.
 *
 * It is worth checking because the failure is silent. Both of this plugin's
 * settings comments were written two lines above their call, with `wp_kses(`
 * in between. They existed, they described the placeholder correctly, and
 * `wp plugin check` reported MissingTranslatorsComment on both: the comments
 * never reached the .pot file at all. The work was done and the placement
 * discarded it.
 */
function i18n_orphan_translator_comments($src)
{
    $fns = array('__', '_e', '_x', '_n', '_nx', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e');
    $tokens = @token_get_all($src);
    $call_lines = array();
    $comment_lines = array();
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_STRING && in_array($t[1], $fns, true)) {
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j < $count && $tokens[$j] === '(') {
                $call_lines[$t[2]] = true;
            }
            continue;
        }
        if (in_array($t[0], array(T_COMMENT, T_DOC_COMMENT), true)
            && stripos($t[1], 'translators:') !== false) {
            // A block comment can span lines; the relevant one is its LAST.
            $comment_lines[] = $t[2] + substr_count($t[1], "\n");
        }
    }
    $orphans = array();
    foreach ($comment_lines as $line) {
        if (!isset($call_lines[$line + 1])) {
            $orphans[] = 'line ' . $line . ' has no translation call on the next line';
        }
    }
    return $orphans;
}

$i18n_orphans = array();
foreach ($i18n_files as $rel => $src) {
    foreach (i18n_orphan_translator_comments($src) as $o) {
        $i18n_orphans[] = $rel . ': ' . $o;
    }
}
expect('every translators: comment is on the line above its call', $i18n_orphans, array());

// CONTROLS, both directions. An empty result is also what a file with no
// translator comments produces, so the negative case has to be shown too.
expect(
    'a comment two lines above its call is caught',
    count(i18n_orphan_translator_comments(
        "<?php\nsprintf(\n/* translators: %s is a url. */\nwp_kses(\n__('a %s b', 'skyphusion-ai'), array()),\n\$u);\n"
    )),
    1
);
expect(
    'a comment directly above its call is not',
    count(i18n_orphan_translator_comments(
        "<?php\nsprintf(\nwp_kses(\n/* translators: %s is a url. */\n__('a %s b', 'skyphusion-ai'), array()),\n\$u);\n"
    )),
    0
);
expect(
    'a call reached through return on the next line is not',
    count(i18n_orphan_translator_comments(
        "<?php\n/* translators: %d is a status. */\nreturn sprintf(__('HTTP %d.', 'skyphusion-ai'), 1);\n"
    )),
    0
);
expect(
    'this plugin actually has translator comments to check',
    count(preg_grep('/translators:/', explode("\n", $i18n_files['includes/settings.php']))) >= 2,
    true
);
