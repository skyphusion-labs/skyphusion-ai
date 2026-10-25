<?php
/**
 * Daily neuron budget. Issue #79.
 *
 * Loaded after lead-guard-test.php. The transport stub and the option
 * store already exist. Two failures have to be observable:
 *
 *   the counter does not move
 *   a call at the cap still performs the HTTP request
 *
 * A recorder that returns the right text and writes nothing stays red.
 * A gate that refuses in PHP and still dials stays red.
 */

if (!isset($GLOBALS['cfais_options']) || !is_array($GLOBALS['cfais_options'])) {
    $GLOBALS['cfais_options'] = array();
}
$GLOBALS['cfais_mail'] = array();
$GLOBALS['cfais_mail_ok'] = true;

if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        if (!isset($GLOBALS['cfais_options']) || !is_array($GLOBALS['cfais_options'])) {
            $GLOBALS['cfais_options'] = array();
        }
        $GLOBALS['cfais_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = '', $attachments = array())
    {
        $GLOBALS['cfais_mail'][] = array($to, $subject, $message);
        return !empty($GLOBALS['cfais_mail_ok']);
    }
}
if (!function_exists('is_email')) {
    function is_email($email)
    {
        return is_string($email) && preg_match('/^[^@\s]+@[^@\s]+$/', $email) === 1;
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return $value;
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

function cfais_neuron_reset()
{
    $GLOBALS['cfais_options']['admin_email'] = 'admin@example.test';
    unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
    unset($GLOBALS['cfais_body']);
    unset($GLOBALS['skyphusion_ai_neuron_call']);
    $GLOBALS['cfais_mail'] = array();
    $GLOBALS['cfais_mail_ok'] = true;
    if (isset($GLOBALS['cfais_stored']['neuron_cap'])) {
        unset($GLOBALS['cfais_stored']['neuron_cap']);
    }
}

function cfais_fn_body($src, $fn)
{
    $start = strpos($src, 'function ' . $fn);
    if ($start === false) {
        return '';
    }
    $next = strpos($src, "\nfunction ", $start + 1);
    if ($next === false) {
        $next = strlen($src);
    }
    return substr($src, $start, $next - $start);
}

cfais_neuron_reset();

expect(
    'one million default-text input tokens are 26637 neurons, rounded up',
    skyphusion_ai_neuron_from_tokens(1000000, 293),
    26637
);
expect(
    'one million embeddings input tokens are 18546 neurons, rounded up',
    skyphusion_ai_neuron_from_tokens(1000000, 204),
    18546
);
expect('the default cap is 10000', skyphusion_ai_neuron_cap(), 10000);

$cfais_llama = array(
    'usage' => array(
        'prompt_tokens' => 1000000,
        'completion_tokens' => 0,
        'neurons' => 15,
    ),
);
$cfais_measured = skyphusion_ai_neuron_measure('@cf/meta/llama-3.3-70b-instruct-fp8-fast', $cfais_llama);
expect('a neuron figure is used as given', $cfais_measured['neurons'], 15);
expect('and that figure is not an estimate', $cfais_measured['estimated'], false);

$cfais_unknown = skyphusion_ai_neuron_measure(
    '@cf/example/no-such',
    array('usage' => array('prompt_tokens' => 1000000, 'completion_tokens' => 0))
);
expect('an unknown model uses the default text rate', $cfais_unknown['neurons'], 26637);
expect('and a token estimate is marked estimated', $cfais_unknown['estimated'], true);

$cfais_embed_rate = skyphusion_ai_neuron_measure(
    '@cf/baai/bge-large-en-v1.5',
    array('result' => array('usage' => array('input_tokens' => 1000000)))
);
expect('the embeddings rate is the README rate', $cfais_embed_rate['neurons'], 18546);
expect(
    'a neuron figure on result is used as given',
    skyphusion_ai_neuron_measure('', array('result' => array('neurons' => 4)))['neurons'],
    4
);
expect(
    'a neuron figure at the top of the body is used as given',
    skyphusion_ai_neuron_measure('', array('neurons' => 6))['neurons'],
    6
);

$cfais_prompt = 'unique-prompt-should-not-be-stored';
$cfais_closed = skyphusion_ai_neuron_closed_message(
    'https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/@cf/meta/llama-3.3-70b-instruct-fp8-fast',
    array('prompt' => $cfais_prompt)
);
expect('under the cap the Workers AI call is allowed', $cfais_closed, '');
$cfais_noted = skyphusion_ai_neuron_note(array('usage' => array('neurons' => 15)));
expect('note() returns the response data', $cfais_noted['usage']['neurons'], 15);
$cfais_state = skyphusion_ai_neuron_state();
expect('the counter moved', $cfais_state['neurons'], 15);
expect('the call was counted under text', $cfais_state['features']['text'], 1);
expect('a Cloudflare neuron figure is not stored as an estimate', $cfais_state['estimated'], 0);
$cfais_saved = json_encode($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
expect('the saved row does not contain the prompt', strpos((string) $cfais_saved, $cfais_prompt), false);
expect('the held call was cleared', isset($GLOBALS['skyphusion_ai_neuron_call']), false);

cfais_neuron_reset();
$cfais_vision = skyphusion_ai_neuron_closed_message(
    'https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/@cf/mistralai/mistral-small-3.1-24b-instruct',
    array('messages' => array(array('content' => array(array('type' => 'image_url')))))
);
expect('a vision request is allowed under the cap', $cfais_vision, '');
skyphusion_ai_neuron_note(array('usage' => array('neurons' => 3)));
expect('an image in the request counts as vision', skyphusion_ai_neuron_state()['features']['vision'], 1);

$GLOBALS['skyphusion_ai_models_runtime'] = array(
    array('id' => '@cf/example/new-picture', 'name' => 'New picture', 'task' => 'text-to-image'),
    array('id' => '@cf/example/new-voice', 'name' => 'New voice', 'task' => 'text-to-speech'),
    array('id' => '@cf/example/new-embed', 'name' => 'New embed', 'task' => 'embeddings'),
);
expect(
    'an account image model counts as image',
    skyphusion_ai_neuron_feature('@cf/example/new-picture', array()),
    'image'
);
expect(
    'an account speech model counts as speech',
    skyphusion_ai_neuron_feature('@cf/example/new-voice', array()),
    'speech'
);
expect(
    'an account embeddings model counts as embeddings',
    skyphusion_ai_neuron_feature('@cf/example/new-embed', array()),
    'embeddings'
);
unset($GLOBALS['skyphusion_ai_models_runtime']);

cfais_neuron_reset();
$GLOBALS['cfais_options']['skyphusion_ai_neurons'] = array(
    'day' => gmdate('Y-m-d'),
    'neurons' => 10000,
    'estimated' => 0,
    'features' => array(
        'text' => 0,
        'vision' => 0,
        'image' => 0,
        'speech' => 0,
        'embeddings' => 0,
        'search' => 0,
    ),
    'mailed_80' => 1,
    'mailed_100' => 1,
);
$cfais_token = 'sentinel-token-must-not-appear-in-any-message';
$cfais_blocked_text = skyphusion_ai_neuron_closed_message(
    'https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/@cf/meta/llama-3.3-70b-instruct-fp8-fast',
    array('prompt' => $cfais_token)
);
expect('at the cap the next Workers AI call is refused', $cfais_blocked_text !== '', true);
expect('the refusal does not contain the token', strpos($cfais_blocked_text, $cfais_token), false);
$cfais_calls_before = (int) $GLOBALS['cfais_calls'];
$cfais_embed_block = skyphusion_ai_neuron_embeddings_post(
    'https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/@cf/baai/bge-large-en-v1.5',
    $cfais_token,
    array('text' => array('unique-embedding-text'))
);
expect('the embeddings call at the cap is an error', is_wp_error($cfais_embed_block), true);
expect('and the error does not contain the token', strpos((string) $cfais_embed_block->message, $cfais_token), false);
expect(
    'and the call at the cap did not perform the HTTP request',
    (int) $GLOBALS['cfais_calls'],
    $cfais_calls_before
);

cfais_neuron_reset();
$GLOBALS['cfais_body'] = json_encode(array('usage' => array('neurons' => 9)));
$cfais_calls_before = (int) $GLOBALS['cfais_calls'];
$cfais_embed_ok = skyphusion_ai_neuron_embeddings_post(
    'https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/@cf/baai/bge-large-en-v1.5',
    $cfais_token,
    array('text' => array('unique-embedding-text'))
);
expect('under the cap embeddings leave the site', is_wp_error($cfais_embed_ok), false);
expect('and that costs one HTTP request', (int) $GLOBALS['cfais_calls'], $cfais_calls_before + 1);
expect('and the embeddings counter moved', skyphusion_ai_neuron_state()['neurons'], 9);
expect('under the embeddings feature', skyphusion_ai_neuron_state()['features']['embeddings'], 1);
$cfais_saved = json_encode($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
expect('the embeddings row does not contain the token', strpos((string) $cfais_saved, $cfais_token), false);
expect('and does not contain the embedded text', strpos((string) $cfais_saved, 'unique-embedding-text'), false);

cfais_neuron_reset();
$GLOBALS['cfais_body'] = json_encode(array(
    'choices' => array(array('message' => array('content' => 'An answer.'))),
    'usage' => array('neurons' => 7),
));
$cfais_calls_before = (int) $GLOBALS['cfais_calls'];
$cfais_search = skyphusion_ai_query('hoodie');
expect('a search answer is still returned', is_array($cfais_search) && $cfais_search['answer'] === 'An answer.', true);
expect('and the search call did leave the site', (int) $GLOBALS['cfais_calls'], $cfais_calls_before + 1);
expect('and its neuron figure was counted', skyphusion_ai_neuron_state()['neurons'], 7);
expect('under the search feature', skyphusion_ai_neuron_state()['features']['search'], 1);

$GLOBALS['cfais_options']['skyphusion_ai_neurons']['neurons'] = 10000;
$GLOBALS['cfais_options']['skyphusion_ai_neurons']['mailed_80'] = 1;
$GLOBALS['cfais_options']['skyphusion_ai_neurons']['mailed_100'] = 1;
$GLOBALS['cfais_body'] = json_encode(array(
    'choices' => array(array('message' => array('content' => 'An answer.'))),
    'usage' => array('neurons' => 4),
));
$cfais_calls_before = (int) $GLOBALS['cfais_calls'];
$cfais_search_capped = skyphusion_ai_query('beanie');
expect('a search answer is not blocked at the cap', is_array($cfais_search_capped) && $cfais_search_capped['answer'] === 'An answer.', true);
expect('and it still performed the HTTP request', (int) $GLOBALS['cfais_calls'], $cfais_calls_before + 1);
expect('and the figure was still counted', skyphusion_ai_neuron_state()['neurons'], 10004);

cfais_neuron_reset();
$GLOBALS['cfais_body'] = json_encode(array(
    'choices' => array(array('message' => array('content' => 'An answer.'))),
));
$cfais_calls_before = (int) $GLOBALS['cfais_calls'];
$cfais_plain = skyphusion_ai_query('cap');
expect('a search body with no usage still answers', is_array($cfais_plain) && $cfais_plain['answer'] === 'An answer.', true);
expect('and still leaves the site', (int) $GLOBALS['cfais_calls'], $cfais_calls_before + 1);
expect('and adds no neurons', skyphusion_ai_neuron_state()['neurons'], 0);

cfais_neuron_reset();
$GLOBALS['cfais_options']['skyphusion_ai_neurons'] = array(
    'day' => '2020-01-01',
    'neurons' => 9000,
    'estimated' => 10,
    'features' => array('text' => 4),
    'mailed_80' => 1,
    'mailed_100' => 1,
);
expect('a previous UTC day reads as zero', skyphusion_ai_neuron_state()['neurons'], 0);
expect('and its mail flags do not carry over', skyphusion_ai_neuron_state()['mailed_100'], 0);

cfais_neuron_reset();
skyphusion_ai_neuron_add('text', 8000, true);
expect('80% sends one mail', count($GLOBALS['cfais_mail']), 1);
expect('and the subject names 80%', strpos($GLOBALS['cfais_mail'][0][1], '80%') !== false, true);
skyphusion_ai_neuron_add('text', 1, false);
expect('a later call under the cap does not mail again', count($GLOBALS['cfais_mail']), 1);
skyphusion_ai_neuron_add('text', 1999, false);
expect('100% sends the second mail', count($GLOBALS['cfais_mail']), 2);
expect('and that subject names the budget', strpos($GLOBALS['cfais_mail'][1][1], 'reached') !== false, true);
skyphusion_ai_neuron_add('text', 1, false);
expect('100% mails once', count($GLOBALS['cfais_mail']), 2);

cfais_neuron_reset();
skyphusion_ai_neuron_add('text', 10000, false);
expect('crossing both thresholds mails twice', count($GLOBALS['cfais_mail']), 2);

cfais_neuron_reset();
$GLOBALS['cfais_mail_ok'] = false;
skyphusion_ai_neuron_add('text', 10000, false);
expect('a failed mail was still attempted', count($GLOBALS['cfais_mail']), 2);
expect('and the 100% flag is set anyway', skyphusion_ai_neuron_state()['mailed_100'], 1);
expect('the admin notice still says the budget is spent', skyphusion_ai_neuron_notice_code(), '100');
skyphusion_ai_neuron_add('text', 1, false);
expect('a failed mail is not retried the same day', count($GLOBALS['cfais_mail']), 2);

cfais_neuron_reset();
skyphusion_ai_neuron_add('text', 8000, true);
expect('80% is the notice before the cap', skyphusion_ai_neuron_notice_code(), '80');
expect(
    'the settings text names the uncounted snippets',
    strpos(skyphusion_ai_neuron_settings_text(), 'public search box') !== false
        && strpos(skyphusion_ai_neuron_settings_text(), 'chat button') !== false,
    true
);
expect('the admin bar shows the total and the cap', skyphusion_ai_neuron_bar_title(), '8000 / 10000 neurons');

expect('an empty cap field returns to 10000', skyphusion_ai_neuron_parse_cap('', 15000), 10000);
expect('a whole number is kept', skyphusion_ai_neuron_parse_cap('15000', 10000), 15000);
expect('zero is rejected', skyphusion_ai_neuron_parse_cap('0', 10000), null);
expect('a fraction is rejected', skyphusion_ai_neuron_parse_cap('1.5', 10000), null);

$GLOBALS['cfais_notices'] = array();
$_POST['skyphusion_ai_settings'] = array('neuron_cap' => '15000');
$cfais_clean = skyphusion_ai_neuron_sanitize_option(array('endpoint' => ''), 'skyphusion_ai_settings', array('neuron_cap' => 10000));
expect('the settings filter stores a raised cap', $cfais_clean['neuron_cap'], 15000);
$_POST['skyphusion_ai_settings'] = array('neuron_cap' => '0');
$GLOBALS['cfais_notices'] = array();
$cfais_kept = skyphusion_ai_neuron_sanitize_option(array('endpoint' => ''), 'skyphusion_ai_settings', array('neuron_cap' => 10000));
expect('a rejected cap keeps the previous one', $cfais_kept['neuron_cap'], 10000);
expect('and tells the admin', $GLOBALS['cfais_notices'], array('neuron_cap'));
unset($_POST['skyphusion_ai_settings']);
$cfais_absent = skyphusion_ai_neuron_sanitize_option(array('endpoint' => ''), 'skyphusion_ai_settings', array('neuron_cap' => 12345));
expect('a save that omits the field keeps the stored cap', $cfais_absent['neuron_cap'], 12345);

$cfais_provider = (string) file_get_contents(dirname(__DIR__) . '/includes/provider.php');
$cfais_models = (string) file_get_contents(dirname(__DIR__) . '/includes/provider-models.php');
$cfais_post = cfais_fn_body($cfais_models, 'cloudflare_ai_provider_post');
$cfais_gate = strpos($cfais_post, 'skyphusion_ai_neuron_closed_message');
$cfais_send = strpos($cfais_post, 'getHttpTransporter');
$cfais_note = strpos($cfais_post, 'skyphusion_ai_neuron_note');
expect(
    'provider_post refuses before the HTTP transport and records after it',
    $cfais_gate !== false && $cfais_send !== false && $cfais_note !== false && $cfais_gate < $cfais_send && $cfais_send < $cfais_note,
    true
);
$cfais_embeddings = cfais_fn_body($cfais_provider, 'cloudflare_ai_provider_embeddings');
expect(
    'embeddings() does not dial on its own',
    strpos($cfais_embeddings, 'wp_remote_post'),
    false
);
expect(
    'embeddings() asks the budget before the post',
    strpos($cfais_embeddings, 'skyphusion_ai_neuron_refusal') !== false
        && strpos($cfais_embeddings, 'skyphusion_ai_neuron_refusal') < strpos($cfais_embeddings, 'skyphusion_ai_neuron_embeddings_post'),
    true
);
$cfais_query = cfais_fn_body($cfais_provider, 'skyphusion_ai_query');
expect('search still performs its own HTTP request', strpos($cfais_query, 'wp_remote_post') !== false, true);
expect('search counts a usage figure', strpos($cfais_query, 'skyphusion_ai_neuron_count_search') !== false, true);
expect('search does not refuse on the neuron cap', strpos($cfais_query, 'skyphusion_ai_neuron_refusal'), false);
expect('search does not refuse on a tool cap', strpos($cfais_query, 'skyphusion_ai_tool_refusal'), false);

if (!isset($GLOBALS['cfais_stored']) || !is_array($GLOBALS['cfais_stored'])) {
    $GLOBALS['cfais_stored'] = array();
}
$cfais_stored_over = array_key_exists('neuron_over', $GLOBALS['cfais_stored']) ? $GLOBALS['cfais_stored']['neuron_over'] : null;
$GLOBALS['cfais_stored']['neuron_over'] = 'cheapest';
$GLOBALS['skyphusion_ai_models_runtime'] = array(
    array(
        'id' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        'name' => 'Large',
        'task' => 'text generation',
        'price_in' => 0.293,
        'price_out' => 2.253,
    ),
    array(
        'id' => '@cf/meta/llama-3.2-1b-instruct',
        'name' => 'Small',
        'task' => 'text generation',
        'price_in' => 0.027,
        'price_out' => 0.201,
    ),
    array(
        'id' => '@cf/example/cheap-vision',
        'name' => 'Cheap vision',
        'task' => 'image-to-text',
        'price_in' => 0.001,
        'price_out' => 0.001,
    ),
);
expect('the cheapest text model skips a vision model', skyphusion_ai_neuron_cheapest_text(), '@cf/meta/llama-3.2-1b-instruct');
$GLOBALS['cfais_options']['skyphusion_ai_neurons'] = array(
    'day' => gmdate('Y-m-d'),
    'neurons' => 10000,
    'estimated' => 0,
    'features' => array(),
    'mailed_80' => 1,
    'mailed_100' => 1,
);
$cfais_large_url = 'https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/@cf/meta/llama-3.3-70b-instruct-fp8-fast';
$cfais_swapped = skyphusion_ai_neuron_cheap_request($cfais_large_url, array('prompt' => 'hello'));
expect('a plain text call moves to the cheapest model', strpos($cfais_swapped[0], '@cf/meta/llama-3.2-1b-instruct') !== false, true);
expect('the large model is no longer on that call', strpos($cfais_swapped[0], '70b') === false, true);
$cfais_cheap_closed = skyphusion_ai_neuron_closed_message($cfais_swapped[0], $cfais_swapped[1]);
expect('the cheapest text call is allowed at the cap', $cfais_cheap_closed, '');
$cfais_vision_url = 'https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/@cf/mistralai/mistral-small-3.1-24b-instruct';
$cfais_vision_body = array('messages' => array(array('content' => array(array('type' => 'image_url')))));
$cfais_vision_same = skyphusion_ai_neuron_cheap_request($cfais_vision_url, $cfais_vision_body);
expect('a vision call is not moved', $cfais_vision_same[0], $cfais_vision_url);
expect('a vision call still pauses at the cap', skyphusion_ai_neuron_closed_message($cfais_vision_url, $cfais_vision_body) !== '', true);
$cfais_embed_still = skyphusion_ai_neuron_embeddings_post(
    'https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/@cf/baai/bge-large-en-v1.5',
    'sentinel-token-must-not-appear-in-any-message',
    array('text' => array('unique-embedding-text'))
);
expect('embeddings still pause at the cap', is_wp_error($cfais_embed_still), true);
$GLOBALS['skyphusion_ai_models_runtime'] = array(
    array('id' => '@cf/meta/llama-3.2-1b-instruct', 'name' => 'Small', 'task' => 'text generation'),
);
expect('a model with no price is not invented', skyphusion_ai_neuron_cheapest_text(), '');
$cfais_unpriced = skyphusion_ai_neuron_cheap_request($cfais_large_url, array('prompt' => 'hello'));
expect('no price leaves the call in place', $cfais_unpriced[0], $cfais_large_url);
expect('no price still pauses the call', skyphusion_ai_neuron_closed_message($cfais_large_url, array('prompt' => 'hello')) !== '', true);
$GLOBALS['cfais_stored']['neuron_over'] = 'pause';
expect('pause stays the refusal', skyphusion_ai_neuron_closed_message($cfais_swapped[0], array('prompt' => 'hello')) !== '', true);
$_POST['skyphusion_ai_settings'] = array('neuron_cap' => '10000', 'neuron_over' => 'cheapest');
$cfais_over_saved = skyphusion_ai_neuron_sanitize_option(array('endpoint' => ''), 'skyphusion_ai_settings', array('neuron_cap' => 10000));
expect('the screen stores the cheapest choice', $cfais_over_saved['neuron_over'], 'cheapest');
unset($_POST['skyphusion_ai_settings']);
$cfais_over_kept = skyphusion_ai_neuron_sanitize_option(array('endpoint' => ''), 'skyphusion_ai_settings', array('neuron_cap' => 10000, 'neuron_over' => 'cheapest'));
expect('a save that omits the choice keeps it', $cfais_over_kept['neuron_over'], 'cheapest');
if (null === $cfais_stored_over) {
    unset($GLOBALS['cfais_stored']['neuron_over']);
} else {
    $GLOBALS['cfais_stored']['neuron_over'] = $cfais_stored_over;
}
unset($GLOBALS['skyphusion_ai_models_runtime']);

unset($GLOBALS['cfais_stored']['tool_caps']);
expect('embeddings start at 3000', skyphusion_ai_tool_default('embeddings'), 3000);
expect('the site assistant starts at 1000', skyphusion_ai_tool_default('assistant'), 1000);
expect('writing has no limit until one is saved', skyphusion_ai_tool_cap('text'), 0);
expect('an unsaved writing limit does not stop the tool', skyphusion_ai_tool_blocked('text'), false);

$GLOBALS['cfais_options']['skyphusion_ai_neurons'] = array(
    'day' => gmdate('Y-m-d'),
    'neurons' => 9,
    'tools' => array(
        'text' => 4,
        'vision' => 1,
        'embeddings' => 2,
        'assistant' => 7,
    ),
);
$GLOBALS['cfais_stored']['tool_caps'] = array(
    'text' => 4,
    'vision' => 0,
    'embeddings' => 2,
    'assistant' => 7,
);
expect('writing stops when its own limit is spent', skyphusion_ai_tool_blocked('text'), true);
expect('image reading with no limit of its own continues', skyphusion_ai_tool_blocked('vision'), false);
expect('embeddings stop when their own limit is spent', skyphusion_ai_tool_blocked('embeddings'), true);
$cfais_tool_text_url = 'https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/@cf/meta/llama-3.3-70b-instruct-fp8-fast';
expect(
    'a writing call at its tool limit is the tool refusal',
    skyphusion_ai_neuron_closed_message($cfais_tool_text_url, array('prompt' => 'hello')),
    skyphusion_ai_tool_refusal_text()
);
$cfais_tool_vision_url = 'https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/@cf/mistralai/mistral-small-3.1-24b-instruct';
$cfais_tool_vision_body = array('messages' => array(array('content' => array(array('type' => 'image_url')))));
expect(
    'image reading continues while writing is stopped',
    skyphusion_ai_neuron_closed_message($cfais_tool_vision_url, $cfais_tool_vision_body),
    ''
);
$cfais_tool_embed = skyphusion_ai_neuron_embeddings_post(
    'https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/@cf/baai/bge-large-en-v1.5',
    'sentinel-token-must-not-appear-in-any-message',
    array('text' => array('unique-embedding-text'))
);
expect('embeddings stop on their own limit while the site total is under the budget', is_wp_error($cfais_tool_embed), true);

$GLOBALS['cfais_options']['skyphusion_ai_neurons'] = array(
    'day' => '2000-01-01',
    'neurons' => 50,
    'tools' => array('text' => 50),
);
expect('a row from another UTC day does not count as writing use', skyphusion_ai_tool_used('text'), 0);
expect('a row from another UTC day does not stop writing', skyphusion_ai_tool_blocked('text'), false);
expect('that row leaves the saved writing limit in place', skyphusion_ai_tool_cap('text'), 4);

$cfais_assistant_state = skyphusion_ai_neuron_record(
    'text',
    '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
    array('usage' => array('neurons' => 4)),
    'assistant'
);
expect('an assistant call spends the assistant limit', skyphusion_ai_tool_used('assistant'), 4);
expect('an assistant call does not spend writing', skyphusion_ai_tool_used('text'), 0);
expect('an assistant call still adds to the site total', $cfais_assistant_state['neurons'], 4);
$cfais_text_before_backfill = skyphusion_ai_tool_used('text');
skyphusion_ai_neuron_record(
    'text',
    '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
    array('usage' => array('neurons' => 3)),
    'backfill'
);
expect('backfill does not spend writing', skyphusion_ai_tool_used('text'), $cfais_text_before_backfill);
expect('the neuron row does not store an ip', array_key_exists('ip', skyphusion_ai_neuron_state()), false);

$GLOBALS['cfais_stored']['neuron_over'] = 'cheapest';
$GLOBALS['cfais_stored']['tool_caps'] = array('text' => 5);
$GLOBALS['cfais_options']['skyphusion_ai_neurons'] = array(
    'day' => gmdate('Y-m-d'),
    'neurons' => 10000,
    'tools' => array('text' => 5),
);
$GLOBALS['skyphusion_ai_models_runtime'] = array(
    array(
        'id' => '@cf/meta/llama-3.2-1b-instruct',
        'name' => 'Small',
        'task' => 'text generation',
        'price_in' => 0.027,
        'price_out' => 0.201,
    ),
);
$cfais_tool_swapped = skyphusion_ai_neuron_cheap_request($cfais_tool_text_url, array('prompt' => 'hello'));
expect(
    'a spent writing limit still moves a plain text call to the cheapest model',
    $cfais_tool_swapped[0],
    'https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/@cf/meta/llama-3.2-1b-instruct'
);
expect(
    'the cheapest switch does not bypass a spent writing limit',
    skyphusion_ai_neuron_closed_message($cfais_tool_swapped[0], $cfais_tool_swapped[1]),
    skyphusion_ai_tool_refusal_text()
);
$GLOBALS['cfais_stored']['neuron_over'] = 'pause';
$GLOBALS['cfais_stored']['tool_caps'] = array('text' => 100);
$GLOBALS['cfais_options']['skyphusion_ai_neurons'] = array(
    'day' => gmdate('Y-m-d'),
    'neurons' => 10000,
    'tools' => array('text' => 0),
);
expect(
    'the site budget still stops every tool',
    skyphusion_ai_neuron_closed_message($cfais_tool_text_url, array('prompt' => 'hello')),
    skyphusion_ai_neuron_refusal_text()
);

unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
$GLOBALS['cfais_stored']['tool_caps'] = array('text' => 8000);
expect(
    'tool limits over the daily budget are named',
    strpos(skyphusion_ai_tool_warning(), 'more than the daily budget') !== false,
    true
);
$GLOBALS['cfais_stored']['tool_caps'] = array(
    'text' => 0,
    'embeddings' => 3000,
);
expect('limits inside the daily budget do not warn', skyphusion_ai_tool_warning(), '');

require_once dirname(__DIR__) . '/includes/backfill.php';
require_once dirname(__DIR__) . '/includes/assistant.php';
$GLOBALS['cfais_options']['skyphusion_ai_backfill'] = array(
    'enabled' => 1,
    'limit' => 3000,
);
$GLOBALS['cfais_options']['skyphusion_ai_assistant'] = array(
    'enabled' => 1,
);
$GLOBALS['cfais_stored']['tool_caps'] = array(
    'text' => 8000,
    'embeddings' => 3000,
);
expect('backfill and the assistant add to the shared total', skyphusion_ai_tool_limit_sum(), 15000);
$GLOBALS['cfais_options']['skyphusion_ai_backfill']['enabled'] = 0;
$GLOBALS['cfais_options']['skyphusion_ai_assistant']['enabled'] = 0;
expect('a job that is off does not add its limit', skyphusion_ai_tool_limit_sum(), 11000);

$_POST['skyphusion_ai_settings'] = array(
    'tool_caps' => array(
        'text' => '0',
        'embeddings' => '12',
        'assistant' => 'nope',
        'vision' => '',
    ),
);
$cfais_tool_saved = skyphusion_ai_neuron_sanitize_option(
    array('endpoint' => ''),
    'skyphusion_ai_settings',
    array(
        'tool_caps' => array(
            'assistant' => 40,
            'vision' => 9,
        ),
    )
);
expect('a posted zero stores no writing limit', $cfais_tool_saved['tool_caps']['text'], 0);
expect('a posted embeddings limit is stored', $cfais_tool_saved['tool_caps']['embeddings'], 12);
expect('a rejected assistant limit keeps the previous one', $cfais_tool_saved['tool_caps']['assistant'], 40);
expect('clearing image reading stores zero', $cfais_tool_saved['tool_caps']['vision'], 0);
unset($_POST['skyphusion_ai_settings']);
$cfais_tool_kept = skyphusion_ai_neuron_sanitize_option(
    array('endpoint' => ''),
    'skyphusion_ai_settings',
    array(
        'tool_caps' => array('text' => 15),
    )
);
expect('a save that omits tool limits keeps the writing limit', $cfais_tool_kept['tool_caps']['text'], 15);
unset($_POST['skyphusion_ai_settings']);

$cfais_tool_assistant_src = (string) file_get_contents(dirname(__DIR__) . '/includes/assistant.php');
$cfais_tool_translate_src = (string) file_get_contents(dirname(__DIR__) . '/includes/translate.php');
$cfais_tool_backfill_src = (string) file_get_contents(dirname(__DIR__) . '/includes/backfill.php');
$cfais_tool_prompts_src = (string) file_get_contents(dirname(__DIR__) . '/includes/prompts.php');
expect(
    'the assistant asks its own tool limit',
    strpos($cfais_tool_assistant_src, "skyphusion_ai_tool_refusal( 'assistant' )") !== false,
    true
);
expect(
    'translation asks its own tool limit',
    strpos($cfais_tool_translate_src, "skyphusion_ai_tool_refusal( 'translate' )") !== false,
    true
);
expect(
    'backfill records neurons on its own counter',
    strpos($cfais_tool_backfill_src, "skyphusion_ai_neuron_record( \$feature, \$model, \$decoded, 'backfill' )") !== false,
    true
);
expect(
    'a prompt test asks the writing tool limit',
    strpos($cfais_tool_prompts_src, "skyphusion_ai_tool_refusal( 'text' )") !== false,
    true
);

if (null === $cfais_stored_over) {
    unset($GLOBALS['cfais_stored']['neuron_over']);
} else {
    $GLOBALS['cfais_stored']['neuron_over'] = $cfais_stored_over;
}
unset($GLOBALS['cfais_stored']['tool_caps']);
unset($GLOBALS['skyphusion_ai_models_runtime']);
unset($GLOBALS['cfais_options']['skyphusion_ai_backfill']);
unset($GLOBALS['cfais_options']['skyphusion_ai_assistant']);

cfais_neuron_reset();
unset($GLOBALS['cfais_body']);
