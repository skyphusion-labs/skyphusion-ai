<?php
/**
 * Stock on search results and on the product description.
 *
 * The hosted search snippet quotes the page meta description. It has no
 * result template, so it cannot print a stock field of its own. On a single
 * product page this file appends the SKU, the stock status, and the managed
 * count to that description. It also prints the managed count on the
 * WordPress and WooCommerce search loop.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allowed tags for the search-result count.
 *
 * @return array<string, array<string, bool>>
 */
function skyphusion_ai_search_stock_allowed_html() {
	return array(
		'p' => array(
			'class' => true,
		),
	);
}

/**
 * Managed unit count for one product, or null when there is no count to show.
 *
 * A product that manages its own stock uses that count. A variable product
 * that stocks each variation separately does not get a summed total on the
 * search card. That sum is not the count of any one variation. The product
 * page already lists each variation.
 *
 * @param mixed $product Product object, or anything else.
 * @return int|null
 */
function skyphusion_ai_search_stock_count( $product ) {
	if ( ! is_object( $product ) ) {
		return null;
	}

	$row = skyphusion_ai_stock_row_from_product( $product, false );
	if (
		is_array( $row )
		&& ! empty( $row['managing_stock'] )
		&& isset( $row['quantity'] )
		&& is_int( $row['quantity'] )
		&& $row['quantity'] >= 0
	) {
		return $row['quantity'];
	}

	return null;
}

/**
 * Escaped quantity sentence for one search result.
 *
 * @param mixed $product Product object, or anything else.
 * @return string
 */
function skyphusion_ai_search_stock_text( $product ) {
	$count = skyphusion_ai_search_stock_count( $product );
	if ( ! is_int( $count ) ) {
		return '';
	}
	return skyphusion_ai_stock_quantity_text( $count );
}

/**
 * One paragraph of search-result markup, or an empty string.
 *
 * @param mixed $product Product object, or anything else.
 * @return string
 */
function skyphusion_ai_search_stock_markup( $product ) {
	$text = skyphusion_ai_search_stock_text( $product );
	if ( '' === $text ) {
		return '';
	}
	return '<p class="cloudflare-ai-search-stock-count">' . $text . '</p>';
}

/**
 * Whether this request is a public search results page.
 *
 * @return bool
 */
function skyphusion_ai_search_stock_should_print() {
	if ( is_admin() ) {
		return false;
	}
	if ( function_exists( 'is_feed' ) && is_feed() ) {
		return false;
	}
	if ( function_exists( 'is_embed' ) && is_embed() ) {
		return false;
	}
	if ( ! function_exists( 'is_search' ) || ! is_search() ) {
		return false;
	}
	if ( function_exists( 'skyphusion_ai_quiet_page' ) && skyphusion_ai_quiet_page() ) {
		return false;
	}
	return true;
}

/**
 * Markup for this product, once per product id on the request.
 *
 * @param mixed $product Product object, or anything else.
 * @param int   $post_id Post id when the product has none.
 * @return string
 */
function skyphusion_ai_search_stock_once( $product, $post_id ) {
	static $seen = array();

	$key = (int) $post_id;
	if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
		$key = (int) $product->get_id();
	}
	if ( $key > 0 && isset( $seen[ $key ] ) ) {
		return '';
	}

	$markup = skyphusion_ai_search_stock_markup( $product );
	if ( '' === $markup ) {
		return '';
	}
	if ( $key > 0 ) {
		$seen[ $key ] = true;
	}
	return $markup;
}

/**
 * Classic loop: print the count under the product title.
 *
 * @return void
 */
function skyphusion_ai_search_stock_item() {
	if ( ! skyphusion_ai_search_stock_should_print() ) {
		return;
	}

	$product = null;
	if ( isset( $GLOBALS['product'] ) && is_object( $GLOBALS['product'] ) ) {
		$product = $GLOBALS['product'];
	}

	$post_id = 0;
	if ( function_exists( 'get_the_ID' ) ) {
		$post_id = (int) get_the_ID();
	}

	$markup = skyphusion_ai_search_stock_once( $product, $post_id );
	if ( '' === $markup ) {
		return;
	}

	echo wp_kses( $markup, skyphusion_ai_search_stock_allowed_html() );
}

/**
 * Block loop: append the count after the product price block.
 *
 * @param string $html  Rendered block.
 * @param array  $block Block data.
 * @return string
 */
function skyphusion_ai_search_stock_block( $html, $block ) {
	if ( ! skyphusion_ai_search_stock_should_print() ) {
		return $html;
	}

	$name = '';
	if ( isset( $block['blockName'] ) ) {
		$name = (string) $block['blockName'];
	}
	if ( 'woocommerce/product-price' !== $name ) {
		return $html;
	}

	$post_id = 0;
	if ( isset( $block['context']['postId'] ) ) {
		$post_id = (int) $block['context']['postId'];
	}
	if ( $post_id <= 0 && function_exists( 'get_the_ID' ) ) {
		$post_id = (int) get_the_ID();
	}

	$product = null;
	if ( $post_id > 0 && function_exists( 'wc_get_product' ) ) {
		$product = wc_get_product( $post_id );
	}
	if ( ! is_object( $product ) && isset( $GLOBALS['product'] ) && is_object( $GLOBALS['product'] ) ) {
		$product = $GLOBALS['product'];
	}

	$markup = skyphusion_ai_search_stock_once( $product, $post_id );
	if ( '' === $markup ) {
		return $html;
	}

	return $html . wp_kses( $markup, skyphusion_ai_search_stock_allowed_html() );
}

