<?php
/**
 * Stock text checks. Loaded by tests/endpoint-test.php, which defines
 * ABSPATH and holds expect(). No WordPress, no WooCommerce, no network.
 *
 * This file does not define ABSPATH and does not weaken the direct-access
 * guard in includes/stock.php. It reads the constant the harness already set.
 *
 * ORDER MATTERS IN THIS FILE. Part one runs with no WordPress translation
 * functions defined, so the label set falls back to English and the expected
 * strings are literal. Part two defines __(), _n() and apply_filters() to
 * prove the translated and filtered seams are really reached. Those
 * definitions are global and permanent for the rest of the process, so
 * nothing from part one may be moved below them.
 */

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

/*
 * The grouped reader strips markup from a child name through
 * wp_strip_all_tags(). Production always has WordPress loaded there, so the
 * plugin calls it directly; the harness supplies it instead of the plugin
 * carrying a strip_tags() fallback that Plugin Check flags.
 */
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text, $remove_breaks = false)
    {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
        $text = strip_tags($text);
        if ($remove_breaks) {
            $text = preg_replace('/[\r\n\t ]+/', ' ', $text);
        }
        return trim($text);
    }
}

require_once dirname(__DIR__) . '/includes/stock.php';

/* ---------- part one: English fallback, no WordPress ---------- */

$simple = skyphusion_ai_stock_html(array(array(
    'attributes' => '',
    'sku' => 'SHIRT-1',
    'price' => '45.00 USD',
    'stock_status' => 'instock',
    'managing_stock' => true,
    'quantity' => 4,
)));
expect(
    'simple in stock',
    $simple,
    '<section class="cloudflare-ai-search-stock"><h2>Stock</h2><ul><li>SKU SHIRT-1. 45.00 USD. In stock. 4 available.</li></ul></section>'
);
expect('section class', strpos($simple, 'cloudflare-ai-search-stock') !== false, true);

expect(
    'out of stock without quantity',
    skyphusion_ai_stock_html(array(array(
        'attributes' => '',
        'sku' => '',
        'price' => '',
        'stock_status' => 'outofstock',
        'managing_stock' => false,
        'quantity' => 9,
    ))),
    '<section class="cloudflare-ai-search-stock"><h2>Stock</h2><ul><li>Out of stock.</li></ul></section>'
);

expect(
    'on backorder',
    skyphusion_ai_stock_html(array(array(
        'attributes' => '',
        'sku' => '',
        'price' => '12.00 USD',
        'stock_status' => 'onbackorder',
        'managing_stock' => true,
        'quantity' => 2,
    ))),
    '<section class="cloudflare-ai-search-stock"><h2>Stock</h2><ul><li>12.00 USD. On backorder. 2 available.</li></ul></section>'
);

/*
 * A managed product goes negative once backorders are taken against it.
 * The status has to survive and the count has to be dropped: "-3 available."
 * is not a fact worth putting in an index.
 */
expect(
    'negative quantity drops the count',
    skyphusion_ai_stock_html(array(array(
        'attributes' => '',
        'sku' => 'BACK-1',
        'price' => '',
        'stock_status' => 'onbackorder',
        'managing_stock' => true,
        'quantity' => -3,
    ))),
    '<section class="cloudflare-ai-search-stock"><h2>Stock</h2><ul><li>SKU BACK-1. On backorder.</li></ul></section>'
);
expect(
    'zero quantity still prints',
    skyphusion_ai_stock_html(array(array(
        'attributes' => '',
        'sku' => '',
        'price' => '',
        'stock_status' => 'outofstock',
        'managing_stock' => true,
        'quantity' => 0,
    ))),
    '<section class="cloudflare-ai-search-stock"><h2>Stock</h2><ul><li>Out of stock. 0 available.</li></ul></section>'
);

