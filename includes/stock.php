<?php
/**
 * Server-rendered variation and stock text.
 *
 * Two halves, deliberately separated so one of them is testable.
 *
 * The BUILDER (escape, sentence, labels, quantity_text, line, html) turns
 * plain arrays into markup. It calls no WooCommerce at all, and the only
 * WordPress it calls is the optional i18n in the label helpers, which falls
 * back to English when WordPress is absent. That is what lets
 * tests/stock-test.php exercise it without a WordPress bootstrap.
 *
 * The READER (plain, attribute_text, price_text, quantity, row_from_product,
 * max_variations, variation_rows, rows, markup) talks to WooCommerce and runs
 * only when a hook in includes/frontend.php calls it. No WooCommerce object
 * crosses into the builder; it receives plain arrays.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Escape one interpolated value. Tests define esc_html when WordPress is absent.
 *
 * @param mixed $value
 * @return string
 */
function skyphusion_ai_stock_escape($value)
{
    $text = (string) $value;
    if (function_exists('esc_html')) {
        return esc_html($text);
    }
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * End a fragment with a period when it does not already end a sentence.
 *
 * @param string $text
 * @return string
 */
function skyphusion_ai_stock_sentence($text)
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    $last = substr($text, -1);
    if ($last === '.' || $last === '!' || $last === '?') {
        return $text;
    }
    return $text . '.';
}

/**
 * The visible label set, translated when WordPress is loaded.
 *
 * Every __() argument here is a literal, because the WordPress string
 * extractor reads the source and cannot see a variable passed to __().
 * Shoppers read these strings, so they are translated; the section class
 * name is what a crawler and a stylesheet match on, so that one is not.
 *
 * @return array
 */
function skyphusion_ai_stock_labels()
{
    if (!function_exists('__')) {
        return array(
            'heading' => 'Stock',
            'sku' => 'SKU %s.',
            'instock' => 'In stock.',
            'outofstock' => 'Out of stock.',
            'onbackorder' => 'On backorder.',
        );
    }

    return array(
        'heading' => __('Stock', 'skyphusion-ai'),
        /* translators: %s is a product or variation SKU. */
        'sku' => __('SKU %s.', 'skyphusion-ai'),
        'instock' => __('In stock.', 'skyphusion-ai'),
        'outofstock' => __('Out of stock.', 'skyphusion-ai'),
        'onbackorder' => __('On backorder.', 'skyphusion-ai'),
    );
}

/**
 * Translated unit count. Kept apart from the label set because it needs the
 * plural form, and _n() has to see the number to pick one.
 *
 * @param int $quantity
 * @return string
 */
function skyphusion_ai_stock_quantity_text($quantity)
{
    $count = (int) $quantity;
    if (function_exists('_n')) {
        /* translators: %d is the number of units in stock. */
        $format = _n('%d available.', '%d available.', $count, 'skyphusion-ai');
    } else {
        $format = '%d available.';
    }
    return sprintf(skyphusion_ai_stock_escape($format), $count);
}

/**
 * Plain text for one stock row. Empty when the row has nothing to say.
 *
 * A label can arrive from a translation file, so labels are escaped here too
 * and not trusted for being ours. %s and %d survive escaping unchanged.
 *
 * @param mixed      $row
 * @param array|null $labels Pass the set once when looping, or let it load.
 * @return string Escaped text, with no markup of its own.
 */
function skyphusion_ai_stock_line($row, $labels = null)
{
    if (!is_array($row)) {
        return '';
    }
    if (!is_array($labels)) {
        $labels = skyphusion_ai_stock_labels();
    }

    $parts = array();

    $attributes = isset($row['attributes']) ? trim((string) $row['attributes']) : '';
    if ($attributes !== '') {
        $parts[] = skyphusion_ai_stock_escape(skyphusion_ai_stock_sentence($attributes));
    }

    $sku = isset($row['sku']) ? trim((string) $row['sku']) : '';
    if ($sku !== '') {
        $parts[] = sprintf(
            skyphusion_ai_stock_escape($labels['sku']),
            skyphusion_ai_stock_escape($sku)
        );
    }

    $price = isset($row['price']) ? trim((string) $row['price']) : '';
    if ($price !== '') {
        $parts[] = skyphusion_ai_stock_escape(skyphusion_ai_stock_sentence($price));
    }

    $status = isset($row['stock_status']) ? (string) $row['stock_status'] : '';
    if ($status === 'instock' || $status === 'outofstock' || $status === 'onbackorder') {
        $parts[] = skyphusion_ai_stock_escape($labels[$status]);
    }

    /*
     * A managed product can hold a negative quantity once backorders have
     * been taken against it. "-3 available." is not a fact worth indexing,
     * so the count is dropped and the status line carries the meaning.
     */
    $managing = isset($row['managing_stock']) && $row['managing_stock'] === true;
    $quantity = array_key_exists('quantity', $row) ? $row['quantity'] : null;
    if ($managing && is_int($quantity) && $quantity >= 0) {
        $parts[] = skyphusion_ai_stock_quantity_text($quantity);
    }

    return implode(' ', $parts);
}

