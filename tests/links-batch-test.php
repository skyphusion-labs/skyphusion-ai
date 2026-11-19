<?php
/**
 * The link box reads pages one batch at a time (issue #207). Loaded after links-test.php.
 *
 * skyphusion_ai_link_posts() used to load every published post and page
 * in one get_posts call, twice per render, plus once more for the linked
 * ids. The box now walks ids and pages in batches with the embed jobs'
 * walk (#205). These checks drive the shipped render on 2609 pages.
 */

$lbatch_prev_posts = isset($GLOBALS['skyphusion_ai_link_posts']) ? $GLOBALS['skyphusion_ai_link_posts'] : null;
$lbatch_prev_linked = isset($GLOBALS['skyphusion_ai_link_linked']) ? $GLOBALS['skyphusion_ai_link_linked'] : null;
unset($GLOBALS['skyphusion_ai_link_linked'], $GLOBALS['skyphusion_ai_link_rows']);

/** Address of page $i. */
function lbatch_url($i)
{
    return 'https://shop.example/page-' . $i . '/';
}

// Pages 1..2609 have ids 3, 6, 9, ... Page i links to page i+1 up to
// page 2580, so pages 2..2581 are linked and 1 and 2582..2609 are not.
// Seven pages contain the word linen.
$lbatch_linen = array(5, 1200, 2000, 2400, 2500, 2600, 2608);
$lbatch_rows = array();
for ($i = 1; $i <= 2609; $i++) {
    $content = 'Body of page ' . $i . '.';
    if ($i <= 2580) {
        $content .= ' See <a href="' . lbatch_url($i + 1) . '">next</a>.';
    }
    if (in_array($i, $lbatch_linen, true)) {
        $content .= ' Our linen is soft.';
    }
    $lbatch_rows[] = array('post_id' => $i * 3, 'title' => 'Page ' . $i, 'url' => lbatch_url($i), 'content' => $content);
}
shuffle($lbatch_rows);
$GLOBALS['skyphusion_ai_link_posts'] = $lbatch_rows;

// 1. The link source on the shared walk: batches of at most 100, every page once, in id order.
$GLOBALS['lbatch_sizes'] = array();
$GLOBALS['lbatch_ids'] = array();
$lbatch_walk = skyphusion_ai_embed_each_batch(function ($rows) {
    $GLOBALS['lbatch_sizes'][] = count($rows);
    foreach ($rows as $row) {
        $GLOBALS['lbatch_ids'][] = (int) $row['post_id'];
    }
}, skyphusion_ai_link_batch(), array('ids' => 'skyphusion_ai_link_ids', 'load' => 'skyphusion_ai_link_load_page'));
expect('no link batch holds more than 100 pages', max($GLOBALS['lbatch_sizes']), 100);
expect('the link walk takes ceil(2609 / 100) batches', $lbatch_walk['batches'], 27);
expect('the link walk gives every page once', count(array_unique($GLOBALS['lbatch_ids'])), 2609);
$lbatch_sorted = $GLOBALS['lbatch_ids'];
sort($lbatch_sorted);
expect('the link walk goes in id order', $GLOBALS['lbatch_ids'], $lbatch_sorted);
$lbatch_prev_embed = isset($GLOBALS['skyphusion_ai_embed_posts']) ? $GLOBALS['skyphusion_ai_embed_posts'] : null;
$GLOBALS['lbatch_embed'] = 0;
$GLOBALS['skyphusion_ai_embed_posts'] = array(embed_post(4, 'Embed only', 'Not a link page'));
skyphusion_ai_embed_each_batch(function ($rows) {
    $GLOBALS['lbatch_embed'] += count($rows);
}, 25);
expect('with no source the walk still reads the embed selection', $GLOBALS['lbatch_embed'], 1);
if (null === $lbatch_prev_embed) {
    unset($GLOBALS['skyphusion_ai_embed_posts']);
} else {
    $GLOBALS['skyphusion_ai_embed_posts'] = $lbatch_prev_embed;
}

