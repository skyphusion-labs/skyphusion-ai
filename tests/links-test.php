<?php
/**
 * Internal link suggestions. Loaded after translate-test.php.
 * The screen suggests. It does not change the post.
 */

if (!function_exists('skyphusion_ai_link_suggestions')) {
    require_once dirname(__DIR__) . '/includes/links.php';
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1)
    {
        if (-1 === $component) {
            return parse_url($url);
        }
        return parse_url($url, $component);
    }
}

$link_rows = array(
    array('id' => 'p6', 'title' => 'Linen shirt', 'url' => 'https://shop.example/linen'),
    array('id' => 'p7', 'title' => 'Wool coat', 'url' => 'https://shop.example/wool'),
    array('id' => 'p2', 'title' => 'Hi', 'url' => 'https://shop.example/hi'),
);
$link_content = 'The linen shirt is soft. A wool coat is not mentioned with that exact title here? Wait, Wool coat is here.';
$link_found = skyphusion_ai_link_suggestions($link_content, $link_rows, 2);
expect('a phrase in the post is suggested', $link_found[0]['title'], 'Linen shirt');
expect('the second phrase in the post is suggested', $link_found[1]['title'], 'Wool coat');
expect('a short phrase is not suggested', count($link_found), 2);

$link_self = skyphusion_ai_link_suggestions('Linen shirt and Wool coat', $link_rows, 6);
expect('the current page is not suggested', count($link_self), 1);
expect('the other page remains', $link_self[0]['title'], 'Wool coat');

$link_orphans = skyphusion_ai_link_orphans(
    array(
        array('post_id' => 6, 'title' => 'Linen shirt', 'url' => 'https://shop.example/linen'),
        array('post_id' => 8, 'title' => 'Silk scarf', 'url' => 'https://shop.example/silk'),
    ),
    array(6)
);
expect('a page with no inbound link is listed', $link_orphans[0]['title'], 'Silk scarf');
expect('a linked page is left out', count($link_orphans), 1);

$GLOBALS['cfais_calls'] = 0;
update_option('skyphusion_ai_vectorize', array(
    'index' => 'skyphusion-ai',
    'created' => 0,
), false);
$GLOBALS['cfais_can']['edit_posts'] = true;
unset($GLOBALS['skyphusion_ai_link_rows']);
$GLOBALS['skyphusion_ai_link_content'] = 'The linen shirt is soft.';
$GLOBALS['skyphusion_ai_link_posts'] = array(
    array('post_id' => 8, 'title' => 'Silk scarf', 'url' => 'https://shop.example/silk'),
);
$GLOBALS['skyphusion_ai_link_linked'] = array();
ob_start();
skyphusion_ai_link_render(array('ID' => 2));
$link_screen = (string) ob_get_clean();
expect('a missing index is named', strpos($link_screen, 'The embedding index is needed first.') !== false, true);
expect('a missing index makes no request', $GLOBALS['cfais_calls'], 0);
expect('the screen says the post is not changed', strpos($link_screen, 'The post is not changed.') !== false, true);
expect('the screen lists the unlinked page', strpos($link_screen, 'Silk scarf') !== false, true);

$GLOBALS['skyphusion_ai_link_rows'] = $link_rows;
ob_start();
skyphusion_ai_link_render(array('ID' => 2));
$link_ready = (string) ob_get_clean();
expect('the screen names the matching page', strpos($link_ready, 'Linen shirt') !== false, true);
expect('the screen links the matching page', strpos($link_ready, 'https://shop.example/linen') !== false, true);
expect('the screen does not name the short phrase', strpos($link_ready, '>Hi<') === false, true);
expect('suggestions make no request', $GLOBALS['cfais_calls'], 0);

unset($GLOBALS['cfais_can']['edit_posts']);
ob_start();
skyphusion_ai_link_render(array('ID' => 2));
$link_hidden = (string) ob_get_clean();
expect('another role sees no suggestions', $link_hidden, '');

