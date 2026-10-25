<?php
/**
 * Internal link suggestions. Loaded after translate-test.php.
 * The screen suggests. It does not change the post.
 */

if (!function_exists('skyphusion_ai_link_suggestions')) {
    require_once dirname(__DIR__) . '/includes/links.php';
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

$link_src = (string) file_get_contents(dirname(__DIR__) . '/includes/links.php');
expect('the screen does not read a remote address', strpos($link_src, 'REMOTE_ADDR') === false, true);
expect('the screen does not call the embeddings model', strpos($link_src, 'skyphusion_ai_embed_search') === false, true);
expect('the screen does not save the post', strpos($link_src, 'wp_update_post') === false, true);
expect('the screen uses the stored vectors', strpos($link_src, 'skyphusion_ai_related_rows') !== false, true);

unset($GLOBALS['skyphusion_ai_link_rows']);
unset($GLOBALS['skyphusion_ai_link_content']);
unset($GLOBALS['skyphusion_ai_link_posts']);
unset($GLOBALS['skyphusion_ai_link_linked']);
unset($GLOBALS['cfais_options']['skyphusion_ai_vectorize']);
unset($GLOBALS['cfais_can']['edit_posts']);
