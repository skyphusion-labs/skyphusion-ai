<?php
/**
 * The unlinked-pages scan keeps its result (issue #211). Loaded after links-batch-test.php.
 *
 * The second pass checks every page against every address. Its result is
 * kept in a transient keyed by a fingerprint of every page that the first
 * pass read. These checks change one page at a time and check that the
 * next scan runs the second pass again and equals one full scan.
 */

$lcache_prev_posts = isset($GLOBALS['skyphusion_ai_link_posts']) ? $GLOBALS['skyphusion_ai_link_posts'] : null;
$lcache_prev_linked = isset($GLOBALS['skyphusion_ai_link_linked']) ? $GLOBALS['skyphusion_ai_link_linked'] : null;
unset($GLOBALS['skyphusion_ai_link_linked'], $GLOBALS['skyphusion_ai_link_rows']);
delete_transient(skyphusion_ai_link_cache_key());

/** Address of cache-test page $i. */
function lcache_url($i)
{
    return 'https://shop.example/note-' . $i . '/';
}

// Pages 1..300 have ids 7, 14, 21, ... Page i links to page i+1 up to page
// 270, so pages 2..271 are linked and 1 and 272..300 are not.
$lcache_pages = array();
for ($i = 1; $i <= 300; $i++) {
    $content = 'Body of note ' . $i . '.';
    if ($i <= 270) {
        $content .= ' See <a href="' . lcache_url($i + 1) . '">next</a>.';
    }
    $lcache_pages[$i] = array('post_id' => $i * 7, 'title' => 'Note ' . $i, 'url' => lcache_url($i), 'content' => $content);
}

/** Load the pages as the stand-in, shuffled, and scan. */
function lcache_scan($pages)
{
    $rows = array_values($pages);
    shuffle($rows);
    $GLOBALS['skyphusion_ai_link_posts'] = $rows;
    return skyphusion_ai_link_scan('Nothing', 0);
}

/** Orphan ids of a scan. */
function lcache_orphans($scan)
{
    $ids = array();
    foreach ($scan['orphans'] as $row) {
        $ids[] = (int) $row['post_id'];
    }
    return $ids;
}

/** Orphan ids from one full scan with the unchanged functions, no cache. */
function lcache_full($pages)
{
    $rows = array_values($pages);
    usort($rows, function ($a, $b) {
        return $b['post_id'] - $a['post_id'];
    });
    $ids = array();
    foreach (skyphusion_ai_link_orphans($rows, skyphusion_ai_link_inbound_ids($rows)) as $row) {
        $ids[] = (int) $row['post_id'];
    }
    return $ids;
}

// 1. Cold: both passes run and the result is kept.
$lcache_cold = lcache_scan($lcache_pages);
expect('a cold scan runs both passes', $lcache_cold['batches'], 6);
expect('a cold scan is not from the cache', $lcache_cold['cached'], false);
$lcache_entry = get_transient(skyphusion_ai_link_cache_key());
expect('a cold scan keeps its result', is_array($lcache_entry) && 1 === preg_match('/^[0-9a-f]{64}$/', (string) $lcache_entry['print']), true);
expect('the kept result holds the linked ids', count($lcache_entry['linked']), 270);
expect('the kept result expires after one day', $GLOBALS['cfais_store'][skyphusion_ai_link_cache_key()]['ttl'], 86400);
expect('the cold list equals one full scan', lcache_orphans($lcache_cold), lcache_full($lcache_pages));

// 2. Warm: the same pages, in another order, skip the second pass.
$lcache_warm = lcache_scan($lcache_pages);
expect('a warm scan runs the first pass only', $lcache_warm['batches'], 3);
expect('a warm scan is from the cache', $lcache_warm['cached'], true);
expect('the warm list equals the cold list', $lcache_warm['orphans'], $lcache_cold['orphans']);

