<?php
/**
 * Site assistant. Loaded after insights-test.php.
 * The assistant is off until the admin turns it on.
 */

if (!function_exists('skyphusion_ai_assistant_ask')) {
    require_once dirname(__DIR__) . '/includes/assistant.php';
}

$assistant_prev_key = getenv('CLOUDFLARE_API_KEY');
$assistant_prev_account = getenv('CLOUDFLARE_ACCOUNT_ID');
$assistant_token = 'sentinel-token-' . str_repeat('a', 30);
putenv('CLOUDFLARE_API_KEY=' . $assistant_token);
putenv('CLOUDFLARE_ACCOUNT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
$GLOBALS['cfais_settings'] = array(
    'endpoint' => 'https://abc123.search.ai.cloudflare.com',
    'lead_search' => true,
    'account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'gateway_id' => '',
);
$GLOBALS['cfais_status'] = 200;

function assistant_clear()
{
    $GLOBALS['cfais_calls'] = 0;
    unset($GLOBALS['cfais_bodies']);
    $GLOBALS['cfais_last_post'] = array('marker' => 1);
    $GLOBALS['cfais_last_request'] = array();
}

$assistant_embed = '{"result":{"data":[[0.25,0.5]]}}';
$assistant_query = '{"success":true,"result":{"matches":[{"id":"p6","score":0.5,"metadata":{"title":"Linen shirt","post_id":"6","url":"https://shop.example/linen"}}]}}';
$assistant_text = '{"choices":[{"message":{"content":"The shirt is linen."}}]}';
$assistant_none = '{"success":true,"result":{"matches":[]}}';

assistant_clear();
$assistant_off = skyphusion_ai_assistant_ask('linen', false);
expect('the assistant is off by default', $assistant_off['state'], 'off');
expect('an off assistant makes no request', $GLOBALS['cfais_calls'], 0);
expect('the shortcode is empty while the assistant is off', skyphusion_ai_assistant_shortcode(), '');

skyphusion_ai_assistant_apply(array('enabled' => '1', 'save' => ''));
update_option('skyphusion_ai_vectorize', array(
    'index' => 'skyphusion-ai',
    'post_types' => array('post', 'product'),
    'fields' => array('title', 'content', 'excerpt'),
    'model' => '',
    'created' => 0,
    'related' => 0,
), false);
assistant_clear();
$assistant_index = skyphusion_ai_assistant_ask('linen', false);
expect('a missing index does not call the model', $assistant_index['state'], 'not_created');
expect('a missing index makes no request', $GLOBALS['cfais_calls'], 0);

embed_ready();
assistant_clear();
$GLOBALS['cfais_bodies'] = array($assistant_embed, $assistant_embed, $assistant_none);
$assistant_miss = skyphusion_ai_assistant_ask('unknown item', false);
expect('no matching page says so', $assistant_miss['state'], 'empty');
expect('no matching page uses the fixed sentence', $assistant_miss['answer'], 'I cannot find an answer on this site.');
expect('no matching page does not cite anything', $assistant_miss['citations'], array());
expect('no matching page does not call the text model', $GLOBALS['cfais_calls'], 2);
$assistant_post_url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
expect('the miss stays on the embeddings model', strpos($assistant_post_url, 'bge-large') !== false, true);
expect('the miss does not call the text model', strpos($assistant_post_url, 'llama') === false, true);

assistant_clear();
$GLOBALS['cfais_bodies'] = array($assistant_embed, $assistant_embed, $assistant_query, $assistant_text);
$assistant_hit = skyphusion_ai_assistant_ask('linen', false);
expect('a matching page is answered', $assistant_hit['state'], 'answered');
expect('the answer is the model sentence', $assistant_hit['answer'], 'The shirt is linen.');
expect('the citation is the stored title', $assistant_hit['citations'][0]['title'], 'Linen shirt');
expect('the answer makes three requests', $GLOBALS['cfais_calls'], 3);
$assistant_post_url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
$assistant_model = skyphusion_ai_assistant_model();
expect('the text call uses the text model', strpos($assistant_post_url, $assistant_model) !== false, true);
expect('the text url does not carry the token', strpos($assistant_post_url, $assistant_token) === false, true);
$assistant_request_url = isset($GLOBALS['cfais_last_request']['url']) ? (string) $GLOBALS['cfais_last_request']['url'] : '';
expect('retrieval stays on vectorize', strpos($assistant_request_url, '/vectorize/v2/') !== false, true);
$assistant_html = skyphusion_ai_assistant_html($assistant_hit);
expect('the page lists the citation', strpos($assistant_html, 'Linen shirt') !== false, true);
expect('the page shows the answer', strpos($assistant_html, 'The shirt is linen.') !== false, true);
expect('the page does not show the token', strpos($assistant_html, $assistant_token) === false, true);

$assistant_saved = get_option('skyphusion_ai_assistant_chats', array());
expect('a chat is not saved without consent', $assistant_saved, array());

skyphusion_ai_assistant_apply(array('save' => '1'));
assistant_clear();
$GLOBALS['cfais_bodies'] = array($assistant_embed, $assistant_embed, $assistant_query, $assistant_text);
skyphusion_ai_assistant_ask('linen', true);
$assistant_saved = get_option('skyphusion_ai_assistant_chats', array());
expect('consent stores the question', $assistant_saved[0]['question'], 'linen');
expect('consent stores the answer', $assistant_saved[0]['answer'], 'The shirt is linen.');
expect('a saved chat has no address', isset($assistant_saved[0]['ip']), false);

assistant_clear();
$GLOBALS['cfais_bodies'] = array($assistant_embed, $assistant_embed, $assistant_query, $assistant_text);
skyphusion_ai_assistant_ask('wool', false);
$assistant_saved = get_option('skyphusion_ai_assistant_chats', array());
expect('a later chat without consent is not added', count($assistant_saved), 1);

update_option('skyphusion_ai_neurons', array(
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 10000,
    'estimated' => 0,
    'features' => array(),
    'mailed_80' => 1,
    'mailed_100' => 1,
), false);
assistant_clear();
$assistant_capped = skyphusion_ai_assistant_ask('linen', false);
expect('a spent budget blocks the assistant', $assistant_capped['state'], 'blocked');
expect('a spent budget makes no request', $GLOBALS['cfais_calls'], 0);
expect('a spent budget names the pause', strpos($assistant_capped['answer'], 'paused') !== false, true);
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);

