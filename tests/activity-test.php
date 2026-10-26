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
