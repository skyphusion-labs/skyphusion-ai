<?php
/**
 * Which source is supplying the Workers AI token. Issue #34.
 *
 * Loaded by tests/endpoint-test.php, after settings-sanitize-test.php has
 * loaded includes/settings.php. It does not call
 * skyphusion_ai_token_source_now(): that reads the process
 * environment, and a machine that exports CLOUDFLARE_API_KEY would make the
 * result depend on the machine. The decision under test is the pure function.
 *
 * The notice function is given keys only. A value passed in its place must
 * come back as "No token is set.", never as that value.
 */

$secret = 'not-a-real-token';

expect('environment wins over the constant and the option', skyphusion_ai_token_source($secret, 'constant-value', 'saved-value'), 'env_ignores_saved');
expect('environment with an empty option is just the environment', skyphusion_ai_token_source($secret, 'constant-value', ''), 'env');
expect('environment with a null option is just the environment', skyphusion_ai_token_source($secret, null, null), 'env');
expect('a false environment falls through to the constant', skyphusion_ai_token_source(false, 'constant-value', 'saved-value'), 'constant_ignores_saved');
expect('an empty environment falls through to the constant', skyphusion_ai_token_source('', 'constant-value', ''), 'constant');
expect('constant with a saved option says the saved one is unused', skyphusion_ai_token_source(false, 'constant-value', 'saved-value'), 'constant_ignores_saved');
expect('the option is used when the other two are absent', skyphusion_ai_token_source(false, null, 'saved-value'), 'option');
expect('nothing set', skyphusion_ai_token_source(false, null, ''), 'none');
expect('a non-string option is not a saved token', skyphusion_ai_token_source($secret, null, array('x')), 'env');
expect('a non-string constant is absent', skyphusion_ai_token_source(false, 12, ''), 'none');
expect('a non-string option alone is nothing', skyphusion_ai_token_source(false, null, array('x')), 'none');
expect('integer zero is absent', skyphusion_ai_token_source(0, 0, 0), 'none');
expect('boolean true is absent', skyphusion_ai_token_source(false, true, false), 'none');
expect('the string zero counts as set', skyphusion_ai_token_source('0', null, ''), 'env');
expect('the string zero in the option counts as saved', skyphusion_ai_token_source(false, null, '0'), 'option');
expect('whitespace in the environment counts as set', skyphusion_ai_token_source(' ', null, ''), 'env');
expect('whitespace in the option counts as saved and ignored', skyphusion_ai_token_source(false, 'constant-value', ' '), 'constant_ignores_saved');

$labels = array(
    skyphusion_ai_token_source($secret, 'c', 's'),
    skyphusion_ai_token_source($secret, 'c', ''),
    skyphusion_ai_token_source(false, 'c', 's'),
    skyphusion_ai_token_source(false, 'c', ''),
    skyphusion_ai_token_source(false, null, 's'),
    skyphusion_ai_token_source(false, null, ''),
);
foreach ($labels as $label) {
    expect(
        'a label does not contain the token',
        strpos($label, $secret) === false,
        true
    );
}

$env_sentence = 'Workers AI is using the token from the <code>CLOUDFLARE_API_KEY</code> environment variable.';
$constant_sentence = 'Workers AI is using the token from the <code>CLOUDFLARE_API_KEY</code> constant.';
$ignored = ' The token saved in Settings, then Connectors is not being used.';
expect('notice for the environment', skyphusion_ai_token_source_notice('env'), $env_sentence);
expect('notice for the environment when a saved token is unused', skyphusion_ai_token_source_notice('env_ignores_saved'), $env_sentence . $ignored);
expect('notice for the constant', skyphusion_ai_token_source_notice('constant'), $constant_sentence);
expect('notice for the constant when a saved token is unused', skyphusion_ai_token_source_notice('constant_ignores_saved'), $constant_sentence . $ignored);
expect('notice for the option', skyphusion_ai_token_source_notice('option'), 'Workers AI is using the token from Settings, then Connectors.');
expect('notice when nothing is set', skyphusion_ai_token_source_notice('none'), 'No token is set.');
expect('an unknown key is not printed', skyphusion_ai_token_source_notice($secret), 'No token is set.');

foreach (array('env', 'env_ignores_saved', 'constant', 'constant_ignores_saved', 'option', 'none') as $key) {
    expect(
        'the ' . $key . ' sentence does not contain the token',
        strpos(skyphusion_ai_token_source_notice($key), $secret) === false,
        true
    );
}