$GLOBALS['cfais_can']['manage_options'] = true;
ob_start();
skyphusion_ai_assistant_render();
$assistant_screen = (string) ob_get_clean();
expect('the screen offers the assistant switch', strpos($assistant_screen, 'name="skyphusion_ai_assistant_enabled"') !== false, true);
expect('the screen says the chat button stays', strpos($assistant_screen, 'The chat button stays as it is.') !== false, true);
expect('the screen does not show the token', strpos($assistant_screen, $assistant_token) === false, true);

$assistant_form = skyphusion_ai_assistant_shortcode();
expect('the shortcode prints a form when the assistant is on', strpos($assistant_form, 'skyphusion_ai_assistant_question') !== false, true);
expect('the form asks before saving', strpos($assistant_form, 'skyphusion_ai_assistant_consent') !== false, true);

$assistant_src = (string) file_get_contents(dirname(__DIR__) . '/includes/assistant.php');
expect('the assistant does not read a remote address', strpos($assistant_src, 'REMOTE_ADDR') === false, true);
expect('the assistant uses the embedding index', strpos($assistant_src, 'skyphusion_ai_embed_search') !== false, true);
expect('the assistant stops at the neuron budget', strpos($assistant_src, 'skyphusion_ai_neuron_refusal') !== false, true);
$assistant_uninstall = (string) file_get_contents(dirname(__DIR__) . '/uninstall.php');
expect('uninstall removes the assistant option', strpos($assistant_uninstall, 'skyphusion_ai_assistant') !== false, true);

unset($GLOBALS['cfais_settings']);
unset($GLOBALS['cfais_bodies']);
unset($GLOBALS['cfais_options']['skyphusion_ai_assistant']);
unset($GLOBALS['cfais_options']['skyphusion_ai_assistant_chats']);
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
unset($GLOBALS['cfais_can']['manage_options']);
if (false === $assistant_prev_key || '' === $assistant_prev_key) {
    putenv('CLOUDFLARE_API_KEY');
} else {
    putenv('CLOUDFLARE_API_KEY=' . $assistant_prev_key);
}
if (false === $assistant_prev_account || '' === $assistant_prev_account) {
    putenv('CLOUDFLARE_ACCOUNT_ID');
} else {
    putenv('CLOUDFLARE_ACCOUNT_ID=' . $assistant_prev_account);
}
