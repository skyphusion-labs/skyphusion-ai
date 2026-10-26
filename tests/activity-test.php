<?php
/**
 * Activity log. Issue #126.
 *
 * A row keeps the feature, the model, the neurons, the time, and the
 * result. It does not keep a prompt, a page body, or an IP address.
 * The export uses the same rows as the screen.
 */

$activity_prompt = 'Write a long page about the sentinel phrase activity-sentinel-not-stored';
$activity_model = '@cf/meta/llama-3.3-70b-instruct-fp8-fast';
$activity_neurons_before = isset($GLOBALS['cfais_options']['skyphusion_ai_neurons']) ? $GLOBALS['cfais_options']['skyphusion_ai_neurons'] : null;
$activity_had_neurons = array_key_exists('skyphusion_ai_neurons', $GLOBALS['cfais_options']);

require_once dirname(__DIR__) . '/includes/activity.php';

expect('the log keeps 200 rows', skyphusion_ai_activity_cap(), 200);
expect('uninstall removes the activity option', strpos((string) file_get_contents(dirname(__DIR__) . '/uninstall.php'), 'skyphusion_ai_activity') !== false, true);
expect('reset removes the activity option', in_array('skyphusion_ai_activity', skyphusion_ai_reset_option_names(), true), true);
expect('wp skyphusion-ai activity is a command', strpos((string) file_get_contents(dirname(__DIR__) . '/includes/cli.php'), 'function activity') !== false, true);

skyphusion_ai_activity_add('text', $activity_model, 4, true, 1700000000);
skyphusion_ai_activity_add($activity_prompt, $activity_model, 0, false, 1700000060);
$activity_rows = skyphusion_ai_activity_rows();
expect('newest row is first', $activity_rows[0]['ok'], 0);
expect('a success row stores the feature', $activity_rows[1]['feature'], 'text');
expect('a success row stores the model', $activity_rows[1]['model'], $activity_model);
expect('a success row stores the neurons', $activity_rows[1]['neurons'], 4);
expect('a success row stores the time', $activity_rows[1]['time'], 1700000000);
expect('a success row stores success', $activity_rows[1]['ok'], 1);
expect('a failure row stores failure', $activity_rows[0]['ok'], 0);
expect('a prompt is not a feature slug', $activity_rows[0]['feature'], '');
$activity_saved = (string) json_encode($GLOBALS['cfais_options']['skyphusion_ai_activity']);
expect('the stored log does not contain the prompt', strpos($activity_saved, 'activity-sentinel-not-stored') === false, true);
expect('the stored log has no IP field', strpos($activity_saved, 'ip') === false, true);
expect('a row has no page body field', isset($activity_rows[1]['body']), false);

$activity_screen = skyphusion_ai_activity_screen_rows();
$activity_export = skyphusion_ai_activity_export();
$activity_lines = explode("\n", trim($activity_export));
expect('the export header names the screen columns', $activity_lines[0], 'feature,model,neurons,time,result');
expect('the export has one line per screen row', count($activity_lines) - 1, count($activity_screen));
expect(
    'the export names the success feature',
    strpos($activity_export, 'text,' . $activity_model . ',4,' . gmdate('Y-m-d H:i', 1700000000) . ',success') !== false,
    true
);
expect('the export names the failure', strpos($activity_lines[1], ',failure') !== false, true);
expect('the export does not contain the prompt', strpos($activity_export, 'activity-sentinel-not-stored') === false, true);

$GLOBALS['skyphusion_ai_activity_cap'] = 2;
skyphusion_ai_activity_add('vision', $activity_model, 1, true, 1700000120);
$activity_capped = skyphusion_ai_activity_stored();
expect('the cap keeps the newest rows', count($activity_capped), 2);
expect('the cap drops the oldest row', $activity_capped[0]['ok'], 0);
unset($GLOBALS['skyphusion_ai_activity_cap']);

