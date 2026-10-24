<?php
/**
 * One-release compatibility shim.
 *
 * Old function names call the renamed functions. The lead renderer
 * and the block filter are hooked under the old callback names so
 * an older consumer can still remove them. The old CSS classes stay
 * in the markup the renderers print. skyphusion_ai_settings() reads
 * the old option key when the new key is absent.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'CLOUDFLARE_AI_SEARCH_VERSION' ) && defined( 'SKYPHUSION_AI_VERSION' ) ) {
	define( 'CLOUDFLARE_AI_SEARCH_VERSION', SKYPHUSION_AI_VERSION );
}
if ( ! defined( 'CLOUDFLARE_AI_SEARCH_FILE' ) && defined( 'SKYPHUSION_AI_FILE' ) ) {
	define( 'CLOUDFLARE_AI_SEARCH_FILE', SKYPHUSION_AI_FILE );
}
if ( ! defined( 'CLOUDFLARE_AI_SEARCH_SNIPPET' ) && defined( 'SKYPHUSION_AI_SNIPPET' ) ) {
	define( 'CLOUDFLARE_AI_SEARCH_SNIPPET', SKYPHUSION_AI_SNIPPET );
}

/**
 * Compatibility wrapper for skyphusion_ai_action_links().
 *
 * @param mixed $links Passed through.
 * @return mixed Result of skyphusion_ai_action_links().
 */
function cloudflare_ai_search_action_links( $links ) {
	return skyphusion_ai_action_links( $links );
}

/**
 * Compatibility wrapper for skyphusion_ai_activate().
 *
 * @return mixed Result of skyphusion_ai_activate().
 */
function cloudflare_ai_search_activate() {
	return skyphusion_ai_activate();
}

/**
 * Compatibility wrapper for skyphusion_ai_add_menu().
 *
 * @return mixed Result of skyphusion_ai_add_menu().
 */
function cloudflare_ai_search_add_menu() {
	return skyphusion_ai_add_menu();
}

/**
 * Compatibility wrapper for skyphusion_ai_admin_assets().
 *
 * @param mixed $hook Passed through.
 * @return mixed Result of skyphusion_ai_admin_assets().
 */
function cloudflare_ai_search_admin_assets( $hook ) {
	return skyphusion_ai_admin_assets( $hook );
}

/**
 * Compatibility wrapper for skyphusion_ai_admin_notice().
 *
 * @return mixed Result of skyphusion_ai_admin_notice().
 */
function cloudflare_ai_search_admin_notice() {
	return skyphusion_ai_admin_notice();
}

/**
 * Compatibility wrapper for skyphusion_ai_choice_text().
 *
 * @param mixed $decoded Passed through.
 * @return mixed Result of skyphusion_ai_choice_text().
 */
function cloudflare_ai_search_choice_text( $decoded ) {
	return skyphusion_ai_choice_text( $decoded );
}

/**
 * Compatibility wrapper for skyphusion_ai_cli_accent_color().
 *
 * @param mixed $assoc Passed through.
 * @param mixed $argv Passed through.
 * @return mixed Result of skyphusion_ai_cli_accent_color().
 */
function cloudflare_ai_search_cli_accent_color( $assoc, $argv ) {
	return skyphusion_ai_cli_accent_color( $assoc, $argv );
}

/**
 * Compatibility wrapper for skyphusion_ai_cli_truthy().
 *
 * @param mixed $value Passed through.
 * @return mixed Result of skyphusion_ai_cli_truthy().
 */
function cloudflare_ai_search_cli_truthy( $value ) {
	return skyphusion_ai_cli_truthy( $value );
}

/**
 * Compatibility wrapper for skyphusion_ai_component().
 *
 * @param mixed $tag Passed through.
 * @param mixed $settings Passed through.
 * @return mixed Result of skyphusion_ai_component().
 */
function cloudflare_ai_search_component( $tag, $settings ) {
	return skyphusion_ai_component( $tag, $settings );
}

/**
 * Compatibility wrapper for skyphusion_ai_defaults().
 *
 * @return mixed Result of skyphusion_ai_defaults().
 */
