<?php
/**
 * Record Cloudflare agent tools next to WooCommerce. Issue #92.
 *
 * The plugin does not open an MCP server. It does not install the
 * WordPress MCP Adapter. It does not upgrade WooCommerce. It does
 * not turn WebMCP on.
 */

require_once dirname(__DIR__) . '/includes/agent-tools.php';

$agent_root = dirname(__DIR__);
$agent_doc = (string) file_get_contents($agent_root . '/docs/agent-tools.md');
$agent_readme = (string) file_get_contents($agent_root . '/README.md');
$agent_readme_txt = (string) file_get_contents($agent_root . '/readme.txt');
$agent_plugin = (string) file_get_contents($agent_root . '/skyphusion-ai.php');
$agent_php = (string) file_get_contents($agent_root . '/includes/agent-tools.php');

expect('this release does not open an MCP server', skyphusion_ai_agent_tools_opens_mcp(), false);
expect('this plugin does not install the adapter', skyphusion_ai_agent_tools_installs_adapter(), false);
expect('this plugin does not upgrade WooCommerce', skyphusion_ai_agent_tools_upgrades_woocommerce(), false);
expect('this plugin does not turn WebMCP on', skyphusion_ai_agent_tools_webmcp_on(), false);
expect('checkout stays a person', skyphusion_ai_agent_tools_checkout_is_a_person(), true);
expect('the snippet does not hold a token', skyphusion_ai_agent_tools_snippet_holds_secret(), false);
expect('the provider id stays cloudflare', skyphusion_ai_agent_tools_provider_id(), 'cloudflare');

expect(
    'WooCommerce is the last of the four tools',
    skyphusion_ai_agent_tools_ids(),
    array('cloudflare_api_mcp', 'ai_search_mcp', 'webmcp', 'woocommerce_mcp')
);
expect('an unknown surface is an empty record', skyphusion_ai_agent_tools_surface('missing'), array());

$agent_api = skyphusion_ai_agent_tools_surface('cloudflare_api_mcp');
expect('the Cloudflare API MCP host is mcp.cloudflare.com', $agent_api['host'], 'mcp.cloudflare.com');
expect('the Cloudflare API MCP path is /mcp', $agent_api['path'], '/mcp');
expect('the Cloudflare API MCP tools are search, execute, and docs', $agent_api['tools'], array('search', 'execute', 'docs'));
expect('the Cloudflare API MCP does not change a cart', $agent_api['changes_cart'], false);
expect('the Cloudflare API MCP manages the account', $agent_api['manages_account'], true);
expect('the Cloudflare API MCP token stays out of the snippet', $agent_api['token_in_snippet'], false);
expect('the Cloudflare API MCP docs date is 2026-10-22', $agent_api['docs_updated'], '2026-10-22');

$agent_search = skyphusion_ai_agent_tools_surface('ai_search_mcp');
expect('AI Search MCP exposes one search tool', $agent_search['tools'], array('search'));
expect('AI Search MCP uses the /mcp path', $agent_search['path'], '/mcp');
expect('a namespace host starts with ns-', $agent_search['namespace_prefix'], 'ns-');
expect('AI Search MCP searches pages', $agent_search['searches_pages'], true);
expect('AI Search MCP does not change a cart', $agent_search['changes_cart'], false);
expect('a custom hostname must also turn the default host off', $agent_search['default_host_stays_open'], true);
expect('the AI Search MCP docs date is 2026-08-06', $agent_search['docs_updated'], '2026-08-06');

$agent_web = skyphusion_ai_agent_tools_surface('webmcp');
expect('WebMCP tools sit on document.modelContext', $agent_web['browser_object'], 'document.modelContext');
expect('WebMCP is experimental', $agent_web['experimental'], true);
expect('WebMCP began as a zone switch', $agent_web['zone_switch'], true);
expect('the WebMCP record is off', $agent_web['turned_on'], false);
expect('WebMCP checkout stays a person', $agent_web['checkout_is_a_person'], true);
expect('the WebMCP post is dated 2026-10-21', $agent_web['docs_updated'], '2026-10-21');

$agent_woo = skyphusion_ai_agent_tools_surface('woocommerce_mcp');
expect('WooCommerce MCP is not a Cloudflare product', $agent_woo['cloudflare_product'], false);
expect('WooCommerce 11.3 is the recorded release', $agent_woo['woocommerce'], '11.3');
expect('product and order abilities are the recorded pair', $agent_woo['abilities'], array('product', 'order'));
expect('the old WooCommerce path is the removed path', $agent_woo['removed_path'], '/wp-json/woocommerce/mcp');
expect('the adapter path is the recorded path', $agent_woo['path'], '/wp-json/mcp/mcp-adapter-default-server');
expect('the adapter is not installed from here', $agent_woo['installed_here'], false);
expect('WooCommerce is not upgraded from here', $agent_woo['upgraded_here'], false);
expect('WooCommerce MCP does not change a cart from this plugin', $agent_woo['changes_cart'], false);
expect('the WooCommerce announcement date is 2026-10-21', $agent_woo['announced'], '2026-10-21');