$variable = skyphusion_ai_stock_html(array(
    array(
        'attributes' => 'Color Blue, Size Large',
        'sku' => 'HOODIE-BL-L',
        'price' => '45.00 USD',
        'stock_status' => 'instock',
        'managing_stock' => true,
        'quantity' => 3,
    ),
    array(
        'attributes' => 'Color Blue, Size Small',
        'sku' => 'HOODIE-BL-S',
        'price' => '45.00 USD',
        'stock_status' => 'outofstock',
        'managing_stock' => true,
        'quantity' => 0,
    ),
));
expect(
    'variable rows',
    $variable,
    '<section class="cloudflare-ai-search-stock"><h2>Stock</h2><ul>'
        . '<li>Color Blue, Size Large. SKU HOODIE-BL-L. 45.00 USD. In stock. 3 available.</li>'
        . '<li>Color Blue, Size Small. SKU HOODIE-BL-S. 45.00 USD. Out of stock. 0 available.</li>'
        . '</ul></section>'
);
expect('out of stock variation present', strpos($variable, 'HOODIE-BL-S') !== false && strpos($variable, 'Out of stock.') !== false, true);

expect('empty input', skyphusion_ai_stock_html(array()), '');
expect('blank row', skyphusion_ai_stock_html(array(array(
    'attributes' => '',
    'sku' => '   ',
    'price' => '',
    'stock_status' => 'mystery',
    'managing_stock' => true,
    'quantity' => '4',
))), '');

expect(
    'unknown status omitted',
    skyphusion_ai_stock_html(array(array(
        'attributes' => '',
        'sku' => 'PLAIN',
        'price' => '',
        'stock_status' => 'mystery',
        'managing_stock' => false,
        'quantity' => null,
    ))),
    '<section class="cloudflare-ai-search-stock"><h2>Stock</h2><ul><li>SKU PLAIN.</li></ul></section>'
);

/*
 * skyphusion_ai_stock_line() takes the label set as an argument so the
 * html builder loads it once per section instead of once per row. Called
 * without one it has to load its own, so that path is exercised here.
 */
expect(
    'line loads its own labels when none are passed',
    skyphusion_ai_stock_line(array(
        'attributes' => 'Color Red',
        'sku' => 'LINE-1',
        'price' => '9.00 USD',
        'stock_status' => 'instock',
        'managing_stock' => true,
        'quantity' => 2,
    )),
    'Color Red. SKU LINE-1. 9.00 USD. In stock. 2 available.'
);
expect('line returns empty for a non array', skyphusion_ai_stock_line('nope'), '');

$nasty = skyphusion_ai_stock_html(array(array(
    'attributes' => 'Color <script>alert(1)</script>',
    'sku' => '<script>',
    'price' => '1 <b>USD</b>',
    'stock_status' => 'instock',
    'managing_stock' => false,
    'quantity' => null,
)));
expect('script open absent', strpos($nasty, '<script') === false, true);
expect('script close absent', strpos($nasty, '</script') === false, true);
expect('attribute script escaped', strpos($nasty, '&lt;script&gt;') !== false, true);
expect('sku script escaped', strpos($nasty, 'SKU &lt;script&gt;.') !== false, true);

/* The cap has to answer with its default before apply_filters exists. */
expect('variation cap default without wordpress', skyphusion_ai_stock_max_variations(), 100);

/* ---------- part two: translated and filtered seams ---------- */
/*
 * Everything below defines WordPress stand-ins. They are global and cannot
 * be undefined, so no English expectation may follow them.
 */

$GLOBALS['cfai_stock_test_strings'] = array(
    'Stock' => 'Lager',
    'SKU %s.' => 'Artikelnummer %s.',
    'In stock.' => 'Auf Lager.',
    'Out of stock.' => 'Nicht auf Lager.',
);
$GLOBALS['cfai_stock_test_plural'] = 'Verfuegbar: %d.';
$GLOBALS['cfai_stock_test_filters'] = array();

if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        $strings = $GLOBALS['cfai_stock_test_strings'];
        return isset($strings[$text]) ? $strings[$text] : $text;
    }
}
if (!function_exists('_n')) {
    function _n($single, $plural, $count, $domain = 'default')
    {
        if ($count === 1) {
            return $single;
        }
        return $GLOBALS['cfai_stock_test_plural'];
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value)
    {
        $filters = $GLOBALS['cfai_stock_test_filters'];
        return array_key_exists($hook, $filters) ? $filters[$hook] : $value;
    }
}

