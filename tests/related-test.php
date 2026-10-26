<?php
/**
 * Related posts from stored vectors. Loaded after embed-test.php.
 * A page view reads Vectorize. It does not call the embeddings model.
 */

$related_prev_key = getenv('CLOUDFLARE_API_KEY');
$related_prev_account = getenv('CLOUDFLARE_ACCOUNT_ID');
$related_token = 'sentinel-token-' . str_repeat('a', 30);
putenv('CLOUDFLARE_API_KEY=' . $related_token);
putenv('CLOUDFLARE_ACCOUNT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
$GLOBALS['cfais_settings'] = array(
    'endpoint' => 'https://abc123.search.ai.cloudflare.com',
    'lead_search' => true,
    'account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'gateway_id' => '',
);
$GLOBALS['cfais_status'] = 200;
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_last_post'] = array('marker' => 1);

function related_store($created, $related)
{
    update_option('skyphusion_ai_vectorize', array(
        'index' => 'skyphusion-ai',
        'post_types' => array('post', 'product'),
        'fields' => array('title', 'content', 'excerpt'),
        'model' => '@cf/baai/bge-large-en-v1.5',
        'created' => $created,
        'related' => $related,
    ), false);
}

function related_clear()
{
    $GLOBALS['cfais_calls'] = 0;
    unset($GLOBALS['cfais_bodies']);
    $GLOBALS['cfais_last_request'] = array();
}

$related_page = embed_post(6, 'This page', 'Body');
$related_got = wp_json_encode(array(
    'success' => true,
    'result' => array(
        array(
            'id' => 'p6',
            'values' => array(0.25, 0.5),
            'metadata' => array('title' => 'This page'),
        ),
    ),
));
$related_matches = array(
    array(
        'id' => 'p6',
        'metadata' => array(
            'title' => 'This page',
            'url' => 'https://example.test/this',
        ),
    ),
);
for ($related_i = 7; $related_i <= 12; $related_i++) {
    $related_matches[] = array(
        'id' => 'p' . $related_i,
        'values' => array(0.1),
        'score' => 0.64,
        'metadata' => array(
            'title' => 'Other ' . $related_i,
            'url' => 'https://example.test/p' . $related_i,
        ),
    );
}
$related_query = wp_json_encode(array(
    'success' => true,
    'result' => array('matches' => $related_matches),
));

related_store(0, 0);
related_clear();
expect('an index that is not created returns no rows', skyphusion_ai_related_rows($related_page), array());
expect('an index that is not created makes no request', $GLOBALS['cfais_calls'], 0);

related_store(1, 0);
related_clear();
$GLOBALS['cfais_bodies'] = array('{"success":true,"result":[]}');
expect('a missing vector returns no rows', skyphusion_ai_related_rows($related_page), array());
expect('a missing vector reads the index once', $GLOBALS['cfais_calls'], 1);
$related_url = isset($GLOBALS['cfais_last_request']['url']) ? (string) $GLOBALS['cfais_last_request']['url'] : '';
expect('a missing vector uses get_by_ids', strpos($related_url, '/get_by_ids') !== false, true);
expect('a missing vector stays off the gateway', strpos($related_url, 'gateway.ai.cloudflare.com') === false, true);

related_clear();
$GLOBALS['cfais_bodies'] = array('{"success":true,"result":[{"id":"p6","values":["no"]}]}');
expect('a non numeric vector is not queried', skyphusion_ai_related_rows($related_page), array());
expect('a non numeric vector makes one request', $GLOBALS['cfais_calls'], 1);
$related_url = isset($GLOBALS['cfais_last_request']['url']) ? (string) $GLOBALS['cfais_last_request']['url'] : '';
expect('a non numeric vector does not query', strpos($related_url, '/query') === false, true);

related_clear();
$GLOBALS['cfais_bodies'] = array('{"success":true,"result":{"vectors":[{"id":"p6","values":[0.1,0.2]}]}}');
expect(
    'vectors are read from the stored list',
    skyphusion_ai_related_vector(6, skyphusion_ai_embed_config(), skyphusion_ai_embed_credentials()),
    array(0.1, 0.2)
);

related_clear();
$GLOBALS['cfais_last_post'] = array('marker' => 1);
$GLOBALS['cfais_bodies'] = array($related_got, $related_query);
$related_rows = skyphusion_ai_related_rows($related_page);
expect('five neighbours are kept', count($related_rows), 5);
expect('the current page is left out', $related_rows[0]['id'], 'p7');
expect('the sixth neighbour is left out', $related_rows[4]['id'], 'p11');
$related_ids = array();
foreach ($related_rows as $related_row) {
    $related_ids[] = $related_row['id'];
}
expect('the current vector id is absent', in_array('p6', $related_ids, true), false);
expect('a row has no vector values', isset($related_rows[0]['values']), false);
expect('the reason uses the stored score', $related_rows[0]['reason'], '64% the same subject.');
expect('a page view makes two vectorize requests', $GLOBALS['cfais_calls'], 2);
expect('a page view does not call the embeddings route', $GLOBALS['cfais_last_post'], array('marker' => 1));
$related_url = isset($GLOBALS['cfais_last_request']['url']) ? (string) $GLOBALS['cfais_last_request']['url'] : '';
expect('the second call queries the index', strpos($related_url, '/query') !== false, true);
expect('the query uses the vectorize host', strpos($related_url, '/vectorize/v2/indexes/') !== false, true);
expect('the query does not use the gateway', strpos($related_url, 'gateway.ai.cloudflare.com') === false, true);
expect('the query does not call the model', strpos($related_url, '/ai/run/') === false, true);
expect('the query url does not carry the token', strpos($related_url, $related_token) === false, true);
$related_args = isset($GLOBALS['cfais_last_request']['args']) ? $GLOBALS['cfais_last_request']['args'] : array();
$related_header = '';
if (isset($related_args['headers']['Authorization'])) {
    $related_header = (string) $related_args['headers']['Authorization'];
}
expect('the header starts with the bearer prefix', strpos($related_header, 'Bearer ') === 0, true);
expect('the header token is the sentinel length', strlen($related_header), 52);
$related_body = isset($related_args['body']) ? json_decode((string) $related_args['body'], true) : array();
expect('the query reuses the stored vector', isset($related_body['vector']) ? $related_body['vector'] : array(), array(0.25, 0.5));
expect('the query asks for six so the current page can be dropped', isset($related_body['topK']) ? $related_body['topK'] : 0, 6);
$related_type = isset($related_args['headers']['Content-Type']) ? (string) $related_args['headers']['Content-Type'] : '';
expect('the query body is json', $related_type, 'application/json');

related_clear();
$GLOBALS['cfais_bodies'] = array($related_got, $related_query);
$related_html = skyphusion_ai_related_html($related_page);
expect('the list names a neighbour', strpos($related_html, 'Other 7') !== false, true);
expect('the list includes the reason', strpos($related_html, '64% the same subject.') !== false, true);
expect('the list links the public address', strpos($related_html, 'href="https://example.test/p7"') !== false, true);
expect('the list uses the related class', strpos($related_html, 'skyphusion-ai-related') !== false, true);
expect('the list does not show the token', strpos($related_html, $related_token) === false, true);

related_clear();
$GLOBALS['cfais_bodies'] = array($related_got, wp_json_encode(array(
    'success' => true,
    'result' => array(
        'matches' => array(
            array(
                'id' => 'p9',
                'metadata' => array(
                    'title' => 'Script page',
                    'url' => 'javascript:alert(1)',
                ),
            ),
        ),
    ),
)));
$related_script = skyphusion_ai_related_rows($related_page);
expect('a script address is not a link', $related_script[0]['url'], '');
related_clear();
$GLOBALS['cfais_bodies'] = array($related_got, wp_json_encode(array(
    'success' => true,
    'result' => array(
        'matches' => array(
            array(
                'id' => 'p9',
                'metadata' => array(
                    'title' => 'Script page',
                    'url' => 'javascript:alert(1)',
                ),
            ),
        ),
    ),
)));
$related_script_html = skyphusion_ai_related_html($related_page);
expect('a script address is plain text', strpos($related_script_html, 'href=') === false, true);
expect('a script address still shows the title', strpos($related_script_html, 'Script page') !== false, true);

related_store(1, 0);
related_clear();
$GLOBALS['cfais_bodies'] = array($related_got, $related_query);
expect('the switch off leaves the page body', skyphusion_ai_related_append('Body.', $related_page), 'Body.');
expect('the switch off makes no request', $GLOBALS['cfais_calls'], 0);

related_store(1, 1);
related_clear();
$GLOBALS['cfais_bodies'] = array($related_got, $related_query);
$related_appended = skyphusion_ai_related_append('Body.', $related_page);
expect('the switch on appends the list', strpos($related_appended, 'skyphusion-ai-related') !== false, true);
expect('the switch on keeps the page body', strpos($related_appended, 'Body.') === 0, true);

related_clear();
$GLOBALS['cfais_bodies'] = array($related_got, $related_query);
$related_once = skyphusion_ai_related_append('Body. [skyphusion_ai_related]', $related_page);
expect('a placed shortcode is not appended twice', $related_once, 'Body. [skyphusion_ai_related]');
expect('a placed shortcode makes no extra request', $GLOBALS['cfais_calls'], 0);

related_clear();
$related_locked = embed_post(6, 'Hidden', 'Body', array('post_password' => 'locked'));
expect(
    'a password protected post is not appended',
    skyphusion_ai_related_append('Body.', $related_locked),
    'Body.'
);
expect('a password protected post makes no request', $GLOBALS['cfais_calls'], 0);

related_clear();
$GLOBALS['cfais_bodies'] = array($related_got, $related_query);
$related_product = embed_post(6, 'Shirt', 'Linen', array('post_type' => 'product'));
$related_product_html = skyphusion_ai_related_append('Linen.', $related_product);
expect('a product can show the list', strpos($related_product_html, 'skyphusion-ai-related') !== false, true);

related_store(1, 0);
related_clear();
$GLOBALS['cfais_bodies'] = array($related_got, $related_query);
$GLOBALS['cfais_embed_post'] = $related_page;
$related_short = skyphusion_ai_related_shortcode();
expect('the shortcode works while the switch is off', strpos($related_short, 'Other 7') !== false, true);
unset($GLOBALS['cfais_embed_post']);

related_store(1, 1);
putenv('CLOUDFLARE_API_KEY');
related_clear();
expect('missing credentials return no rows', skyphusion_ai_related_rows($related_page), array());
expect('missing credentials make no request', $GLOBALS['cfais_calls'], 0);
expect('missing credentials print nothing', skyphusion_ai_related_html($related_page), '');
putenv('CLOUDFLARE_API_KEY=' . $related_token);

$related_kept = skyphusion_ai_embed_apply(array('index' => 'skyphusion-ai'));
expect('a later save keeps the related switch', $related_kept['related'], 1);
$related_off = skyphusion_ai_embed_apply(array('related' => ''));
expect('an empty related field turns the switch off', $related_off['related'], 0);
$related_on = skyphusion_ai_embed_apply(array('related' => '1'));
expect('a related field turns the switch on', $related_on['related'], 1);
$related_placed = skyphusion_ai_embed_apply(array(
    'related_place' => 'paragraph',
    'related_paragraph' => '2',
    'related_why' => '1',
));
expect('a paragraph place is saved', $related_placed['related_place'], 'paragraph');
expect('the paragraph number is saved', $related_placed['related_paragraph'], 2);
expect('a why field turns the line on', $related_placed['related_why'], 1);
$related_kept_place = skyphusion_ai_embed_apply(array('index' => 'skyphusion-ai'));
expect('a later save keeps the place', $related_kept_place['related_place'], 'paragraph');
expect('a later save keeps the why line', $related_kept_place['related_why'], 1);
$related_bad_place = skyphusion_ai_embed_apply(array('related_place' => 'sidebar', 'related_why' => ''));
expect('an unknown place falls back to after', $related_bad_place['related_place'], 'after');
expect('an empty why field turns the line off', $related_bad_place['related_why'], 0);
$related_before = skyphusion_ai_embed_apply(array(
    'related_place' => 'before',
    'related_why' => '1',
    'related_paragraph' => '0',
));
expect('before is a saved place', $related_before['related_place'], 'before');
expect('a paragraph number below one becomes one', $related_before['related_paragraph'], 1);

$GLOBALS['skyphusion_ai_embed_posts'] = array();
$GLOBALS['cfais_calls'] = 0;
ob_start();
skyphusion_ai_embed_render();
$related_screen = (string) ob_get_clean();
expect('the screen offers the related switch', strpos($related_screen, 'name="skyphusion_ai_related"') !== false, true);
expect('the screen offers a place', strpos($related_screen, 'name="skyphusion_ai_related_place"') !== false, true);
expect('the screen offers a paragraph number', strpos($related_screen, 'name="skyphusion_ai_related_paragraph"') !== false, true);
expect('the screen offers the why line', strpos($related_screen, 'name="skyphusion_ai_related_why"') !== false, true);
expect('the screen selects before', strpos($related_screen, 'value="before" selected="selected"') !== false, true);
expect('the screen checks the switch when it is on', strpos($related_screen, 'checked="checked"') !== false, true);
expect('the screen makes no request', $GLOBALS['cfais_calls'], 0);
expect('the screen does not show the token', strpos($related_screen, $related_token) === false, true);

$related_src = (string) file_get_contents(dirname(__DIR__) . '/includes/related.php');
expect('related posts do not name another vector store', stripos($related_src, 'qdrant') === false && stripos($related_src, 'postgres') === false, true);
expect('the shortcode is registered', strpos($related_src, "add_shortcode( 'skyphusion_ai_related'") !== false, true);
expect('the content filter is registered', strpos($related_src, "'the_content'") !== false, true);
expect('a page view does not call the embeddings function', strpos($related_src, 'cloudflare_ai_provider_embeddings') === false, true);
expect('related cli is registered', strpos($related_src, "WP_CLI::add_command( 'skyphusion-ai related'") !== false, true);

related_store(1, 1);
related_clear();
$GLOBALS['cfais_last_post'] = array('marker' => 1);
$GLOBALS['cfais_bodies'] = array($related_got, $related_query);
$GLOBALS['skyphusion_ai_embed_posts'] = array($related_page);
$GLOBALS['cfais_embed_post'] = $related_page;
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_related_cli(array('refresh', '6'), array());
expect('refresh stores one list', strpos(implode("\n", $GLOBALS['cfais_cli_log']), 'related_saved=1') !== false, true);
expect('refresh does not call the embeddings model', $GLOBALS['cfais_last_post'], array('marker' => 1));
expect('refresh says the model was not called', strpos(implode("\n", $GLOBALS['cfais_cli_log']), 'embed_called=0') !== false, true);
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_related_cli(array('show', '6'), array());
expect('show prints the saved neighbour', strpos(implode("\n", $GLOBALS['cfais_cli_log']), 'p7') !== false, true);
expect('show prints the reason', strpos(implode("\n", $GLOBALS['cfais_cli_log']), '64% the same subject.') !== false, true);
expect('show uses the saved list', $GLOBALS['cfais_calls'], 0);
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_related_cli(array('translate'), array());
expect('translation is not a related command', strpos(implode("\n", $GLOBALS['cfais_cli_log']), 'related_state=unknown') !== false, true);

related_store(1, 1);
$related_why_off = skyphusion_ai_embed_config();
$related_why_off['related_why'] = 0;
update_option('skyphusion_ai_vectorize', $related_why_off, false);
related_clear();
$GLOBALS['cfais_bodies'] = array($related_got, $related_query);
$related_quiet = skyphusion_ai_related_html($related_page);
expect('the why line can stay off', strpos($related_quiet, 'skyphusion-ai-related-why') === false, true);
expect('the title stays when the why line is off', strpos($related_quiet, 'Other 7') !== false, true);
expect('the why switch does not call the embeddings model', strpos((string) $GLOBALS['cfais_last_request']['url'], '/ai/run/') === false, true);

$related_before_config = skyphusion_ai_embed_config();
$related_before_config['related_place'] = 'before';
$related_before_config['related_why'] = 1;
$related_before_config['related'] = 1;
update_option('skyphusion_ai_vectorize', $related_before_config, false);
related_clear();
$GLOBALS['cfais_bodies'] = array($related_got, $related_query);
$related_before_html = skyphusion_ai_related_append('Body.', $related_page);
expect('before places the list first', strpos($related_before_html, '<section class="skyphusion-ai-related">') === 0, true);
expect('before keeps the page body', substr($related_before_html, -5), 'Body.');

$related_para = skyphusion_ai_embed_config();
$related_para['related_place'] = 'paragraph';
$related_para['related_paragraph'] = 1;
update_option('skyphusion_ai_vectorize', $related_para, false);
related_clear();
$GLOBALS['cfais_bodies'] = array($related_got, $related_query);
$related_para_html = skyphusion_ai_related_append('<p>One.</p><p>Two.</p>', $related_page);
expect('a paragraph place follows the first paragraph', strpos($related_para_html, '<p>One.</p><section class="skyphusion-ai-related">') === 0, true);
expect('the second paragraph stays after the list', strpos($related_para_html, '</section><p>Two.</p>') !== false, true);

$related_para['related_paragraph'] = 9;
update_option('skyphusion_ai_vectorize', $related_para, false);
related_clear();
$GLOBALS['cfais_bodies'] = array($related_got, $related_query);
$related_late = skyphusion_ai_related_append('<p>One.</p>', $related_page);
expect('a later paragraph puts the list at the end', strpos($related_late, '</p><section class="skyphusion-ai-related">') !== false, true);

related_clear();
$GLOBALS['cfais_bodies'] = array($related_got, $related_query);
$related_nop = skyphusion_ai_related_append('Body.', $related_page);
expect('text with no paragraph keeps the list at the end', strpos($related_nop, 'Body.<section class="skyphusion-ai-related">') === 0, true);

$related_term_got = wp_json_encode(array(
    'success' => true,
    'result' => array(array(
        'id' => 'p6',
        'values' => array(0.25, 0.5),
        'metadata' => array('title' => 'This page', 'terms' => 'Linen, Cotton'),
    )),
));
$related_term_query = wp_json_encode(array(
    'success' => true,
    'result' => array('matches' => array(array(
        'id' => 'p7',
        'score' => 0.64,
        'metadata' => array(
            'title' => 'Other 7',
            'url' => 'https://example.test/p7',
            'terms' => 'Cotton, Wool',
        ),
    ))),
));
related_clear();
$GLOBALS['cfais_bodies'] = array($related_term_got, $related_term_query);
$related_term_rows = skyphusion_ai_related_rows($related_page);
expect('shared stored terms say why', $related_term_rows[0]['reason'], 'Also about Cotton.');
$related_two = embed_post(6, 'This page', 'Body', array('categories' => array('Linen'), 'tags' => array('Wool')));
related_clear();
$GLOBALS['cfais_bodies'] = array($related_got, $related_term_query);
$related_two_rows = skyphusion_ai_related_rows($related_two);
expect('a stored tag on the post says why', $related_two_rows[0]['reason'], 'Also about Wool.');
expect(
    'three shared terms stay in one sentence',
    skyphusion_ai_related_why(array('terms' => 'Wool, Cotton, Linen'), 0.2, array('Linen', 'Cotton', 'Wool')),
    'Also about Wool, Cotton, and Linen.'
);
expect(
    'two shared terms use and',
    skyphusion_ai_related_why(array('terms' => 'Linen, Cotton'), 0.2, array('Linen', 'Cotton')),
    'Also about Linen and Cotton.'
);
$related_meta = skyphusion_ai_embed_metadata(embed_post(6, 'This page', 'Body', array(
    'categories' => array('Linen'),
    'tags' => array('Cotton'),
)));
expect('stored metadata keeps the terms', $related_meta['terms'], 'Linen, Cotton');
expect('stored metadata still omits the body', isset($related_meta['post_content']), false);
expect('a row with no stored clue says it is close', skyphusion_ai_related_why(array(), null, array()), 'Close in subject.');
expect('a high paragraph number becomes twenty', skyphusion_ai_embed_apply(array('related_paragraph' => '40'))['related_paragraph'], 20);

unset($GLOBALS['cfais_settings']);
unset($GLOBALS['cfais_bodies']);
unset($GLOBALS['skyphusion_ai_embed_posts']);
unset($GLOBALS['cfais_embed_post']);
unset($GLOBALS['cfais_options']['skyphusion_ai_vectorize']);
if (false === $related_prev_key || '' === $related_prev_key) {
    putenv('CLOUDFLARE_API_KEY');
} else {
    putenv('CLOUDFLARE_API_KEY=' . $related_prev_key);
}
if (false === $related_prev_account || '' === $related_prev_account) {
    putenv('CLOUDFLARE_ACCOUNT_ID');
} else {
    putenv('CLOUDFLARE_ACCOUNT_ID=' . $related_prev_account);
}