$activity_before = count(skyphusion_ai_activity_rows());
skyphusion_ai_neuron_record('text', $activity_model, array('usage' => array('neurons' => 2)));
$activity_after = skyphusion_ai_activity_rows();
expect('a counted call is recorded', count($activity_after), $activity_before + 1);
expect('a counted call is a success', $activity_after[0]['ok'], 1);
expect('a counted call stores the neurons', $activity_after[0]['neurons'], 2);
skyphusion_ai_neuron_record('text', $activity_model, new WP_Error('skyphusion_ai_activity', 'failed'));
$activity_failed = skyphusion_ai_activity_rows();
expect('an error response is a failure', $activity_failed[0]['ok'], 0);

$activity_transport_flag = !empty($GLOBALS['cfais_transport_error']);
$activity_body_set = array_key_exists('cfais_body', $GLOBALS);
$activity_body_saved = $activity_body_set ? $GLOBALS['cfais_body'] : null;
$activity_status_saved = array_key_exists('cfais_status', $GLOBALS) ? $GLOBALS['cfais_status'] : null;
if (!isset($GLOBALS['cfais_options']['skyphusion_ai_neurons']) || !is_array($GLOBALS['cfais_options']['skyphusion_ai_neurons'])) {
    skyphusion_ai_neuron_state();
}
$GLOBALS['cfais_options']['skyphusion_ai_neurons']['neurons'] = 0;
if (isset($GLOBALS['cfais_options']['skyphusion_ai_neurons']['tools']) && is_array($GLOBALS['cfais_options']['skyphusion_ai_neurons']['tools'])) {
    $GLOBALS['cfais_options']['skyphusion_ai_neurons']['tools']['embeddings'] = 0;
}
$activity_embed_model = '@cf/baai/bge-large-en-v1.5';
if (function_exists('cloudflare_ai_provider_embeddings_model')) {
    $activity_embed_model = cloudflare_ai_provider_embeddings_model();
}
$activity_embed_url = 'https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/' . $activity_embed_model;
$activity_embed_token = 'activity-transport-token-not-stored';
$activity_before_transport = count(skyphusion_ai_activity_rows());
$GLOBALS['cfais_transport_error'] = true;
$activity_transport = skyphusion_ai_neuron_embeddings_post(
    $activity_embed_url,
    $activity_embed_token,
    array('text' => array('activity-transport-text-not-stored'))
);
$GLOBALS['cfais_transport_error'] = false;
expect('a transport failure is an error', is_wp_error($activity_transport), true);
expect(
    'a transport failure does not contain the token',
    strpos((string) $activity_transport->message, $activity_embed_token) === false,
    true
);
$activity_transport_rows = skyphusion_ai_activity_rows();
expect('a transport failure is logged', count($activity_transport_rows), $activity_before_transport + 1);
expect('a transport failure is a failure', $activity_transport_rows[0]['ok'], 0);
expect('a transport failure names embeddings', $activity_transport_rows[0]['feature'], 'embeddings');
expect('a transport failure stores the model', $activity_transport_rows[0]['model'], $activity_embed_model);
expect('a transport failure stores no neurons', $activity_transport_rows[0]['neurons'], 0);
$activity_transport_saved = (string) json_encode($GLOBALS['cfais_options']['skyphusion_ai_activity']);
expect('a transport failure does not store the token', strpos($activity_transport_saved, $activity_embed_token) === false, true);
expect('a transport failure does not store the text', strpos($activity_transport_saved, 'activity-transport-text-not-stored') === false, true);

$GLOBALS['cfais_body'] = json_encode(array('usage' => array('neurons' => 3)));
$GLOBALS['cfais_status'] = 200;
$activity_embed_ok = skyphusion_ai_neuron_embeddings_post(
    $activity_embed_url,
    $activity_embed_token,
    array('text' => array('activity-transport-text-not-stored'))
);
expect('a completed embeddings call is not an error', is_wp_error($activity_embed_ok), false);
$activity_embed_rows = skyphusion_ai_activity_rows();
expect('a completed embeddings call is logged', $activity_embed_rows[0]['ok'], 1);
expect('a completed embeddings call stores the neurons', $activity_embed_rows[0]['neurons'], 3);
expect('a completed embeddings call names embeddings', $activity_embed_rows[0]['feature'], 'embeddings');

