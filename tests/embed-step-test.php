<?php
/**
 * Chunked, resumable embedding run (issue #197). Loaded after embed-test.php.
 *
 * The step embeds at most one batch after a saved cursor. These checks
 * drive the shipped skyphusion_ai_embed_step(): with a counting stand-in
 * for the per-post store (to measure a store with 2609 posts), and with
 * the real skyphusion_ai_embed_sync_post() behind the HTTP stand-ins.
 */

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook)
    {
        return isset($GLOBALS['step_cron'][$hook]) ? $GLOBALS['step_cron'][$hook] : false;
    }
}
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($when, $hook)
    {
        $GLOBALS['step_cron'][$hook] = (int) $when;
        return true;
    }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args)
    {
        $GLOBALS['step_routes'][$namespace . $route] = $args;
        return true;
    }
}

$step_prev_posts = isset($GLOBALS['skyphusion_ai_embed_posts']) ? $GLOBALS['skyphusion_ai_embed_posts'] : null;
$step_prev_body = isset($GLOBALS['cfais_body']) ? $GLOBALS['cfais_body'] : null;

/** Posts with ids 3, 6, 9, ... so the cursor has gaps to cross. */
function step_posts($count)
{
    $posts = array();
    for ($i = 1; $i <= $count; $i++) {
        $posts[] = embed_post($i * 3, 'Item ' . $i, 'Body of item ' . $i);
    }
    // Shuffled input: the step must order by id itself.
    shuffle($posts);
    return $posts;
}

/** A per-post store that records which post it saw, in which step. */
function step_counter($post)
{
    $GLOBALS['step_seen'][] = (int) $post['ID'];
    $GLOBALS['step_this'][] = (int) $post['ID'];
    if (isset($GLOBALS['step_limit_at']) && (int) $post['ID'] === $GLOBALS['step_limit_at']) {
        unset($GLOBALS['step_limit_at']);
        array_pop($GLOBALS['step_seen']);
        return array('state' => 'limit');
    }
    if (isset($GLOBALS['step_die_at']) && (int) $post['ID'] === $GLOBALS['step_die_at']) {
        unset($GLOBALS['step_die_at']);
        array_pop($GLOBALS['step_seen']);
        throw new RuntimeException('the request ended');
    }
    return array('state' => 'embedded');
}

