<?php
/**
 * Source links under the search answer.
 *
 * Loaded by tests/endpoint-test.php after gateway-test.php. expect() and
 * the WordPress stand-ins already exist. esc_html and esc_url are defined
 * here only when an earlier file did not define them.
 */

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        $url = (string) $url;
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ('http' !== $scheme && 'https' !== $scheme) {
            return '';
        }
        if (isset($parts['user']) || isset($parts['pass']) || '' === (string) $parts['host']) {
            return '';
        }
        return $url;
    }
}

function sources_capture($result)
{
    ob_start();
    skyphusion_ai_lead_sources_html($result);
    return (string) ob_get_clean();
}

$cited = skyphusion_ai_read_answer(array(
    'choices' => array(
        array('message' => array('content' => 'Ships in two days.')),
    ),
    'chunks' => array(
        array(
            'text' => 'Shipping page',
            'item' => array('key' => 'https://shop.example/shipping'),
        ),
    ),
    'token' => 'do-not-print-this-token',
));
expect('cited source text stays', $cited['sources'][0], 'Shipping page');
expect('cited source url kept', $cited['source_urls'][0], 'https://shop.example/shipping');

$filename = skyphusion_ai_read_answer(array(
    'chunks' => array(
        array(
            'text' => 'Shipping page',
            'item' => array('key' => 'about-cloudflare.md'),
        ),
    ),
));
expect('filename source text unchanged', $filename['sources'][0], 'Shipping page');
expect('filename is not a url', $filename['source_urls'][0], '');

$script = skyphusion_ai_read_answer(array(
    'chunks' => array(
        array(
            'text' => 'Shipping page',
            'item' => array('key' => 'javascript:alert(1)'),
        ),
    ),
));
expect('javascript scheme dropped', $script['source_urls'][0], '');

$data_url = skyphusion_ai_read_answer(array(
    'chunks' => array(
        array(
            'text' => 'Shipping page',
            'item' => array('key' => 'data:text/html,hello'),
        ),
    ),
));
expect('data scheme dropped', $data_url['source_urls'][0], '');

$userinfo = skyphusion_ai_read_answer(array(
    'chunks' => array(
        array(
            'text' => 'Shipping page',
            'item' => array('key' => 'https://user:secret@shop.example/shipping'),
        ),
    ),
));
expect('userinfo url dropped', $userinfo['source_urls'][0], '');

$user_only = skyphusion_ai_read_answer(array(
    'chunks' => array(
        array(
            'text' => 'Shipping page',
            'item' => array('key' => 'https://user@shop.example/shipping'),
        ),
    ),
));
expect('userinfo without a password dropped', $user_only['source_urls'][0], '');

$http = skyphusion_ai_read_answer(array(
    'chunks' => array(
        array(
            'text' => 'Shipping page',
            'item' => array('key' => 'http://shop.example/shipping'),
        ),
    ),
));
expect('http url kept', $http['source_urls'][0], 'http://shop.example/shipping');

$from_url_field = skyphusion_ai_read_answer(array(
    'chunks' => array(
        array(
            'text' => 'Shipping page',
            'item' => array('key' => '  '),
            'url' => 'https://shop.example/from-url',
        ),
    ),
));
expect('empty key falls through to url', $from_url_field['source_urls'][0], 'https://shop.example/from-url');

$top_key = skyphusion_ai_read_answer(array(
    'chunks' => array(
        array(
            'text' => 'Shipping page',
            'key' => 'https://shop.example/top',
        ),
    ),
));
expect('top level key kept', $top_key['source_urls'][0], 'https://shop.example/top');

$cited['token'] = 'do-not-print-this-token';
$cited['question'] = 'do-not-print-this-token';
$cited['error'] = 'do-not-print-this-token';
$lead = sources_capture($cited);
expect('source list links the page', strpos($lead, 'href="https://shop.example/shipping"') !== false, true);
expect('source list shows the source text', strpos($lead, 'Shipping page') !== false, true);
expect('source list marks nofollow', strpos($lead, 'rel="nofollow"') !== false, true);
expect('source list sets no target', strpos($lead, 'target=') === false, true);
expect('source list hides the token', strpos($lead, 'do-not-print-this-token') === false, true);

