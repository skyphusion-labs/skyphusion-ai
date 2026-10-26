<?php
/**
 * Model labels from the account payload, and the publisher licence step.
 *
 * Neurons, context, and tool or vision flags are shown only when the
 * account row sent them. The acceptance is not sent until the admin asks.
 */

$labels_src = (string) file_get_contents(dirname(__DIR__) . '/includes/models.php');
expect('the model labels marker is present', strpos($labels_src, 'model-labels') !== false, true);

$labels_token = 'sentinel-token-must-not-appear-in-any-message';
$labels_account = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$labels_saved_key = getenv('CLOUDFLARE_API_KEY');
$labels_saved_account = array_key_exists('account_id', $GLOBALS['cfais_stored']) ? $GLOBALS['cfais_stored']['account_id'] : null;
$labels_saved_can = isset($GLOBALS['cfais_can']['manage_options']) ? $GLOBALS['cfais_can']['manage_options'] : null;
$labels_saved_status = isset($GLOBALS['cfais_status']) ? $GLOBALS['cfais_status'] : 200;
$labels_calls = isset($GLOBALS['cfais_calls']) ? $GLOBALS['cfais_calls'] : 0;
$labels_last = isset($GLOBALS['cfais_last_post']) ? $GLOBALS['cfais_last_post'] : null;
putenv('CLOUDFLARE_API_KEY=' . $labels_token);
$GLOBALS['cfais_stored']['account_id'] = $labels_account;

$labels_full = skyphusion_ai_account_models_from_body(array(
    'success' => true,
    'result' => array(
        array(
            'id' => '11111111-1111-1111-1111-111111111111',
            'name' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
            'task' => array('name' => 'Text Generation'),
            'properties' => array(
                array('property_id' => 'context_window', 'value' => '128000'),
                array('property_id' => 'function_calling', 'value' => true),
                array(
                    'property_id' => 'price',
                    'value' => array(
                        array('unit' => 'per M input tokens', 'price' => 0.293),
                        array('unit' => 'per M output tokens', 'price' => 2.253),
                    ),
                ),
            ),
        ),
    ),
));
expect('the parser keeps the model id from the name', $labels_full[0]['id'], '@cf/meta/llama-3.3-70b-instruct-fp8-fast');
expect('context comes from the account row', $labels_full[0]['context'], 128000);
expect('tools come from function calling', !empty($labels_full[0]['tools']), true);
expect('vision is not taken from the id', !empty($labels_full[0]['vision']), false);
$labels_full_text = skyphusion_ai_model_label($labels_full[0]);
expect('the label shows input neurons', strpos($labels_full_text, '26.6 neurons per 1,000 input tokens') !== false, true);
expect('the label shows output neurons', strpos($labels_full_text, '204.8') !== false && strpos($labels_full_text, 'output') !== false, true);
expect('the label shows context', strpos($labels_full_text, '128K context') !== false, true);
expect('the label shows tools', strpos($labels_full_text, 'tools') !== false, true);
expect('the label does not show vision', strpos($labels_full_text, 'vision') !== false, false);

$labels_same = skyphusion_ai_account_models_from_body(array(
    'result' => array(
        array(
            'id' => '@cf/meta/llama-3.2-1b-instruct',
            'name' => 'Small',
            'task' => 'Text Generation',
            'properties' => array(
                array('property_id' => 'context_window', 'value' => 24000),
                array('property_id' => 'function_calling', 'value' => false),
                array(
                    'property_id' => 'price',
                    'value' => array(
                        array('unit' => 'per M input tokens', 'price' => 0.342),
                        array('unit' => 'per M output tokens', 'price' => 0.342),
                    ),
                ),
            ),
        ),
    ),
));
$labels_same_text = skyphusion_ai_model_label($labels_same[0]);
expect('equal prices use one neuron figure', strpos($labels_same_text, '31.1 neurons per 1,000 tokens') !== false, true);
expect('equal prices do not say input tokens', strpos($labels_same_text, 'input tokens') !== false, false);
expect('a false tool flag is not shown', strpos($labels_same_text, 'tools') !== false, false);
expect('24000 tokens is 24K context', strpos($labels_same_text, '24K context') !== false, true);

$labels_vision = skyphusion_ai_account_models_from_body(array(
    'result' => array(
        array(
            'id' => '@cf/example/sees',
            'name' => 'Sees',
            'task' => 'image-to-text',
        ),
    ),
));
expect('image-to-text is still vision on the label', strpos(skyphusion_ai_model_label($labels_vision[0]), 'vision') !== false, true);

