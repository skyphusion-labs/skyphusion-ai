<?php
/**
 * Storefront markup.
 */

if (!defined('ABSPATH')) {
    exit;
}

function skyphusion_ai_ready()
{
    if (is_admin()) {
        return false;
    }
    $settings = skyphusion_ai_settings();
    if ($settings['endpoint'] === '') {
        return false;
    }
    return $settings['search_bar'] || $settings['modal'] || $settings['chat'];
}

function skyphusion_ai_enqueue()
{
    $settings = skyphusion_ai_settings();
    $lead = !is_admin() && $settings['lead_search'] && $settings['endpoint'] !== '';
    if (!skyphusion_ai_ready() && !$lead) {
        return;
    }

    wp_enqueue_style(
        'cloudflare-ai-search',
        plugins_url('assets/search.css', SKYPHUSION_AI_FILE),
        array(),
        SKYPHUSION_AI_VERSION
    );
    if (!skyphusion_ai_ready()) {
        return;
    }
    // THE ONE REAL OFFLOAD, and the Plugin Check sniff cannot see it (#16).
    // This enqueues EXECUTABLE JavaScript from a third-party host. The host
    // arrives from the database and the only literals in the source are a
    // path fragment and a constant, so a sniff looking for a remote URL
    // literal matches nothing here. A clean report on that sniff therefore
    // says the sniff is blind, not that the condition is absent.
    //
    // NO `phpcs:ignore` BELONGS HERE. The eight annotations elsewhere in this
    // plugin are on dashboard and docs hyperlinks, which is the harmless
    // case. Annotating this one would claim a sniff had examined it.
    //
    // The position is published in `readme.txt` under `== External services ==`
    // and `tests/external-services-test.php` keeps that paragraph true,
    // including that it names the build this constant pins.
    $src = skyphusion_ai_snippet_src($settings['endpoint']);
    if ($src === '') {
        return;
    }
    wp_enqueue_script(
        'cloudflare-ai-search',
        $src,
        array(),
        SKYPHUSION_AI_SNIPPET,
        true
    );
}
add_action('wp_enqueue_scripts', 'skyphusion_ai_enqueue');

function skyphusion_ai_script_tag($tag, $handle, $src)
{
    if ($handle !== 'cloudflare-ai-search') {
        return $tag;
    }
    // The handle is already registered with wp_enqueue_script. This only sets type=module.
    // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
    return '<script type="module" src="' . esc_url($src) . '"></script>' . "\n";
}
add_filter('script_loader_tag', 'skyphusion_ai_script_tag', 10, 3);

function skyphusion_ai_quiet_page()
{
    $settings = skyphusion_ai_settings();
    if (!$settings['hide_checkout']) {
        return false;
    }
    if (function_exists('is_cart') && is_cart()) {
        return true;
    }
    if (function_exists('is_checkout') && is_checkout()) {
        return true;
    }
    return false;
}

function skyphusion_ai_component($tag, $settings)
{
    $allowed = array('search-bar-snippet', 'search-modal-snippet', 'chat-bubble-snippet');
    if (!in_array($tag, $allowed, true)) {
        return '';
    }

    $style = '--search-snippet-primary-color:' . $settings['color'];
    return sprintf(
        '<%1$s api-url="%2$s" placeholder="%3$s" max-results="%4$d" theme="%5$s" hide-branding="%6$s" style="%7$s"></%1$s>',
        $tag,
        esc_url($settings['endpoint']),
        esc_attr($settings['placeholder']),
        (int) $settings['max_results'],
        esc_attr($settings['theme']),
        $settings['hide_branding'] ? 'true' : 'false',
        esc_attr($style)
    );
}

function skyphusion_ai_snippet_allowed_html()
{
    $attributes = array(
        'api-url' => true,
        'placeholder' => true,
        'max-results' => true,
        'theme' => true,
        'hide-branding' => true,
        'style' => true,
    );
    return array(
        'search-bar-snippet' => $attributes,
        'search-modal-snippet' => $attributes,
        'chat-bubble-snippet' => $attributes,
    );
}

function skyphusion_ai_render_bar()
{
    if (!skyphusion_ai_ready() || skyphusion_ai_quiet_page()) {
        return;
    }
    $settings = skyphusion_ai_settings();
    if (!$settings['search_bar'] || $settings['placement'] !== 'top') {
        return;
    }
    echo '<div class="cloudflare-ai-search-bar">';
    echo wp_kses(
        skyphusion_ai_component('search-bar-snippet', $settings),
        skyphusion_ai_snippet_allowed_html()
    );
    echo '</div>';
}
add_action('wp_body_open', 'skyphusion_ai_render_bar', 5);

function skyphusion_ai_render_footer()
{
    if (!skyphusion_ai_ready()) {
        return;
    }
    $settings = skyphusion_ai_settings();
    if ($settings['modal']) {
        echo wp_kses(
            skyphusion_ai_component('search-modal-snippet', $settings),
            skyphusion_ai_snippet_allowed_html()
        );
    }
    if ($settings['chat'] && !skyphusion_ai_quiet_page()) {
        echo wp_kses(
            skyphusion_ai_component('chat-bubble-snippet', $settings),
            skyphusion_ai_snippet_allowed_html()
        );
    }
}
add_action('wp_footer', 'skyphusion_ai_render_footer', 30);

