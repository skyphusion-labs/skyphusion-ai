<?php
/**
 * Keep paid providers out of the WordPress AI fallback list. Issue #128.
 *
 * The switch defaults on. Anthropic, Google, and OpenAI leave the
 * preferred-model lists. A Cloudflare model id that contains the word
 * google stays. The plugin still registers only the provider id cloudflare.
 */

$nopaid_had_setting = array_key_exists('no_paid_fallback', $GLOBALS['cfais_stored']);
$nopaid_saved_setting = $nopaid_had_setting ? $GLOBALS['cfais_stored']['no_paid_fallback'] : null;
$nopaid_had_post = array_key_exists('skyphusion_ai_settings', $_POST);
$nopaid_saved_post = $nopaid_had_post ? $_POST['skyphusion_ai_settings'] : null;

require_once dirname(__DIR__) . '/includes/nopaid.php';

$nopaid_paid = array(
    array('anthropic', 'claude-sonnet-4-6'),
    array('Google', 'gemini-2.5-flash'),
    array('openai', 'gpt-4.1-mini'),
    array('cloudflare', '@cf/google/gemma-4-26b-a4b-it'),
    array('mistral', 'ministral-8b'),
);

expect(
    'the blocked ids are Anthropic, Google, and OpenAI',
    skyphusion_ai_nopaid_blocked_ids(),
    array('anthropic', 'google', 'openai')
);
expect('the only provider id is cloudflare', skyphusion_ai_nopaid_provider_id(), 'cloudflare');
expect('the paid-provider filter runs at priority 99', skyphusion_ai_nopaid_priority(), 99);
expect(
    'text, image, and vision lists are filtered',
    skyphusion_ai_nopaid_hooks(),
    array(
        'wpai_preferred_text_models',
        'wpai_preferred_image_models',
        'wpai_preferred_vision_models',
    )
);

expect('a missing switch is on', skyphusion_ai_nopaid_on(array()), true);
expect('a stored zero is off', skyphusion_ai_nopaid_on(array('no_paid_fallback' => '0')), false);
expect('a stored one is on', skyphusion_ai_nopaid_on(array('no_paid_fallback' => '1')), true);
expect('boolean false is off', skyphusion_ai_nopaid_on(array('no_paid_fallback' => false)), false);

unset($GLOBALS['cfais_stored']['no_paid_fallback']);
expect('saved settings without the switch read as on', skyphusion_ai_nopaid_on(), true);

$nopaid_kept = skyphusion_ai_nopaid_filter_models($nopaid_paid, array());
expect(
    'a google model id on cloudflare stays',
    $nopaid_kept,
    array(array('cloudflare', '@cf/google/gemma-4-26b-a4b-it'))
);

$nopaid_only_paid = array(
    array('anthropic', 'claude-sonnet-4-6'),
    array('google', 'gemini-2.5-flash'),
    array('openai', 'gpt-4.1-mini'),
);
expect(
    'a paid text list becomes one cloudflare row',
    skyphusion_ai_nopaid_filter_text($nopaid_only_paid),
    array(array('cloudflare', skyphusion_ai_model_choice('writing')))
);
expect(
    'a paid image list becomes the cloudflare image model',
    skyphusion_ai_nopaid_filter_image($nopaid_only_paid),
    array(array('cloudflare', skyphusion_ai_model_choice('image')))
);
expect(
    'a paid vision list becomes the cloudflare vision model',
    skyphusion_ai_nopaid_filter_vision($nopaid_only_paid),
    array(array('cloudflare', skyphusion_ai_model_choice('vision')))
);

expect(
    'an off switch keeps the paid rows',
    skyphusion_ai_nopaid_filter_models($nopaid_paid, array('no_paid_fallback' => '0')),
    $nopaid_paid
);
expect(
    'a value that is not a list becomes an empty list',
    skyphusion_ai_nopaid_filter_models('anthropic', array()),
    array()
);