$labels_string_tools = skyphusion_ai_account_models_from_body(array(
    'result' => array(
        array(
            'id' => '@cf/example/tools',
            'name' => 'Tools',
            'task' => 'text generation',
            'properties' => array(
                array('property_id' => 'function_calling', 'value' => 'true'),
            ),
        ),
    ),
));
expect('the string true is a tool flag', !empty($labels_string_tools[0]['tools']), true);

$labels_bare = skyphusion_ai_account_models_from_body(array(
    'result' => array(
        array(
            'id' => '@cf/meta/llama-3.2-11b-vision-instruct',
            'name' => 'Vision name',
            'task' => 'Text Generation',
        ),
        array(
            'id' => '@cf/example/priced',
            'name' => 'Priced',
            'task' => 'Text Generation',
            'properties' => array(
                array(
                    'property_id' => 'price',
                    'value' => array(
                        array('unit' => 'per M input tokens', 'price' => 0.293),
                        array('unit' => 'per M output tokens', 'price' => 2.253),
                    ),
                ),
            ),
        ),
    ),
));
expect('a row with no price has no price', skyphusion_ai_account_model_prices($labels_bare[0]), array());
expect('a missing price is not stored as zero', array_key_exists('price_in', $labels_bare[0]), false);
expect('a missing context is not stored', array_key_exists('context', $labels_bare[0]), false);
$labels_bare_text = skyphusion_ai_model_label($labels_bare[0]);
expect('a bare label has no neurons', strpos($labels_bare_text, 'neurons') !== false, false);
expect('a bare label has no context', strpos($labels_bare_text, 'context') !== false, false);
expect('a bare label has no tools', strpos($labels_bare_text, 'tools') !== false, false);
expect('letters in the id are not vision', strpos($labels_bare_text, 'vision') !== false, false);
expect('a bare label does not borrow 26.6', strpos($labels_bare_text, '26.6') !== false, false);
expect('the priced sibling keeps its own price', isset($labels_bare[1]['price_in']) && $labels_bare[1]['price_in'] === 0.293, true);

$labels_groups = skyphusion_ai_model_groups(array(
    array('id' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'name' => 'A'),
    array('id' => '@cf/meta/llama-3.2-1b-instruct', 'name' => 'B'),
    array('id' => '@cf/mistralai/mistral-small-3.1-24b-instruct', 'name' => 'C'),
    array('id' => 'not-a-vendor', 'name' => 'D'),
));
expect('both meta ids share one group', count($labels_groups['meta']), 2);
expect('mistral is its own group', $labels_groups['mistralai'][0]['id'], '@cf/mistralai/mistral-small-3.1-24b-instruct');
expect('an id without a vendor is not meta', isset($labels_groups['other']), true);

$GLOBALS['skyphusion_ai_models_runtime'] = array(
    array('id' => '@cf/meta/llama-3.2-1b-instruct', 'name' => 'Small', 'task' => 'text generation'),
    array('id' => '@cf/mistralai/mistral-small-3.1-24b-instruct', 'name' => 'Mistral', 'task' => 'text generation'),
);
$labels_calls_before = $GLOBALS['cfais_calls'];
$labels_last_before = isset($GLOBALS['cfais_last_post']) ? $GLOBALS['cfais_last_post'] : null;
ob_start();
skyphusion_ai_field_model_writing();
$labels_html = ob_get_clean();
expect('the picker groups meta', strpos($labels_html, '<optgroup label="meta">') !== false, true);
$labels_mistral_at = strpos($labels_html, '<optgroup label="mistralai">');
$labels_mistral_end = false === $labels_mistral_at ? false : strpos($labels_html, '</optgroup>', $labels_mistral_at);
$labels_mistral_html = (false === $labels_mistral_at || false === $labels_mistral_end)
    ? ''
    : substr($labels_html, $labels_mistral_at, $labels_mistral_end - $labels_mistral_at);
expect('mistral stays in its own group', strpos($labels_mistral_html, '@cf/mistralai/mistral-small-3.1-24b-instruct') !== false, true);
expect('the meta group does not hold mistral', strpos($labels_mistral_html, '@cf/meta/') !== false, false);
expect('drawing the picker does not call Cloudflare', $GLOBALS['cfais_calls'], $labels_calls_before);
expect('drawing the picker does not replace the last call', isset($GLOBALS['cfais_last_post']) ? $GLOBALS['cfais_last_post'] : null, $labels_last_before);

