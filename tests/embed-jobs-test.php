<?php
/**
 * The embed jobs read the selection one batch at a time (issue #200).
 * Loaded after embed-step-test.php.
 *
 * status, estimate, retry, reconcile, move and the related refresh used
 * to load every chosen post in one request. They now walk the same ids
 * and pages as the step from #201, in batches. These checks drive the
 * shipped WP-CLI commands on a store with 2609 posts.
 */

if (!function_exists('wp_cache_supports')) {
    function wp_cache_supports($feature)
    {
        return !empty($GLOBALS['jobs_cache_runtime']) && 'flush_runtime' === $feature;
    }
}
if (!function_exists('wp_cache_flush_runtime')) {
    function wp_cache_flush_runtime()
    {
        $GLOBALS['jobs_flushes']++;
        return true;
    }
}

$jobs_prev_posts = isset($GLOBALS['skyphusion_ai_embed_posts']) ? $GLOBALS['skyphusion_ai_embed_posts'] : null;
$jobs_prev_body = isset($GLOBALS['cfais_body']) ? $GLOBALS['cfais_body'] : null;
$jobs_prev_key = getenv('CLOUDFLARE_API_KEY');
$jobs_prev_account = getenv('CLOUDFLARE_ACCOUNT_ID');
putenv('CLOUDFLARE_API_KEY=sentinel-token-' . str_repeat('a', 30));
putenv('CLOUDFLARE_ACCOUNT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
$GLOBALS['cfais_settings'] = array(
    'endpoint' => 'https://abc123.search.ai.cloudflare.com',
    'lead_search' => true,
    'account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'gateway_id' => '',
);

/** Count the lines of the last command that carry this text. */
function jobs_line($text)
{
    return strpos(embed_cli_lines(), $text) !== false;
}

/** Hash a post as stored, so status counts it as embedded. */
function jobs_store($post)
{
    $GLOBALS['cfais_embed_meta'][(int) $post['ID']]['skyphusion_ai_embed_hash'] = hash('sha256', skyphusion_ai_embed_text($post, array('title', 'content', 'excerpt')));
}

/** Forget every meta row the posts of this file own. */
function jobs_forget($posts)
{
    foreach ($posts as $post) {
        unset($GLOBALS['cfais_embed_meta'][(int) $post['ID']]);
    }
}

embed_ready();
$jobs_posts = step_posts(2609);
$GLOBALS['skyphusion_ai_embed_posts'] = $jobs_posts;
jobs_forget($jobs_posts);

// 1. The walk: at most one batch per call, every post once, in id order.
$jobs_run_cursor = array('after' => 501, 'batch' => 25, 'active' => 1, 'seen' => 167, 'embedded' => 167, 'state' => 'running');
update_option('skyphusion_ai_embed_cursor', $jobs_run_cursor, false);
$GLOBALS['jobs_sizes'] = array();
$GLOBALS['jobs_ids'] = array();
$jobs_walk = skyphusion_ai_embed_each_batch(function ($rows) {
    $GLOBALS['jobs_sizes'][] = count($rows);
    foreach ($rows as $row) {
        $GLOBALS['jobs_ids'][] = (int) $row['ID'];
    }
});
expect('no batch holds more than 25 posts', max($GLOBALS['jobs_sizes']), 25);
expect('the walk takes ceil(2609 / 25) batches', $jobs_walk['batches'], 105);
expect('the walk counts every post', $jobs_walk['posts'], 2609);
expect('the walk gives every post once', count(array_unique($GLOBALS['jobs_ids'])), 2609);
$jobs_sorted = $GLOBALS['jobs_ids'];
sort($jobs_sorted);
expect('the walk goes in id order', $GLOBALS['jobs_ids'], $jobs_sorted);
expect('the walk does not move a run in progress', skyphusion_ai_embed_cursor(), $jobs_run_cursor);

$GLOBALS['jobs_sizes'] = array();
$jobs_walk = skyphusion_ai_embed_each_batch(function ($rows) {
    $GLOBALS['jobs_sizes'][] = count($rows);
}, 200);
expect('a bigger batch size gives fewer batches', $jobs_walk['batches'], 14);
expect('a batch of 200 holds at most 200 posts', max($GLOBALS['jobs_sizes']), 200);

$GLOBALS['jobs_sizes'] = array();
$jobs_walk = skyphusion_ai_embed_each_batch(function ($rows) {
    $GLOBALS['jobs_sizes'][] = count($rows);
    return count($GLOBALS['jobs_sizes']) < 3;
});
expect('a job can stop the walk', $jobs_walk['stopped'], true);
expect('a stopped walk loads no more batches', $jobs_walk['batches'], 3);

$GLOBALS['jobs_cache_runtime'] = true;
$GLOBALS['jobs_flushes'] = 0;
skyphusion_ai_embed_each_batch(function ($rows) {
});
expect('the runtime cache is cleared after each batch', $GLOBALS['jobs_flushes'], 105);
$GLOBALS['jobs_cache_runtime'] = false;
$GLOBALS['jobs_flushes'] = 0;
skyphusion_ai_embed_each_batch(function ($rows) {
});
expect('a cache that cannot clear its runtime part is left alone', $GLOBALS['jobs_flushes'], 0);
update_option('skyphusion_ai_embed_cursor', array(), false);

// NEGATIVE CONTROL: the old one-request shape hands one call every post.
$GLOBALS['jobs_sizes'] = array(count($jobs_posts));
expect('the old one-request shape holds 2609 posts in one call', max($GLOBALS['jobs_sizes']), 2609);
expect('so the old shape fails the batch rule this file applies', max($GLOBALS['jobs_sizes']) > 25, true);
expect('the full-load helper is gone', function_exists('skyphusion_ai_embed_collect'), false);

// 2. status: 1000 stored, 100 failed, the rest waiting. Same counts as one full tally.
foreach ($jobs_posts as $jobs_post) {
    $jobs_id = (int) $jobs_post['ID'];
    if ($jobs_id <= 3000) {
        jobs_store($jobs_post);
    } elseif ($jobs_id <= 3300) {
        $GLOBALS['cfais_embed_meta'][$jobs_id]['skyphusion_ai_embed_failed'] = '1';
    }
}
$jobs_full = skyphusion_ai_embed_tally($jobs_posts, skyphusion_ai_embed_config());
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('status'), array());
expect('status counts the stored posts', jobs_line('embed_embedded=1000'), true);
expect('status counts the failed posts', jobs_line('embed_failed=100'), true);
expect('status counts the waiting posts', jobs_line('embed_waiting=1509'), true);
expect('status agrees with one full tally', array($jobs_full['embedded'], $jobs_full['waiting'], $jobs_full['failed']), array(1000, 1509, 100));
expect('status reads 105 batches', jobs_line('embed_batches=105'), true);
expect('status makes no request', $GLOBALS['cfais_calls'], 0);
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('status'), array('batch' => '200'));
expect('status obeys --batch', jobs_line('embed_batches=14'), true);
expect('status counts the same with --batch', jobs_line('embed_waiting=1509'), true);