/**
 * Visible stock section. An empty list returns an empty string, not an empty section.
 *
 * @param mixed $rows
 * @return string
 */
function skyphusion_ai_stock_html($rows)
{
    if (!is_array($rows) || $rows === array()) {
        return '';
    }

    $labels = skyphusion_ai_stock_labels();

    $items = '';
    foreach ($rows as $row) {
        $line = skyphusion_ai_stock_line($row, $labels);
        if ($line === '') {
            continue;
        }
        $items .= '<li>' . $line . '</li>';
    }
    if ($items === '') {
        return '';
    }

    return '<section class="cloudflare-ai-search-stock"><h2>'
        . skyphusion_ai_stock_escape($labels['heading'])
        . '</h2><ul>' . $items . '</ul></section>';
}

/**
 * Strip tags from a variation attribute summary.
 *
 * @param mixed $value
 * @return string
 */
function skyphusion_ai_stock_plain($value)
{
    $text = wp_strip_all_tags((string) $value);
    $text = preg_replace('/\s+/u', ' ', $text);
    if (!is_string($text)) {
        return '';
    }
    return trim($text);
}

/**
 * Human readable attributes from the variation summary. Empty for other product types.
 *
 * @param object $product
 * @return string
 */
function skyphusion_ai_stock_attribute_text($product)
{
    if (!is_object($product)) {
        return '';
    }
    $summary = '';
    if (method_exists($product, 'get_attribute_summary')) {
        $summary = $product->get_attribute_summary();
    } elseif (function_exists('wc_get_formatted_variation')) {
        $summary = wc_get_formatted_variation($product, true, true, false);
    }
    return skyphusion_ai_stock_plain($summary);
}

/**
 * Numeric price plus the store currency code. Not get_price_html().
 *
 * get_price_html() carries markup and renders a range for a variable parent;
 * the index wants one plain amount per row.
 *
 * @param object $product
 * @return string
 */
function skyphusion_ai_stock_price_text($product)
{
    if (!is_object($product) || !method_exists($product, 'get_price')) {
        return '';
    }
    $amount = $product->get_price();
    if ($amount === '' || $amount === null) {
        return '';
    }
    $amount = trim((string) $amount);
    if ($amount === '') {
        return '';
    }
    $currency = '';
    if (function_exists('get_woocommerce_currency')) {
        $currency = trim((string) get_woocommerce_currency());
    }
    if ($currency === '') {
        return $amount;
    }
    return $amount . ' ' . $currency;
}

/**
 * Whole-number stock quantity, or null when the product does not manage stock.
 *
 * Read faithfully, including a negative, so the reader stays a reader. The
 * builder decides what is worth printing.
 *
 * @param object $product
 * @param bool   $managing
 * @return int|null
 */
function skyphusion_ai_stock_quantity($product, $managing)
{
    if (!$managing || !is_object($product) || !method_exists($product, 'get_stock_quantity')) {
        return null;
    }
    $raw = $product->get_stock_quantity();
    if (is_int($raw)) {
        return $raw;
    }
    if (is_numeric($raw) && (string) (int) $raw === trim((string) $raw)) {
        return (int) $raw;
    }
    return null;
}

/**
 * One renderer row from a product or variation. No WooCommerce object leaves this function.
 *
 * @param object $product
 * @param bool   $include_attributes
 * @return array
 */
function skyphusion_ai_stock_row_from_product($product, $include_attributes)
{
    /*
     * A variation reports the string 'parent' when it defers stock to its
     * parent, and its get_stock_quantity() then returns the parent's pooled
     * figure. Coercing that string to true made every variation row claim the
     * whole pool as its own. A pooled count is not this row's count, so the
     * row keeps its status and drops the number.
     */
    $managing = false;
    if (is_object($product) && method_exists($product, 'managing_stock')) {
        $mode = $product->managing_stock();
        $managing = $mode !== 'parent' && (bool) $mode;
    }
    $status = '';
    if (is_object($product) && method_exists($product, 'get_stock_status')) {
        $status = (string) $product->get_stock_status();
    }
    $sku = '';
    if (is_object($product) && method_exists($product, 'get_sku')) {
        $sku = trim((string) $product->get_sku());
    }

    return array(
        'attributes' => $include_attributes ? skyphusion_ai_stock_attribute_text($product) : '',
        'sku' => $sku,
        'price' => skyphusion_ai_stock_price_text($product),
        'stock_status' => $status,
        'managing_stock' => $managing,
        'quantity' => skyphusion_ai_stock_quantity($product, $managing),
    );
}