/**
 * One failed Workers AI call outside embeddings still stores one row.
 *
 * @param string $label    Expectation prefix.
 * @param int    $before   Row count before the call.
 * @param string $feature  Feature slug.
 * @param string $model    Model id.
 * @param string $token    Token that must stay out of the log.
 * @param string $sentinel Prompt text that must stay out of the log.
 * @return void
 */
function activity_miss_logged($label, $before, $feature, $model, $token, $sentinel)
{
    $rows = skyphusion_ai_activity_rows();
    expect($label . ' is logged', count($rows), $before + 1);
    expect($label . ' is a failure', $rows[0]['ok'], 0);
    expect($label . ' names the feature', $rows[0]['feature'], $feature);
    expect($label . ' stores the model', $rows[0]['model'], $model);
    expect($label . ' stores no neurons', $rows[0]['neurons'], 0);
    expect($label . ' stores a time', $rows[0]['time'] > 0, true);
    $saved = isset($GLOBALS['cfais_options']['skyphusion_ai_activity'])
        ? (string) json_encode($GLOBALS['cfais_options']['skyphusion_ai_activity'])
        : '';
    expect($label . ' does not store the token', strpos($saved, $token) === false, true);
    expect($label . ' does not store the text', strpos($saved, $sentinel) === false, true);
    $posted = isset($GLOBALS['cfais_last_post']['args']['body']) ? (string) $GLOBALS['cfais_last_post']['args']['body'] : '';
    $url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
    $auth = '';
    if (isset($GLOBALS['cfais_last_post']['args']['headers']['Authorization'])) {
        $auth = (string) $GLOBALS['cfais_last_post']['args']['headers']['Authorization'];
    }
    expect($label . ' sent the text', strpos($posted, $sentinel) !== false, true);
    expect($label . ' sent the bearer', $auth, 'Bearer ' . $token);
    expect($label . ' called the model', strpos($url, $model) !== false, true);
    expect($label . ' url omits the bearer', strpos($url, $token) === false, true);
}

