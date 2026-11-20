# Search and other abilities

The plugin registers four read-only abilities.
The category id is `skyphusion-ai`.
You do not register them again.
Activate this plugin.
The abilities are present when WordPress provides the abilities API.

## Ability ids

- `skyphusion-ai/search`
- `skyphusion-ai/related`
- `skyphusion-ai/member-search`
- `skyphusion-ai/status`

Every ability is read-only.
None of them edit a post.
None of them change a cart.
This plugin does not open an MCP server.

## Call them from a client

Install the WordPress MCP Adapter when a client should call an ability.
This plugin does not install the adapter.
The endpoint is `/wp-json/mcp/mcp-adapter-default-server`.
The client signs in as a WordPress user who can read.
A logged-out visitor is refused.
The status ability also needs `manage_options`.

## skyphusion-ai/search

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

## skyphusion-ai/related

Send one field.
The field name is `post_id`.
The value is the post id.

The result has one field.
`pages` is a list of title, url, reason, and score.
The list comes from Vectorize.
Embeddings must be on.
A post that is not published returns an empty list.
A post with a password returns an empty list.
A post this user cannot read returns an empty list.
An unknown post returns that same empty list.
A match this user cannot read is left out.

## skyphusion-ai/member-search

Send one field.
The field name is `query`.
The value is the search words.

The result has two fields.
`state` is `ok`, or a closed state such as `closed`.
`pages` is a list of title and url.
Member search must be on for that role.

## skyphusion-ai/status

Send no fields.
The call does not reach Cloudflare.

The result names gateway id, host in use, fallback, route, prompt logging, neuron use, embed state, and the provider id.

## WebMCP

WebMCP can call the same endpoint.
The zone bridge uses the visitor session.
A logged-out visitor is refused.
This plugin does not turn WebMCP on.
