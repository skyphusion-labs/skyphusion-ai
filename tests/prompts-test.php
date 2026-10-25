<?php
/**
 * Prompt editor. Loaded after links-test.php.
 * A test runs one call. The link screen and the translation helper do not.
 */

if (!function_exists('skyphusion_ai_prompt_test')) {
    require_once dirname(__DIR__) . '/includes/prompts.php';
}

$prompt_prev_key = getenv('CLOUDFLARE_API_KEY');
$prompt_prev_account = getenv('CLOUDFLARE_ACCOUNT_ID');
$prompt_token = 'sentinel-token-' . str_repeat('a', 30);
putenv('CLOUDFLARE_API_KEY=' . $prompt_token);
putenv('CLOUDFLARE_ACCOUNT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
$GLOBALS['cfais_settings'] = array(
    'endpoint' => 'https://abc123.search.ai.cloudflare.com',
    'lead_search' => true,
    'account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'gateway_id' => '',
);
$GLOBALS['cfais_status'] = 200;
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
unset($GLOBALS['cfais_options']['skyphusion_ai_prompts']);
unset($GLOBALS['cfais_options']['skyphusion_ai_prompt_last']);

function prompt_clear()
{
    $GLOBALS['cfais_calls'] = 0;
    unset($GLOBALS['cfais_bodies']);
    $GLOBALS['cfais_last_post'] = array('marker' => 1);
}

$prompt_alt = skyphusion_ai_backfill_prompt('alt', 'Linen shirt');
expect('the alt prompt stays the sentence already sent', $prompt_alt, 'Write one alt sentence for this image. Title: Linen shirt');
$prompt_seo = skyphusion_ai_backfill_prompt('description', 'Linen shirt');
expect('the seo prompt stays the sentence already sent', $prompt_seo, 'Write one SEO description for this page. Title: Linen shirt');
$prompt_assistant = skyphusion_ai_assistant_prompt(
    'Is this linen?',
    array(array('title' => 'Linen shirt', 'url' => 'https://shop.example/linen'))
);
expect(
    'the assistant prompt stays the sentence already sent',
    $prompt_assistant,
    "Answer from these pages only.\n- Linen shirt\nQuestion: Is this linen?"
);
$prompt_assistant_empty = skyphusion_ai_assistant_prompt('Hi', array());
expect(
    'an assistant prompt with no pages has no blank line',
    $prompt_assistant_empty,
    "Answer from these pages only.\nQuestion: Hi"
);

$GLOBALS['cfai_stock_test_filters']['skyphusion_ai_prompt'] = 'Replaced prompt';
expect('a filter can replace the prompt', skyphusion_ai_prompt_fill('backfill_alt', array('title' => 'Linen shirt')), 'Replaced prompt');
unset($GLOBALS['cfai_stock_test_filters']['skyphusion_ai_prompt']);
expect(
    'clearing the filter restores the prompt',
    skyphusion_ai_prompt_fill('backfill_alt', array('title' => 'Linen shirt')),
    'Write one alt sentence for this image. Title: Linen shirt'
);

skyphusion_ai_prompt_assign('backfill_alt', 'Write one alt sentence for this image. Title: {title}');
$prompt_stored = get_option('skyphusion_ai_prompts', array());
expect('a default prompt is not stored', isset($prompt_stored['backfill_alt']), false);
skyphusion_ai_prompt_assign('backfill_alt', 'Custom alt. Title: {title}');
expect('an edit is what the empty text job sends', skyphusion_ai_backfill_prompt('alt', 'Linen shirt'), 'Custom alt. Title: Linen shirt');
$prompt_page = 'This linen shirt is cut for warm weather and a long walk.';
skyphusion_ai_prompt_assign('backfill_description', 'Custom SEO. Title: {title}');
$prompt_custom_seo = skyphusion_ai_backfill_prompt('description', 'Linen shirt', $prompt_page);
expect('an edit is what the seo job sends', strpos($prompt_custom_seo, 'Custom SEO. Title: Linen shirt') === 0, true);
expect('the seo job appends the page text', strpos($prompt_custom_seo, "\n\nContent:\n" . $prompt_page) !== false, true);
skyphusion_ai_prompt_reset('backfill_description');
skyphusion_ai_prompt_assign('assistant', "Answer from these pages only.\n{pages}\nQuestion: {question}");
expect('the assistant default is not stored', isset(get_option('skyphusion_ai_prompts', array())['assistant']), false);
skyphusion_ai_prompt_assign('links', 'Keep <b>this</b>');
expect('a prompt drops tags', skyphusion_ai_prompt_raw('links'), 'Keep this');
skyphusion_ai_prompt_assign('links', str_repeat('b', 4001));
expect('a prompt stops at 4000 characters', strlen(skyphusion_ai_prompt_raw('links')), 4000);
skyphusion_ai_prompt_reset('links');
expect('reset restores the link prompt', skyphusion_ai_prompt_raw('links'), 'Suggest an internal link only when the phrase is already in the post. Phrase: {phrase}');
skyphusion_ai_prompt_reset('backfill_alt');
expect('reset restores the alt prompt', skyphusion_ai_backfill_prompt('alt', 'Linen shirt'), 'Write one alt sentence for this image. Title: Linen shirt');

$GLOBALS['cfais_can']['manage_options'] = true;
$_POST['skyphusion_ai_prompt'] = array('translate' => 'Custom translation. Text: {text}');
skyphusion_ai_prompt_handle_save();
expect('the save form stores an edit', skyphusion_ai_prompt_raw('translate'), 'Custom translation. Text: {text}');
unset($_POST['skyphusion_ai_prompt']);
$_POST['skyphusion_ai_prompt_key'] = 'translate';
skyphusion_ai_prompt_handle_reset();
expect('the reset form restores the translation prompt', skyphusion_ai_prompt_raw('translate'), 'Translate the text. Keep the meaning. Text: {text}');
unset($_POST['skyphusion_ai_prompt_key']);

prompt_clear();
$prompt_unknown = skyphusion_ai_prompt_test('missing');
expect('an unknown prompt is an error', $prompt_unknown['error'] !== '', true);
expect('an unknown prompt makes no request', $GLOBALS['cfais_calls'], 0);

prompt_clear();
skyphusion_ai_prompt_assign('links', '');
$prompt_empty = skyphusion_ai_prompt_test('links');
expect('an empty prompt is an error', $prompt_empty['error'], 'The prompt is empty.');
expect('an empty prompt makes no request', $GLOBALS['cfais_calls'], 0);
skyphusion_ai_prompt_reset('links');

putenv('CLOUDFLARE_API_KEY');
prompt_clear();
$prompt_naked = skyphusion_ai_prompt_test('backfill_alt');
expect('a missing token is an error', $prompt_naked['error'], 'The prompt test could not run.');
expect('a missing token makes no request', $GLOBALS['cfais_calls'], 0);
expect('a missing token does not return the token', strpos($prompt_naked['error'], $prompt_token) === false, true);
putenv('CLOUDFLARE_API_KEY=' . $prompt_token);

$prompt_body = '{"choices":[{"message":{"content":"A linen shirt."}}],"usage":{"neurons":4}}';
prompt_clear();
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
$GLOBALS['cfais_bodies'] = array($prompt_body);
$GLOBALS['cfais_status'] = 200;
$prompt_ran = skyphusion_ai_prompt_test('backfill_alt');
expect('a test returns the answer', $prompt_ran['text'], 'A linen shirt.');
expect('a test reports the neurons', $prompt_ran['neurons'], 4);
expect('a test has no error', $prompt_ran['error'], '');
expect('a test makes one request', $GLOBALS['cfais_calls'], 1);
expect('the writing model id is set', skyphusion_ai_prompt_model() !== '', true);
$prompt_url = (string) $GLOBALS['cfais_last_post']['url'];
expect('the test calls the writing model', strpos($prompt_url, skyphusion_ai_prompt_model()) !== false, true);
expect('the test url does not include the token', strpos($prompt_url, $prompt_token) === false, true);
$prompt_sent = (string) $GLOBALS['cfais_last_post']['args']['body'];
expect('the test sends the alt prompt', strpos($prompt_sent, 'Write one alt sentence for this image.') !== false, true);
expect('the test body does not include the token', strpos($prompt_sent, $prompt_token) === false, true);
$prompt_usage = get_option('skyphusion_ai_neurons', array());
$prompt_text_calls = isset($prompt_usage['features']['text']) ? (int) $prompt_usage['features']['text'] : 0;
expect('the test counts as text', $prompt_text_calls, 1);

prompt_clear();
$GLOBALS['cfais_status'] = 500;
$GLOBALS['cfais_bodies'] = array('{"error":"no"}');
$prompt_down = skyphusion_ai_prompt_test('assistant');
expect('a failed test is an error', $prompt_down['error'], 'The prompt test could not run.');
expect('a failed test does not include the token', strpos($prompt_down['error'], $prompt_token) === false, true);
expect('a failed test made the request', $GLOBALS['cfais_calls'], 1);
$GLOBALS['cfais_status'] = 200;

update_option('skyphusion_ai_neurons', array(
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 10000,
    'estimated' => 0,
    'features' => array(),
    'mailed_80' => 1,
    'mailed_100' => 1,
), false);
prompt_clear();
$prompt_capped = skyphusion_ai_prompt_test('translate');
expect('a spent budget is an error', strpos($prompt_capped['error'], 'paused') !== false, true);
expect('a spent budget makes no request', $GLOBALS['cfais_calls'], 0);
expect('a spent budget does not include the token', strpos($prompt_capped['error'], $prompt_token) === false, true);
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);

