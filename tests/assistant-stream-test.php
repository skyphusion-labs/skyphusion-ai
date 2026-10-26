<?php
/**
 * Blank Worker URL streams the assistant answer.
 * Loaded after worker-test.php. The saved Worker address is empty again.
 */

$stream_prev_key = getenv('CLOUDFLARE_API_KEY');
$stream_prev_account = getenv('CLOUDFLARE_ACCOUNT_ID');
$stream_token = 'sentinel-token-' . str_repeat('b', 30);
putenv('CLOUDFLARE_API_KEY=' . $stream_token);
putenv('CLOUDFLARE_ACCOUNT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
if (!isset($GLOBALS['cfais_settings']) || !is_array($GLOBALS['cfais_settings'])) {
    $GLOBALS['cfais_settings'] = array();
}
$GLOBALS['cfais_settings']['endpoint'] = 'https://abc123.search.ai.cloudflare.com';
$GLOBALS['cfais_settings']['account_id'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$GLOBALS['cfais_settings']['gateway_id'] = '';
unset($GLOBALS['cfais_options']['skyphusion_ai_gateway_down']);

$stream_text = "data: {\"text\":\"The \"}\n\ndata: {\"text\":\"shirt\"}\n\ndata: [DONE]\n\n";
$stream_response = "data: {\"response\":\"The \"}\n\ndata: {\"response\":\"shirt\"}\n\ndata: [DONE]\n\n";
expect('two text pieces stay two pieces', skyphusion_ai_assistant_stream_events($stream_text), array('The ', 'shirt'));
expect('the response key is a piece too', skyphusion_ai_assistant_stream_events($stream_response), array('The ', 'shirt'));
expect(
    'done and blank lines add nothing',
    skyphusion_ai_assistant_stream_events("data: {\"text\":\"The \"}\n\ndata:\n\ndata: [DONE]\n\nnot-json\n"),
    array('The ')
);

$stream_writes = array();
$stream_seen = null;
$stream_lines = (function () use (&$stream_writes, &$stream_seen) {
    yield "data: {\"text\":\"The \"}\n\n";
    $stream_seen = count($stream_writes);
    yield "data: {\"text\":\"shirt\"}\n\n";
})();
skyphusion_ai_assistant_stream_emit($stream_lines, function ($piece) use (&$stream_writes) {
    $stream_writes[] = $piece;
});
expect('the first piece is written before the next line', $stream_seen, 1);
$stream_stored = get_transient('skyphusion_ai_assistant_last');
$stream_stored_answer = '';
if (is_array($stream_stored) && isset($stream_stored['answer'])) {
    $stream_stored_answer = (string) $stream_stored['answer'];
}
expect('the finished answer is not stored before the first piece', $stream_stored_answer, '');
expect('each write is one piece', $stream_writes, array('The ', 'shirt'));
expect('a piece is not the joined sentence', in_array('The shirt', $stream_writes, true), false);
expect('a piece is not a full document', strpos(implode('', $stream_writes), 'skyphusion-ai-assistant') === false, true);

if (!function_exists('skyphusion_ai_agent_tools_provider_id')) {
    require_once dirname(__DIR__) . '/includes/agent-tools.php';
}

$stream_embed = '{"result":{"data":[[0.25,0.5]]}}';
$stream_query = '{"success":true,"result":{"matches":[{"id":"p6","score":0.5,"metadata":{"title":"Linen shirt","post_id":"6","url":"https://shop.example/linen"}}]}}';
$stream_answer = '{"choices":[{"message":{"content":"The shirt is linen."}}]}';
$stream_summary = '{"choices":[{"message":{"content":"A short earlier block."}}]}';

unset($GLOBALS['cfais_options']['skyphusion_ai_worker']);
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
$GLOBALS['cfais_user_id'] = 4;
$GLOBALS['cfais_can']['read'] = true;
$GLOBALS['cfais_status'] = 200;
embed_ready();
skyphusion_ai_assistant_apply(array(
    'enabled' => '1',
    'save' => '',
    'audience' => 'roles',
    'safety' => '',
    'rules' => '',
    'blocked' => array(),
    'turns' => 10,
    'limit' => 0,
));
unset($GLOBALS['cfais_options']['skyphusion_ai_assistant_chats']);

function stream_clear()
{
    $GLOBALS['cfais_calls'] = 0;
    unset($GLOBALS['cfais_bodies']);
    $GLOBALS['cfais_last_post'] = array();
    $GLOBALS['cfais_store'] = array();
}

stream_clear();
$GLOBALS['cfais_bodies'] = array($stream_embed, $stream_embed, $stream_query, $stream_answer);
$stream_hit = skyphusion_ai_assistant_ask('linen', false);
$stream_body = json_decode((string) $GLOBALS['cfais_last_post']['args']['body'], true);
$stream_model = skyphusion_ai_assistant_model();
$stream_direct = 'https://api.cloudflare.com/client/v4/accounts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/ai/run/' . $stream_model;
expect('a blank url still answers in three requests', $GLOBALS['cfais_calls'], 3);
expect('the answer request sets stream true', is_array($stream_body) && true === $stream_body['stream'], true);
expect('an empty gateway id uses the Workers AI URL', $GLOBALS['cfais_last_post']['url'], $stream_direct);
expect('an empty gateway id does not use the gateway host', strpos((string) $GLOBALS['cfais_last_post']['url'], 'gateway.ai.cloudflare.com') === false, true);
expect('an empty gateway id does not use the search host', strpos((string) $GLOBALS['cfais_last_post']['url'], 'search.ai.cloudflare.com') === false, true);
expect('an empty gateway id does not use chat completions', strpos((string) $GLOBALS['cfais_last_post']['url'], '/ai/v1/chat/completions') === false, true);
expect('the direct url does not carry the token', strpos((string) $GLOBALS['cfais_last_post']['url'], $stream_token) === false, true);
expect('the direct body does not carry the token', strpos((string) $GLOBALS['cfais_last_post']['args']['body'], $stream_token) === false, true);

$GLOBALS['cfais_settings']['gateway_id'] = 'shop-gw';
$stream_gateway_was = array_key_exists('gateway_id', $GLOBALS['cfais_stored']) ? $GLOBALS['cfais_stored']['gateway_id'] : null;
$GLOBALS['cfais_stored']['gateway_id'] = 'shop-gw';
unset($GLOBALS['cfais_options']['skyphusion_ai_gateway_down']);
stream_clear();
$GLOBALS['cfais_bodies'] = array($stream_embed, $stream_embed, $stream_query, $stream_answer);
skyphusion_ai_assistant_ask('linen', false);
$stream_via = json_decode((string) $GLOBALS['cfais_last_post']['args']['body'], true);
$stream_gateway = 'https://gateway.ai.cloudflare.com/v1/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/shop-gw/workers-ai/' . $stream_model;
expect('a saved gateway uses the gateway URL', $GLOBALS['cfais_last_post']['url'], $stream_gateway);
expect('a saved gateway does not use api.cloudflare.com', strpos((string) $GLOBALS['cfais_last_post']['url'], 'api.cloudflare.com') === false, true);
expect('a saved gateway still sets stream true', is_array($stream_via) && true === $stream_via['stream'], true);
expect('a saved gateway still makes three requests', $GLOBALS['cfais_calls'], 3);

$GLOBALS['cfais_settings']['gateway_id'] = 'Shop GW';
$GLOBALS['cfais_stored']['gateway_id'] = 'Shop GW';
unset($GLOBALS['cfais_options']['skyphusion_ai_gateway_down']);
stream_clear();
$GLOBALS['cfais_bodies'] = array($stream_embed, $stream_embed, $stream_query, $stream_answer);
skyphusion_ai_assistant_ask('linen', false);
expect('a bad gateway id stays on api.cloudflare.com', strpos((string) $GLOBALS['cfais_last_post']['url'], $stream_direct) === 0, true);
expect('a bad gateway id does not use the gateway host', strpos((string) $GLOBALS['cfais_last_post']['url'], 'gateway.ai.cloudflare.com') === false, true);
expect('a bad gateway id is not a path segment', strpos((string) $GLOBALS['cfais_last_post']['url'], 'Shop') === false, true);
$GLOBALS['cfais_settings']['gateway_id'] = '';
if (null === $stream_gateway_was) {
    unset($GLOBALS['cfais_stored']['gateway_id']);
} else {
    $GLOBALS['cfais_stored']['gateway_id'] = $stream_gateway_was;
}

unset($GLOBALS['cfais_store']['skyphusion_ai_assistant_last']);
$stream_form = skyphusion_ai_assistant_shortcode();
expect('a blank worker address has a stream slot', strpos($stream_form, 'skyphusion-ai-assistant-stream') !== false, true);
expect('a blank worker address reads the stream', strpos($stream_form, 'getReader') !== false, true);
expect('a blank worker address does not call a worker', strpos($stream_form, '.workers.dev') === false, true);
expect('a blank worker address has no worker attribute', strpos($stream_form, 'data-worker') === false, true);
expect('a blank worker address does not name api.cloudflare.com', strpos($stream_form, 'api.cloudflare.com') === false, true);
expect('a blank worker address does not name the gateway host', strpos($stream_form, 'gateway.ai.cloudflare.com') === false, true);
expect('a blank worker address still asks the question', strpos($stream_form, 'skyphusion_ai_assistant_question') !== false, true);
expect('the blank page does not show the token', strpos($stream_form, $stream_token) === false, true);
expect('the blank page does not show a bearer', strpos($stream_form, 'Bearer') === false, true);
$stream_html = skyphusion_ai_assistant_html($stream_hit);
expect('the answer html does not show the token', strpos($stream_html, $stream_token) === false, true);
expect('the answer html does not show a bearer', strpos($stream_html, 'Bearer') === false, true);

$stream_src_pin = skyphusion_ai_snippet_src('https://abc123.search.ai.cloudflare.com');
expect('the public snippet pin stays', $stream_src_pin, 'https://abc123.search.ai.cloudflare.com/assets/v0.0.25/search-snippet.es.js');
expect('the public snippet does not carry the token', strpos($stream_src_pin, $stream_token) === false, true);
$stream_frontend = (string) file_get_contents(dirname(__DIR__) . '/includes/frontend.php');
expect('the snippet markup does not carry a bearer', strpos($stream_frontend, 'Bearer') === false, true);
expect('the snippet markup does not call api.cloudflare.com', strpos($stream_frontend, 'api.cloudflare.com') === false, true);

$stream_window = array(
    array('question' => 'compact-oldest-phrase', 'answer' => 'old'),
);
for ($stream_fill = 2; $stream_fill <= 9; $stream_fill++) {
    $stream_window[] = array('question' => 'compact-fill-' . $stream_fill, 'answer' => 'fill');
}
$stream_window[] = array('question' => 'compact-newest-phrase', 'answer' => 'new');
$stream_long = $stream_window;
$stream_long[] = array('question' => 'compact-eleventh-phrase', 'answer' => 'eleven', 'ip' => '203.0.113.9');
stream_clear();
$GLOBALS['cfais_bodies'] = array($stream_embed, $stream_embed, $stream_query, $stream_summary, $stream_answer);
$stream_eleven = skyphusion_ai_assistant_ask('linen', false, $stream_long);
$stream_eleven_body = (string) $GLOBALS['cfais_last_post']['args']['body'];
expect('eleven turns still make four requests', $GLOBALS['cfais_calls'], 4);
expect('the answer still sends the summary', strpos($stream_eleven_body, 'A short earlier block.') !== false, true);
expect('the answer still sends the summary label', strpos($stream_eleven_body, 'Earlier talk:') !== false, true);
expect('the answer still sends the latest turn', strpos($stream_eleven_body, 'compact-eleventh-phrase') !== false, true);
expect('the answer still leaves out the oldest turn', strpos($stream_eleven_body, 'compact-oldest-phrase') === false, true);
expect('the answer still does not send an address', strpos($stream_eleven_body, '203.0.113.9') === false, true);
expect('the streamed answer still sets stream true', strpos($stream_eleven_body, '"stream":true') !== false, true);
expect('the page still shows the oldest turn', strpos(skyphusion_ai_assistant_html($stream_eleven), 'compact-oldest-phrase') !== false, true);
expect('the page still shows the answer', strpos(skyphusion_ai_assistant_html($stream_eleven), 'The shirt is linen.') !== false, true);
$stream_fields = skyphusion_ai_assistant_turn_fields($stream_long);
expect('the fields still repeat the oldest turn', strpos($stream_fields, 'compact-oldest-phrase') !== false, true);
expect('the fields still hide the address', strpos($stream_fields, '203.0.113.9') === false, true);
set_transient('skyphusion_ai_assistant_last', $stream_eleven, 120);
$stream_saved_form = skyphusion_ai_assistant_shortcode();
expect('the blank page still shows the oldest turn', strpos($stream_saved_form, 'compact-oldest-phrase') !== false, true);
unset($GLOBALS['cfais_store']['skyphusion_ai_assistant_last']);

stream_clear();
$GLOBALS['cfais_bodies'] = array($stream_embed, $stream_embed, $stream_query, $stream_answer);
$stream_ten = skyphusion_ai_assistant_ask('linen', false, $stream_window);
expect('ten turns still make three requests', $GLOBALS['cfais_calls'], 3);
expect('ten turns still skip the summary label', strpos((string) $GLOBALS['cfais_last_post']['args']['body'], 'Earlier talk:') === false, true);
expect('ten turns still answer', $stream_ten['state'], 'answered');

skyphusion_ai_assistant_apply(array('save' => '1'));
update_option('skyphusion_ai_assistant_chats', array(), false);
stream_clear();
$GLOBALS['cfais_bodies'] = array($stream_embed, $stream_embed, $stream_query, $stream_answer);
skyphusion_ai_assistant_ask('linen', false);
expect('a stream does not store a chat without consent', get_option('skyphusion_ai_assistant_chats', array()), array());
expect('the blank form still asks before saving', strpos(skyphusion_ai_assistant_shortcode(), 'name="skyphusion_ai_assistant_consent"') !== false, true);
stream_clear();
$GLOBALS['cfais_bodies'] = array($stream_embed, $stream_embed, $stream_query, $stream_answer);
skyphusion_ai_assistant_ask('wool', true);
$stream_saved = get_option('skyphusion_ai_assistant_chats', array());
expect('consent still stores the question', $stream_saved[0]['question'], 'wool');
expect('consent still stores the answer', $stream_saved[0]['answer'], 'The shirt is linen.');
expect('a stored chat still has no address', isset($stream_saved[0]['ip']), false);
skyphusion_ai_assistant_apply(array('save' => ''));

$GLOBALS['cfais_can']['manage_options'] = true;
ob_start();
skyphusion_ai_assistant_render();
$stream_screen = (string) ob_get_clean();
expect('the audience switch is still on the screen', strpos($stream_screen, 'name="skyphusion_ai_assistant_audience"') !== false, true);
expect('the audience switch still offers roles', strpos($stream_screen, 'value="roles"') !== false, true);
expect('the audience switch still offers public', strpos($stream_screen, 'value="public"') !== false, true);
expect('the screen still says the chat button stays', strpos($stream_screen, 'The chat button stays as it is.') !== false, true);
$GLOBALS['cfais_user_id'] = 0;
stream_clear();
$stream_closed = skyphusion_ai_assistant_ask('linen', false);
expect('a logged-out visitor still stays out', $stream_closed['state'], 'closed');
expect('a logged-out visitor still makes no request', $GLOBALS['cfais_calls'], 0);
expect('a logged-out visitor still gets an empty shortcode', skyphusion_ai_assistant_shortcode(), '');
skyphusion_ai_assistant_apply(array('audience' => 'public'));
stream_clear();
$GLOBALS['cfais_bodies'] = array($stream_embed, $stream_embed, $stream_query, $stream_answer);
$stream_public = skyphusion_ai_assistant_ask('linen', false);
expect('a public visitor is still answered', $stream_public['state'], 'answered');
expect('a public visitor still does not call siteverify', strpos((string) $GLOBALS['cfais_last_post']['url'], 'siteverify') === false, true);
skyphusion_ai_assistant_apply(array('audience' => 'roles'));
$GLOBALS['cfais_user_id'] = 4;

putenv('CLOUDFLARE_API_KEY');
stream_clear();
$GLOBALS['cfais_bodies'] = array($stream_embed, $stream_embed, $stream_query, $stream_answer);
$stream_missing = skyphusion_ai_assistant_ask('linen', false);
expect('a missing token does not answer', $stream_missing['state'] === 'answered', false);
expect('a missing token does not call the text model', strpos((string) (isset($GLOBALS['cfais_last_post']['url']) ? $GLOBALS['cfais_last_post']['url'] : ''), $stream_model) === false, true);
expect('a missing token does not echo the token', strpos((string) $stream_missing['answer'], $stream_token) === false, true);
expect('a missing token does not echo a bearer', strpos((string) $stream_missing['answer'], 'Bearer') === false, true);
expect('a missing token does not put a bearer on the page', strpos(skyphusion_ai_assistant_shortcode(), 'Bearer') === false, true);
putenv('CLOUDFLARE_API_KEY=' . $stream_token);

update_option('skyphusion_ai_worker', array('url' => 'https://skyphusion-ai.example.workers.dev'), false);
$stream_edge = skyphusion_ai_worker_edge_html('https://skyphusion-ai.example.workers.dev', $stream_model);
expect('a saved worker url is still the edge page', skyphusion_ai_assistant_shortcode(), $stream_edge);
expect('the edge page still names the worker', strpos($stream_edge, 'https://skyphusion-ai.example.workers.dev') !== false, true);
expect('the edge page still uses the edge form', strpos($stream_edge, 'skyphusion-ai-assistant-edge') !== false, true);
expect('the edge page still sends the turn window', strpos($stream_edge, 'data-keep="10"') !== false, true);
expect('the edge page still does not post to PHP', strpos($stream_edge, 'admin-post.php') === false, true);
expect('the edge page still does not name api.cloudflare.com', strpos($stream_edge, 'api.cloudflare.com') === false, true);
expect('the edge page still does not name the gateway host', strpos($stream_edge, 'gateway.ai.cloudflare.com') === false, true);
expect('the edge page still hides the token', strpos($stream_edge, $stream_token) === false, true);
expect('the edge page still hides a bearer', strpos($stream_edge, 'Bearer') === false, true);
expect('the edge page does not name the PHP emit function', strpos($stream_edge, 'skyphusion_ai_assistant_stream_emit') === false, true);
expect('the edge page does not name the PHP stream action', strpos($stream_edge, 'skyphusion_ai_assistant_stream') === false, true);
unset($GLOBALS['cfais_options']['skyphusion_ai_worker']);
$stream_blank_again = skyphusion_ai_assistant_shortcode();
expect('clearing the worker url leaves the PHP form', $stream_blank_again === $stream_edge, false);
expect('the PHP form is back', strpos($stream_blank_again, 'skyphusion-ai-assistant-php') !== false, true);

$stream_php = (string) file_get_contents(dirname(__DIR__) . '/includes/assistant.php');
expect('the blank path emits each piece', strpos($stream_php, 'skyphusion_ai_assistant_stream_emit') !== false, true);
expect('the assistant does not add to cart', strpos($stream_php, 'add_to_cart') === false, true);
expect('the assistant does not look up an order', strpos($stream_php, 'wc_get_order') === false, true);
expect('the assistant does not touch the cart object', strpos($stream_php, 'WC()->cart') === false, true);
expect('the assistant does not call the store API', strpos($stream_php, '/wc/store') === false, true);
expect('the assistant does not call OpenAI', strpos($stream_php, 'api.openai.com') === false, true);
expect('the assistant does not call Anthropic', strpos($stream_php, 'api.anthropic.com') === false, true);
expect('the assistant does not call Google', strpos($stream_php, 'generativelanguage.googleapis.com') === false, true);
expect('the provider id stays cloudflare', skyphusion_ai_agent_tools_provider_id(), 'cloudflare');
expect('checkout stays a person', skyphusion_ai_agent_tools_checkout_is_a_person(), true);
foreach (skyphusion_ai_agent_tools_surfaces() as $stream_surface) {
    expect(
        'the ' . $stream_surface['id'] . ' surface does not change the cart',
        $stream_surface['changes_cart'],
        false
    );
}

unset($GLOBALS['cfais_options']['skyphusion_ai_assistant']);
unset($GLOBALS['cfais_options']['skyphusion_ai_assistant_chats']);
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
unset($GLOBALS['cfais_options']['skyphusion_ai_gateway_down']);
unset($GLOBALS['cfais_options']['skyphusion_ai_worker']);
unset($GLOBALS['cfais_user_id']);
unset($GLOBALS['cfais_can']['read']);
unset($GLOBALS['cfais_can']['manage_options']);
$GLOBALS['cfais_settings']['gateway_id'] = '';
if (false === $stream_prev_key || '' === $stream_prev_key) {
    putenv('CLOUDFLARE_API_KEY');
} else {
    putenv('CLOUDFLARE_API_KEY=' . $stream_prev_key);
}
if (false === $stream_prev_account || '' === $stream_prev_account) {
    putenv('CLOUDFLARE_ACCOUNT_ID');
} else {
    putenv('CLOUDFLARE_ACCOUNT_ID=' . $stream_prev_account);
}