/*
 * The point of this one is that __() is actually on the path. If the labels
 * were hardcoded, or if __() were handed a variable the extractor cannot
 * see but the runtime still resolved, this would stay English and fail.
 */
expect(
    'labels come through translation',
    skyphusion_ai_stock_html(array(array(
        'attributes' => '',
        'sku' => 'DE-1',
        'price' => '',
        'stock_status' => 'instock',
        'managing_stock' => true,
        'quantity' => 6,
    ))),
    '<section class="cloudflare-ai-search-stock"><h2>Lager</h2><ul><li>Artikelnummer DE-1. Auf Lager. Verfuegbar: 6.</li></ul></section>'
);
expect(
    'singular plural form selected',
    skyphusion_ai_stock_html(array(array(
        'attributes' => '',
        'sku' => '',
        'price' => '',
        'stock_status' => 'instock',
        'managing_stock' => true,
        'quantity' => 1,
    ))),
    '<section class="cloudflare-ai-search-stock"><h2>Lager</h2><ul><li>Auf Lager. 1 available.</li></ul></section>'
);
expect('class name is not translated', strpos(skyphusion_ai_stock_html(array(array(
    'attributes' => '',
    'sku' => 'DE-2',
    'price' => '',
    'stock_status' => 'outofstock',
    'managing_stock' => false,
    'quantity' => null,
))), 'class="cloudflare-ai-search-stock"') !== false, true);

/* A translation file is content, not code. It must not be able to add markup. */
$GLOBALS['cfai_stock_test_strings']['Out of stock.'] = '<script>alert(1)</script>';
$GLOBALS['cfai_stock_test_strings']['Stock'] = '<b>Stock</b>';
$GLOBALS['cfai_stock_test_strings']['SKU %s.'] = '<i>SKU %s.</i>';
$hostile = skyphusion_ai_stock_html(array(array(
    'attributes' => '',
    'sku' => 'HOSTILE',
    'price' => '',
    'stock_status' => 'outofstock',
    'managing_stock' => false,
    'quantity' => null,
)));
expect('hostile translation cannot open a tag', strpos($hostile, '<script') === false, true);
expect('hostile translation is escaped', strpos($hostile, '&lt;script&gt;') !== false, true);
expect('hostile heading cannot open a tag', strpos($hostile, '<b>') === false, true);
/* The SKU label carries a placeholder, so it is escaped before sprintf. */
expect('hostile sku label cannot open a tag', strpos($hostile, '<i>') === false, true);
expect('hostile sku label keeps its value', strpos($hostile, 'SKU HOSTILE.') !== false, true);

/* The cap reads the filter, and refuses a value it cannot use. */
$GLOBALS['cfai_stock_test_filters']['cloudflare_ai_search_stock_max_variations'] = 5;
expect('variation cap honours the filter', skyphusion_ai_stock_max_variations(), 5);
$GLOBALS['cfai_stock_test_filters']['cloudflare_ai_search_stock_max_variations'] = '7';
expect('variation cap accepts a numeric string', skyphusion_ai_stock_max_variations(), 7);
$GLOBALS['cfai_stock_test_filters']['cloudflare_ai_search_stock_max_variations'] = 0;
expect('variation cap rejects zero', skyphusion_ai_stock_max_variations(), 100);
$GLOBALS['cfai_stock_test_filters']['cloudflare_ai_search_stock_max_variations'] = 'all';
expect('variation cap rejects a non number', skyphusion_ai_stock_max_variations(), 100);
/*
 * A boolean is the case only is_numeric() catches. Someone wiring
 * __return_true to this filter by accident casts to 1, which the lower
 * bound below would wave through as a cap of one variation per page.
 */
$GLOBALS['cfai_stock_test_filters']['cloudflare_ai_search_stock_max_variations'] = true;
expect('variation cap rejects a boolean', skyphusion_ai_stock_max_variations(), 100);