function cloudflare_ai_search_defaults() {
	return skyphusion_ai_defaults();
}

/**
 * Compatibility wrapper for skyphusion_ai_enqueue().
 *
 * @return mixed Result of skyphusion_ai_enqueue().
 */
function cloudflare_ai_search_enqueue() {
	return skyphusion_ai_enqueue();
}

/**
 * Compatibility wrapper for skyphusion_ai_field_account_id().
 *
 * @return mixed Result of skyphusion_ai_field_account_id().
 */
function cloudflare_ai_search_field_account_id() {
	return skyphusion_ai_field_account_id();
}

/**
 * Compatibility wrapper for skyphusion_ai_field_chat().
 *
 * @return mixed Result of skyphusion_ai_field_chat().
 */
function cloudflare_ai_search_field_chat() {
	return skyphusion_ai_field_chat();
}

/**
 * Compatibility wrapper for skyphusion_ai_field_color().
 *
 * @return mixed Result of skyphusion_ai_field_color().
 */
function cloudflare_ai_search_field_color() {
	return skyphusion_ai_field_color();
}

/**
 * Compatibility wrapper for skyphusion_ai_field_endpoint().
 *
 * @return mixed Result of skyphusion_ai_field_endpoint().
 */
function cloudflare_ai_search_field_endpoint() {
	return skyphusion_ai_field_endpoint();
}

/**
 * Compatibility wrapper for skyphusion_ai_field_gateway_id().
 *
 * @return mixed Result of skyphusion_ai_field_gateway_id().
 */
function cloudflare_ai_search_field_gateway_id() {
	return skyphusion_ai_field_gateway_id();
}

/**
 * Compatibility wrapper for skyphusion_ai_field_hide_branding().
 *
 * @return mixed Result of skyphusion_ai_field_hide_branding().
 */
function cloudflare_ai_search_field_hide_branding() {
	return skyphusion_ai_field_hide_branding();
}

/**
 * Compatibility wrapper for skyphusion_ai_field_hide_checkout().
 *
 * @return mixed Result of skyphusion_ai_field_hide_checkout().
 */
function cloudflare_ai_search_field_hide_checkout() {
	return skyphusion_ai_field_hide_checkout();
}

/**
 * Compatibility wrapper for skyphusion_ai_field_lead_search().
 *
 * @return mixed Result of skyphusion_ai_field_lead_search().
 */
function cloudflare_ai_search_field_lead_search() {
	return skyphusion_ai_field_lead_search();
}

/**
 * Compatibility wrapper for skyphusion_ai_field_max_results().
 *
 * @return mixed Result of skyphusion_ai_field_max_results().
 */
function cloudflare_ai_search_field_max_results() {
	return skyphusion_ai_field_max_results();
}

/**
 * Compatibility wrapper for skyphusion_ai_field_modal().
 *
 * @return mixed Result of skyphusion_ai_field_modal().
 */
function cloudflare_ai_search_field_modal() {
	return skyphusion_ai_field_modal();
}

/**
 * Compatibility wrapper for skyphusion_ai_field_placeholder().
 *
 * @return mixed Result of skyphusion_ai_field_placeholder().
 */
function cloudflare_ai_search_field_placeholder() {
	return skyphusion_ai_field_placeholder();
}

/**
 * Compatibility wrapper for skyphusion_ai_field_placement().
 *
 * @return mixed Result of skyphusion_ai_field_placement().
 */
function cloudflare_ai_search_field_placement() {
	return skyphusion_ai_field_placement();
}

/**
 * Compatibility wrapper for skyphusion_ai_field_search_bar().
 *
 * @return mixed Result of skyphusion_ai_field_search_bar().
 */
function cloudflare_ai_search_field_search_bar() {
	return skyphusion_ai_field_search_bar();
}

/**
 * Compatibility wrapper for skyphusion_ai_field_theme().
 *
 * @return mixed Result of skyphusion_ai_field_theme().
 */
function cloudflare_ai_search_field_theme() {
	return skyphusion_ai_field_theme();
}

/**
 * Compatibility wrapper for skyphusion_ai_handle_test().
 *
 * @return mixed Result of skyphusion_ai_handle_test().
 */