function skyphusion_ai_shortcode()
{
    if (is_admin() && !wp_doing_ajax()) {
        return '';
    }
    $settings = skyphusion_ai_settings();
    if ($settings['endpoint'] === '' || !$settings['search_bar']) {
        return '';
    }
    return '<div class="cloudflare-ai-search-bar">' . wp_kses(
        skyphusion_ai_component('search-bar-snippet', $settings),
        skyphusion_ai_snippet_allowed_html()
    ) . '</div>';
}
add_shortcode('cloudflare_ai_search', 'skyphusion_ai_shortcode');

/**
 * Print one AI Search answer above the main search loop.
 *
 * A classic theme fires loop_start, and WooCommerce product search also fires
 * woocommerce_before_shop_loop. The flag stops the second hook from printing again.
 * A block theme builds that loop before it prints the doctype, so those hooks
 * return and the block filter inserts the answer inside the results markup.
 * A transport error, an empty answer, or a missing address stays off the page.
 * A missing address is still counted. An empty question is not.
 *
 * @param mixed $query
 * @param bool  $from_block
 */
function skyphusion_ai_render_lead($query = null, $from_block = false)
{
    static $done = false;
    if ($done || is_admin()) {
        return;
    }
    if (function_exists('wp_is_block_theme') && wp_is_block_theme() && !$from_block) {
        return;
    }
    if ($query instanceof WP_Query && !$query->is_main_query()) {
        return;
    }
    if (!is_search() || is_feed() || is_embed() || skyphusion_ai_quiet_page()) {
        return;
    }
    $settings = skyphusion_ai_settings();
    if (!$settings['lead_search']) {
        return;
    }
    $question = trim((string) get_search_query(false));
    if ($question === '') {
        return;
    }

    $done = true;
    // THROUGH THE GUARDED ENTRY POINT, never straight at the provider (#14).
    // This is the only unauthenticated path to a billable generation, so the
    // length cap, the cache and the call ceiling live between it and the
    // network. Null means print nothing, which every refusal and every error
    // already did.
    $result = skyphusion_ai_lead_answer($question);
    if ($result === null) {
        return;
    }
    $answer = trim((string) $result['answer']);
    if ($answer === '') {
        return;
    }

    echo '<section class="cloudflare-ai-search-lead">';
    echo '<p>' . esc_html($answer) . '</p>';
	if ( ! function_exists( 'skyphusion_ai_lead_sources_html' ) ) {
		require_once __DIR__ . '/sources.php';
	}
	skyphusion_ai_lead_sources_html( $result );
    echo '</section>';
}
add_action('woocommerce_before_shop_loop', 'skyphusion_ai_render_lead', 5);
add_action('loop_start', 'skyphusion_ai_render_lead', 5);

/**
 * Insert the search answer inside the results block on a block theme.
 *
 * @param string $html
 * @param array  $block
 * @return string
 */
function skyphusion_ai_lead_block($html, $block)
{
    if (!function_exists('wp_is_block_theme') || !wp_is_block_theme()) {
        return $html;
    }
    $name = isset($block['blockName']) ? (string) $block['blockName'] : '';
    if ($name !== 'woocommerce/product-collection' && $name !== 'core/post-template') {
        return $html;
    }
    if (!is_search() || is_feed() || is_embed() || is_admin()) {
        return $html;
    }
    static $placed = false;
    if ($placed) {
        return $html;
    }
    ob_start();
    skyphusion_ai_render_lead(null, true);
    $lead = ob_get_clean();
    if (!is_string($lead) || $lead === '') {
        return $html;
    }
    $placed = true;
    return $lead . $html;
}
add_filter('render_block', 'skyphusion_ai_lead_block', 10, 2);

/**
 * Print stock text once, inside main, on the single product page.
 *
 * The blockified single product template places woocommerce/add-to-cart-form
 * inside main (WooCommerce templates/templates/blockified/single-product.html).
 * woocommerce/add-to-cart-with-options is that same form slot on templates
 * that use the newer block. The legacy template renders inside main and
 * fires woocommerce_single_product_summary. The static flag in
 * skyphusion_ai_stock_markup() stops a second print.
 *
 * @param string $html
 * @param array  $block
 * @return string
 */
function skyphusion_ai_stock_block($html, $block)
{
    $name = isset($block['blockName']) ? (string) $block['blockName'] : '';
    if ($name !== 'woocommerce/add-to-cart-form' && $name !== 'woocommerce/add-to-cart-with-options') {
        return $html;
    }
    $stock = skyphusion_ai_stock_markup();
    if ($stock === '') {
        return $html;
    }
    return $html . wp_kses($stock, skyphusion_ai_stock_allowed_html());
}
add_filter('render_block', 'skyphusion_ai_stock_block', 10, 2);

/**
 * Classic themes do not render the add to cart block. The summary hook
 * still runs inside main.
 */
function skyphusion_ai_print_stock()
{
    $html = skyphusion_ai_stock_markup();
    if ($html === '') {
        return;
    }
    echo wp_kses($html, skyphusion_ai_stock_allowed_html());
}
add_action('woocommerce_single_product_summary', 'skyphusion_ai_print_stock', 35);
