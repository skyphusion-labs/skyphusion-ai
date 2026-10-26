<?php
/**
 * One click for the recommended defaults. Issue #124.
 *
 * The writing model is set only when the account lists that id.
 * A saved budget is kept. Caching is turned on. The assistant,
 * backfill, and embeddings stay off. Nothing here sends mail.
 */

$defaults_token = 'defaults-sentinel-not-printed';
$defaults_saved_env = getenv('CLOUDFLARE_API_KEY');
$defaults_saved_account = array_key_exists('account_id', $GLOBALS['cfais_stored']) ? $GLOBALS['cfais_stored']['account_id'] : null;
$defaults_had_cap = array_key_exists('neuron_cap', $GLOBALS['cfais_stored']);
$defaults_saved_cap = $defaults_had_cap ? $GLOBALS['cfais_stored']['neuron_cap'] : null;
$defaults_had_cache = array_key_exists('cache', $GLOBALS['cfais_stored']);
$defaults_saved_cache = $defaults_had_cache ? $GLOBALS['cfais_stored']['cache'] : null;
$defaults_had_writing = array_key_exists('model_writing', $GLOBALS['cfais_stored']);
$defaults_saved_writing = $defaults_had_writing ? $GLOBALS['cfais_stored']['model_writing'] : null;
$defaults_saved_endpoint = array_key_exists('endpoint', $GLOBALS['cfais_stored']) ? $GLOBALS['cfais_stored']['endpoint'] : null;
$defaults_mail_before = isset($GLOBALS['cfais_mail']) ? count($GLOBALS['cfais_mail']) : 0;
$defaults_can = isset($GLOBALS['cfais_can']) ? $GLOBALS['cfais_can'] : array();

require_once dirname(__DIR__) . '/includes/defaults.php';

$defaults_src = (string) file_get_contents(dirname(__DIR__) . '/includes/defaults.php');
expect('defaults do not send mail', strpos($defaults_src, 'wp_mail') === false, true);
expect('defaults do not install the AI plugin', strpos($defaults_src, 'plugin-install.php') === false, true);
expect('defaults do not name Qdrant', strpos(strtolower($defaults_src), 'qdrant') === false, true);
expect('defaults do not name Postgres', strpos(strtolower($defaults_src), 'postgres') === false, true);
expect('the provider id in defaults is cloudflare', strpos($defaults_src, "['cloudflare']") !== false, true);
expect('defaults do not name Anthropic', strpos(strtolower($defaults_src), 'anthropic') === false, true);
expect('defaults do not name OpenAI', strpos(strtolower($defaults_src), 'openai') === false, true);

expect(
    'the admin bar still says free when no budget is saved',
    strpos(skyphusion_ai_neuron_bar_title(), 'free') !== false,
    true
);

function defaults_list($id, $task)
{
    unset($GLOBALS['skyphusion_ai_models_runtime']);
    set_transient(
        'skyphusion_ai_models',
        array(
            'ok' => 1,
            'account' => (string) $GLOBALS['cfais_stored']['account_id'],
            'models' => array(
                array(
                    'id' => $id,
                    'name' => 'Listed',
                    'task' => $task,
                ),
            ),
        ),
        600
    );
}