unset($GLOBALS['cfais_options']['skyphusion_ai_prompt_last']);
ob_start();
skyphusion_ai_prompt_render();
$prompt_screen = (string) ob_get_clean();
expect('the screen names the prompts', strpos($prompt_screen, 'Prompts') !== false, true);
expect('the screen shows the alt prompt', strpos($prompt_screen, 'Write one alt sentence for this image.') !== false, true);
expect('the screen says no test has run', strpos($prompt_screen, 'No test has run.') !== false, true);
expect('the screen says the editor does not send the link prompt', strpos($prompt_screen, 'The editor does not send this prompt.') !== false, true);
expect('the screen says the helper does not send the instruction', strpos($prompt_screen, 'The translation helper does not send this instruction.') !== false, true);
expect('the screen offers a save', strpos($prompt_screen, 'Save the prompts') !== false, true);
expect('the screen offers a reset', strpos($prompt_screen, 'Reset Alt text') !== false, true);
expect('the screen offers a test', strpos($prompt_screen, 'Test Alt text') !== false, true);
expect('the screen does not show the token', strpos($prompt_screen, $prompt_token) === false, true);

prompt_clear();
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
$GLOBALS['cfais_bodies'] = array($prompt_body);
skyphusion_ai_prompt_test('backfill_alt');
ob_start();
skyphusion_ai_prompt_render();
$prompt_after = (string) ob_get_clean();
expect('the screen shows the neuron cost', strpos($prompt_after, 'The Alt text test used 4 neurons.') !== false, true);
expect('the screen shows the answer', strpos($prompt_after, 'A linen shirt.') !== false, true);
expect('the screen after a test does not show the token', strpos($prompt_after, $prompt_token) === false, true);

