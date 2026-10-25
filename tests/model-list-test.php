<?php
/**
 * Account model list, the 12 hour cache, and the per-job picker.
 *
 * The built-in seven stay when the list is empty. A model that is not in
 * that seven and not on the account list is not allowed. The stored cache
 * does not contain the token.
 */

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = array())
    {
        $GLOBALS['cfais_model_gets'][] = array('url' => $url, 'args' => $args);
        if (isset($GLOBALS['cfais_model_response'])) {
            return $GLOBALS['cfais_model_response'];
        }
        return array('url' => $url);
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        return isset($GLOBALS['cfais_model_status']) ? (int) $GLOBALS['cfais_model_status'] : 200;
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return $value;
    }
}

$cfais_model_account = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$cfais_model_token = 'sentinel-token-must-not-appear-in-any-message';
$GLOBALS['cfais_stored']['account_id'] = $cfais_model_account;
$GLOBALS['cfais_transients'] = array();
$GLOBALS['cfais_model_gets'] = array();
unset($GLOBALS['skyphusion_ai_models_runtime']);

$cfais_saved_key = getenv('CLOUDFLARE_API_KEY');
$cfais_saved_status = isset($GLOBALS['cfais_status']) ? $GLOBALS['cfais_status'] : 200;
putenv('CLOUDFLARE_API_KEY=' . $cfais_model_token);

expect('a successful list is kept for 12 hours', skyphusion_ai_account_models_ttl(), 43200);

$cfais_parsed = skyphusion_ai_account_models_from_body(array(
    'success' => true,
    'result' => array(
        array(
            'id' => '@cf/example/new-writer',
            'name' => 'New writer',
            'task' => array('name' => 'Text Generation'),
        ),
        array('id' => 'not a model', 'name' => 'Dropped'),
        array(
            'id' => '@cf/example/new-picture',
            'name' => 'New picture',
            'task' => 'text-to-image',
        ),
    ),
));
expect('the parser keeps a real model id', $cfais_parsed[0]['id'], '@cf/example/new-writer');
expect('and reads a task object', $cfais_parsed[0]['task'], 'text generation');
expect('and drops an id that is not a model', count($cfais_parsed), 2);

$cfais_text = skyphusion_ai_account_model_kind('text generation');
expect('text generation stays text', $cfais_text[0], 'text');
$cfais_image = skyphusion_ai_account_model_kind('text-to-image');
expect('text-to-image is an image model', $cfais_image[0], 'image');
$cfais_speech = skyphusion_ai_account_model_kind('text-to-speech');
expect('text-to-speech is a speech model', $cfais_speech[0], 'speech');
$cfais_embed = skyphusion_ai_account_model_kind('text embeddings');
expect('an embeddings task is embeddings', $cfais_embed[0], 'embeddings');
$cfais_vision = skyphusion_ai_account_model_kind('image-to-text');
expect('image-to-text is vision', $cfais_vision[1], true);
expect('and it stays a text model', $cfais_vision[0], 'text');

expect(
    'a missing choice uses the built-in writing model',
    skyphusion_ai_model_choice('writing'),
    '@cf/meta/llama-3.3-70b-instruct-fp8-fast'
);
$GLOBALS['cfais_stored']['model_embeddings'] = '@cf/baai/bge-small-en-v1.5';
expect(
    'a stored embeddings id is the embeddings model',
    cloudflare_ai_provider_embeddings_model(),
    '@cf/baai/bge-small-en-v1.5'
);
unset($GLOBALS['cfais_stored']['model_embeddings']);
expect(
    'clearing it returns the built-in embeddings model',
    cloudflare_ai_provider_embeddings_model(),
    '@cf/baai/bge-large-en-v1.5'
);

