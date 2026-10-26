<?php
/**
 * Record Cloudflare agent tools next to WooCommerce.
 *
 * This file records the four surfaces. It does not open an MCP server.
 * It does not install the WordPress MCP Adapter. It does not upgrade
 * WooCommerce. It does not turn WebMCP on. Checkout stays a person.
 *
 * @package skyphusion-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This release does not open an MCP server.
 *
 * @return bool
 */
function skyphusion_ai_agent_tools_opens_mcp() {
	return false;
}

/**
 * This plugin does not install the WordPress MCP Adapter.
 *
 * @return bool
 */
function skyphusion_ai_agent_tools_installs_adapter() {
	return false;
}

/**
 * This plugin does not upgrade WooCommerce.
 *
 * @return bool
 */
function skyphusion_ai_agent_tools_upgrades_woocommerce() {
	return false;
}

/**
 * This plugin does not turn WebMCP on.
 *
 * @return bool
 */
function skyphusion_ai_agent_tools_webmcp_on() {
	return false;
}

/**
 * Checkout stays a person.
 *
 * @return bool
 */
function skyphusion_ai_agent_tools_checkout_is_a_person() {
	return true;
}

/**
 * The search snippet does not hold a token.
 *
 * @return bool
 */
function skyphusion_ai_agent_tools_snippet_holds_secret() {
	return false;
}

/**
 * The only provider id this plugin registers.
 *
 * @return string
 */
function skyphusion_ai_agent_tools_provider_id() {
	return 'cloudflare';
}

/**
 * Cloudflare API MCP. It manages the account. It does not change a cart.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_agent_tools_cloudflare_api() {
	return array(
		'id'               => 'cloudflare_api_mcp',
		'name'             => 'Cloudflare API MCP',
		'vendor'           => 'cloudflare',
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- recorded host, not a remote asset.
		'host'             => 'mcp.cloudflare.com',
		'path'             => '/mcp',
		'docs_updated'     => '2026-10-22',
		'tools'            => array( 'search', 'execute', 'docs' ),
		'changes_cart'     => false,
		'manages_account'  => true,
		'auth'             => 'oauth_or_bearer',
		'token_in_snippet' => false,
	);
}

/**
 * AI Search MCP. The tool searches indexed pages. It does not change a cart.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_agent_tools_ai_search() {
	return array(
		'id'                      => 'ai_search_mcp',
		'name'                    => 'AI Search MCP',
		'vendor'                  => 'cloudflare',
		'path'                    => '/mcp',
		'namespace_prefix'        => 'ns-',
		'docs_updated'            => '2026-08-06',
		'tools'                   => array( 'search' ),
		'changes_cart'            => false,
		'searches_pages'          => true,
		'default_host_stays_open' => true,
	);
}

/**
 * WebMCP. The record is off. The browser object is document.modelContext.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_agent_tools_webmcp() {
	return array(
		'id'                   => 'webmcp',
		'name'                 => 'WebMCP',
		'vendor'               => 'cloudflare',
		'docs_updated'         => '2026-10-21',
		'browser_object'       => 'document.modelContext',
		'experimental'         => true,
		'zone_switch'          => true,
		'turned_on'            => false,
		'checkout_is_a_person' => true,
		'changes_cart'         => false,
	);
}

/**
 * WooCommerce MCP. It is not a Cloudflare product. This plugin does not install it.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_agent_tools_woocommerce() {
	return array(
		'id'                 => 'woocommerce_mcp',
		'name'               => 'WooCommerce MCP',
		'vendor'             => 'woocommerce',
		'cloudflare_product' => false,
		'announced'          => '2026-10-21',
		'woocommerce'        => '11.3',
		'abilities'          => array( 'product', 'order' ),
		'removed_path'       => '/wp-json/woocommerce/mcp',
		'path'               => '/wp-json/mcp/mcp-adapter-default-server',
		'adapter_plugin'     => 'mcp-adapter',
		'installed_here'     => false,
		'upgraded_here'      => false,
		'changes_cart'       => false,
	);
}

/**
 * The four surfaces, with WooCommerce last.
 *
 * @return array<int, array<string, mixed>>
 */
function skyphusion_ai_agent_tools_surfaces() {
	return array(
		skyphusion_ai_agent_tools_cloudflare_api(),
		skyphusion_ai_agent_tools_ai_search(),
		skyphusion_ai_agent_tools_webmcp(),
		skyphusion_ai_agent_tools_woocommerce(),
	);
}

/**
 * Surface ids, in record order.
 *
 * @return array<int, string>
 */
function skyphusion_ai_agent_tools_ids() {
	$ids = array();
	foreach ( skyphusion_ai_agent_tools_surfaces() as $surface ) {
		if ( isset( $surface['id'] ) && is_string( $surface['id'] ) ) {
			$ids[] = $surface['id'];
		}
	}
	return $ids;
}

/**
 * One recorded surface, or an empty array.
 *
 * @param string $id Surface id.
 * @return array<string, mixed>
 */
function skyphusion_ai_agent_tools_surface( $id ) {
	$want = (string) $id;
	foreach ( skyphusion_ai_agent_tools_surfaces() as $surface ) {
		if ( isset( $surface['id'] ) && $want === $surface['id'] ) {
			return $surface;
		}
	}
	return array();
}

/**
 * A new remote MCP server is not a switch in this plugin.
 *
 * The stateless path is createMcpHandler. The older path is McpAgent.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_agent_tools_remote() {
	return array(
		'handler'      => 'createMcpHandler',
		'stateless'    => true,
		'older_object' => 'McpAgent',
		'docs_updated' => '2026-10-22',
		'is_a_switch'  => false,
		'auth'         => array( 'absent', 'cloudflare_access', 'oauth' ),
	);
}

/**
 * A later build waits until someone asks for it.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_agent_tools_later() {
	return array(
		'this_release'         => false,
		'keeps_search_box'     => true,
		'page_search'          => 'ai_search_mcp',
		'cart_and_order'       => 'woocommerce_abilities',
		'needs_adapter'        => true,
		'checkout_is_a_person' => true,
		'webmcp_per_store_off' => true,
	);
}

/**
 * Gateway tag rules recorded next to the agent tools.
 *
 * The plugin adds the header only on the gateway host. The prompt is not a field.
 *
 * @return array<string, mixed>
 */
function skyphusion_ai_agent_tools_gateway() {
	return array(
		'header'           => 'cf-aig-metadata',
		'fields'           => array( 'site', 'feature', 'model', 'user', 'version' ),
		'types'            => array( 'string', 'number', 'boolean' ),
		'max_entries'      => 5,
		'reserved_prefix'  => 'cf.',
		'sends_prompt'     => false,
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- AI Gateway API host, not a remote asset.
		'host'             => 'gateway.ai.cloudflare.com',
		'direct_host'      => 'api.cloudflare.com',
		'header_on_direct' => false,
		'docs_updated'     => '2026-09-24',
	);
}