function cloudflare_ai_search_handle_test() {
	return skyphusion_ai_handle_test();
}

/**
 * Compatibility wrapper for skyphusion_ai_lead_answer().
 *
 * @param mixed $question Passed through.
 * @return mixed Result of skyphusion_ai_lead_answer().
 */
function cloudflare_ai_search_lead_answer( $question ) {
	return skyphusion_ai_lead_answer( $question );
}

/**
 * Compatibility wrapper for skyphusion_ai_lead_block().
 *
 * @param mixed $html Passed through.
 * @param mixed $block Passed through.
 * @return mixed Result of skyphusion_ai_lead_block().
 */
function cloudflare_ai_search_lead_block( $html, $block ) {
	return skyphusion_ai_lead_block( $html, $block );
}

/**
 * Compatibility wrapper for skyphusion_ai_lead_cache_key().
 *
 * @param mixed $question Passed through.
 * @return mixed Result of skyphusion_ai_lead_cache_key().
 */
function cloudflare_ai_search_lead_cache_key( $question ) {
	return skyphusion_ai_lead_cache_key( $question );
}

/**
 * Compatibility wrapper for skyphusion_ai_lead_cache_ttl().
 *
 * @return mixed Result of skyphusion_ai_lead_cache_ttl().
 */
function cloudflare_ai_search_lead_cache_ttl() {
	return skyphusion_ai_lead_cache_ttl();
}

/**
 * Compatibility wrapper for skyphusion_ai_lead_ceiling().
 *
 * @return mixed Result of skyphusion_ai_lead_ceiling().
 */
function cloudflare_ai_search_lead_ceiling() {
	return skyphusion_ai_lead_ceiling();
}

/**
 * Compatibility wrapper for skyphusion_ai_lead_max_length().
 *
 * @return mixed Result of skyphusion_ai_lead_max_length().
 */
function cloudflare_ai_search_lead_max_length() {
	return skyphusion_ai_lead_max_length();
}

/**
 * Compatibility wrapper for skyphusion_ai_lead_normalise().
 *
 * @param mixed $question Passed through.
 * @return mixed Result of skyphusion_ai_lead_normalise().
 */
function cloudflare_ai_search_lead_normalise( $question ) {
	return skyphusion_ai_lead_normalise( $question );
}

/**
 * Compatibility wrapper for skyphusion_ai_lead_take_slot().
 *
 * @return mixed Result of skyphusion_ai_lead_take_slot().
 */
function cloudflare_ai_search_lead_take_slot() {
	return skyphusion_ai_lead_take_slot();
}

/**
 * Compatibility wrapper for skyphusion_ai_lead_too_long().
 *
 * @param mixed $question Passed through.
 * @return mixed Result of skyphusion_ai_lead_too_long().
 */
function cloudflare_ai_search_lead_too_long( $question ) {
	return skyphusion_ai_lead_too_long( $question );
}

/**
 * Compatibility wrapper for skyphusion_ai_normalize_endpoint().
 *
 * @param mixed $url Passed through.
 * @return mixed Result of skyphusion_ai_normalize_endpoint().
 */
function cloudflare_ai_search_normalize_endpoint( $url ) {
	return skyphusion_ai_normalize_endpoint( $url );
}

/**
 * Compatibility wrapper for skyphusion_ai_part_text().
 *
 * @param mixed $content Passed through.
 * @return mixed Result of skyphusion_ai_part_text().
 */
function cloudflare_ai_search_part_text( $content ) {
	return skyphusion_ai_part_text( $content );
}

/**
 * Compatibility wrapper for skyphusion_ai_print_stock().
 *
 * @return mixed Result of skyphusion_ai_print_stock().
 */
function cloudflare_ai_search_print_stock() {
	return skyphusion_ai_print_stock();
}

/**
 * Compatibility wrapper for skyphusion_ai_query().
 *
 * @param mixed $question Passed through.
 * @return mixed Result of skyphusion_ai_query().
 */
function cloudflare_ai_search_query( $question ) {
	return skyphusion_ai_query( $question );
}