// 3. Each change below must run the second pass again and equal one full scan.
$lcache_cases = array();
$lcache_edit = $lcache_pages;
$lcache_edit[10]['content'] .= ' Also <a href="' . lcache_url(300) . '">the last note</a>.';
$lcache_cases['a page gains a link'] = $lcache_edit;
$lcache_unlink = $lcache_pages;
$lcache_unlink[5]['content'] = 'Body of note 5.';
$lcache_cases['a page loses its link'] = $lcache_unlink;
$lcache_move = $lcache_pages;
$lcache_move[100]['url'] = 'https://shop.example/moved-note/';
$lcache_cases['a page address changes'] = $lcache_move;
$lcache_gone = $lcache_pages;
unset($lcache_gone[50]);
$lcache_cases['a page is removed'] = $lcache_gone;
$lcache_new = $lcache_pages;
$lcache_new[301] = array('post_id' => 301 * 7, 'title' => 'Note 301', 'url' => lcache_url(301), 'content' => 'New note.');
$lcache_cases['a page is added'] = $lcache_new;
$lcache_retitle = $lcache_pages;
$lcache_retitle[300]['title'] = 'Renamed note';
foreach ($lcache_cases as $lcache_name => $lcache_set) {
    lcache_scan($lcache_pages);
    $lcache_after = lcache_scan($lcache_set);
    expect('the second pass runs again when ' . $lcache_name, $lcache_after['batches'] > 3 && false === $lcache_after['cached'], true);
    expect('the list equals one full scan when ' . $lcache_name, lcache_orphans($lcache_after), lcache_full($lcache_set));
}
expect('the gained link takes its page off the list', in_array(300 * 7, lcache_full($lcache_edit), true), false);
expect('the lost link leaves its page with no inbound link', in_array(6 * 7, skyphusion_ai_link_inbound_ids(array_values($lcache_unlink)), true), false);

// A title is not in the fingerprint: the linked ids do not depend on it,
// and the list still shows the new title from the first pass.
lcache_scan($lcache_pages);
$lcache_titled = lcache_scan($lcache_retitle);
expect('a new title alone reuses the kept result', $lcache_titled['cached'], true);
expect('the list shows the new title', $lcache_titled['orphans'][0]['title'], 'Renamed note');

// 4. A kept result for other pages is never used.
set_transient(skyphusion_ai_link_cache_key(), array('print' => str_repeat('0', 64), 'linked' => array()), 86400);
$lcache_other = lcache_scan($lcache_pages);
expect('a kept result with another fingerprint is not used', $lcache_other['cached'], false);
expect('so the list is still right', lcache_orphans($lcache_other), lcache_full($lcache_pages));
set_transient(skyphusion_ai_link_cache_key(), 'not an entry', 86400);
expect('a broken kept result is not used', lcache_scan($lcache_pages)['cached'], false);

// 5. The fingerprint parts.
expect('a row print keeps the field borders', skyphusion_ai_link_print_row(array('post_id' => 1, 'url' => 'ab', 'content' => 'c')) !== skyphusion_ai_link_print_row(array('post_id' => 1, 'url' => 'a', 'content' => 'bc')), true);
expect('the scheme holds a hash of the scan code', strpos(skyphusion_ai_link_cache_scheme(), (string) hash_file('sha256', dirname(__DIR__) . '/includes/links.php')) !== false, true);

// 6. The test stand-in for the linked ids skips the cache.
$GLOBALS['skyphusion_ai_link_linked'] = array();
$lcache_stand = lcache_scan($lcache_pages);
expect('the linked stand-in is not from the cache', $lcache_stand['cached'], false);
unset($GLOBALS['skyphusion_ai_link_linked']);

// 7. Reset and uninstall remove it.
expect('reset deletes the kept result', strpos((string) file_get_contents(dirname(__DIR__) . '/includes/reset.php'), "'skyphusion_ai_link_linked',") !== false, true);
expect('uninstall deletes the kept result', strpos((string) file_get_contents(dirname(__DIR__) . '/uninstall.php'), "delete_transient( 'skyphusion_ai_link_linked' )") !== false, true);

// Leave the shared state as it was for the files that follow.
delete_transient(skyphusion_ai_link_cache_key());
if (null === $lcache_prev_posts) {
    unset($GLOBALS['skyphusion_ai_link_posts']);
} else {
    $GLOBALS['skyphusion_ai_link_posts'] = $lcache_prev_posts;
}
if (null === $lcache_prev_linked) {
    unset($GLOBALS['skyphusion_ai_link_linked']);
} else {
    $GLOBALS['skyphusion_ai_link_linked'] = $lcache_prev_linked;
}