// The same credentials embed-test.php uses. That file restores them when it ends.
$step_prev_key = getenv('CLOUDFLARE_API_KEY');
$step_prev_account = getenv('CLOUDFLARE_ACCOUNT_ID');
putenv('CLOUDFLARE_API_KEY=sentinel-token-' . str_repeat('a', 30));
putenv('CLOUDFLARE_ACCOUNT_ID=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
$GLOBALS['cfais_settings'] = array(
    'endpoint' => 'https://abc123.search.ai.cloudflare.com',
    'lead_search' => true,
    'account_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    'gateway_id' => '',
);

embed_ready();
$GLOBALS['step_cron'] = array();
update_option('skyphusion_ai_embed_cursor', array(), false);

expect('a batch size below one falls back to the default', skyphusion_ai_embed_batch_size(0), 25);
expect('a batch size above the cap is capped', skyphusion_ai_embed_batch_size(5000), 200);
expect('a text batch size falls back to the default', skyphusion_ai_embed_batch_size('many'), 25);

// 1. A large store: no step touches more than N posts, and every post is done once.
$GLOBALS['skyphusion_ai_embed_posts'] = step_posts(2609);
$GLOBALS['step_seen'] = array();
skyphusion_ai_embed_cursor_start(25);
$step_max = 0;
$step_count = 0;
$step_last = array('state' => 'step');
while ('step' === $step_last['state'] && $step_count < 1000) {
    $GLOBALS['step_this'] = array();
    $step_last = skyphusion_ai_embed_step(0, 'step_counter');
    $step_count++;
    $step_max = max($step_max, count($GLOBALS['step_this']));
    if ($step_last['processed'] > 25) {
        break;
    }
}
expect('no step touches more than the batch size', $step_max, 25);
expect('the pass takes ceil(2609 / 25) steps', $step_count, 105);
expect('the pass ends done', $step_last['state'], 'done');
expect('every post is stored exactly once', count($GLOBALS['step_seen']), 2609);
expect('no post is stored twice', count(array_unique($GLOBALS['step_seen'])), 2609);
$step_sorted = $GLOBALS['step_seen'];
sort($step_sorted);
expect('the posts go in id order', $GLOBALS['step_seen'], $step_sorted);
expect('a done pass is not active', skyphusion_ai_embed_cursor()['active'], 0);
expect('a done pass resets the cursor', skyphusion_ai_embed_cursor()['after'], 0);
expect('the cursor counted every post', skyphusion_ai_embed_cursor()['seen'], 2609);

// 2. Resume: a request that dies part-way loses only the post it was on.
$GLOBALS['skyphusion_ai_embed_posts'] = step_posts(60);
$GLOBALS['step_seen'] = array();
skyphusion_ai_embed_cursor_start(25);
$GLOBALS['step_this'] = array();
$step_first = skyphusion_ai_embed_step(0, 'step_counter');
expect('the first step stops after 25 posts', $step_first['processed'], 25);
expect('the first step says where it stopped', $step_first['after'], 75);
expect('the first step says what is left', $step_first['remaining'], 35);
$GLOBALS['step_die_at'] = 90;
$step_died = false;
try {
    skyphusion_ai_embed_step(0, 'step_counter');
} catch (RuntimeException $e) {
    $step_died = true;
}
expect('the stand-in really ended the request', $step_died, true);
expect('the cursor kept the last post done before the end', skyphusion_ai_embed_cursor()['after'], 87);
$GLOBALS['step_this'] = array();
$step_resumed = skyphusion_ai_embed_step(0, 'step_counter');
expect('the next step starts on the post that was in flight', $GLOBALS['step_this'][0], 90);
while ('step' === $step_resumed['state']) {
    $step_resumed = skyphusion_ai_embed_step(0, 'step_counter');
}
expect('after the resume every post is stored once', count($GLOBALS['step_seen']), 60);
expect('after the resume no post is stored twice', count(array_unique($GLOBALS['step_seen'])), 60);

// 3. The daily limit stops the step on that post. The next step starts there.
$GLOBALS['skyphusion_ai_embed_posts'] = step_posts(10);
$GLOBALS['step_seen'] = array();
$GLOBALS['step_cron'] = array();
skyphusion_ai_embed_cursor_start(25);
$GLOBALS['step_limit_at'] = 12;
$step_limited = skyphusion_ai_embed_step(0, 'step_counter');
expect('the limit stops the step', $step_limited['state'], 'limit');
expect('the limit keeps the cursor before that post', $step_limited['after'], 9);
expect('the limit stays active', skyphusion_ai_embed_cursor()['active'], 1);
skyphusion_ai_embed_schedule($step_limited['state']);
expect('a limited pass waits about an hour', ($GLOBALS['step_cron']['skyphusion_ai_embed_step_event'] ?? 0) - time() >= 3500, true);
$GLOBALS['step_this'] = array();
$step_after_limit = skyphusion_ai_embed_step(0, 'step_counter');
expect('after the limit the step starts on that post', $GLOBALS['step_this'][0], 12);
expect('after the limit the pass finishes', $step_after_limit['state'], 'done');

// 4. A blocked index stores nothing and keeps the cursor.
update_option('skyphusion_ai_vectorize', array(
    'index' => 'skyphusion-ai',
    'post_types' => array('post', 'product'),
    'fields' => array('title', 'content', 'excerpt'),
    'model' => '@cf/example/other-embed',
    'created' => 1,
), false);
skyphusion_ai_embed_cursor_start(25);
$GLOBALS['step_this'] = array();
$step_blocked = skyphusion_ai_embed_step(0, 'step_counter');
expect('a model change blocks the step', $step_blocked['state'], 'blocked');
expect('a blocked step stores nothing', $GLOBALS['step_this'], array());
expect('a blocked step keeps the cursor', skyphusion_ai_embed_cursor()['after'], 0);
embed_ready();

// 5. The real store: each post in the step gets its hash, and posts after the cursor do not.
$GLOBALS['skyphusion_ai_embed_posts'] = step_posts(60);
foreach ($GLOBALS['skyphusion_ai_embed_posts'] as $step_post) {
    unset($GLOBALS['cfais_embed_meta'][(int) $step_post['ID']]);
}
unset($GLOBALS['cfais_bodies']);
$GLOBALS['cfais_body'] = '{"success":true,"result":{"data":[[0.25,0.5]],"mutationId":"step1"}}';
skyphusion_ai_embed_cursor_start(25);
$step_real = skyphusion_ai_embed_step();
$step_hashed = array();
foreach ($GLOBALS['skyphusion_ai_embed_posts'] as $step_post) {
    $step_id = (int) $step_post['ID'];
    if (isset($GLOBALS['cfais_embed_meta'][$step_id]['skyphusion_ai_embed_hash'])) {
        $step_hashed[] = $step_id;
    }
}
sort($step_hashed);
expect('the real step stores 25 posts', $step_real['embedded'], 25);
expect('the real step writes 25 hashes', count($step_hashed), 25);
expect('the hashes are the first 25 ids', $step_hashed, range(3, 75, 3));

// NEGATIVE CONTROL: the old one-request shape (every post through the
// real store in one call, as the removed skyphusion_ai_embed_run() did)
// stores all 60 at once.
foreach ($GLOBALS['skyphusion_ai_embed_posts'] as $step_post) {
    unset($GLOBALS['cfais_embed_meta'][(int) $step_post['ID']]);
}
$step_old = 0;
foreach ($GLOBALS['skyphusion_ai_embed_posts'] as $step_post) {
    $step_one = skyphusion_ai_embed_sync_post($step_post);
    if ('embedded' === $step_one['state']) {
        $step_old++;
    }
}
expect('the old one-request shape touches every post at once', $step_old, 60);
expect('so a one-request shape fails the batch-size rule', $step_old > 25, true);

// 6. WP-CLI runs the same step until done, one line per step.
foreach ($GLOBALS['skyphusion_ai_embed_posts'] as $step_post) {
    unset($GLOBALS['cfais_embed_meta'][(int) $step_post['ID']]);
}
$GLOBALS['skyphusion_ai_embed_posts'] = array_slice($GLOBALS['skyphusion_ai_embed_posts'], 0, 35);
update_option('skyphusion_ai_embed_cursor', array(), false);
$GLOBALS['cfais_cli_log'] = array();
skyphusion_ai_embed_cli(array('run'), array('batch' => '10'));
expect('cli prints one line per step', substr_count(embed_cli_lines(), 'embed_step='), 4);
expect('cli steps hold at most ten posts', strpos(embed_cli_lines(), 'processed=11') === false, true);
expect('cli says the pass ran', strpos(embed_cli_lines(), 'embed_state=ran') !== false, true);

// 7. The REST route and the admin button call the same step.
$GLOBALS['step_routes'] = array();
skyphusion_ai_embed_register_rest();
$step_route = $GLOBALS['step_routes']['skyphusion-ai/v1/embed/step'] ?? array();
expect('the step route is POST', $step_route['methods'] ?? '', 'POST');
expect('the step route is admin only', $step_route['permission_callback'] ?? '', 'skyphusion_ai_embed_admin_allowed');
expect('the step route calls the step', $step_route['callback'] ?? '', 'skyphusion_ai_embed_rest_step');
$GLOBALS['cfais_can'] = array();
expect('a visitor cannot run a step', skyphusion_ai_embed_admin_allowed(), false);

class Step_Request
{
    public function get_param($name)
    {
        return 'batch' === $name ? '5' : null;
    }
}
update_option('skyphusion_ai_embed_cursor', array(), false);
$GLOBALS['step_cron'] = array();
$step_rest = skyphusion_ai_embed_rest_step(new Step_Request());
expect('a REST step obeys its batch size', $step_rest['processed'], 5);
expect('a REST step says it is not done', $step_rest['state'], 'step');
expect('a REST step queues the next step', isset($GLOBALS['step_cron']['skyphusion_ai_embed_step_event']), true);
$step_src = (string) file_get_contents(dirname(__DIR__) . '/includes/embed.php');
expect('the admin button runs one step', strpos($step_src, "check_admin_referer( 'skyphusion_ai_embed_run' );\n\tskyphusion_ai_embed_step_now();") !== false, true);
expect('the admin button no longer runs every post', strpos($step_src, 'skyphusion_ai_embed_run( skyphusion_ai_embed_collect(), true )'), false);

// Leave the shared state as it was for the files that follow.
unset($GLOBALS['cfais_settings']);
if (false === $step_prev_key || '' === $step_prev_key) {
    putenv('CLOUDFLARE_API_KEY');
} else {
    putenv('CLOUDFLARE_API_KEY=' . $step_prev_key);
}
if (false === $step_prev_account || '' === $step_prev_account) {
    putenv('CLOUDFLARE_ACCOUNT_ID');
} else {
    putenv('CLOUDFLARE_ACCOUNT_ID=' . $step_prev_account);
}
update_option('skyphusion_ai_embed_cursor', array(), false);
if (null === $step_prev_posts) {
    unset($GLOBALS['skyphusion_ai_embed_posts']);
} else {
    $GLOBALS['skyphusion_ai_embed_posts'] = $step_prev_posts;
}
if (null === $step_prev_body) {
    unset($GLOBALS['cfais_body']);
} else {
    $GLOBALS['cfais_body'] = $step_prev_body;
}