/**
 * Compatibility wrapper for skyphusion_ai_quiet_page().
 *
 * @return mixed Result of skyphusion_ai_quiet_page().
 */
function cloudflare_ai_search_quiet_page() {
	return skyphusion_ai_quiet_page();
}

/**
 * Compatibility wrapper for skyphusion_ai_read_answer().
 *
 * @param mixed $decoded Passed through.
 * @return mixed Result of skyphusion_ai_read_answer().
 */
function cloudflare_ai_search_read_answer( $decoded ) {
	return skyphusion_ai_read_answer( $decoded );
}

/**
 * Compatibility wrapper for skyphusion_ai_ready().
 *
 * @return mixed Result of skyphusion_ai_ready().
 */
function cloudflare_ai_search_ready() {
	return skyphusion_ai_ready();
}

/**
 * Compatibility wrapper for skyphusion_ai_register_settings().
 *
 * @return mixed Result of skyphusion_ai_register_settings().
 */
function cloudflare_ai_search_register_settings() {
	return skyphusion_ai_register_settings();
}

/**
 * Compatibility wrapper for skyphusion_ai_reject().
 *
 * @param mixed $code Passed through.
 * @param mixed $message Passed through.
 * @return mixed Result of skyphusion_ai_reject().
 */
function cloudflare_ai_search_reject( $code, $message ) {
	return skyphusion_ai_reject( $code, $message );
}

/**
 * Compatibility wrapper for skyphusion_ai_render_bar().
 *
 * @return mixed Result of skyphusion_ai_render_bar().
 */
function cloudflare_ai_search_render_bar() {
	return skyphusion_ai_render_bar();
}

/**
 * Compatibility wrapper for skyphusion_ai_render_footer().
 *
 * @return mixed Result of skyphusion_ai_render_footer().
 */
function cloudflare_ai_search_render_footer() {
	return skyphusion_ai_render_footer();
}

/**
 * Compatibility wrapper for skyphusion_ai_render_lead().
 *
 * @param mixed $query Passed through.
 * @param mixed $from_block Passed through.
 * @return mixed Result of skyphusion_ai_render_lead().
 */
function cloudflare_ai_search_render_lead( $query = null, $from_block = false ) {
	return skyphusion_ai_render_lead( $query, $from_block );
}

/**
 * Compatibility wrapper for skyphusion_ai_render_page().
 *
 * @return mixed Result of skyphusion_ai_render_page().
 */
function cloudflare_ai_search_render_page() {
	return skyphusion_ai_render_page();
}

/**
 * Compatibility wrapper for skyphusion_ai_render_product_links().
 *
 * @return mixed Result of skyphusion_ai_render_product_links().
 */
function cloudflare_ai_search_render_product_links() {
	return skyphusion_ai_render_product_links();
}

/**
 * Compatibility wrapper for skyphusion_ai_row_text().
 *
 * @param mixed $row Passed through.
 * @return mixed Result of skyphusion_ai_row_text().
 */
function cloudflare_ai_search_row_text( $row ) {
	return skyphusion_ai_row_text( $row );
}

/**
 * Compatibility wrapper for skyphusion_ai_sanitize().
 *
 * @param mixed $input Passed through.
 * @return mixed Result of skyphusion_ai_sanitize().
 */
function cloudflare_ai_search_sanitize( $input ) {
	return skyphusion_ai_sanitize( $input );
}

/**
 * Compatibility wrapper for skyphusion_ai_script_tag().
 *
 * @param mixed $tag Passed through.
 * @param mixed $handle Passed through.
 * @param mixed $src Passed through.
 * @return mixed Result of skyphusion_ai_script_tag().
 */
function cloudflare_ai_search_script_tag( $tag, $handle, $src ) {
	return skyphusion_ai_script_tag( $tag, $handle, $src );
}

/**
 * Compatibility wrapper for skyphusion_ai_section_appearance().
 *
 * @return mixed Result of skyphusion_ai_section_appearance().
 */
function cloudflare_ai_search_section_appearance() {
	return skyphusion_ai_section_appearance();
}

/**
 * Compatibility wrapper for skyphusion_ai_section_connection().
 *
 * @return mixed Result of skyphusion_ai_section_connection().
 */