$cfais_account_rows = array(
    array('id' => '@cf/example/new-writer', 'name' => 'New writer', 'task' => 'text generation'),
    array('id' => '@cf/example/new-picture', 'name' => 'New picture', 'task' => 'text-to-image'),
);
expect(
    'a built-in writing model is allowed with no account list',
    skyphusion_ai_model_id_allowed('@cf/meta/llama-3.3-70b-instruct-fp8-fast', 'writing', array()),
    true
);
expect(
    'an unknown id is not allowed',
    skyphusion_ai_model_id_allowed('@cf/example/no-such', 'writing', array()),
    false
);
expect(
    'an account text model is allowed for writing',
    skyphusion_ai_model_id_allowed('@cf/example/new-writer', 'writing', $cfais_account_rows),
    true
);
expect(
    'that same id is not a vision model',
    skyphusion_ai_model_id_allowed('@cf/example/new-writer', 'vision', $cfais_account_rows),
    false
);

$GLOBALS['skyphusion_ai_models_runtime'] = $cfais_account_rows;
$cfais_options = skyphusion_ai_model_options('writing', $cfais_account_rows);
$cfais_option_ids = array();
foreach ($cfais_options as $cfais_option) {
    $cfais_option_ids[] = $cfais_option['id'];
}
expect('the writing picker lists the account model', in_array('@cf/example/new-writer', $cfais_option_ids, true), true);
expect('and still lists the built-in writing model', in_array('@cf/meta/llama-3.3-70b-instruct-fp8-fast', $cfais_option_ids, true), true);

$GLOBALS['skyphusion_ai_models_runtime'] = array(
    array('id' => '@cf/example/new-writer', 'name' => 'New writer', 'task' => 'text generation'),
    array('id' => '@cf/example/new-embed', 'name' => 'New embed', 'task' => 'embeddings'),
    array('id' => '@cf/example/new-voice', 'name' => 'New voice', 'task' => 'text-to-speech'),
);
$cfais_directory_ids = array();
foreach (skyphusion_ai_model_directory_rows() as $cfais_directory_row) {
    $cfais_directory_ids[] = $cfais_directory_row['id'];
}
expect('an account embeddings model is not in the AI Client directory', in_array('@cf/example/new-embed', $cfais_directory_ids, true), false);
expect('an account speech model stays in the AI Client directory', in_array('@cf/example/new-voice', $cfais_directory_ids, true), true);
expect('the AI Client does not construct an embeddings model', skyphusion_ai_model_resolved_entry('@cf/example/new-embed'), null);
$cfais_voice_entry = skyphusion_ai_model_resolved_entry('@cf/example/new-voice');
expect('an account speech model still resolves', is_array($cfais_voice_entry) && $cfais_voice_entry['kind'] === 'speech', true);
$cfais_embed_options = skyphusion_ai_model_options('embeddings');
$cfais_embed_ids = array();
foreach ($cfais_embed_options as $cfais_embed_option) {
    $cfais_embed_ids[] = $cfais_embed_option['id'];
}
expect('the embeddings picker still lists that model', in_array('@cf/example/new-embed', $cfais_embed_ids, true), true);
$GLOBALS['skyphusion_ai_models_runtime'] = $cfais_account_rows;

$cfais_promoted = skyphusion_ai_model_promote(
    cloudflare_ai_provider_catalog(),
    array('writing' => '@cf/meta/llama-3.2-11b-vision-instruct')
);
expect('the chosen writing model moves to the front', $cfais_promoted[0]['id'], '@cf/meta/llama-3.2-11b-vision-instruct');

$GLOBALS['cfais_notices'] = array();
$_POST['skyphusion_ai_settings'] = array('model_writing' => '');
$cfais_empty = skyphusion_ai_model_sanitize_option(array('endpoint' => ''), 'skyphusion_ai_settings', array());
expect('an empty picker stores the built-in writing model', $cfais_empty['model_writing'], '@cf/meta/llama-3.3-70b-instruct-fp8-fast');
expect('and stays silent', $GLOBALS['cfais_notices'], array());