$sources_frontend = file_get_contents(dirname(__DIR__) . '/includes/frontend.php');
$sources_render_at = strpos((string) $sources_frontend, 'function skyphusion_ai_render_lead');
$sources_render = false === $sources_render_at ? '' : substr((string) $sources_frontend, $sources_render_at, 1800);
expect('the lead renderer prints the source list', strpos($sources_render, 'skyphusion_ai_lead_sources_html') !== false, true);
expect('the source list stays inside the lead section', strpos($sources_render, 'skyphusion_ai_lead_sources_html') < strpos($sources_render, '</section>'), true);

$url_only_row = skyphusion_ai_read_answer(array(
    'chunks' => array(
        array(
            'item' => array('key' => 'https://shop.example/key-only'),
        ),
    ),
));
expect('a url with no text is kept', $url_only_row['source_urls'][0], 'https://shop.example/key-only');
expect('a url with no text has an empty source string', $url_only_row['sources'][0], '');

$dup_url = skyphusion_ai_read_answer(array(
    'chunks' => array(
        array('item' => array('key' => 'https://shop.example/same')),
        array('item' => array('key' => 'https://shop.example/same')),
        array('item' => array('key' => 'https://shop.example/other')),
    ),
));
expect('a repeated url with no text is listed once', count($dup_url['source_urls']), 2);
expect('a repeated url keeps the first address', $dup_url['source_urls'][0], 'https://shop.example/same');
expect('a later different url is kept', $dup_url['source_urls'][1], 'https://shop.example/other');

$stored_script = sources_capture(array(
    'sources' => array('Shipping page'),
    'source_urls' => array('javascript:alert(1)'),
));
expect('a stored script address is not a link', strpos($stored_script, '<a') === false, true);
expect('a stored script address still shows the text', strpos($stored_script, 'Shipping page') !== false, true);

$plain = sources_capture(array(
    'sources' => array('Care page'),
    'source_urls' => array(''),
    'token' => 'do-not-print-this-token',
));
expect('text source is present', strpos($plain, 'Care page') !== false, true);
expect('no anchor when every url is empty', strpos($plain, '<a') === false, true);
expect('plain list hides the token', strpos($plain, 'do-not-print-this-token') === false, true);

$old_cache = sources_capture(array(
    'answer' => 'Ships in two days.',
    'sources' => array('Shipping page', 'Another page'),
));
expect('missing urls still print text', strpos($old_cache, 'Shipping page') !== false && strpos($old_cache, 'Another page') !== false, true);
expect('missing urls print no anchor', strpos($old_cache, '<a') === false, true);

$short = sources_capture(array(
    'sources' => array('First page', 'Second page'),
    'source_urls' => array('https://shop.example/first'),
));
expect('short url list links the first page', strpos($short, 'href="https://shop.example/first"') !== false, true);
expect('short url list keeps the second page as text', strpos($short, 'Second page') !== false, true);

$url_only = sources_capture(array(
    'sources' => array(''),
    'source_urls' => array('https://shop.example/only'),
));
expect('empty text shows the url', strpos($url_only, 'https://shop.example/only') !== false, true);
expect('empty text is still a link', strpos($url_only, '<a') !== false, true);

$skipped = sources_capture(array(
    'sources' => array('', 'Kept page'),
    'source_urls' => array('', ''),
));
expect('blank row is skipped', strpos($skipped, 'Kept page') !== false, true);
expect('blank row leaves no anchor', strpos($skipped, '<a') === false, true);

expect('no sources produce an empty source string', sources_capture(array(
    'answer' => 'Ships in two days.',
    'sources' => array(),
    'source_urls' => array(),
    'token' => 'do-not-print-this-token',
)), '');
expect('null result prints no sources', sources_capture(null), '');
expect('rows that are both empty print nothing', sources_capture(array(
    'sources' => array(''),
    'source_urls' => array(''),
)), '');

$long = str_repeat('A', 241);
$capped = sources_capture(array(
    'sources' => array($long),
    'source_urls' => array(''),
));
expect('long source text is capped', strpos($capped, str_repeat('A', 237) . '...') !== false, true);
expect('long source text has no unicode ellipsis', strpos($capped, "\xE2\x80\xA6") === false, true);
expect('long source text is not left whole', strpos($capped, $long) === false, true);

$exact = sources_capture(array(
    'sources' => array(str_repeat('B', 240)),
    'source_urls' => array(''),
));
expect('text at the cap is not cut', strpos($exact, str_repeat('B', 240)) !== false, true);
expect('text at the cap has no dots added', strpos($exact, '...') === false, true);