/* ---------- part three: the grouped product decision path ---------- */
/*
 * These stubs prove the DECISION PATH only: that a grouped product is read
 * through its children, that the child name takes the leading field, and
 * that the cap applies. They do NOT prove the shipped behaviour against real
 * WooCommerce, because the objects here are mine and encode my own
 * assumptions about the methods. The live check is a rendered page on a store
 * that has a grouped product.
 *
 * Assertions are on the ROW ARRAYS rather than on HTML, so the translated
 * label stubs defined above do not affect them.
 */

class Cfai_Stock_Test_Child
{
    public $name;
    public $sku;
    public $price;
    public $status;
    public $manages;
    public $qty;

    public function __construct($name, $sku, $price, $status, $manages = false, $qty = null)
    {
        $this->name = $name;
        $this->sku = $sku;
        $this->price = $price;
        $this->status = $status;
        $this->manages = $manages;
        $this->qty = $qty;
    }

    public function get_name()
    {
        return $this->name;
    }
    public function get_sku()
    {
        return $this->sku;
    }
    public function get_price()
    {
        return $this->price;
    }
    public function get_stock_status()
    {
        return $this->status;
    }
    public function managing_stock()
    {
        return $this->manages;
    }
    public function get_stock_quantity()
    {
        return $this->qty;
    }
    public function is_type($type)
    {
        return $type === 'simple';
    }
}

class Cfai_Stock_Test_Grouped
{
    public $children;

    public function __construct($children)
    {
        $this->children = $children;
    }
    public function get_children()
    {
        return $this->children;
    }
    public function is_type($type)
    {
        return $type === 'grouped';
    }
}

$GLOBALS['cfai_stock_test_products'] = array(
    11 => new Cfai_Stock_Test_Child('Beanie', 'woo-beanie', '18', 'instock'),
    12 => new Cfai_Stock_Test_Child('T-Shirt', 'woo-tshirt', '18', 'outofstock'),
    13 => new Cfai_Stock_Test_Child('Logo <b>Cap</b>', 'woo-cap', '16', 'instock', true, 7),
);

if (!function_exists('wc_get_product')) {
    function wc_get_product($id)
    {
        $all = $GLOBALS['cfai_stock_test_products'];
        return isset($all[$id]) ? $all[$id] : null;
    }
}

$GLOBALS['cfai_stock_test_filters']['cloudflare_ai_search_stock_max_variations'] = 100;

$grouped = new Cfai_Stock_Test_Grouped(array(11, 12));
$rows = skyphusion_ai_stock_grouped_rows($grouped);
expect('grouped yields one row per child', count($rows), 2);
expect('grouped child name takes the leading field', $rows[0]['attributes'], 'Beanie');
expect('grouped child sku', $rows[0]['sku'], 'woo-beanie');
expect('grouped child price', $rows[0]['price'], '18');
expect('grouped child status', $rows[0]['stock_status'], 'instock');
expect('grouped out of stock child kept', $rows[1]['stock_status'], 'outofstock');
expect('grouped child without managed stock has no quantity', $rows[0]['quantity'], null);

/* A grouped child is a product, not a variation, so no attribute summary is read. */
$managed = skyphusion_ai_stock_grouped_rows(new Cfai_Stock_Test_Grouped(array(13)));
expect('grouped child name is stripped of markup', $managed[0]['attributes'], 'Logo Cap');
expect('grouped child managed quantity is read', $managed[0]['quantity'], 7);

/* A missing child must be skipped rather than producing an empty row. */
$withGap = skyphusion_ai_stock_grouped_rows(new Cfai_Stock_Test_Grouped(array(11, 999, 12)));
expect('grouped skips a child that cannot be loaded', count($withGap), 2);

/* The cap bounds grouped children too, using the same filter. */
$GLOBALS['cfai_stock_test_filters']['cloudflare_ai_search_stock_max_variations'] = 1;
expect('grouped honours the row cap', count(skyphusion_ai_stock_grouped_rows(new Cfai_Stock_Test_Grouped(array(11, 12, 13)))), 1);
$GLOBALS['cfai_stock_test_filters']['cloudflare_ai_search_stock_max_variations'] = 100;