// 2. The scan: two passes of 27 batches, and the same lists as one full scan.
$GLOBALS['jobs_cache_runtime'] = true;
$GLOBALS['jobs_flushes'] = 0;
$lbatch_scan = skyphusion_ai_link_scan('Linen', 2608 * 3);
expect('the scan reads two passes of 27 batches', $lbatch_scan['batches'], 54);
expect('the runtime cache is cleared after each link batch', $GLOBALS['jobs_flushes'], 54);
$GLOBALS['jobs_cache_runtime'] = false;
expect('the scan counts every page', $lbatch_scan['pages'], 2609);
$lbatch_titles = array();
foreach ($lbatch_scan['returns'] as $row) {
    $lbatch_titles[] = $row['title'];
}
expect('return links are the five newest pages with the title', $lbatch_titles, array('Page 2600', 'Page 2500', 'Page 2400', 'Page 2000', 'Page 1200'));
expect('the page on the screen is not a return link', in_array('Page 2608', $lbatch_titles, true), false);
$lbatch_orphans = array();
foreach ($lbatch_scan['orphans'] as $row) {
    $lbatch_orphans[] = (int) $row['post_id'];
}
expect('unlinked pages are the 20 newest with no link', $lbatch_orphans, range(2609 * 3, 2590 * 3, -3));
expect('a linked page is not listed', in_array(2581 * 3, $lbatch_orphans, true), false);

// The same answer as one full scan over every page (the old shape).
$lbatch_full_linked = skyphusion_ai_link_inbound_ids($lbatch_rows);
expect('the full scan finds pages 2 to 2581 linked', count($lbatch_full_linked), 2580);
$lbatch_desc = $lbatch_rows;
usort($lbatch_desc, function ($a, $b) {
    return $b['post_id'] - $a['post_id'];
});
$lbatch_full_orphans = skyphusion_ai_link_orphans($lbatch_desc, $lbatch_full_linked);
expect('the batched unlinked list matches one full scan', $lbatch_scan['orphans'], $lbatch_full_orphans);
$lbatch_full_returns = skyphusion_ai_link_returns('Linen', $lbatch_desc, 2608 * 3);
expect('the batched return links match one full scan', $lbatch_scan['returns'], $lbatch_full_returns);

// NEGATIVE CONTROL: the old shape hands one call every page.
expect('the old one-request shape holds 2609 pages in one call', count($lbatch_rows), 2609);
expect('so the old shape fails the batch rule this file applies', count($lbatch_rows) > skyphusion_ai_link_batch(), true);
expect('the full-load helper is gone', function_exists('skyphusion_ai_link_posts'), false);

// 3. The shipped render on 2609 pages.
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_can']['edit_posts'] = true;
$GLOBALS['skyphusion_ai_link_content'] = 'Nothing to suggest here.';
update_option('skyphusion_ai_vectorize', array('index' => 'skyphusion-ai', 'created' => 0), false);
ob_start();
skyphusion_ai_link_render(array('ID' => 2608 * 3, 'post_title' => 'Linen'));
$lbatch_screen = (string) ob_get_clean();
expect('the render names a return page', strpos($lbatch_screen, 'Page 2600') !== false, true);
expect('the render lists the newest unlinked page', strpos($lbatch_screen, '<li>Page 2609</li>') !== false, true);
expect('the render does not list a linked page', strpos($lbatch_screen, '<li>Page 2581</li>') === false, true);
expect('the render makes no request', $GLOBALS['cfais_calls'], 0);

// 4. No pages: the screen says so.
$GLOBALS['skyphusion_ai_link_posts'] = array();
ob_start();
skyphusion_ai_link_render(array('ID' => 3, 'post_title' => 'Linen'));
$lbatch_empty = (string) ob_get_clean();
expect('with no pages the screen says none was found', strpos($lbatch_empty, 'No published page was found.') !== false, true);

// Leave the shared state as it was for the files that follow.
unset($GLOBALS['cfais_can']['edit_posts'], $GLOBALS['skyphusion_ai_link_content'], $GLOBALS['cfais_options']['skyphusion_ai_vectorize']);
if (null === $lbatch_prev_posts) {
    unset($GLOBALS['skyphusion_ai_link_posts']);
} else {
    $GLOBALS['skyphusion_ai_link_posts'] = $lbatch_prev_posts;
}
if (null === $lbatch_prev_linked) {
    unset($GLOBALS['skyphusion_ai_link_linked']);
} else {
    $GLOBALS['skyphusion_ai_link_linked'] = $lbatch_prev_linked;
}
