<?php
/**
 * Reset clears plugin state and keeps text already stored on posts.
 * Issue #125.
 *
 * The Vectorize index is not deleted. A credential is not printed.
 */

$reset_secret = 'reset-sentinel-not-printed';
$reset_saved_env = getenv('CLOUDFLARE_API_KEY');
$reset_saved_stored = $GLOBALS['cfais_stored'];
$reset_mail_before = isset($GLOBALS['cfais_mail']) ? count($GLOBALS['cfais_mail']) : 0;
$reset_calls_before = isset($GLOBALS['cfais_calls']) ? (int) $GLOBALS['cfais_calls'] : 0;
$reset_can = isset($GLOBALS['cfais_can']) ? $GLOBALS['cfais_can'] : array();

if (!function_exists('delete_option')) {
    function delete_option($name)
    {
        unset($GLOBALS['cfais_options'][$name]);
        if ('skyphusion_ai_settings' === $name) {
            $GLOBALS['cfais_stored'] = array();
        }
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient($name)
    {
        unset($GLOBALS['cfais_store'][$name]);
        return true;
    }
}

require_once dirname(__DIR__) . '/includes/reset.php';

$reset_src = (string) file_get_contents(dirname(__DIR__) . '/includes/reset.php');
expect('reset does not send mail', strpos($reset_src, 'wp_mail') === false, true);
expect('reset does not delete post meta', strpos($reset_src, 'delete_post_meta') === false, true);
expect('reset does not call the index delete', strpos($reset_src, 'embed_delete') === false, true);
expect('reset does not call the network', strpos($reset_src, 'wp_remote_') === false, true);

expect('an empty confirm does not run', skyphusion_ai_reset_confirmed(array()), false);
expect('a ticked box confirms the reset', skyphusion_ai_reset_confirmed(array('skyphusion_ai_reset_confirm' => '1')), true);

putenv('CLOUDFLARE_API_KEY=' . $reset_secret);
$GLOBALS['cfais_stored']['endpoint'] = 'https://abc123.search.ai.cloudflare.com';
$GLOBALS['cfais_stored']['account_id'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$GLOBALS['cfais_options']['skyphusion_ai_prompts'] = array('assistant' => 'Custom prompt text.');
$GLOBALS['cfais_options']['skyphusion_ai_prompt_last'] = array('assistant' => 'last');
$GLOBALS['cfais_options']['skyphusion_ai_neurons'] = array('day' => '2026-10-26', 'neurons' => 40);
$GLOBALS['cfais_options']['skyphusion_ai_backfill'] = array('enabled' => 1, 'neurons' => 12);
$GLOBALS['cfais_options']['skyphusion_ai_insights'] = array(array('text' => 'red shoes'));
$GLOBALS['cfais_options']['skyphusion_ai_search_outcomes'] = array('day' => '2026-10-26');
$GLOBALS['cfais_options']['skyphusion_ai_vectorize'] = array('created' => 1, 'index' => 'shop');
$GLOBALS['cfais_options']['skyphusion_ai_assistant'] = array('enabled' => 1);
$GLOBALS['cfais_options']['skyphusion_ai_assistant_chats'] = array(array('q' => 'hello'));
$GLOBALS['cfais_options']['skyphusion_ai_gateway_down'] = time() + 100;
$GLOBALS['cfais_options']['connectors_ai_cloudflare_api_key'] = $reset_secret;
$GLOBALS['cfais_options']['wpai_connector_approvals'] = array(
    'skyphusion-ai.php' => array('cloudflare' => true),
    'other/plugin.php' => array('cloudflare' => true),
);
$GLOBALS['cfais_store']['skyphusion_ai_models'] = array('value' => array('ok' => 1), 'expires' => 0, 'ttl' => 0);
$GLOBALS['cfais_store']['skyphusion_ai_pc_abc'] = array('value' => array('text' => 'cached'), 'expires' => 0, 'ttl' => 0);
$GLOBALS['cfais_embed_meta'][4]['_yoast_wpseo_metadesc'] = 'A description already written.';
$GLOBALS['cfais_embed_meta'][4]['_wp_attachment_image_alt'] = 'Alt already written.';
$GLOBALS['cfais_embed_meta'][4]['skyphusion_ai_embed_hash'] = 'hash-of-stored-text';
$GLOBALS['cfais_embed_meta'][4]['_skyphusion_ai_decorative_alt'] = '1';
$GLOBALS['skyphusion_ai_backfill_aioseo'][4] = 'All in One SEO text.';
$reset_meta_before = $GLOBALS['cfais_embed_meta'][4];

$reset_result = skyphusion_ai_reset_apply();
expect('settings are cleared', $GLOBALS['cfais_stored'], array());
expect('prompt edits are cleared', isset($GLOBALS['cfais_options']['skyphusion_ai_prompts']), false);
expect('the last prompt row is cleared', isset($GLOBALS['cfais_options']['skyphusion_ai_prompt_last']), false);
expect('usage is cleared', isset($GLOBALS['cfais_options']['skyphusion_ai_neurons']), false);
expect('backfill progress is cleared', isset($GLOBALS['cfais_options']['skyphusion_ai_backfill']), false);
expect('search logs are cleared', isset($GLOBALS['cfais_options']['skyphusion_ai_insights']), false);
expect('search counts are cleared', isset($GLOBALS['cfais_options']['skyphusion_ai_search_outcomes']), false);
expect('the local index record is cleared', isset($GLOBALS['cfais_options']['skyphusion_ai_vectorize']), false);
expect('assistant settings are cleared', isset($GLOBALS['cfais_options']['skyphusion_ai_assistant']), false);
expect('assistant chats are cleared', isset($GLOBALS['cfais_options']['skyphusion_ai_assistant_chats']), false);
expect('the model list cache is cleared', isset($GLOBALS['cfais_store']['skyphusion_ai_models']), false);
expect('a prompt cache row is cleared', isset($GLOBALS['cfais_store']['skyphusion_ai_pc_abc']), false);
expect('the connector credential option stays', $GLOBALS['cfais_options']['connectors_ai_cloudflare_api_key'], $reset_secret);
expect('another plugin approval stays', !empty($GLOBALS['cfais_options']['wpai_connector_approvals']['other/plugin.php']['cloudflare']), true);
expect('this plugin approval is dropped', isset($GLOBALS['cfais_options']['wpai_connector_approvals']['skyphusion-ai.php']), false);
expect('a meta description stays on the post', $GLOBALS['cfais_embed_meta'][4]['_yoast_wpseo_metadesc'], 'A description already written.');
expect('alt text stays on the post', $GLOBALS['cfais_embed_meta'][4]['_wp_attachment_image_alt'], 'Alt already written.');
expect('the embed hash stays', $GLOBALS['cfais_embed_meta'][4]['skyphusion_ai_embed_hash'], 'hash-of-stored-text');
expect('a decorative image mark stays', $GLOBALS['cfais_embed_meta'][4]['_skyphusion_ai_decorative_alt'], '1');
expect('the post meta row is unchanged', $GLOBALS['cfais_embed_meta'][4], $reset_meta_before);
expect('All in One SEO text stays', $GLOBALS['skyphusion_ai_backfill_aioseo'][4], 'All in One SEO text.');
expect('the result says the index is kept', $reset_result['index'], 'kept');
expect('the result does not contain the sentinel', strpos((string) json_encode($reset_result), $reset_secret) === false, true);
expect('the notice does not contain the sentinel', strpos(skyphusion_ai_reset_notice(), $reset_secret) === false, true);
expect('reset does not call the network', isset($GLOBALS['cfais_calls']) ? (int) $GLOBALS['cfais_calls'] : 0, $reset_calls_before);
expect('the environment credential is unchanged', getenv('CLOUDFLARE_API_KEY'), $reset_secret);
expect('kept meta includes the alt key', in_array('_wp_attachment_image_alt', skyphusion_ai_reset_kept_meta_keys(), true), true);
expect('kept meta includes the embed hash', in_array('skyphusion_ai_embed_hash', skyphusion_ai_reset_kept_meta_keys(), true), true);

$GLOBALS['cfais_can']['manage_options'] = true;
ob_start();
skyphusion_ai_reset_render();
$reset_html = ob_get_clean();
expect('the button says Reset this plugin', strpos($reset_html, 'Reset this plugin') !== false, true);
expect('the button does not print the sentinel', strpos($reset_html, $reset_secret) === false, true);
$GLOBALS['cfais_can']['manage_options'] = false;
ob_start();
skyphusion_ai_reset_render();
$reset_hidden = ob_get_clean();
expect('the button is hidden without manage_options', $reset_hidden, '');

expect('reset sent no mail', isset($GLOBALS['cfais_mail']) ? count($GLOBALS['cfais_mail']) : 0, $reset_mail_before);

$GLOBALS['cfais_stored'] = $reset_saved_stored;
unset($GLOBALS['cfais_options']['connectors_ai_cloudflare_api_key']);
unset($GLOBALS['cfais_options']['wpai_connector_approvals']);
unset($GLOBALS['cfais_embed_meta'][4]);
unset($GLOBALS['skyphusion_ai_backfill_aioseo']);
$GLOBALS['cfais_can'] = $reset_can;
if (is_string($reset_saved_env) && '' !== $reset_saved_env) {
    putenv('CLOUDFLARE_API_KEY=' . $reset_saved_env);
} else {
    putenv('CLOUDFLARE_API_KEY');
}