$_POST['skyphusion_ai_settings'] = array('model_writing' => '@cf/example/new-writer');
$GLOBALS['cfais_notices'] = array();
$cfais_saved = skyphusion_ai_model_sanitize_option(array('endpoint' => ''), 'skyphusion_ai_settings', array('model_writing' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast'));
expect('a listed account model is stored', $cfais_saved['model_writing'], '@cf/example/new-writer');

$_POST['skyphusion_ai_settings'] = array('model_writing' => '@cf/example/no-such');
$GLOBALS['cfais_notices'] = array();
$cfais_kept = skyphusion_ai_model_sanitize_option(
    array('endpoint' => ''),
    'skyphusion_ai_settings',
    array('model_writing' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast')
);
expect('an id that is not on the list keeps the previous model', $cfais_kept['model_writing'], '@cf/meta/llama-3.3-70b-instruct-fp8-fast');
expect('and tells the admin', $GLOBALS['cfais_notices'], array('model_writing'));

$_POST['skyphusion_ai_settings'] = array('model_light' => '');
$GLOBALS['cfais_notices'] = array();
$cfais_light_empty = skyphusion_ai_model_sanitize_option(array('endpoint' => ''), 'skyphusion_ai_settings', array('model_light' => '@cf/example/new-writer'));
expect('an empty light field stays empty', $cfais_light_empty['model_light'], '');
expect('an empty light field stays quiet', $GLOBALS['cfais_notices'], array());

$_POST['skyphusion_ai_settings'] = array('model_light' => '@cf/example/new-writer');
$GLOBALS['cfais_notices'] = array();
$cfais_light_saved = skyphusion_ai_model_sanitize_option(array('endpoint' => ''), 'skyphusion_ai_settings', array());
expect('a listed light model is stored', $cfais_light_saved['model_light'], '@cf/example/new-writer');

$_POST['skyphusion_ai_settings'] = array('model_light' => '@cf/example/no-such');
$GLOBALS['cfais_notices'] = array();
$cfais_light_kept = skyphusion_ai_model_sanitize_option(
    array('endpoint' => ''),
    'skyphusion_ai_settings',
    array('model_light' => '@cf/example/new-writer')
);
expect('an unlisted light model stays the previous choice', $cfais_light_kept['model_light'], '@cf/example/new-writer');
expect('an unlisted light model tells the admin', $GLOBALS['cfais_notices'], array('model_light'));

unset($_POST['skyphusion_ai_settings']);
$cfais_light_omit = skyphusion_ai_model_sanitize_option(
    array('endpoint' => ''),
    'skyphusion_ai_settings',
    array('model_light' => '@cf/example/new-writer')
);
expect('an omitted light field keeps the stored id', $cfais_light_omit['model_light'], '@cf/example/new-writer');

if (!isset($GLOBALS['cfais_stored']) || !is_array($GLOBALS['cfais_stored'])) {
    $GLOBALS['cfais_stored'] = array();
}
$cfais_light_was = array_key_exists('model_light', $GLOBALS['cfais_stored']) ? $GLOBALS['cfais_stored']['model_light'] : null;
unset($GLOBALS['cfais_stored']['model_light']);
expect('an empty light field uses the writing model', skyphusion_ai_light_model(), skyphusion_ai_model_choice('writing'));
expect('a short task with no light model uses the writing model', skyphusion_ai_model_for_task('meta'), skyphusion_ai_model_choice('writing'));
$GLOBALS['cfais_stored']['model_light'] = '@cf/example/new-writer';
expect('a saved light model is used for a meta description', skyphusion_ai_model_for_task('meta'), '@cf/example/new-writer');
expect('a saved light model is used for alt wording', skyphusion_ai_model_for_task('alt'), '@cf/example/new-writer');
expect(
    'a model the editor picked stays that model',
    skyphusion_ai_model_for_task('meta', '@cf/meta/llama-3.3-70b-instruct-fp8-fast'),
    '@cf/meta/llama-3.3-70b-instruct-fp8-fast'
);
expect('a long task keeps the writing model', skyphusion_ai_model_for_task('chat'), skyphusion_ai_model_choice('writing'));
if (null === $cfais_light_was) {
    unset($GLOBALS['cfais_stored']['model_light']);
} else {
    $GLOBALS['cfais_stored']['model_light'] = $cfais_light_was;
}

unset($_POST['skyphusion_ai_settings']);
$cfais_absent = skyphusion_ai_model_sanitize_option(
    array('endpoint' => ''),
    'skyphusion_ai_settings',
    array('model_image' => '@cf/black-forest-labs/flux-2-dev')
);
expect('a save that omits the picker keeps the stored image model', $cfais_absent['model_image'], '@cf/black-forest-labs/flux-2-dev');

unset($GLOBALS['skyphusion_ai_models_runtime']);
$GLOBALS['cfais_model_gets'] = array();
$GLOBALS['cfais_status'] = 200;
$GLOBALS['cfais_model_response'] = array('ok' => true);
$GLOBALS['cfais_body'] = json_encode(array(
    'success' => true,
    'result' => array(
        array('id' => '@cf/example/new-writer', 'name' => 'New writer', 'task' => 'text generation'),
    ),
));
$cfais_fresh = skyphusion_ai_account_models_refresh();
expect('a live list returns the account model', $cfais_fresh[0]['id'], '@cf/example/new-writer');
$cfais_cache_row = $GLOBALS['cfais_store']['skyphusion_ai_models'];
expect(
    'and the cache lasts 12 hours',
    $cfais_cache_row['expires'] - time() >= 43190 && $cfais_cache_row['expires'] - time() <= 43200,
    true
);
$cfais_cached = json_encode($cfais_cache_row['value']);
expect('the cache does not contain the token', strpos((string) $cfais_cached, $cfais_model_token), false);
expect('the request url does not contain the token', strpos((string) $GLOBALS['cfais_model_gets'][0]['url'], $cfais_model_token), false);

$GLOBALS['cfais_model_gets'] = array();
$GLOBALS['cfais_status'] = 500;
unset($GLOBALS['skyphusion_ai_models_runtime']);
$cfais_failed = skyphusion_ai_account_models_refresh();
expect('a failed read returns no models', $cfais_failed, array());
$cfais_fail_row = $GLOBALS['cfais_store']['skyphusion_ai_models'];
expect(
    'and is remembered for 10 minutes',
    $cfais_fail_row['expires'] - time() >= 590 && $cfais_fail_row['expires'] - time() <= 600,
    true
);
$cfais_failed_row = json_encode($cfais_fail_row['value']);
expect('the failure row does not contain the token', strpos((string) $cfais_failed_row, $cfais_model_token), false);
$GLOBALS['cfais_model_gets'] = array();
skyphusion_ai_account_models_current();
expect('a failed read is not fetched again', $GLOBALS['cfais_model_gets'], array());

$cfais_models = (string) file_get_contents(dirname(__DIR__) . '/includes/provider-models.php');
expect(
    'the directory asks the resolver before the catalog error',
    strpos($cfais_models, 'skyphusion_ai_model_resolved_entry') !== false
        && strpos($cfais_models, 'skyphusion_ai_model_resolved_entry') < strpos($cfais_models, 'That Cloudflare model is not in the catalog.'),
    true
);
$cfais_resolver_src = (string) file_get_contents(dirname(__DIR__) . '/includes/models.php');
$cfais_resolver_at = strpos($cfais_resolver_src, 'function skyphusion_ai_model_resolved_entry');
$cfais_resolver_end = strpos($cfais_resolver_src, 'function skyphusion_ai_account_model_entry');
$cfais_resolver = substr($cfais_resolver_src, $cfais_resolver_at, $cfais_resolver_end - $cfais_resolver_at);
expect(
    'the resolver asks the account list when the catalog misses',
    strpos($cfais_resolver, 'cloudflare_ai_provider_catalog_entry') !== false
        && strpos($cfais_resolver, 'skyphusion_ai_account_model_entry') !== false
        && strpos($cfais_resolver, 'cloudflare_ai_provider_catalog_entry') < strpos($cfais_resolver, 'skyphusion_ai_account_model_entry'),
    true
);

unset($GLOBALS['skyphusion_ai_models_runtime']);
unset($GLOBALS['cfais_model_response'], $GLOBALS['cfais_body']);
$GLOBALS['cfais_status'] = $cfais_saved_status;
unset($GLOBALS['cfais_stored']['account_id'], $GLOBALS['cfais_stored']['model_writing'], $GLOBALS['cfais_stored']['model_image'], $GLOBALS['cfais_stored']['model_embeddings']);
if (is_string($cfais_saved_key)) {
    putenv('CLOUDFLARE_API_KEY=' . $cfais_saved_key);
} else {
    putenv('CLOUDFLARE_API_KEY');
}
