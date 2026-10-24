<?php
/**
 * `--accent-color` and the deprecated `--color` alias. Issue #6.
 *
 * WHY THIS FILE EXISTS AT ALL. The old code recovered `--color` by scraping
 * `$_SERVER['argv']` inside the command, which meant the precedence between
 * the two spellings could not be driven without a WP-CLI process. The
 * resolution is now a pure function taking argv as a PARAMETER, so every
 * combination is reachable here.
 *
 * THE DECISION THESE PIN, recorded on the issue before the code: `--color`
 * shipped in seven releases and `ggc/host/bootstrap.sh:221` passes it to our
 * own store's provisioning script, so it is a published flag and renaming it
 * is a breaking change. It keeps working. `--accent-color` is the declared
 * one, and the declared one wins when both arrive.
 */

$ac = 'skyphusion_ai_cli_accent_color';

// --- the declared flag ------------------------------------------------------

$r = $ac(array('accent-color' => '#1B3A32'), array());
expect('accent-color is taken', $r['value'], '#1B3A32');
expect('and is not deprecated', $r['deprecated'], false);

// --- the shipped alias, via the raw arguments -------------------------------
// This is the real path: WP-CLI reserves --color and consumes it, so it never
// reaches $assoc and only argv has it.

$r = $ac(array(), array('wp', 'skyphusion-ai', 'set', '--color=#1B3A32'));
expect('color is recovered from argv', $r['value'], '#1B3A32');
expect('and is reported deprecated', $r['deprecated'], true);

// --- PRECEDENCE, which is the whole point of declaring a second flag --------

$r = $ac(array('accent-color' => '#AAAAAA'), array('--color=#BBBBBB'));
expect('the declared flag wins over argv', $r['value'], '#AAAAAA');
expect('and no deprecation is reported', $r['deprecated'], false);

$r = $ac(array('accent-color' => '#AAAAAA', 'color' => '#BBBBBB'), array());
expect('the declared flag wins in assoc too', $r['value'], '#AAAAAA');
expect('still not deprecated', $r['deprecated'], false);

// --- the belt-and-braces path ------------------------------------------------
// Reached only if WP-CLI ever stops reserving the global flag.

$r = $ac(array('color' => '#CCCCCC'), array());
expect('color in assoc is honoured', $r['value'], '#CCCCCC');
expect('and reported deprecated', $r['deprecated'], true);

// --- nothing passed ----------------------------------------------------------

$r = $ac(array(), array());
expect('no colour flag yields null', $r['value'], null);
expect('and no deprecation notice', $r['deprecated'], false);

$r = $ac(array('endpoint' => 'x'), array('wp', 'set', '--chat=0'));
expect('other flags do not look like a colour', $r['value'], null);
expect('and raise no notice', $r['deprecated'], false);

// --- shapes that used to be ambiguous ---------------------------------------

// The first --color= in argv wins, which is the documented behaviour of the
// shim rather than an accident. Pinned so a change to it is deliberate.
$r = $ac(array(), array('--color=#111111', '--color=#222222'));
expect('the first argv colour wins', $r['value'], '#111111');

// An empty value is a value, not an absence. The hex validation in set()
// rejects it; this function must not silently drop it, or the user gets no
// error at all.
$r = $ac(array(), array('--color='));
expect('an empty colour is still a value', $r['value'], '');
expect('and still deprecated', $r['deprecated'], true);

// A bare --color with no "=" is WP-CLI's own global flag and must NOT be read
// as a value for this command.
$r = $ac(array(), array('--color'));
expect('a bare --color is not a value', $r['value'], null);
expect('and raises no deprecation notice', $r['deprecated'], false);

// A flag that merely STARTS with the name is not the flag.
$r = $ac(array(), array('--colorize=#333333'));
expect('--colorize is not --color', $r['value'], null);

// --- the docblock and the docs agree with the code --------------------------

$ac_cli = (string) file_get_contents(dirname(__DIR__) . '/includes/cli.php');
expect(
    'the declared flag is documented',
    strpos($ac_cli, '[--accent-color=<hex>]') !== false,
    true
);
expect(
    'the alias is documented as deprecated',
    strpos($ac_cli, 'Deprecated alias for --accent-color') !== false,
    true
);
expect(
    'the example no longer shows the colliding form',
    strpos($ac_cli, 'set --endpoint=https://abc.search.ai.cloudflare.com/ --color=') !== false,
    false
);

$ac_readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
expect(
    'README shows the declared flag',
    strpos($ac_readme, '--accent-color=#1B3A32') !== false,
    true
);
expect(
    'README no longer shows the colliding example',
    strpos($ac_readme, 'set --endpoint=https://abc.search.ai.cloudflare.com/ --color=') !== false,
    false
);
