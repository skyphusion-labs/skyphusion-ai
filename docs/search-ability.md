# Search ability

The plugin registers one read-only ability.
The ability name is `skyphusion-ai/search`.
You do not register it again.
Activate this plugin.
The ability is present when WordPress provides the abilities API.

## Call it from a client

Install the WordPress MCP Adapter when a client should call the ability.
This plugin does not install the adapter.
The endpoint is `/wp-json/mcp/mcp-adapter-default-server`.
The client signs in as a WordPress user who can read.
A logged-out visitor is refused.

Send one field.
The field name is `query`.
The value is the question.

The result has two fields.
`answer` is the sentence.
`sources` is a list of page addresses.

The call uses the AI Search address saved in Settings, then Skyphusion AI.
The call sends no token.
The call does not change a cart.
Checkout stays a person.

## WebMCP

WebMCP can call the same endpoint.
The zone bridge uses the visitor session.
A logged-out visitor is refused.
This plugin does not turn WebMCP on.