/* Nothing usable in, nothing out. */
expect('grouped returns empty for a non object', skyphusion_ai_stock_grouped_rows('nope'), array());
expect('grouped returns empty when get_children gives a non array', skyphusion_ai_stock_grouped_rows(new Cfai_Stock_Test_Grouped('bad')), array());

/*
 * The is_array() guards on get_children() return the same empty list whether
 * or not they are present, because foreach over a non array yields nothing.
 * The difference is a PHP warning on every page view of a malformed product,
 * so the warning is what the assertion has to observe. Without this, removing
 * either guard leaves the suite green and the guard is decoration.
 */
function cfai_stock_test_warnings($callable, $argument)
{
    $seen = 0;
    set_error_handler(function ($errno, $errstr) use (&$seen) {
        $seen++;
        return true;
    });
    $callable($argument);
    restore_error_handler();
    return $seen;
}

expect(
    'grouped children guard emits no warning on a non array',
    cfai_stock_test_warnings('skyphusion_ai_stock_grouped_rows', new Cfai_Stock_Test_Grouped('bad')),
    0
);
expect(
    'variation children guard emits no warning on a non array',
    cfai_stock_test_warnings('skyphusion_ai_stock_variation_rows', new Cfai_Stock_Test_Grouped('bad')),
    0
);

/*
 * The type dispatch in skyphusion_ai_stock_rows() is the thing that
 * actually broke: it was an allowlist of variable and simple, so a grouped
 * product fell through to an empty array and its children never reached the
 * index. Covering it needs the WordPress context functions, so they are
 * stubbed from here down. Still a decision-path proof, not a WooCommerce one.
 */

class Cfai_Stock_Test_External
{
    public function get_sku()
    {
        return 'woo-ext';
    }
    public function get_price()
    {
        return '25';
    }
    public function get_stock_status()
    {
        return 'instock';
    }
    public function managing_stock()
    {
        return false;
    }
    public function is_type($type)
    {
        return $type === 'external';
    }
}

$GLOBALS['cfai_stock_test_products'][20] = new Cfai_Stock_Test_Grouped(array(11, 12));
$GLOBALS['cfai_stock_test_products'][21] = new Cfai_Stock_Test_External();
$GLOBALS['cfai_stock_test_current'] = 20;

if (!function_exists('is_admin')) {
    function is_admin()
    {
        return false;
    }
}
if (!function_exists('is_feed')) {
    function is_feed()
    {
        return false;
    }
}
if (!function_exists('is_embed')) {
    function is_embed()
    {
        return false;
    }
}
if (!function_exists('is_product')) {
    function is_product()
    {
        return true;
    }
}
if (!function_exists('get_the_ID')) {
    function get_the_ID()
    {
        return $GLOBALS['cfai_stock_test_current'];
    }
}

$GLOBALS['cfai_stock_test_current'] = 20;
$dispatched = skyphusion_ai_stock_rows();
expect('dispatch sends a grouped product to its children', count($dispatched), 2);
expect('dispatch keeps the child name', $dispatched[0]['attributes'], 'Beanie');

/*
 * The allowlist is gone, so a type that is neither variable nor grouped now
 * yields a row for itself. Under the old code this returned an empty array
 * and an external product was invisible to the index.
 */
$GLOBALS['cfai_stock_test_current'] = 21;
$ext = skyphusion_ai_stock_rows();
expect('dispatch gives an unlisted type one row', count($ext), 1);
expect('unlisted type row carries its sku', $ext[0]['sku'], 'woo-ext');
expect('unlisted type row carries its status', $ext[0]['stock_status'], 'instock');
expect('unlisted type row has no attribute text', $ext[0]['attributes'], '');

$GLOBALS['cfai_stock_test_current'] = 20;

/* The rows still render through the same builder. */
$groupedHtml = skyphusion_ai_stock_html(skyphusion_ai_stock_grouped_rows($grouped));
expect('grouped rows render two list items', substr_count($groupedHtml, '<li>'), 2);
expect('grouped html names the child', strpos($groupedHtml, 'Beanie') !== false, true);