expect('code 5016 is a publisher gate', skyphusion_ai_model_licence_is_gate(array(
    'errors' => array(array('code' => 5016, 'message' => 'no')),
)), true);
expect('a model agreement message is a publisher gate', skyphusion_ai_model_licence_is_gate(array(
    'errors' => array(array('code' => 0, 'message' => 'Model Agreement required')),
)), true);
expect('an empty success is not a publisher gate', skyphusion_ai_model_licence_is_gate(array(
    'success' => true,
    'result' => array(),
)), false);
expect('a transport failure is not a publisher gate', skyphusion_ai_model_licence_is_gate('The site could not reach Cloudflare.'), false);

$labels_gate = '@cf/meta/llama-3.2-11b-vision-instruct';
$labels_calls_before = $GLOBALS['cfais_calls'];
$labels_last_before = isset($GLOBALS['cfais_last_post']) ? $GLOBALS['cfais_last_post'] : null;
skyphusion_ai_model_licence_record($labels_gate, 'Model Agreement required ' . $labels_token);
expect('recording a gate does not call Cloudflare', $GLOBALS['cfais_calls'], $labels_calls_before);
expect('recording a gate does not replace the last call', isset($GLOBALS['cfais_last_post']) ? $GLOBALS['cfais_last_post'] : null, $labels_last_before);
expect(
    'the stored gate does not keep the upstream text',
    strpos((string) json_encode($GLOBALS['cfais_options']['skyphusion_ai_model_licences']), $labels_token) !== false,
    false
);

$GLOBALS['cfais_can']['manage_options'] = true;
$labels_calls_before = $GLOBALS['cfais_calls'];
ob_start();
skyphusion_ai_model_licence_notice();
$labels_notice = ob_get_clean();
expect('the notice names the model', strpos($labels_notice, $labels_gate) !== false, true);
expect('the notice says licence', strpos($labels_notice, 'licence') !== false, true);
expect('the notice names the publisher', strpos($labels_notice, 'Meta') !== false, true);
expect('the notice does not name another plugin', strpos($labels_notice, 'AIPCF') !== false, false);
expect('the notice does not show the token', strpos($labels_notice, $labels_token) !== false, false);
expect('the notice does not call Cloudflare', $GLOBALS['cfais_calls'], $labels_calls_before);

$labels_calls_before = $GLOBALS['cfais_calls'];
$labels_quiet = skyphusion_ai_model_licence_accept($labels_gate, false);
expect('a render does not send the acceptance', $GLOBALS['cfais_calls'], $labels_calls_before);
expect('the quiet result does not show the token', strpos($labels_quiet, $labels_token) !== false, false);
$_POST['skyphusion_ai_settings'] = array('endpoint' => '');
$labels_calls_before = $GLOBALS['cfais_calls'];
skyphusion_ai_model_sanitize_option(array('endpoint' => ''), 'skyphusion_ai_settings', array());
expect('saving settings does not send the acceptance', $GLOBALS['cfais_calls'], $labels_calls_before);
unset($_POST['skyphusion_ai_settings']);

$GLOBALS['cfais_status'] = 200;
$labels_calls_before = $GLOBALS['cfais_calls'];
$labels_sent = skyphusion_ai_model_licence_accept($labels_gate, true);
expect('the admin ask sends one acceptance', $GLOBALS['cfais_calls'], $labels_calls_before + 1);
expect(
    'the acceptance uses the model run url',
    $GLOBALS['cfais_last_post']['url'],
    'https://api.cloudflare.com/client/v4/accounts/' . $labels_account . '/ai/run/' . $labels_gate
);
expect(
    'the acceptance uses the site token',
    $GLOBALS['cfais_last_post']['args']['headers']['Authorization'],
    'Bearer ' . $labels_token
);
expect('the url does not contain the token', strpos($GLOBALS['cfais_last_post']['url'], $labels_token) !== false, false);
expect('the body is the agree prompt', $GLOBALS['cfais_last_post']['args']['body'], '{"prompt":"agree"}');
expect('the body does not contain the token', strpos((string) $GLOBALS['cfais_last_post']['args']['body'], $labels_token) !== false, false);
expect('the result does not show the token', strpos($labels_sent, $labels_token) !== false, false);

$labels_calls_before = $GLOBALS['cfais_calls'];
skyphusion_ai_model_licence_accept('@cf/example/not-gated', true);
expect('a model that is not gated is not called', $GLOBALS['cfais_calls'], $labels_calls_before);