function cloudflare_ai_search_section_connection() {
	return skyphusion_ai_section_connection();
}

/**
 * Compatibility wrapper for skyphusion_ai_section_display().
 *
 * @return mixed Result of skyphusion_ai_section_display().
 */
function cloudflare_ai_search_section_display() {
	return skyphusion_ai_section_display();
}

/**
 * Compatibility wrapper for skyphusion_ai_section_workers().
 *
 * @return mixed Result of skyphusion_ai_section_workers().
 */
function cloudflare_ai_search_section_workers() {
	return skyphusion_ai_section_workers();
}

/**
 * Compatibility wrapper for skyphusion_ai_settings().
 *
 * @return mixed Result of skyphusion_ai_settings().
 */
function cloudflare_ai_search_settings() {
	return skyphusion_ai_settings();
}

/**
 * Compatibility wrapper for skyphusion_ai_shortcode().
 *
 * @return mixed Result of skyphusion_ai_shortcode().
 */
function cloudflare_ai_search_shortcode() {
	return skyphusion_ai_shortcode();
}

/**
 * Compatibility wrapper for skyphusion_ai_snippet_allowed_html().
 *
 * @return mixed Result of skyphusion_ai_snippet_allowed_html().
 */
function cloudflare_ai_search_snippet_allowed_html() {
	return skyphusion_ai_snippet_allowed_html();
}

/**
 * Compatibility wrapper for skyphusion_ai_snippet_src().
 *
 * @param mixed $url Passed through.
 * @return mixed Result of skyphusion_ai_snippet_src().
 */