expect('an email does not count as the title', skyphusion_ai_link_phrase_word('GGC', 'Write to hello@ggc.sh for help.'), false);
expect('a sentence keeps the word', skyphusion_ai_link_phrase_word('checkout', 'What the store collects at checkout, why it is kept.'), true);
$link_back = skyphusion_ai_link_returns('Checkout', array(
    array('post_id' => 3, 'title' => 'Privacy', 'url' => 'https://shop.example/privacy', 'content' => 'What the store collects at checkout, why it is kept.'),
    array('post_id' => 7, 'title' => 'Checkout', 'url' => 'https://shop.example/checkout', 'content' => 'Pay here.'),
), 7);
expect('a return link names the other page', $link_back[0]['title'], 'Privacy');
expect('a return link uses the title that is already there', $link_back[0]['phrase'], 'Checkout');
expect('a return link keeps the sentence', strpos($link_back[0]['sentence'], 'checkout') !== false, true);
expect('the current page is not a return link', count($link_back), 1);
$link_mail = skyphusion_ai_link_returns('GGC', array(
    array('post_id' => 10, 'title' => 'Privacy', 'url' => 'https://shop.example/privacy', 'content' => 'Write to hello@ggc.sh for help.'),
), 1);
expect('an email is not a return link', $link_mail, array());
$link_inbound = skyphusion_ai_link_inbound_ids(array(
    array('post_id' => 5, 'title' => 'Shop', 'url' => 'https://shop.example/shop/', 'content' => ''),
    array('post_id' => 6, 'title' => 'Cart', 'url' => 'https://shop.example/cart/', 'content' => 'See https://shop.example/shop/ for products.'),
));
expect('a content link is inbound', $link_inbound, array(5));
expect('a path link counts', skyphusion_ai_link_url_in('https://shop.example/shop/', 'See <a href="/shop/">shop</a>.'), true);
expect('the home slash is not a link', skyphusion_ai_link_url_in('https://shop.example/', 'A slash / is common.'), false);
$link_rows_public = skyphusion_ai_link_public_rows(array(
    array('ID' => 3, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Privacy', 'post_content' => 'checkout', 'url' => 'https://shop.example/privacy'),
    array('ID' => 4, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => 'locked', 'post_title' => 'Secret', 'post_content' => 'hidden', 'url' => 'https://shop.example/secret'),
    array('ID' => 5, 'post_type' => 'page', 'post_status' => 'draft', 'post_password' => '', 'post_title' => 'Draft', 'post_content' => 'later', 'url' => 'https://shop.example/draft'),
));
expect('a published page is scanned', $link_rows_public[0]['title'], 'Privacy');
expect('a password page stays out of the scan', count($link_rows_public), 1);
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_can']['edit_posts'] = true;
$GLOBALS['skyphusion_ai_link_posts'] = array(
    array('post_id' => 3, 'title' => 'Privacy', 'url' => 'https://shop.example/privacy', 'content' => 'What the store collects at checkout, why it is kept.'),
);
$GLOBALS['skyphusion_ai_link_linked'] = array();
ob_start();
skyphusion_ai_link_render(array('ID' => 7, 'post_title' => 'Checkout'));
$link_return_screen = (string) ob_get_clean();
expect('the screen names the return page', strpos($link_return_screen, 'Privacy') !== false, true);
expect('the screen says nothing is inserted', strpos($link_return_screen, 'Nothing is inserted.') !== false, true);
expect('the return screen makes no request', $GLOBALS['cfais_calls'], 0);

$link_src = (string) file_get_contents(dirname(__DIR__) . '/includes/links.php');
expect('the box is on pages', strpos($link_src, "array( 'post', 'page', 'product' )") !== false, true);
expect('the scan reads published pages', strpos($link_src, 'get_posts') !== false, true);
expect('the screen does not read a remote address', strpos($link_src, 'REMOTE_ADDR') === false, true);
expect('the screen does not call the embeddings model', strpos($link_src, 'skyphusion_ai_embed_search') === false, true);
expect('the screen does not save the post', strpos($link_src, 'wp_update_post') === false, true);
expect('the screen does not insert a post', strpos($link_src, 'wp_insert_post') === false, true);
expect('the screen uses the stored vectors', strpos($link_src, 'skyphusion_ai_related_rows') !== false, true);

if (!function_exists('skyphusion_ai_prompt_fill')) {
    require_once dirname(__DIR__) . '/includes/prompts.php';
}
$GLOBALS['cfais_can']['edit_posts'] = false;
$GLOBALS['cfais_calls'] = 0;
$link_denied = skyphusion_ai_link_ask(2, 'Where does this link go?');
expect('another role cannot ask', is_wp_error($link_denied), true);
expect('another role makes no request', $GLOBALS['cfais_calls'], 0);
$GLOBALS['cfais_can']['edit_posts'] = true;
$link_blank = skyphusion_ai_link_ask(2, '  ');
expect('an empty question makes no request', is_wp_error($link_blank), true);
expect('an empty question stays on the site', $GLOBALS['cfais_calls'], 0);
$link_prev_key = getenv('CLOUDFLARE_API_KEY');
$link_prev_account = getenv('CLOUDFLARE_ACCOUNT_ID');
putenv('CLOUDFLARE_API_KEY=sentinel-token-' . str_repeat('a', 30));
putenv('CLOUDFLARE_ACCOUNT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
$GLOBALS['cfais_settings']['account_id'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$GLOBALS['cfais_settings']['gateway_id'] = '';
$GLOBALS['skyphusion_ai_link_rows'] = array(
    array('id' => 'p6', 'title' => 'Linen shirt', 'url' => 'https://shop.example/linen'),
);
$GLOBALS['skyphusion_ai_link_content'] = 'The linen shirt is soft.';
$GLOBALS['cfais_status'] = 200;
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_bodies'] = array('{"choices":[{"message":{"content":"A linen shirt."}}],"usage":{"neurons":4}}');
$link_text_before = skyphusion_ai_neuron_state();
$link_reply = skyphusion_ai_link_ask(2, 'Where does this link go?');
$link_text_after = skyphusion_ai_neuron_state();
expect('the link chat returns the model sentence', $link_reply, 'A linen shirt.');
expect('the link chat makes one request', $GLOBALS['cfais_calls'], 1);
$link_sent = (string) $GLOBALS['cfais_last_post']['args']['body'];
expect('the link chat sends the suggestion', strpos($link_sent, 'Linen shirt') !== false, true);
expect('the link chat sends the question', strpos($link_sent, 'Where does this link go?') !== false, true);
expect('the link chat counts the text feature', $link_text_after['features']['text'] > $link_text_before['features']['text'], true);
expect('the link chat counts the writing tool', $link_text_after['tools']['text'] > $link_text_before['tools']['text'], true);
$GLOBALS['cfai_stock_test_filters']['skyphusion_ai_prompt'] = 'Replaced prompt';
expect('a filter can replace the link prompt', skyphusion_ai_prompt_fill('links', array('suggestions' => 'Linen shirt', 'question' => 'Where?')), 'Replaced prompt');
unset($GLOBALS['cfai_stock_test_filters']['skyphusion_ai_prompt']);
update_option('skyphusion_ai_neurons', array(
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 10000,
    'estimated' => 0,
    'features' => array(),
    'mailed_80' => 1,
    'mailed_100' => 1,
), false);
$GLOBALS['cfais_calls'] = 0;
$link_paused = skyphusion_ai_link_ask(2, 'Where does this link go?');
expect('a spent budget pauses the link chat', is_wp_error($link_paused) && strpos($link_paused->message, 'paused') !== false, true);
expect('a spent budget makes no link request', $GLOBALS['cfais_calls'], 0);
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
$GLOBALS['cfais_stored']['tool_caps'] = array('text' => 1);
update_option('skyphusion_ai_neurons', array(
    'day' => skyphusion_ai_neuron_today(),
    'neurons' => 0,
    'estimated' => 0,
    'features' => array(),
    'tools' => array('text' => 1),
    'mailed_80' => 0,
    'mailed_100' => 0,
), false);
$GLOBALS['cfais_calls'] = 0;
$link_tool = skyphusion_ai_link_ask(2, 'Where does this link go?');
expect('a spent writing limit stops the link chat', is_wp_error($link_tool) && strpos($link_tool->message, 'daily limit') !== false, true);
expect('a spent writing limit makes no link request', $GLOBALS['cfais_calls'], 0);
unset($GLOBALS['cfais_stored']['tool_caps']);
unset($GLOBALS['cfais_options']['skyphusion_ai_neurons']);
$GLOBALS['cfais_calls'] = 0;
ob_start();
skyphusion_ai_link_render(array('ID' => 2));
$link_quiet = (string) ob_get_clean();
expect('the box still makes no request', $GLOBALS['cfais_calls'], 0);
expect('the box offers the question', strpos($link_quiet, 'skyphusion_ai_link_question') !== false, true);
if (false === $link_prev_key || '' === $link_prev_key) {
    putenv('CLOUDFLARE_API_KEY');
} else {
    putenv('CLOUDFLARE_API_KEY=' . $link_prev_key);
}
if (false === $link_prev_account || '' === $link_prev_account) {
    putenv('CLOUDFLARE_ACCOUNT_ID');
} else {
    putenv('CLOUDFLARE_ACCOUNT_ID=' . $link_prev_account);
}

unset($GLOBALS['skyphusion_ai_link_rows']);
unset($GLOBALS['skyphusion_ai_link_content']);
unset($GLOBALS['skyphusion_ai_link_posts']);
unset($GLOBALS['skyphusion_ai_link_linked']);
unset($GLOBALS['cfais_options']['skyphusion_ai_vectorize']);
unset($GLOBALS['cfais_can']['edit_posts']);