unset($GLOBALS['cfais_can']['manage_options']);
ob_start();
skyphusion_ai_prompt_render();
expect('the screen is empty without the capability', (string) ob_get_clean(), '');

$prompt_src = (string) file_get_contents(dirname(__DIR__) . '/includes/prompts.php');
expect('the prompts do not read a remote address', strpos($prompt_src, 'REMOTE_ADDR') === false, true);
expect('the test stops at the neuron budget', strpos($prompt_src, 'skyphusion_ai_neuron_refusal') !== false, true);
expect('the prompts keep a filter', strpos($prompt_src, 'skyphusion_ai_prompt') !== false, true);
expect('the prompts do not save a post', strpos($prompt_src, 'wp_update_post') === false, true);
$prompt_uninstall = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
expect('uninstall removes the prompt option', strpos($prompt_uninstall, 'skyphusion_ai_prompts') !== false, true);

unset($GLOBALS['cfais_settings']);
unset($GLOBALS['cfais_bodies']);
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
unset($GLOBALS['cfais_options']['skyphusion_ai_prompts']);
unset($GLOBALS['cfais_options']['skyphusion_ai_prompt_last']);
unset($GLOBALS['cfais_can']['manage_options']);
if (false === $prompt_prev_key || '' === $prompt_prev_key) {
    putenv('CLOUDFLARE_API_KEY');
} else {
    putenv('CLOUDFLARE_API_KEY=' . $prompt_prev_key);
}
if (false === $prompt_prev_account || '' === $prompt_prev_account) {
    putenv('CLOUDFLARE_ACCOUNT_ID');
} else {
    putenv('CLOUDFLARE_ACCOUNT_ID=' . $prompt_prev_account);
}
