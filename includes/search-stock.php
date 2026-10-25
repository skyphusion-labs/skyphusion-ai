<?php
/**
 * Stock quantity on search results.
 *
 * The hosted search snippet has no result template, so it cannot print a
 * stock field. This file prints the managed count on the WordPress and
 * WooCommerce search loop, which is the list the shopper actually sees.
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

if ( function_exists( 'add_action' ) ) {
	add_action( 'woocommerce_after_shop_loop_item_title', 'skyphusion_ai_search_stock_item', 15 );
	add_action( 'wp_head', 'skyphusion_ai_search_stock_meta', 2 );
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'render_block', 'skyphusion_ai_search_stock_block', 12, 2 );
}