function cloudflare_ai_search_snippet_src( $url ) {
	return skyphusion_ai_snippet_src( $url );
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_allowed_html().
 *
 * @return mixed Result of skyphusion_ai_stock_allowed_html().
 */
function cloudflare_ai_search_stock_allowed_html() {
	return skyphusion_ai_stock_allowed_html();
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_attribute_text().
 *
 * @param mixed $product Passed through.
 * @return mixed Result of skyphusion_ai_stock_attribute_text().
 */
function cloudflare_ai_search_stock_attribute_text( $product ) {
	return skyphusion_ai_stock_attribute_text( $product );
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_block().
 *
 * @param mixed $html Passed through.
 * @param mixed $block Passed through.
 * @return mixed Result of skyphusion_ai_stock_block().
 */
function cloudflare_ai_search_stock_block( $html, $block ) {
	return skyphusion_ai_stock_block( $html, $block );
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_escape().
 *
 * @param mixed $value Passed through.
 * @return mixed Result of skyphusion_ai_stock_escape().
 */
function cloudflare_ai_search_stock_escape( $value ) {
	return skyphusion_ai_stock_escape( $value );
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_grouped_rows().
 *
 * @param mixed $product Passed through.
 * @return mixed Result of skyphusion_ai_stock_grouped_rows().
 */
function cloudflare_ai_search_stock_grouped_rows( $product ) {
	return skyphusion_ai_stock_grouped_rows( $product );
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_html().
 *
 * @param mixed $rows Passed through.
 * @return mixed Result of skyphusion_ai_stock_html().
 */
function cloudflare_ai_search_stock_html( $rows ) {
	return skyphusion_ai_stock_html( $rows );
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_labels().
 *
 * @return mixed Result of skyphusion_ai_stock_labels().
 */
function cloudflare_ai_search_stock_labels() {
	return skyphusion_ai_stock_labels();
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_line().
 *
 * @param mixed $row Passed through.
 * @param mixed $labels Passed through.
 * @return mixed Result of skyphusion_ai_stock_line().
 */
function cloudflare_ai_search_stock_line( $row, $labels = null ) {
	return skyphusion_ai_stock_line( $row, $labels );
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_markup().
 *
 * @return mixed Result of skyphusion_ai_stock_markup().
 */
function cloudflare_ai_search_stock_markup() {
	return skyphusion_ai_stock_markup();
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_max_variations().
 *
 * @return mixed Result of skyphusion_ai_stock_max_variations().
 */
function cloudflare_ai_search_stock_max_variations() {
	return skyphusion_ai_stock_max_variations();
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_plain().
 *
 * @param mixed $value Passed through.
 * @return mixed Result of skyphusion_ai_stock_plain().
 */
function cloudflare_ai_search_stock_plain( $value ) {
	return skyphusion_ai_stock_plain( $value );
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_price_text().
 *
 * @param mixed $product Passed through.
 * @return mixed Result of skyphusion_ai_stock_price_text().
 */
function cloudflare_ai_search_stock_price_text( $product ) {
	return skyphusion_ai_stock_price_text( $product );
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_quantity().
 *
 * @param mixed $product Passed through.
 * @param mixed $managing Passed through.
 * @return mixed Result of skyphusion_ai_stock_quantity().
 */
function cloudflare_ai_search_stock_quantity( $product, $managing ) {
	return skyphusion_ai_stock_quantity( $product, $managing );
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_quantity_text().
 *
 * @param mixed $quantity Passed through.
 * @return mixed Result of skyphusion_ai_stock_quantity_text().
 */
function cloudflare_ai_search_stock_quantity_text( $quantity ) {
	return skyphusion_ai_stock_quantity_text( $quantity );
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_row_from_product().
 *
 * @param mixed $product Passed through.
 * @param mixed $include_attributes Passed through.
 * @return mixed Result of skyphusion_ai_stock_row_from_product().
 */
function cloudflare_ai_search_stock_row_from_product( $product, $include_attributes ) {
	return skyphusion_ai_stock_row_from_product( $product, $include_attributes );
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_rows().
 *
 * @return mixed Result of skyphusion_ai_stock_rows().
 */
function cloudflare_ai_search_stock_rows() {
	return skyphusion_ai_stock_rows();
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_sentence().
 *
 * @param mixed $text Passed through.
 * @return mixed Result of skyphusion_ai_stock_sentence().
 */
function cloudflare_ai_search_stock_sentence( $text ) {
	return skyphusion_ai_stock_sentence( $text );
}

/**
 * Compatibility wrapper for skyphusion_ai_stock_variation_rows().
 *
 * @param mixed $product Passed through.
 * @return mixed Result of skyphusion_ai_stock_variation_rows().
 */
function cloudflare_ai_search_stock_variation_rows( $product ) {
	return skyphusion_ai_stock_variation_rows( $product );
}

/**
 * Compatibility wrapper for skyphusion_ai_token_source().
 *
 * @param mixed $env Passed through.
 * @param mixed $constant Passed through.
 * @param mixed $stored Passed through.
 * @return mixed Result of skyphusion_ai_token_source().
 */
function cloudflare_ai_search_token_source( $env, $constant, $stored ) {
	return skyphusion_ai_token_source( $env, $constant, $stored );
}

/**
 * Compatibility wrapper for skyphusion_ai_token_source_notice().
 *
 * @param mixed $key Passed through.
 * @return mixed Result of skyphusion_ai_token_source_notice().
 */
function cloudflare_ai_search_token_source_notice( $key ) {
	return skyphusion_ai_token_source_notice( $key );
}

/**
 * Compatibility wrapper for skyphusion_ai_token_source_now().
 *
 * @return mixed Result of skyphusion_ai_token_source_now().
 */
function cloudflare_ai_search_token_source_now() {
	return skyphusion_ai_token_source_now();
}

/*
 * Register the lead renderer and the block filter under the old
 * callback names. The new names are registered by frontend.php and
 * removed here, in the same load, before plugins_loaded. An older
 * consumer removes the old names. If both stayed hooked, the answer
 * would render twice.
 */
remove_action( 'woocommerce_before_shop_loop', 'skyphusion_ai_render_lead', 5 );
remove_action( 'loop_start', 'skyphusion_ai_render_lead', 5 );
remove_filter( 'render_block', 'skyphusion_ai_lead_block', 10 );
add_action( 'woocommerce_before_shop_loop', 'cloudflare_ai_search_render_lead', 5 );
add_action( 'loop_start', 'cloudflare_ai_search_render_lead', 5 );
add_filter( 'render_block', 'cloudflare_ai_search_lead_block', 10, 2 );