$agent_remote = skyphusion_ai_agent_tools_remote();
expect('the remote handler is createMcpHandler', $agent_remote['handler'], 'createMcpHandler');
expect('createMcpHandler is stateless', $agent_remote['stateless'], true);
expect('McpAgent is the older object', $agent_remote['older_object'], 'McpAgent');
expect('a remote MCP server is not a switch here', $agent_remote['is_a_switch'], false);
expect(
    'remote auth can be absent, Access, or OAuth',
    $agent_remote['auth'],
    array('absent', 'cloudflare_access', 'oauth')
);

$agent_later = skyphusion_ai_agent_tools_later();
expect('the later build is not this release', $agent_later['this_release'], false);
expect('the later build keeps the search box', $agent_later['keeps_search_box'], true);
expect('the later build uses AI Search for pages', $agent_later['page_search'], 'ai_search_mcp');
expect('cart and order tools stay WooCommerce abilities', $agent_later['cart_and_order'], 'woocommerce_abilities');
expect('the later build needs the adapter', $agent_later['needs_adapter'], true);
expect('the later build still leaves checkout to a person', $agent_later['checkout_is_a_person'], true);

$agent_gateway = skyphusion_ai_agent_tools_gateway();
expect('the gateway header is cf-aig-metadata', $agent_gateway['header'], 'cf-aig-metadata');
expect(
    'the gateway fields are site, feature, model, user, and version',
    $agent_gateway['fields'],
    array('site', 'feature', 'model', 'user', 'version')
);
expect('five gateway entries is the maximum', $agent_gateway['max_entries'], 5);
expect('count of gateway fields is the maximum', count($agent_gateway['fields']), 5);
expect('a gateway key that starts with cf. is reserved', $agent_gateway['reserved_prefix'], 'cf.');
expect('the gateway record does not send the prompt', $agent_gateway['sends_prompt'], false);
expect('the gateway host is gateway.ai.cloudflare.com', $agent_gateway['host'], 'gateway.ai.cloudflare.com');
expect('a direct call does not get the header', $agent_gateway['header_on_direct'], false);
expect(
    'gateway values are strings, numbers, or booleans',
    $agent_gateway['types'],
    array('string', 'number', 'boolean')
);

expect('the live gateway function is loaded', function_exists('skyphusion_ai_gateway_metadata'), true);
$agent_live = skyphusion_ai_gateway_metadata('text', '');
expect('the live gateway fields match the record', array_keys($agent_live), $agent_gateway['fields']);
expect('the live gateway row has no prompt', array_key_exists('prompt', $agent_live), false);

$agent_vendors = array();
foreach (skyphusion_ai_agent_tools_surfaces() as $agent_surface) {
    $agent_vendors[] = $agent_surface['vendor'];
    expect('no recorded tool changes a cart from this plugin', $agent_surface['changes_cart'], false);
}
expect(
    'the only vendors are Cloudflare and WooCommerce',
    $agent_vendors,
    array('cloudflare', 'cloudflare', 'cloudflare', 'woocommerce')
);

expect('the plugin loads the agent tool record', strpos($agent_plugin, "/includes/agent-tools.php'") !== false, true);
expect('the doc names the Cloudflare API MCP id', strpos($agent_doc, 'Cloudflare API MCP') !== false, true);
expect('the doc names the AI Search path', strpos($agent_doc, 'The path is `/mcp`') !== false, true);
expect('the doc names document.modelContext', strpos($agent_doc, 'document.modelContext') !== false, true);
expect('the doc names the adapter path', strpos($agent_doc, '/wp-json/mcp/mcp-adapter-default-server') !== false, true);
expect('the doc says this plugin does not turn WebMCP on', strpos($agent_doc, 'This plugin does not turn it on.') !== false, true);
expect('the doc says createMcpHandler', strpos($agent_doc, 'createMcpHandler()') !== false, true);
expect('the doc cites the Cloudflare API MCP page', strpos($agent_doc, 'https://developers.cloudflare.com/agents/model-context-protocol/cloudflare/servers-for-cloudflare/') !== false, true);
expect('the doc cites the AI Search MCP page', strpos($agent_doc, 'https://developers.cloudflare.com/ai-search/api/search/mcp/') !== false, true);
expect('the doc cites the WebMCP post', strpos($agent_doc, 'https://blog.cloudflare.com/webmcp-commerce/') !== false, true);
expect('the doc cites the WooCommerce advisory', strpos($agent_doc, 'https://developer.woocommerce.com/2026/10/21/woocommerce-11-3-mcp/') !== false, true);
expect('the doc cites the remote MCP guide', strpos($agent_doc, 'https://developers.cloudflare.com/agents/model-context-protocol/guides/remote-mcp-server/') !== false, true);
expect('the doc cites the gateway metadata page', strpos($agent_doc, 'https://developers.cloudflare.com/ai-gateway/observability/custom-metadata/') !== false, true);
expect('the plugin record does not embed a remote address', strpos($agent_php, 'https://') !== false, false);
expect('README points at the agent tools doc', strpos($agent_readme, 'docs/agent-tools.md') !== false, true);
expect('readme.txt points at the agent tools doc', strpos($agent_readme_txt, 'docs/agent-tools.md') !== false, true);
expect('the record does not name the shop host', strpos($agent_php . $agent_doc, 'ggc.sh') !== false, false);
expect('the plugin version is 0.6.2', strpos($agent_plugin, "define('SKYPHUSION_AI_VERSION', '0.6.2')") !== false, true);
