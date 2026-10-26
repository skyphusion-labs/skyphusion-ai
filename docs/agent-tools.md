# Agent tools

This plugin records four agent tools.
WooCommerce is the last one.
This release does not open an MCP server.
This plugin does not install the WordPress MCP Adapter.
This plugin does not upgrade WooCommerce.
This plugin does not turn WebMCP on.
Checkout stays a person.
The search snippet does not hold an API token.
The search snippet does not hold a WordPress application password.
The provider id stays `cloudflare`.

## Cloudflare API MCP

The address is `https://mcp.cloudflare.com/mcp`.
The tools are `search`, `execute`, and `docs`.
The model writes JavaScript.
That code runs in an isolated Dynamic Worker.
This tool manages the Cloudflare account.
It does not add a product to a cart.
Sign-in is OAuth, or a bearer API token for automation.
Do not put that token in the public search snippet.
The docs were updated on 2026-10-22.
Source: https://developers.cloudflare.com/agents/model-context-protocol/cloudflare/servers-for-cloudflare/

## AI Search MCP

The tool is `search`.
The path is `/mcp` on the instance host.
A namespace host starts with `ns-`.
The tool searches indexed pages.
It does not change a cart.
A hostname you own can sit behind Cloudflare Access.
Turn the default hostname off too.
Otherwise that hostname stays open.
The docs were updated on 2026-08-06.
Source: https://developers.cloudflare.com/ai-search/api/search/mcp/

## WebMCP

WebMCP is a Cloudflare edge script.
The script exposes tools on `document.modelContext`.
The script does not need a theme edit.
The shopper's browser runs the tool on the store domain.
The same tools can exist on a server MCP.
Checkout stays a person.
WebMCP is experimental.
It began as a switch for a whole zone.
This plugin does not turn it on.
The blog post is dated 2026-10-21.
Source: https://blog.cloudflare.com/webmcp-commerce/

## WooCommerce MCP

WooCommerce MCP is not a Cloudflare product.
WooCommerce 11.3 enables product abilities by default.
WooCommerce 11.3 enables order abilities by default.
That release was announced on 2026-10-21.
It removes `/wp-json/woocommerce/mcp`.
It removes the old REST key.
Clients use the WordPress MCP Adapter plugin.
Clients use a WordPress application password.
The path is `/wp-json/mcp/mcp-adapter-default-server`.
This plugin does not install the adapter.
This plugin does not upgrade WooCommerce.
Source: https://developer.woocommerce.com/2026/10/21/woocommerce-11-3-mcp/
Source: https://developer.woocommerce.com/docs/features/mcp/

## A remote MCP server

A new remote MCP server on Cloudflare uses `createMcpHandler()`.
That path is stateless.
`McpAgent` is the older Durable Object path.
Auth can be absent.
Auth can be Cloudflare Access.
Auth can be another OAuth provider.
That Worker is a new product.
It is not a switch in this plugin.
The docs were updated on 2026-10-22.
Source: https://developers.cloudflare.com/agents/model-context-protocol/guides/remote-mcp-server/

## A later build

The plugin already registers `skyphusion-ai/search`.
The steps are in docs/search-ability.md.
A later build waits until you ask for it.
That build keeps the search box.
An agent can use the AI Search `/mcp` search tool for indexed pages.
Cart tools belong to WooCommerce abilities.
Order tools belong to WooCommerce abilities.
Those tools sit behind the MCP Adapter.
The WordPress user has only the rights that client needs.
A WebMCP bridge can show those same tools in the browser.
The bridge needs the store endpoint.
The bridge needs a way to turn one store off.
Checkout stays a person.

## AI Gateway tags

AI Gateway can tag a request with the header `cf-aig-metadata`.
Values are strings, numbers, or booleans.
Five entries is the maximum.
Keys that start with `cf.` are reserved.
This plugin sends the site, the feature, the model, the user id, and the plugin version.
The user value is the numeric user id, or empty for a visitor.
The plugin does not send the prompt.
The plugin adds the header only on `gateway.ai.cloudflare.com`.
The plugin does not add the header on a direct `api.cloudflare.com` call.
The docs were updated on 2026-09-24.
Source: https://developers.cloudflare.com/ai-gateway/observability/custom-metadata/