// 3. estimate: the same numbers as one full estimate, in 105 batches.
$jobs_estimate = skyphusion_ai_embed_estimate($jobs_posts, skyphusion_ai_embed_config());
$GLOBALS['cfais_calls'] = 0;
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('estimate'), array());
expect('estimate counts the posts that would be sent', jobs_line('embed_posts=' . $jobs_estimate['posts']), true);
expect('estimate counts the waiting and failed posts', $jobs_estimate['posts'], 1609);
expect('estimate gives the same neurons as one full estimate', jobs_line('embed_neurons=' . $jobs_estimate['neurons']), true);
expect('estimate gives the same passages as one full estimate', jobs_line('embed_passages=' . $jobs_estimate['passages']), true);
expect('estimate reads 105 batches', jobs_line('embed_batches=105'), true);
expect('estimate makes no request', $GLOBALS['cfais_calls'], 0);

// 4. retry: the 100 failed posts are stored again, in 105 batches.
unset($GLOBALS['cfais_bodies']);
$GLOBALS['cfais_body'] = '{"success":true,"result":{"data":[[0.25,0.5]],"mutationId":"jobs1"}}';
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('retry'), array());
expect('retry stores every failed post', jobs_line('embed_retried=100'), true);
expect('retry reads 105 batches', jobs_line('embed_batches=105'), true);
$jobs_failed_left = 0;
foreach ($jobs_posts as $jobs_post) {
    if (!empty($GLOBALS['cfais_embed_meta'][(int) $jobs_post['ID']]['skyphusion_ai_embed_failed'])) {
        $jobs_failed_left++;
    }
}
expect('retry leaves no failed post', $jobs_failed_left, 0);
embed_ready();