/**
 * Most variations to read for one product page.
 *
 * Each row costs one product load. WooCommerce stops loading variations into
 * the add to cart form once a product has more than its own AJAX threshold,
 * so above that an uncapped loop here is new work on every anonymous request
 * rather than a second pass over objects already in the cache. The cap bounds
 * that; a store that needs every row indexed raises it with the filter.
 *
 * It bounds the children of a grouped product as well. The cost is the same
 * one product load per row, so one knob covers both rather than two filters
 * for the same thing.
 *
 * @return int
 */
function skyphusion_ai_stock_max_variations()
{
    $default = 100;
    $max = $default;
    if (function_exists('apply_filters')) {
        $max = apply_filters('cloudflare_ai_search_stock_max_variations', $default);
    }
    if (!is_numeric($max)) {
        return $default;
    }
    $max = (int) $max;
    if ($max < 1) {
        return $default;
    }
    return $max;
}

/**
 * Read variation rows without get_available_variations().
 *
 * That method drops hidden and out-of-stock variations when the catalog
 * hides them. The index has to see those rows. get_children() returns
 * every child variation, including out of stock.
 *
 * @param object $product
 * @return array
 */
function skyphusion_ai_stock_variation_rows($product)
{
    if (!is_object($product) || !method_exists($product, 'get_children') || !function_exists('wc_get_product')) {
        return array();
    }
    $children = $product->get_children();
    if (!is_array($children)) {
        return array();
    }

    $max = skyphusion_ai_stock_max_variations();
    $rows = array();
    foreach ($children as $child_id) {
        if (count($rows) >= $max) {
            break;
        }
        $child = wc_get_product($child_id);
        if (!is_object($child) || !method_exists($child, 'is_type') || !$child->is_type('variation')) {
            continue;
        }
        $rows[] = skyphusion_ai_stock_row_from_product($child, true);
    }
    return $rows;
}

/**
 * Rows for the children of a grouped product.
 *
 * A grouped product sells other products rather than variations of itself,
 * so get_children() returns whole products here. They carry no attribute
 * summary, and their name is what a shopper and an index identify them by,
 * so the name takes the leading field that a variation fills with attributes.
 *
 * @param object $product
 * @return array
 */
function skyphusion_ai_stock_grouped_rows($product)
{
    if (!is_object($product) || !method_exists($product, 'get_children') || !function_exists('wc_get_product')) {
        return array();
    }
    $children = $product->get_children();
    if (!is_array($children)) {
        return array();
    }

    $max = skyphusion_ai_stock_max_variations();
    $rows = array();
    foreach ($children as $child_id) {
        if (count($rows) >= $max) {
            break;
        }
        $child = wc_get_product($child_id);
        if (!is_object($child)) {
            continue;
        }
        $row = skyphusion_ai_stock_row_from_product($child, false);
        if (method_exists($child, 'get_name')) {
            $row['attributes'] = skyphusion_ai_stock_plain($child->get_name());
        }
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Rows for the current single product. Empty off the product page, and when WooCommerce is absent.
 *
 * A variable product yields one row per variation and a grouped product one
 * row per child. Every other type yields a row for itself, including external
 * and any type a third party registers. That default is deliberate: an
 * allowlist of types is what made a grouped product render nothing at all,
 * and a product that reports a stock status has something worth indexing
 * whatever its type is called.
 *
 * @return array
 */
function skyphusion_ai_stock_rows()
{
    if (function_exists('is_admin') && is_admin()) {
        return array();
    }
    if (function_exists('is_feed') && is_feed()) {
        return array();
    }
    if (function_exists('is_embed') && is_embed()) {
        return array();
    }
    if (!function_exists('is_product') || !is_product()) {
        return array();
    }
    if (!function_exists('wc_get_product') || !function_exists('get_the_ID')) {
        return array();
    }

    $product = wc_get_product(get_the_ID());
    if (!is_object($product) || !method_exists($product, 'is_type')) {
        return array();
    }
    if ($product->is_type('variable')) {
        return skyphusion_ai_stock_variation_rows($product);
    }
    if ($product->is_type('grouped')) {
        return skyphusion_ai_stock_grouped_rows($product);
    }
    return array(skyphusion_ai_stock_row_from_product($product, false));
}

/**
 * Markup for this request, printed at most once.
 *
 * Two hooks call this, because a block theme and a classic theme reach the
 * inside of main by different routes and a site can answer to both. The flag
 * is set only once there is something to print, so an early call that found
 * nothing does not consume the one print.
 *
 * @return string
 */
function skyphusion_ai_stock_markup()
{
    static $done = false;
    if ($done) {
        return '';
    }
    $html = skyphusion_ai_stock_html(skyphusion_ai_stock_rows());
    if ($html === '') {
        return '';
    }
    $done = true;
    return $html;
}

/**
 * Tags the stock section is allowed to print.
 *
 * @return array
 */
function skyphusion_ai_stock_allowed_html()
{
    return array(
        'section' => array(
            'class' => true,
        ),
        'h2' => array(),
        'ul' => array(),
        'li' => array(),
    );
}