/**
 * Number meta on a single product, for an AI Search schema that names it.
 *
 * The tag is ignored until the instance schema defines stock_quantity as a
 * number. The schema allows five custom fields. The hosted snippet does not
 * display this tag.
 *
 * @return void
 */
function skyphusion_ai_search_stock_meta() {
	if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	if ( function_exists( 'is_search' ) && is_search() ) {
		return;
	}

	$product = null;
	if ( function_exists( 'wc_get_product' ) && function_exists( 'get_the_ID' ) ) {
		$product = wc_get_product( (int) get_the_ID() );
	}
	$count = skyphusion_ai_search_stock_count( $product );
	if ( ! is_int( $count ) ) {
		return;
	}

	echo '<meta name="stock_quantity" content="' . esc_attr( (string) $count ) . '" />' . "\n";
}

/**
 * Plain SKU, status, and managed count for the product description.
 *
 * The AI Search dropdown quotes this description. The string is plain text.
 * Rank Math and Yoast escape it when they print the tag. Do not escape it
 * here. Do not reuse skyphusion_ai_stock_line(): that function escapes, and
 * it also prints the price.
 *
 * A variable product contributes only its own row. Child quantities are not
 * added. A negative count is omitted. Zero is printed. A product that does
 * not manage stock gets no count.
 *
 * @param mixed $product Product object, or anything else.
 * @return string
 */
function skyphusion_ai_search_stock_facts( $product ) {
	$row = skyphusion_ai_stock_row_from_product( $product, false );
	if ( ! is_array( $row ) ) {
		return '';
	}

	$labels = skyphusion_ai_stock_labels();
	$parts  = array();

	$sku = isset( $row['sku'] ) ? trim( (string) $row['sku'] ) : '';
	if ( '' !== $sku && isset( $labels['sku'] ) ) {
		$parts[] = sprintf( (string) $labels['sku'], $sku );
	}

	$status = isset( $row['stock_status'] ) ? (string) $row['stock_status'] : '';
	if (
		( 'instock' === $status || 'outofstock' === $status || 'onbackorder' === $status )
		&& isset( $labels[ $status ] )
	) {
		$parts[] = (string) $labels[ $status ];
	}

	$managing = ! empty( $row['managing_stock'] );
	$quantity = array_key_exists( 'quantity', $row ) ? $row['quantity'] : null;
	if ( $managing && is_int( $quantity ) && $quantity >= 0 ) {
		$count = (int) $quantity;
		if ( function_exists( '_n' ) ) {
			/* translators: %d is the number of units in stock. */
			$format = _n( '%d available.', '%d available.', $count, 'skyphusion-ai' );
		} else {
			$format = '%d available.';
		}
		$parts[] = sprintf( (string) $format, $count );
	}

	return implode( ' ', $parts );
}

/**
 * Whether the description filter is on a single product request.
 *
 * Search, feeds, embeds, and wp-admin keep the description they already had.
 *
 * @return bool
 */
function skyphusion_ai_search_stock_describe_should() {
	if ( function_exists( 'is_admin' ) && is_admin() ) {
		return false;
	}
	if ( function_exists( 'is_feed' ) && is_feed() ) {
		return false;
	}
	if ( function_exists( 'is_embed' ) && is_embed() ) {
		return false;
	}
	if ( function_exists( 'is_search' ) && is_search() ) {
		return false;
	}
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return false;
	}
	return true;
}

/**
 * True when the facts are already the end of the description.
 *
 * The check is the whole sentence, not the status alone, so a blurb that
 * already ends in "In stock." still gains the SKU and the count.
 *
 * @param string $description Description so far.
 * @param string $facts       Sentence this filter would append.
 * @return bool
 */
function skyphusion_ai_search_stock_facts_are_suffix( $description, $facts ) {
	$description = rtrim( $description );
	if ( '' === $facts || '' === $description ) {
		return false;
	}
	if ( $description === $facts ) {
		return true;
	}
	return str_ends_with( $description, ' ' . $facts );
}

/**
 * Append stock facts to a single product's SEO description.
 *
 * Rank Math reuses rank_math/frontend/description for the meta description
 * and for Open Graph. Yoast keeps those two strings on separate filters.
 * Priority 20 runs after a theme description at the default priority.
 * The description's own final period is kept.
 *
 * @param mixed $description Description the SEO plugin already built.
 * @return mixed
 */
function skyphusion_ai_search_stock_describe( $description ) {
	if ( ! is_string( $description ) ) {
		return $description;
	}
	if ( ! skyphusion_ai_search_stock_describe_should() ) {
		return $description;
	}

	$product = null;
	if ( function_exists( 'wc_get_product' ) && function_exists( 'get_the_ID' ) ) {
		$product = wc_get_product( (int) get_the_ID() );
	}
	$facts = skyphusion_ai_search_stock_facts( $product );
	if ( '' === $facts ) {
		return $description;
	}
	if ( skyphusion_ai_search_stock_facts_are_suffix( $description, $facts ) ) {
		return $description;
	}

	$base = rtrim( $description );
	if ( '' === $base ) {
		return $facts;
	}
	return $base . ' ' . $facts;
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'woocommerce_after_shop_loop_item_title', 'skyphusion_ai_search_stock_item', 15 );
	add_action( 'wp_head', 'skyphusion_ai_search_stock_meta', 2 );
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'render_block', 'skyphusion_ai_search_stock_block', 12, 2 );
	add_filter( 'rank_math/frontend/description', 'skyphusion_ai_search_stock_describe', 20 );
	add_filter( 'wpseo_metadesc', 'skyphusion_ai_search_stock_describe', 20 );
	add_filter( 'wpseo_opengraph_desc', 'skyphusion_ai_search_stock_describe', 20 );
}