putenv('CLOUDFLARE_API_KEY=' . $defaults_token);
unset($GLOBALS['cfais_stored']['neuron_cap']);
unset($GLOBALS['cfais_stored']['cache']);
unset($GLOBALS['cfais_stored']['model_writing']);
unset($GLOBALS['skyphusion_ai_defaults_approval_exposed']);
$GLOBALS['cfais_options']['skyphusion_ai_assistant'] = array('enabled' => 0, 'rules' => 'Keep this reply.');
$GLOBALS['cfais_options']['skyphusion_ai_backfill'] = array('enabled' => 0);
$GLOBALS['cfais_options']['skyphusion_ai_vectorize'] = array('created' => 0, 'index' => 'shop');
$GLOBALS['cfais_embed_meta'][9]['skyphusion_ai_embed_hash'] = 'already-written';
$GLOBALS['cfais_stored']['endpoint'] = 'https://abc123.search.ai.cloudflare.com';
if (!isset($GLOBALS['cfais_stored']['account_id']) || $GLOBALS['cfais_stored']['account_id'] === '') {
    $GLOBALS['cfais_stored']['account_id'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
}

defaults_list('@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'Text Generation');
$defaults_skipped = skyphusion_ai_defaults_apply();
expect('a missing recommended id does not change the writing model', $defaults_skipped['writing'], '');
$defaults_saved = $GLOBALS['cfais_options']['skyphusion_ai_settings'];
expect('a missing recommended id leaves the stored writing model empty', isset($defaults_saved['model_writing']), false);
expect('no saved budget becomes 10000', $defaults_saved['neuron_cap'], 10000);
expect('caching is stored on', $defaults_saved['cache'], '1');
expect('the endpoint is kept', $defaults_saved['endpoint'], 'https://abc123.search.ai.cloudflare.com');
expect('the account id is kept', $defaults_saved['account_id'], $GLOBALS['cfais_stored']['account_id']);
expect('embeddings stay off', $GLOBALS['cfais_options']['skyphusion_ai_vectorize']['created'], 0);
expect('backfill stays off', $GLOBALS['cfais_options']['skyphusion_ai_backfill']['enabled'], 0);
expect('the assistant stays off', $GLOBALS['cfais_options']['skyphusion_ai_assistant']['enabled'], 0);
expect('the assistant text is kept', $GLOBALS['cfais_options']['skyphusion_ai_assistant']['rules'], 'Keep this reply.');
expect('text already stored on a post is kept', $GLOBALS['cfais_embed_meta'][9]['skyphusion_ai_embed_hash'], 'already-written');
expect('approval is skipped when WordPress does not expose it', $defaults_skipped['approval'], 0);
expect('the approval option is not created when the store is absent', isset($GLOBALS['cfais_options']['wpai_connector_approvals']), false);
expect('the apply result does not contain the sentinel', strpos((string) json_encode($defaults_skipped), $defaults_token) === false, true);
expect('the stored row does not contain the sentinel', strpos((string) json_encode($defaults_saved), $defaults_token) === false, true);
expect(
    'the admin bar still says free because this reader has no saved budget',
    strpos(skyphusion_ai_neuron_bar_title(), 'free') !== false,
    true
);

$GLOBALS['cfais_stored'] = $defaults_saved;
$GLOBALS['cfais_stored']['neuron_cap'] = 2500;
$GLOBALS['cfais_embed_meta'][9]['skyphusion_ai_embed_hash'] = 'still-written';
defaults_list(skyphusion_ai_defaults_writing_id(), 'Text Generation');
$GLOBALS['skyphusion_ai_defaults_approval_exposed'] = true;
$defaults_again = skyphusion_ai_defaults_apply();
$defaults_row = $GLOBALS['cfais_options']['skyphusion_ai_settings'];
expect('the account list sets the writing model', $defaults_again['writing'], skyphusion_ai_defaults_writing_id());
expect('the stored writing model is the recommended id', $defaults_row['model_writing'], skyphusion_ai_defaults_writing_id());
expect('a saved budget is not replaced', $defaults_row['neuron_cap'], 2500);
expect('a second click does not turn embeddings on', $GLOBALS['cfais_options']['skyphusion_ai_vectorize']['created'], 0);
expect('a second click does not turn the assistant on', $GLOBALS['cfais_options']['skyphusion_ai_assistant']['enabled'], 0);
expect('a second click keeps text already stored on a post', $GLOBALS['cfais_embed_meta'][9]['skyphusion_ai_embed_hash'], 'still-written');
expect('an exposed store approves the cloudflare connector', $defaults_again['approval'], 1);
$defaults_basename = plugin_basename(SKYPHUSION_AI_FILE);
expect(
    'the approval matrix names this plugin',
    !empty($GLOBALS['cfais_options']['wpai_connector_approvals'][$defaults_basename]['cloudflare']),
    true
);
$defaults_notice = skyphusion_ai_defaults_notice($defaults_again);
expect('the notice does not contain the sentinel', strpos($defaults_notice, $defaults_token) === false, true);
expect('the notice says caching is on', strpos($defaults_notice, 'Caching is on.') !== false, true);

unset($GLOBALS['skyphusion_ai_models_runtime']);
$GLOBALS['cfais_stored']['cache'] = '1';
skyphusion_ai_prompt_cache_write('writing', 'model', 'prompt', '', 'cached answer');
expect('caching on reads a remembered answer', skyphusion_ai_prompt_cache_read('writing', 'model', 'prompt'), 'cached answer');
$GLOBALS['cfais_stored']['cache'] = '0';
expect('caching off does not read a remembered answer', skyphusion_ai_prompt_cache_read('writing', 'model', 'prompt'), null);
$GLOBALS['cfais_stored']['cache'] = '1';
$defaults_gateway = 'https://gateway.ai.cloudflare.com/v1/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/store/workers-ai';
$defaults_headers = skyphusion_ai_gateway_metadata_header($defaults_gateway, 'text', skyphusion_ai_defaults_writing_id());
expect('caching on sets the gateway cache header', isset($defaults_headers['cf-aig-cache-ttl']) ? $defaults_headers['cf-aig-cache-ttl'] : '', '3600');
$defaults_direct = skyphusion_ai_gateway_metadata_header('https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/x', 'text', 'x');
expect('a direct call still has no cache header', $defaults_direct, array());

$GLOBALS['cfais_can']['manage_options'] = true;
ob_start();
skyphusion_ai_defaults_render();
$defaults_html = ob_get_clean();
expect('the button is labelled Apply recommended defaults', strpos($defaults_html, 'Apply recommended defaults') !== false, true);
expect('the button posts skyphusion_ai_defaults', strpos($defaults_html, 'skyphusion_ai_defaults') !== false, true);
expect('the button does not print the sentinel', strpos($defaults_html, $defaults_token) === false, true);
$GLOBALS['cfais_can']['manage_options'] = false;
ob_start();
skyphusion_ai_defaults_render();
$defaults_hidden = ob_get_clean();
expect('the button is hidden without manage_options', $defaults_hidden, '');

$defaults_stripped = array(
    'endpoint' => 'https://abc123.search.ai.cloudflare.com',
    'model_writing' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
    'model_vision' => '@cf/mistralai/mistral-small-3.1-24b-instruct',
    'model_embeddings' => '@cf/baai/bge-large-en-v1.5',
    'neuron_cap' => 10000,
    'neuron_over' => 'pause',
);
$defaults_original = array(
    'endpoint' => 'https://kept.search.ai.cloudflare.com',
    'model_writing' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
);
$GLOBALS['skyphusion_ai_defaults_apply'] = array(
    'writing' => skyphusion_ai_defaults_writing_id(),
    'budget' => 10000,
    'cache' => 1,
    'approval' => 0,
);
$defaults_kept = skyphusion_ai_defaults_sanitize_option($defaults_stripped, 'skyphusion_ai_settings', $defaults_original);
expect('the rebuild keeps the recommended writing model', $defaults_kept['model_writing'], skyphusion_ai_defaults_writing_id());
expect('the rebuild does not store the other model ids', isset($defaults_kept['model_vision']), false);
expect('the rebuild does not store the embeddings model', isset($defaults_kept['model_embeddings']), false);
expect('the rebuild turns caching on', $defaults_kept['cache'], '1');
expect('the rebuild saves the daily budget', $defaults_kept['neuron_cap'], 10000);
expect('the rebuild does not add the over-budget choice', isset($defaults_kept['neuron_over']), false);
unset($GLOBALS['skyphusion_ai_defaults_apply']);
$defaults_later = skyphusion_ai_defaults_sanitize_option(
    array('endpoint' => 'https://abc123.search.ai.cloudflare.com'),
    'skyphusion_ai_settings',
    array('cache' => '1', 'neuron_cap' => 2500)
);
expect('a later save keeps caching on', $defaults_later['cache'], '1');
expect('a later save does not change a budget it did not own', isset($defaults_later['neuron_cap']), false);

expect('defaults sent no mail', isset($GLOBALS['cfais_mail']) ? count($GLOBALS['cfais_mail']) : 0, $defaults_mail_before);

unset($GLOBALS['skyphusion_ai_defaults_approval_exposed']);
unset($GLOBALS['skyphusion_ai_models_runtime']);
unset($GLOBALS['cfais_store']['skyphusion_ai_models']);
unset($GLOBALS['cfais_options']['skyphusion_ai_assistant']);
unset($GLOBALS['cfais_options']['skyphusion_ai_backfill']);
unset($GLOBALS['cfais_options']['skyphusion_ai_vectorize']);
unset($GLOBALS['cfais_options']['wpai_connector_approvals']);
unset($GLOBALS['cfais_options']['skyphusion_ai_settings']);
unset($GLOBALS['cfais_embed_meta'][9]);
if ($defaults_saved_account === null) {
    unset($GLOBALS['cfais_stored']['account_id']);
} else {
    $GLOBALS['cfais_stored']['account_id'] = $defaults_saved_account;
}
if ($defaults_saved_endpoint === null) {
    unset($GLOBALS['cfais_stored']['endpoint']);
} else {
    $GLOBALS['cfais_stored']['endpoint'] = $defaults_saved_endpoint;
}
if ($defaults_had_cap) {
    $GLOBALS['cfais_stored']['neuron_cap'] = $defaults_saved_cap;
} else {
    unset($GLOBALS['cfais_stored']['neuron_cap']);
}
if ($defaults_had_cache) {
    $GLOBALS['cfais_stored']['cache'] = $defaults_saved_cache;
} else {
    unset($GLOBALS['cfais_stored']['cache']);
}
if ($defaults_had_writing) {
    $GLOBALS['cfais_stored']['model_writing'] = $defaults_saved_writing;
} else {
    unset($GLOBALS['cfais_stored']['model_writing']);
}
$GLOBALS['cfais_can'] = $defaults_can;
if (is_string($defaults_saved_env) && $defaults_saved_env !== '') {
    putenv('CLOUDFLARE_API_KEY=' . $defaults_saved_env);
} else {
    putenv('CLOUDFLARE_API_KEY');
}