$activity_miss_token = 'activity-miss-token-not-stored';
$activity_miss_key = getenv('CLOUDFLARE_API_KEY');
$activity_miss_account = getenv('CLOUDFLARE_ACCOUNT_ID');
$activity_miss_settings_set = isset($GLOBALS['cfais_settings']);
$activity_miss_settings = $activity_miss_settings_set ? $GLOBALS['cfais_settings'] : null;
$activity_miss_can = isset($GLOBALS['cfais_can']) && is_array($GLOBALS['cfais_can']) ? $GLOBALS['cfais_can'] : array();
$activity_miss_user_set = isset($GLOBALS['cfais_user_id']);
$activity_miss_user = $activity_miss_user_set ? $GLOBALS['cfais_user_id'] : null;
$activity_miss_rows_set = isset($GLOBALS['skyphusion_ai_insight_rows']);
$activity_miss_rows = $activity_miss_rows_set ? $GLOBALS['skyphusion_ai_insight_rows'] : null;
$activity_miss_link_set = isset($GLOBALS['skyphusion_ai_link_rows']);
$activity_miss_link_rows = $activity_miss_link_set ? $GLOBALS['skyphusion_ai_link_rows'] : null;
$activity_miss_prompt_set = isset($GLOBALS['cfais_options']['skyphusion_ai_prompt_last']);
$activity_miss_prompt_last = $activity_miss_prompt_set ? $GLOBALS['cfais_options']['skyphusion_ai_prompt_last'] : null;
$activity_miss_next_set = isset($GLOBALS['cfais_store']['skyphusion_ai_write_next']);
$activity_miss_next = $activity_miss_next_set ? $GLOBALS['cfais_store']['skyphusion_ai_write_next'] : null;
putenv('CLOUDFLARE_API_KEY=' . $activity_miss_token);
putenv('CLOUDFLARE_ACCOUNT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
$GLOBALS['cfais_settings'] = array(
    'endpoint' => 'https://abc123.search.ai.cloudflare.com',
    'lead_search' => true,
    'account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'gateway_id' => '',
);
if (!isset($GLOBALS['cfais_can']) || !is_array($GLOBALS['cfais_can'])) {
    $GLOBALS['cfais_can'] = array();
}
$GLOBALS['cfais_can']['edit_posts'] = true;
$GLOBALS['cfais_can']['manage_options'] = true;
$GLOBALS['cfais_user_id'] = 0;
$GLOBALS['skyphusion_ai_insight_rows'] = array(array(
    'words' => 'activity-miss-gap-not-stored',
    'searched_at' => time(),
    'results' => 0,
    'logged_in' => 0,
    'outcome' => '',
    'dedupe' => 'activitymiss',
));
$GLOBALS['skyphusion_ai_link_rows'] = array(
    array('id' => 'p6', 'title' => 'Linen shirt', 'url' => 'https://shop.example/linen'),
);
$activity_miss_assistant_model = skyphusion_ai_assistant_model();
$activity_miss_safety_model = skyphusion_ai_assistant_safety_model();
$activity_miss_translate_model = skyphusion_ai_translate_model_for('es', 'en');
$activity_miss_backfill_model = '@cf/meta/llama-3.2-11b-vision-instruct';
$activity_miss_prompt_model = skyphusion_ai_prompt_model();

$activity_miss_before = count(skyphusion_ai_activity_rows());
$activity_miss_calls = (int) $GLOBALS['cfais_calls'];
$GLOBALS['cfais_transport_error'] = true;
$activity_miss_assistant = skyphusion_ai_assistant_text('activity-miss-assistant-not-stored', true);
$GLOBALS['cfais_transport_error'] = false;
expect('an assistant transport failure is an error', is_wp_error($activity_miss_assistant), true);
expect('an assistant transport failure made the request', (int) $GLOBALS['cfais_calls'], $activity_miss_calls + 1);
activity_miss_logged(
    'an assistant transport failure',
    $activity_miss_before,
    'text',
    $activity_miss_assistant_model,
    $activity_miss_token,
    'activity-miss-assistant-not-stored'
);

$activity_miss_before = count(skyphusion_ai_activity_rows());
$activity_miss_calls = (int) $GLOBALS['cfais_calls'];
putenv('CLOUDFLARE_API_KEY');
$activity_miss_safety_bare = skyphusion_ai_assistant_safety_ok('activity-miss-safety-not-stored');
putenv('CLOUDFLARE_API_KEY=' . $activity_miss_token);
expect('a safety check with no bearer stays on the site', $activity_miss_safety_bare, false);
expect('a safety check with no bearer makes no request', (int) $GLOBALS['cfais_calls'], $activity_miss_calls);
expect('a safety check with no bearer is not logged', count(skyphusion_ai_activity_rows()), $activity_miss_before);

$activity_miss_before = count(skyphusion_ai_activity_rows());
$activity_miss_calls = (int) $GLOBALS['cfais_calls'];
$GLOBALS['cfais_transport_error'] = true;
$activity_miss_safety = skyphusion_ai_assistant_safety_ok('activity-miss-safety-not-stored');
$GLOBALS['cfais_transport_error'] = false;
expect('a safety transport failure does not call the writer', $activity_miss_safety, false);
expect('a safety transport failure made the request', (int) $GLOBALS['cfais_calls'], $activity_miss_calls + 1);
activity_miss_logged(
    'a safety transport failure',
    $activity_miss_before,
    'text',
    $activity_miss_safety_model,
    $activity_miss_token,
    'activity-miss-safety-not-stored'
);

$activity_miss_before = count(skyphusion_ai_activity_rows());
$activity_miss_calls = (int) $GLOBALS['cfais_calls'];
$GLOBALS['cfais_transport_error'] = true;
$activity_miss_translate = skyphusion_ai_translate('activity-miss-translate-not-stored', 'es', 'en', true);
$GLOBALS['cfais_transport_error'] = false;
expect('a translation transport failure is an error', is_wp_error($activity_miss_translate), true);
expect('a translation transport failure made the request', (int) $GLOBALS['cfais_calls'], $activity_miss_calls + 1);
activity_miss_logged(
    'a translation transport failure',
    $activity_miss_before,
    'text',
    $activity_miss_translate_model,
    $activity_miss_token,
    'activity-miss-translate-not-stored'
);

$activity_miss_before = count(skyphusion_ai_activity_rows());
$activity_miss_calls = (int) $GLOBALS['cfais_calls'];
$GLOBALS['cfais_transport_error'] = true;
$activity_miss_backfill = skyphusion_ai_backfill_call(
    'activity-miss-backfill-not-stored',
    $activity_miss_backfill_model,
    'vision',
    '',
    true
);
$GLOBALS['cfais_transport_error'] = false;
expect('a backfill transport failure is an error', is_wp_error($activity_miss_backfill), true);
expect('a backfill transport failure made the request', (int) $GLOBALS['cfais_calls'], $activity_miss_calls + 1);
activity_miss_logged(
    'a backfill transport failure',
    $activity_miss_before,
    'vision',
    $activity_miss_backfill_model,
    $activity_miss_token,
    'activity-miss-backfill-not-stored'
);

$activity_miss_before = count(skyphusion_ai_activity_rows());
$activity_miss_calls = (int) $GLOBALS['cfais_calls'];
$GLOBALS['cfais_transport_error'] = true;
$activity_miss_link = skyphusion_ai_link_ask(2, 'activity-miss-link-not-stored');
$GLOBALS['cfais_transport_error'] = false;
expect('a link transport failure is an error', is_wp_error($activity_miss_link), true);
expect('a link transport failure made the request', (int) $GLOBALS['cfais_calls'], $activity_miss_calls + 1);
activity_miss_logged(
    'a link transport failure',
    $activity_miss_before,
    'text',
    $activity_miss_prompt_model,
    $activity_miss_token,
    'activity-miss-link-not-stored'
);

$activity_miss_before = count(skyphusion_ai_activity_rows());
$activity_miss_calls = (int) $GLOBALS['cfais_calls'];
$GLOBALS['cfais_transport_error'] = true;
$activity_miss_prompt = skyphusion_ai_prompt_test('backfill_alt');
$GLOBALS['cfais_transport_error'] = false;
expect('a prompt transport failure is an error', $activity_miss_prompt['error'], 'The prompt test could not run.');
expect('a prompt transport failure made the request', (int) $GLOBALS['cfais_calls'], $activity_miss_calls + 1);
activity_miss_logged(
    'a prompt transport failure',
    $activity_miss_before,
    'text',
    $activity_miss_prompt_model,
    $activity_miss_token,
    'Write one alt sentence for this image.'
);

$activity_miss_before = count(skyphusion_ai_activity_rows());
$activity_miss_calls = (int) $GLOBALS['cfais_calls'];
$GLOBALS['cfais_transport_error'] = true;
$activity_miss_insight = skyphusion_ai_insight_write_next();
$GLOBALS['cfais_transport_error'] = false;
expect('a topic transport failure is an error', is_wp_error($activity_miss_insight), true);
expect('a topic transport failure made the request', (int) $GLOBALS['cfais_calls'], $activity_miss_calls + 1);
activity_miss_logged(
    'a topic transport failure',
    $activity_miss_before,
    'text',
    $activity_miss_prompt_model,
    $activity_miss_token,
    'activity-miss-gap-not-stored'
);

$activity_miss_cap_before = count(skyphusion_ai_activity_rows());
$GLOBALS['cfais_options']['skyphusion_ai_neurons']['neurons'] = skyphusion_ai_neuron_cap();
$GLOBALS['cfais_options']['skyphusion_ai_neurons']['mailed_100'] = 1;
$GLOBALS['cfais_transport_error'] = true;
$activity_miss_refused = skyphusion_ai_translate('activity-miss-translate-not-stored', 'es', 'en', true);
$GLOBALS['cfais_transport_error'] = false;
expect('a spent budget returns before the translation call', is_wp_error($activity_miss_refused), true);
expect('a spent budget is not logged as a translation failure', count(skyphusion_ai_activity_rows()), $activity_miss_cap_before);

if (false === $activity_miss_key || '' === $activity_miss_key) {
    putenv('CLOUDFLARE_API_KEY');
} else {
    putenv('CLOUDFLARE_API_KEY=' . $activity_miss_key);
}
if (false === $activity_miss_account || '' === $activity_miss_account) {
    putenv('CLOUDFLARE_ACCOUNT_ID');
} else {
    putenv('CLOUDFLARE_ACCOUNT_ID=' . $activity_miss_account);
}
if ($activity_miss_settings_set) {
    $GLOBALS['cfais_settings'] = $activity_miss_settings;
} else {
    unset($GLOBALS['cfais_settings']);
}
$GLOBALS['cfais_can'] = $activity_miss_can;
if ($activity_miss_user_set) {
    $GLOBALS['cfais_user_id'] = $activity_miss_user;
} else {
    unset($GLOBALS['cfais_user_id']);
}
if ($activity_miss_rows_set) {
    $GLOBALS['skyphusion_ai_insight_rows'] = $activity_miss_rows;
} else {
    unset($GLOBALS['skyphusion_ai_insight_rows']);
}
if ($activity_miss_link_set) {
    $GLOBALS['skyphusion_ai_link_rows'] = $activity_miss_link_rows;
} else {
    unset($GLOBALS['skyphusion_ai_link_rows']);
}
if ($activity_miss_prompt_set) {
    $GLOBALS['cfais_options']['skyphusion_ai_prompt_last'] = $activity_miss_prompt_last;
} else {
    unset($GLOBALS['cfais_options']['skyphusion_ai_prompt_last']);
}
if ($activity_miss_next_set) {
    $GLOBALS['cfais_store']['skyphusion_ai_write_next'] = $activity_miss_next;
} else {
    unset($GLOBALS['cfais_store']['skyphusion_ai_write_next']);
}

$activity_cap_before = count(skyphusion_ai_activity_rows());
$GLOBALS['cfais_options']['skyphusion_ai_neurons']['neurons'] = skyphusion_ai_neuron_cap();
$GLOBALS['cfais_options']['skyphusion_ai_neurons']['mailed_100'] = 1;
$GLOBALS['cfais_transport_error'] = true;
$activity_refused = skyphusion_ai_neuron_embeddings_post(
    $activity_embed_url,
    $activity_embed_token,
    array('text' => array('activity-transport-text-not-stored'))
);
$GLOBALS['cfais_transport_error'] = false;
expect('a spent budget returns before the call', is_wp_error($activity_refused), true);
expect('a spent budget is not logged as a transport failure', count(skyphusion_ai_activity_rows()), $activity_cap_before);

if ($activity_body_set) {
    $GLOBALS['cfais_body'] = $activity_body_saved;
} else {
    unset($GLOBALS['cfais_body']);
}
if (null !== $activity_status_saved) {
    $GLOBALS['cfais_status'] = $activity_status_saved;
}
$GLOBALS['cfais_transport_error'] = $activity_transport_flag;

if (!isset($GLOBALS['cfais_can']) || !is_array($GLOBALS['cfais_can'])) {
    $GLOBALS['cfais_can'] = array();
}
$activity_can = $GLOBALS['cfais_can'];
$GLOBALS['cfais_can']['manage_options'] = true;
ob_start();
skyphusion_ai_activity_render();
$activity_html = ob_get_clean();
expect('the screen shows the model', strpos($activity_html, $activity_model) !== false, true);
expect('the screen shows success', strpos($activity_html, 'success') !== false, true);
expect('the screen does not show the prompt', strpos($activity_html, 'activity-sentinel-not-stored') === false, true);
$GLOBALS['cfais_can']['manage_options'] = false;
ob_start();
skyphusion_ai_activity_render();
$activity_hidden = ob_get_clean();
expect('the screen is hidden without manage_options', $activity_hidden, '');
$GLOBALS['cfais_can'] = $activity_can;

unset($GLOBALS['cfais_options']['skyphusion_ai_activity']);
unset($GLOBALS['skyphusion_ai_activity_cap']);
if ($activity_had_neurons) {
    $GLOBALS['cfais_options']['skyphusion_ai_neurons'] = $activity_neurons_before;
} else {
    unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
}