unset($GLOBALS['skyphusion_ai_models_runtime']);
$GLOBALS['cfais_model_gets'] = array();
$GLOBALS['cfais_status'] = 200;
$GLOBALS['cfais_model_response'] = array('ok' => true);
$GLOBALS['cfais_body'] = json_encode(array(
    'success' => true,
    'result' => array(
        array(
            'id' => '@cf/example/labeled',
            'name' => 'Labeled',
            'task' => 'Text Generation',
            'properties' => array(
                array('property_id' => 'context_window', 'value' => 128000),
                array('property_id' => 'function_calling', 'value' => true),
                array(
                    'property_id' => 'price',
                    'value' => array(
                        array('unit' => 'per M input tokens', 'price' => 0.293),
                        array('unit' => 'per M output tokens', 'price' => 2.253),
                    ),
                ),
            ),
        ),
    ),
));
$labels_fresh = skyphusion_ai_account_models_refresh();
expect('a live list keeps the labeled model', $labels_fresh[0]['id'], '@cf/example/labeled');
$labels_cache = $GLOBALS['cfais_store']['skyphusion_ai_models'];
expect(
    'labels stay on the 12 hour cache',
    $labels_cache['expires'] - time() >= 43190 && $labels_cache['expires'] - time() <= 43200,
    true
);
expect('the cache keeps context', $labels_cache['value']['models'][0]['context'], 128000);
expect('the cache keeps tools', !empty($labels_cache['value']['models'][0]['tools']), true);
expect('the cache keeps the price the account sent', $labels_cache['value']['models'][0]['price_in'], 0.293);
expect('the cache does not contain the token', strpos((string) json_encode($labels_cache), $labels_token) !== false, false);
$GLOBALS['cfais_model_gets'] = array();
unset($GLOBALS['skyphusion_ai_models_runtime']);
$labels_cached_rows = skyphusion_ai_account_models_current();
expect('a cached list is not fetched again', $GLOBALS['cfais_model_gets'], array());
$labels_cached_text = skyphusion_ai_model_label($labels_cached_rows[0]);
expect('a cached label still shows neurons', strpos($labels_cached_text, '26.6') !== false, true);
expect('a cached label still shows context', strpos($labels_cached_text, '128K context') !== false, true);

unset($GLOBALS['skyphusion_ai_models_runtime']);
$GLOBALS['cfais_model_gets'] = array();
$GLOBALS['cfais_body'] = json_encode(array(
    'success' => true,
    'result' => array(
        array('id' => '@cf/example/plain', 'name' => 'Plain', 'task' => 'Text Generation'),
    ),
));
skyphusion_ai_account_models_refresh();
$labels_plain_cache = $GLOBALS['cfais_store']['skyphusion_ai_models'];
expect(
    'a list with no price still lasts 12 hours',
    $labels_plain_cache['expires'] - time() >= 43190 && $labels_plain_cache['expires'] - time() <= 43200,
    true
);
expect('a plain cache row has no price', array_key_exists('price_in', $labels_plain_cache['value']['models'][0]), false);
expect('a plain cache label has no neurons', strpos(skyphusion_ai_model_label($labels_plain_cache['value']['models'][0]), 'neurons') !== false, false);

unset($GLOBALS['skyphusion_ai_models_runtime']);
unset($GLOBALS['cfais_model_response'], $GLOBALS['cfais_body']);
unset($GLOBALS['cfais_options']['skyphusion_ai_model_licences']);
$GLOBALS['cfais_status'] = $labels_saved_status;
$GLOBALS['cfais_calls'] = $labels_calls;
if (null === $labels_last) {
    unset($GLOBALS['cfais_last_post']);
} else {
    $GLOBALS['cfais_last_post'] = $labels_last;
}
if (null === $labels_saved_account) {
    unset($GLOBALS['cfais_stored']['account_id']);
} else {
    $GLOBALS['cfais_stored']['account_id'] = $labels_saved_account;
}
if (null === $labels_saved_can) {
    unset($GLOBALS['cfais_can']['manage_options']);
} else {
    $GLOBALS['cfais_can']['manage_options'] = $labels_saved_can;
}
if (is_string($labels_saved_key)) {
    putenv('CLOUDFLARE_API_KEY=' . $labels_saved_key);
} else {
    putenv('CLOUDFLARE_API_KEY');
}