unset($_POST['skyphusion_ai_settings']);
$nopaid_clean = array('endpoint' => 'https://abc123.search.ai.cloudflare.com');
expect(
    'a save with no posted switch stays on',
    skyphusion_ai_nopaid_sanitize_option($nopaid_clean, 'skyphusion_ai_settings', array()),
    array(
        'endpoint' => 'https://abc123.search.ai.cloudflare.com',
        'no_paid_fallback' => '1',
    )
);
expect(
    'a saved off switch stays off when the form is not posted',
    skyphusion_ai_nopaid_sanitize_option($nopaid_clean, 'skyphusion_ai_settings', array('no_paid_fallback' => '0')),
    array(
        'endpoint' => 'https://abc123.search.ai.cloudflare.com',
        'no_paid_fallback' => '0',
    )
);
$_POST['skyphusion_ai_settings'] = array('no_paid_fallback' => '0');
expect(
    'an unchecked box stores off',
    skyphusion_ai_nopaid_sanitize_option($nopaid_clean, 'skyphusion_ai_settings', array('no_paid_fallback' => '1'))['no_paid_fallback'],
    '0'
);
$_POST['skyphusion_ai_settings'] = array('no_paid_fallback' => '1', 'endpoint' => 'ignored');
expect(
    'a checked box stores on',
    skyphusion_ai_nopaid_sanitize_option($nopaid_clean, 'skyphusion_ai_settings', array('no_paid_fallback' => '0'))['no_paid_fallback'],
    '1'
);
expect(
    'another option is left alone',
    skyphusion_ai_nopaid_sanitize_option(array('a' => 1), 'other', null),
    array('a' => 1)
);
expect(
    'a non-array settings value is left alone',
    skyphusion_ai_nopaid_sanitize_option('no', 'skyphusion_ai_settings', null),
    'no'
);

ob_start();
skyphusion_ai_nopaid_field();
$nopaid_html_on = ob_get_clean();
expect('the checkbox names Anthropic', strpos($nopaid_html_on, 'Anthropic') !== false, true);
expect('the checkbox names Google', strpos($nopaid_html_on, 'Google') !== false, true);
expect('the checkbox names OpenAI', strpos($nopaid_html_on, 'OpenAI') !== false, true);
expect('the default checkbox is checked', strpos($nopaid_html_on, 'checked="checked"') !== false, true);

$GLOBALS['cfais_stored']['no_paid_fallback'] = '0';
ob_start();
skyphusion_ai_nopaid_field();
$nopaid_html_off = ob_get_clean();
expect('an off switch is not checked', strpos($nopaid_html_off, 'checked=') === false, true);

$nopaid_provider = (string) file_get_contents(dirname(__DIR__) . '/includes/provider.php');
$nopaid_meta = (string) file_get_contents(dirname(__DIR__) . '/includes/provider-models.php');
$nopaid_src = (string) file_get_contents(dirname(__DIR__) . '/includes/nopaid.php');
expect('provider.php registers one provider', substr_count($nopaid_provider, 'registerProvider('), 1);
expect(
    'that provider is the Cloudflare class',
    strpos($nopaid_provider, "registerProvider('Cloudflare_AI_Provider')") !== false,
    true
);
expect('provider.php does not name Anthropic', stripos($nopaid_provider, 'anthropic') === false, true);
expect('provider.php does not name OpenAI', stripos($nopaid_provider, 'openai') === false, true);
expect('provider-models.php does not name Anthropic', stripos($nopaid_meta, 'anthropic') === false, true);
expect('provider-models.php does not name OpenAI', stripos($nopaid_meta, 'openai') === false, true);
expect(
    'the metadata id is cloudflare',
    1 === preg_match("/ProviderMetadata\\(\\s*'cloudflare'/", $nopaid_meta),
    true
);
expect('the switch file does not register a provider', strpos($nopaid_src, 'registerProvider(') === false, true);
expect(
    'the text list uses the priority helper',
    strpos($nopaid_src, "add_filter( 'wpai_preferred_text_models', 'skyphusion_ai_nopaid_filter_text', skyphusion_ai_nopaid_priority() )") !== false,
    true
);

if ($nopaid_had_setting) {
    $GLOBALS['cfais_stored']['no_paid_fallback'] = $nopaid_saved_setting;
} else {
    unset($GLOBALS['cfais_stored']['no_paid_fallback']);
}
if ($nopaid_had_post) {
    $_POST['skyphusion_ai_settings'] = $nopaid_saved_post;
} else {
    unset($_POST['skyphusion_ai_settings']);
}
