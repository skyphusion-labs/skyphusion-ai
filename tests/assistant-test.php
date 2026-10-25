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
$assistant_defaults = skyphusion_ai_assistant_config();
expect('the audience starts as roles', $assistant_defaults['audience'], 'roles');
expect('the capability starts as read', $assistant_defaults['capability'], 'read');
expect('rules start empty', $assistant_defaults['rules'], '');
expect('blocked topics start empty', $assistant_defaults['blocked'], array());
expect('the question check starts off', $assistant_defaults['safety'], 0);
expect('the assistant limit starts at zero', $assistant_defaults['limit'], 0);
expect('ten earlier turns is the default', $assistant_defaults['turns'], 10);
expect('the assistant stays off', $assistant_defaults['enabled'], 0);
$GLOBALS['cfais_user_id'] = 4;
$GLOBALS['cfais_can']['read'] = true;

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

$GLOBALS['cfais_user_id'] = 0;
$GLOBALS['cfais_can']['read'] = true;
assistant_clear();
$assistant_closed = skyphusion_ai_assistant_ask('linen', false);
expect('a logged-out visitor stays out', $assistant_closed['state'], 'closed');
expect('a logged-out visitor makes no request', $GLOBALS['cfais_calls'], 0);
$GLOBALS['cfais_user_id'] = 4;
unset($GLOBALS['cfais_can']['read']);
assistant_clear();
$assistant_nocap = skyphusion_ai_assistant_ask('linen', false);
expect('a member without the capability stays out', $assistant_nocap['state'], 'closed');
expect('a member without the capability makes no request', $GLOBALS['cfais_calls'], 0);
$GLOBALS['cfais_can']['read'] = true;
skyphusion_ai_assistant_apply(array('audience' => 'public'));
$GLOBALS['cfais_user_id'] = 0;
assistant_clear();
$GLOBALS['cfais_bodies'] = array($assistant_embed, $assistant_embed, $assistant_query, $assistant_text);
$assistant_public = skyphusion_ai_assistant_ask('linen', false);
expect('a public visitor is answered', $assistant_public['state'], 'answered');
$assistant_public_url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
expect('a public visitor does not call siteverify', strpos($assistant_public_url, 'siteverify') === false, true);
skyphusion_ai_assistant_apply(array('audience' => 'roles'));
$GLOBALS['cfais_user_id'] = 4;
update_option('skyphusion_ai_assistant', array_merge(skyphusion_ai_assistant_config(), array(
    'enabled' => 1,
    'limit' => 5,
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 5,
)), false);
assistant_clear();
$assistant_limited = skyphusion_ai_assistant_ask('linen', false);
expect('a spent assistant limit blocks the assistant', $assistant_limited['state'], 'blocked');
expect('a spent assistant limit makes no request', $GLOBALS['cfais_calls'], 0);
expect('a spent assistant limit leaves the site budget open', skyphusion_ai_neuron_at_cap(), false);
expect('a spent assistant limit leaves the text tool open', skyphusion_ai_tool_blocked('text'), false);
update_option('skyphusion_ai_assistant', array_merge(skyphusion_ai_assistant_config(), array(
    'enabled' => 1,
    'limit' => 5,
    'day' => '2000-01-01',
    'neurons' => 5,
)), false);
expect('yesterday does not spend the assistant', skyphusion_ai_assistant_limit_blocked(skyphusion_ai_assistant_config()), false);
update_option('skyphusion_ai_assistant', array_merge(skyphusion_ai_assistant_config(), array(
    'enabled' => 1,
    'limit' => 0,
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 9,
)), false);
expect('a zero assistant limit does not stop the job', skyphusion_ai_assistant_limit_blocked(skyphusion_ai_assistant_config()), false);
skyphusion_ai_assistant_apply(array(
    'blocked' => array('weapon'),
    'blocked_reply' => 'That topic is closed.',
    'rules' => 'Stay brief.',
    'turns' => 1,
));
assistant_clear();
$assistant_topic = skyphusion_ai_assistant_ask('a weapon question', false);
expect('a blocked topic does not call the model', $assistant_topic['state'], 'blocked');
expect('a blocked topic makes no request', $GLOBALS['cfais_calls'], 0);
expect('a blocked topic uses the saved reply', $assistant_topic['answer'], 'That topic is closed.');
update_option('skyphusion_ai_assistant_chats', array(
    array('question' => 'consented-archive-phrase', 'answer' => 'stored', 'at' => 1),
), false);
assistant_clear();
$GLOBALS['cfais_bodies'] = array($assistant_embed, $assistant_embed, $assistant_query, $assistant_text);
$assistant_ruled = skyphusion_ai_assistant_ask('linen', false, array(
    array('question' => 'older-turn-phrase', 'answer' => 'old'),
    array('question' => 'newer-turn-phrase', 'answer' => 'new'),
));
expect('rules are sent with the question', strpos((string) $GLOBALS['cfais_last_post']['args']['body'], 'Stay brief.') !== false, true);
expect('one earlier turn is sent', strpos((string) $GLOBALS['cfais_last_post']['args']['body'], 'newer-turn-phrase') !== false, true);
expect('an older turn is left out', strpos((string) $GLOBALS['cfais_last_post']['args']['body'], 'older-turn-phrase') === false, true);
expect('a consented row is not copied into the prompt', strpos((string) $GLOBALS['cfais_last_post']['args']['body'], 'consented-archive-phrase') === false, true);
skyphusion_ai_assistant_apply(array('safety' => '1', 'rules' => '', 'blocked' => array(), 'turns' => 10));
assistant_clear();
$GLOBALS['cfais_bodies'] = array('{"choices":[{"message":{"content":"unsafe"}}]}');
$assistant_unsafe = skyphusion_ai_assistant_ask('linen', false);
$assistant_guard_url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
expect('a question check uses the guard model', strpos($assistant_guard_url, 'llama-guard-3-8b') !== false, true);
expect('a question check that is not safe skips the writing model', strpos($assistant_guard_url, skyphusion_ai_assistant_model()) === false, true);
expect('a question check makes one request', $GLOBALS['cfais_calls'], 1);
expect('a question check is counted on the assistant', skyphusion_ai_assistant_config()['day'], skyphusion_ai_neuron_today());
expect('an unsafe question is blocked', $assistant_unsafe['state'], 'blocked');
skyphusion_ai_assistant_apply(array('safety' => ''));
assistant_clear();
$GLOBALS['cfais_bodies'] = array($assistant_embed, $assistant_embed, $assistant_query, $assistant_text);
skyphusion_ai_assistant_ask('cotton', false);
$assistant_plain_url = isset($GLOBALS['cfais_last_post']['url']) ? (string) $GLOBALS['cfais_last_post']['url'] : '';
expect('the writing call does not use the guard model', strpos($assistant_plain_url, 'llama-guard') === false, true);
update_option('skyphusion_ai_assistant', array_merge(skyphusion_ai_assistant_config(), array(
    'enabled' => 1,
    'limit' => 10,
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 8,
)), false);
ob_start();
skyphusion_ai_assistant_render();
$assistant_amber = (string) ob_get_clean();
expect('eight of ten is amber', strpos($assistant_amber, 'usage-amber') !== false, true);
expect('eight of ten is not red', strpos($assistant_amber, 'usage-red') === false, true);
expect('the amber screen keeps the chat button sentence', strpos($assistant_amber, 'The chat button stays as it is.') !== false, true);
expect('the amber screen has no address', strpos($assistant_amber, 'REMOTE_ADDR') === false, true);
update_option('skyphusion_ai_assistant', array_merge(skyphusion_ai_assistant_config(), array(
    'neurons' => 10,
)), false);
ob_start();
skyphusion_ai_assistant_render();
$assistant_red = (string) ob_get_clean();
expect('ten of ten is red', strpos($assistant_red, 'usage-red') !== false, true);
skyphusion_ai_assistant_apply(array('limit' => 0));
ob_start();
skyphusion_ai_assistant_render();
$assistant_open = (string) ob_get_clean();
expect('a zero limit has no amber marker', strpos($assistant_open, 'usage-amber') === false, true);
expect('a zero limit has no red marker', strpos($assistant_open, 'usage-red') === false, true);
$assistant_pin = (string) file_get_contents(dirname(__DIR__) . '/includes/endpoint.php');
expect('the snippet pin stays', strpos($assistant_pin, "define('SKYPHUSION_AI_SNIPPET', '0.0.25')") !== false, true);
unset($GLOBALS['cfais_user_id']);
unset($GLOBALS['cfais_can']['read']);

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