/* ---------- part four: pooled vs own-level stock ---------- */
/*
 * Verified against WooCommerce trunk: WC_Product_Variation::get_manage_stock()
 * returns true, false, or the STRING 'parent', and get_stock_quantity() then
 * returns the parent's pooled figure. So a variation that defers stock to its
 * parent must keep its status and drop the number, because the pool is not
 * that row's count.
 *
 * Assertions are on the row arrays, so the translated label stubs defined
 * earlier do not affect them.
 */

class Cfai_Stock_Test_Variation
{
    public $mode;
    public $qty;
    public $status;
    public $sku;

    public function __construct($mode, $qty, $status = 'instock', $sku = 'woo-var')
    {
        $this->mode = $mode;
        $this->qty = $qty;
        $this->status = $status;
        $this->sku = $sku;
    }

    public function managing_stock()
    {
        return $this->mode;
    }
    public function get_stock_quantity()
    {
        return $this->qty;
    }
    public function get_stock_status()
    {
        return $this->status;
    }
    public function get_sku()
    {
        return $this->sku;
    }
    public function get_price()
    {
        return '';
    }
    public function get_attribute_summary()
    {
        return 'Color: Blue';
    }
    public function is_type($type)
    {
        return $type === 'variation';
    }
}

/* ---------- part five: the count on a search result ---------- */
/*
 * Decision path only. The objects are stubs. They prove which count is
 * chosen, not that WooCommerce renders the loop.
 */

require_once dirname(__DIR__) . '/includes/search-stock.php';

class Cfai_Stock_Test_Search_Product
{
    public $id;
    public $type;
    public $mode;
    public $qty;
    public $children;

    public function __construct($id, $type, $mode, $qty, $children = array())
    {
        $this->id = $id;
        $this->type = $type;
        $this->mode = $mode;
        $this->qty = $qty;
        $this->children = $children;
    }

    public function get_id()
    {
        return $this->id;
    }
    public function is_type($type)
    {
        return $type === $this->type;
    }
    public function managing_stock()
    {
        return $this->mode;
    }
    public function get_stock_quantity()
    {
        return $this->qty;
    }
    public function get_children()
    {
        return $this->children;
    }
    public function get_stock_status()
    {
        return 'instock';
    }
    public function get_sku()
    {
        return '';
    }
    public function get_price()
    {
        return '';
    }
}

$simple_search = new Cfai_Stock_Test_Search_Product(31, 'simple', true, 4);
expect('search count for a managed simple product', skyphusion_ai_search_stock_count($simple_search), 4);
$search_line = skyphusion_ai_stock_quantity_text(4);
expect('search text reuses the stock sentence', skyphusion_ai_search_stock_text($simple_search), $search_line);
expect('search text carries the count', strpos($search_line, '4') !== false, true);
expect(
    'search markup is one paragraph',
    skyphusion_ai_search_stock_markup($simple_search),
    '<p class="cloudflare-ai-search-stock-count">' . $search_line . '</p>'
);

$unmanaged = new Cfai_Stock_Test_Search_Product(32, 'simple', false, 9);
expect('search count is empty when stock is not managed', skyphusion_ai_search_stock_count($unmanaged), null);
expect('search text is empty when stock is not managed', skyphusion_ai_search_stock_text($unmanaged), '');

$zero = new Cfai_Stock_Test_Search_Product(33, 'simple', true, 0);
expect('search count keeps zero', skyphusion_ai_search_stock_count($zero), 0);
expect('search text prints zero', skyphusion_ai_search_stock_text($zero), skyphusion_ai_stock_quantity_text(0));

$negative = new Cfai_Stock_Test_Search_Product(34, 'simple', true, -3);
expect('search count drops a negative', skyphusion_ai_search_stock_count($negative), null);

$not_a_product = skyphusion_ai_search_stock_count('nope');
expect('search count rejects a non object', $not_a_product, null);

$GLOBALS['cfai_stock_test_products'][41] = new Cfai_Stock_Test_Search_Product(41, 'variation', true, 3);
$GLOBALS['cfai_stock_test_products'][42] = new Cfai_Stock_Test_Search_Product(42, 'variation', true, 5);
$GLOBALS['cfai_stock_test_products'][43] = new Cfai_Stock_Test_Variation('parent', 10);
$variable_search = new Cfai_Stock_Test_Search_Product(40, 'variable', false, null, array(41, 42));
expect('search count sums own-level variations', skyphusion_ai_search_stock_count($variable_search), 8);