// 5. reconcile: 50 stored posts that are now locked lose their vectors.
$jobs_locked = 0;
foreach ($jobs_posts as $jobs_index => $jobs_post) {
    if ((int) $jobs_post['ID'] <= 150) {
        $jobs_posts[$jobs_index]['post_password'] = 'locked';
        $jobs_locked++;
    }
}
expect('the fixture locks 50 stored posts', $jobs_locked, 50);
$GLOBALS['skyphusion_ai_embed_posts'] = $jobs_posts;
$GLOBALS['cfais_body'] = '{"success":true,"result":{"mutationId":"jobs2"}}';
$GLOBALS['cfais_last_post'] = array('marker' => 1);
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('reconcile'), array());
expect('reconcile removes the locked posts', jobs_line('embed_removed=50'), true);
expect('reconcile reads 105 batches', jobs_line('embed_batches=105'), true);
expect('reconcile does not call the model', $GLOBALS['cfais_last_post'], array('marker' => 1));

// 6. move: one create, then one read and one write per batch.
foreach ($jobs_posts as $jobs_post) {
    jobs_store($jobs_post);
}
$GLOBALS['cfais_body'] = '{"success":true,"result":[{"id":"p3","values":[0.25,0.5],"metadata":{"title":"Item"}}]}';
$GLOBALS['cfais_request_urls'] = array();
$GLOBALS['cfais_last_post'] = array('marker' => 1);
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('move'), array('index' => 'shop-copy'));
$jobs_create = 0;
$jobs_read = 0;
$jobs_write = 0;
foreach ($GLOBALS['cfais_request_urls'] as $jobs_url) {
    if ('/vectorize/v2/indexes' === substr($jobs_url, -strlen('/vectorize/v2/indexes'))) {
        $jobs_create++;
    }
    if (strpos($jobs_url, '/skyphusion-ai/get_by_ids') !== false) {
        $jobs_read++;
    }
    if (strpos($jobs_url, '/shop-copy/upsert') !== false) {
        $jobs_write++;
    }
}
expect('move creates the other index once', $jobs_create, 1);
expect('move reads one batch at a time', $jobs_read, 105);
expect('move writes one batch at a time', $jobs_write, 105);
expect('move says how many batches it read', jobs_line('embed_batches=105'), true);
expect('move copies each batch', jobs_line('embed_moved=105'), true);
expect('move does not call the model', $GLOBALS['cfais_last_post'], array('marker' => 1));

$GLOBALS['cfais_body'] = '{"success":true,"result":[]}';
$GLOBALS['cfais_request_urls'] = array();
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('move'), array('index' => 'shop-copy'));
$jobs_read = 0;
foreach ($GLOBALS['cfais_request_urls'] as $jobs_url) {
    if (strpos($jobs_url, '/get_by_ids') !== false) {
        $jobs_read++;
    }
}
expect('a batch that reads no vectors stops the move', jobs_line('embed_state=blocked'), true);
expect('a blocked move reads no more batches', $jobs_read, 1);

// 7. related refresh: every post gets a list, in 105 batches.
$GLOBALS['cfais_body'] = '{"success":true,"result":{"matches":[]}}';
$GLOBALS['cfais_last_post'] = array('marker' => 1);
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_related_cli(array('refresh'), array());
expect('refresh stores a list for every post', jobs_line('related_saved=2609'), true);
expect('refresh reads 105 batches', jobs_line('related_batches=105'), true);
expect('refresh does not call the embeddings model', $GLOBALS['cfais_last_post'], array('marker' => 1));
// The one-post refresh (related refresh 123) is unchanged. related-test.php covers it.

// Leave the shared state as it was for the files that follow.
jobs_forget($jobs_posts);
unset($GLOBALS['cfais_settings'], $GLOBALS['jobs_cache_runtime']);
if (false === $jobs_prev_key || '' === $jobs_prev_key) {
    putenv('CLOUDFLARE_API_KEY');
} else {
    putenv('CLOUDFLARE_API_KEY=' . $jobs_prev_key);
}
if (false === $jobs_prev_account || '' === $jobs_prev_account) {
    putenv('CLOUDFLARE_ACCOUNT_ID');
} else {
    putenv('CLOUDFLARE_ACCOUNT_ID=' . $jobs_prev_account);
}
update_option('skyphusion_ai_embed_cursor', array(), false);
if (null === $jobs_prev_posts) {
    unset($GLOBALS['skyphusion_ai_embed_posts']);
} else {
    $GLOBALS['skyphusion_ai_embed_posts'] = $jobs_prev_posts;
}
if (null === $jobs_prev_body) {
    unset($GLOBALS['cfais_body']);
} else {
    $GLOBALS['cfais_body'] = $jobs_prev_body;
}