$parent_manages = new Cfai_Stock_Test_Search_Product(44, 'variable', true, 9, array(41, 42));
expect('search count uses the parent pool and does not add the children', skyphusion_ai_search_stock_count($parent_manages), 9);

$pooled_only = new Cfai_Stock_Test_Search_Product(45, 'variable', false, null, array(43));
expect('search count ignores a variation that defers to its parent', skyphusion_ai_search_stock_count($pooled_only), null);

$GLOBALS['cfai_stock_test_filters']['cloudflare_ai_search_stock_max_variations'] = 1;
$over_cap = new Cfai_Stock_Test_Search_Product(46, 'variable', false, null, array(41, 42));
expect('search count refuses a partial variation sum', skyphusion_ai_search_stock_count($over_cap), null);
$GLOBALS['cfai_stock_test_filters']['cloudflare_ai_search_stock_max_variations'] = 100;

$grouped_search = new Cfai_Stock_Test_Search_Product(47, 'grouped', false, null, array(41));
expect('search count does not sum a grouped product', skyphusion_ai_search_stock_count($grouped_search), null);

$once_a = skyphusion_ai_search_stock_once($simple_search, 31);
$once_b = skyphusion_ai_search_stock_once($simple_search, 31);
expect('search markup prints once for a product id', $once_a !== '' && $once_b === '', true);

$search_src = (string) file_get_contents(dirname(__DIR__) . '/includes/search-stock.php');
preg_match_all(
    '/add_(?:action|filter)\(\s*\'[^\']*\'\s*,\s*\'([A-Za-z0-9_]+)\'/',
    $search_src,
    $search_hits
);
$search_missing = array();
foreach ((array) $search_hits[1] as $search_callback) {
    if (!function_exists($search_callback)) {
        $search_missing[] = $search_callback;
    }
}
expect('search stock registers three callbacks', count($search_hits[1]), 3);
expect('search stock callbacks exist', $search_missing, array());

/* Deferred to the parent: the pooled count must NOT be claimed by this row. */
$pooled = skyphusion_ai_stock_row_from_product(new Cfai_Stock_Test_Variation('parent', 10), true);
expect('pooled stock is not treated as managed', $pooled['managing_stock'], false);
expect('pooled count is dropped from the row', $pooled['quantity'], null);
expect('pooled row keeps its own status', $pooled['stock_status'], 'instock');
expect('pooled row keeps its attributes', $pooled['attributes'], 'Color: Blue');

/* The pooled figure must not reach the rendered text either. The row has no
 * price, so the digits can only come from a count. */
$pooledHtml = skyphusion_ai_stock_html(array($pooled));
expect('pooled count absent from the rendered row', strpos($pooledHtml, '10') === false, true);

/* Managed at its own level: the count is this variation's and is printed. */
$own = skyphusion_ai_stock_row_from_product(new Cfai_Stock_Test_Variation(true, 3), true);
expect('own-level stock is treated as managed', $own['managing_stock'], true);
expect('own-level count is kept', $own['quantity'], 3);

/* A truthy non-boolean that is not the parent sentinel still counts as
 * managed, so the guard narrows on 'parent' alone and not on type. */
$truthy = skyphusion_ai_stock_row_from_product(new Cfai_Stock_Test_Variation(1, 4), true);
expect('a truthy mode other than parent still manages', $truthy['managing_stock'], true);
expect('a truthy mode other than parent keeps its count', $truthy['quantity'], 4);

/* An out-of-stock variation with a pooled parent keeps the negative answer. */
$pooledOut = skyphusion_ai_stock_row_from_product(new Cfai_Stock_Test_Variation('parent', 10, 'outofstock'), true);
expect('pooled out-of-stock row survives', $pooledOut['stock_status'], 'outofstock');
expect('pooled out-of-stock row has no count', $pooledOut['quantity'], null);
