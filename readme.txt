=== Skyphusion AI for Cloudflare ===
Contributors: skyphusion
Tags: search, cloudflare, ai, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Workers AI, AI Gateway, and AI Search for WordPress and WooCommerce.

== Description ==

This plugin connects your site to Cloudflare® AI Search. Open Settings, then Skyphusion AI. Paste your public endpoint address there. You can set the same options with `wp skyphusion-ai`.

Visitors get a search box. They can also open the keyboard search with Ctrl+K or Command+K. You can turn on a chat button as well.

WordPress and WooCommerce call Workers AI through the AI Client. The provider id is `cloudflare`. Open Settings, then Connectors. Save the API token there. This plugin does not store the token.

Set the account id on the Skyphusion AI screen. Add a gateway id only when calls should go through AI Gateway. The screen also links to Workers AI, AI Gateway, and AI Search.

One switch prints an AI Search answer above WordPress and WooCommerce search results. The switch is off until you turn it on. Errors stay off the page.

A single product page also prints its stock as text. Each variation names its attributes, its SKU, its price, and its stock. A simple product names its stock. The text sits in the page itself. A crawler that does not run JavaScript can index it.

Shortcode: `[cloudflare_ai_search]`

Example:

`wp_ai_client_prompt( 'Write a product summary.' )->using_provider( 'cloudflare' );`

Other plugins can call `cloudflare_ai_provider_embeddings()` and `skyphusion_ai_query()`. `cloudflare_ai_search_query()` still calls the same helper. The search helper posts to the public chat/completions path. It does not send a token.

Cloudflare, the Cloudflare logo, and Cloudflare Workers are trademarks and/or registered trademarks of Cloudflare, Inc. in the United States and other jurisdictions. This plugin is an independent work. Cloudflare, Inc. does not sponsor or endorse it.

== External services ==

AI Search. The plugin loads a JavaScript file from your own AI Search instance. Your browser runs that file. The file is search-snippet.es.js, pinned to snippet build 0.0.25. The plugin builds the address from the public endpoint you paste. It accepts only an https address on search.ai.cloudflare.com. It rejects any path, query, fragment or user information in that address. It checks the address again every time it reads the setting. The plugin loads no script until you paste an endpoint. It also loads none until you turn on the search box, keyboard search or chat. The connection check posts to /search. The search helper posts to /chat/completions. The optional search-results answer posts there as well. None of these requests send an API token.

Workers AI and AI Gateway. The plugin sends prompts, images, speech text, and embedding texts to api.cloudflare.com. It sends them to gateway.ai.cloudflare.com instead when you set a gateway id. Each request carries the Connectors API token as a bearer token. The account id and the gateway id are not secrets.

== Installation ==

1. Upload the plugin and activate it.
2. In Cloudflare, turn on the public endpoint for your AI Search instance.
3. In WordPress, open Settings, then Skyphusion AI. Paste that address.
4. Paste the account id.
5. Open Settings, then Connectors. Save the Workers AI API token for Cloudflare.

== Frequently Asked Questions ==

= Where do I get the endpoint address? =

Open the Cloudflare dashboard. Go to AI Search, then your instance, then Settings, then Public endpoint. Copy the address that ends in search.ai.cloudflare.com. The Skyphusion AI screen links there.

= Where does the API token go? =

Save it in Settings, then Connectors. The plugin reads the `CLOUDFLARE_API_KEY` environment variable or constant first. You can put the account id in front of the token as `account-id:api-token`. This plugin's own option never stores the token. The Skyphusion AI screen says which source is in use. It says when the saved token is not being used. It does not show the token.

= Does the chat button cost extra? =

The search box only lists pages. The chat button answers in a sentence. It uses your Workers AI allowance. You can leave the chat button off.

= Does this replace another AI provider? =

No. A caller that wants Cloudflare uses `using_provider('cloudflare')`.

== Changelog ==

= 0.4.1 =

* The plugin directory is now `skyphusion-ai`, matching the slug the plugin is submitted under. Earlier releases installed to `cloudflare-ai-search`.
* Upgrading from 0.4.0 or earlier: install this release, then remove the old `cloudflare-ai-search` directory from disk. Do NOT use "Delete" in the plugins screen for the old copy, because that runs its uninstall routine and drops the saved settings. Settings live in the database and are not tied to the directory, so a disk removal keeps them.

= 0.4.0 =

* Every shipped function is named `skyphusion_ai_*`. The old `cloudflare_ai_*` names keep working through shims, so existing snippets and child themes do not break.
* Settings carry forward once on update, so a stored configuration survives the rename instead of reverting to defaults.
* A rejected setting now says which submitted value was rejected and what was kept, instead of failing silently.
* User-facing strings are translatable, and the translation surface is gated so an untranslated string is a test failure rather than a surprise.
* `wp skyphusion-ai` accepts `--accent-color`; `--color` keeps working.
* A variable product no longer reports its parent stock pool as a variation quantity.
* Cloudflare trademark attribution and first-use designation, per Cloudflare's published guidelines.

= 0.3.3 =

* Grouped products print their children, so AI Search can index them.
* An unknown product type prints a row for itself instead of nothing.
* A malformed product no longer raises a PHP warning on every page view.

= 0.3.2 =

* The search-results answer caps question length, caches answers, and limits calls per window.
* A repeated question costs no model call and no worker time.
* A failed or empty answer is cached for five minutes.

= 0.3.1 =

* The plugin documentation uses Simplified Technical English.
* No code changed in this release.

= 0.3.0 =
* Product pages print variation and stock text as server-rendered HTML inside main, so AI Search can index it without browser rendering.
* Each variation row names its attributes, its SKU when it has one, its price and its stock status. Out of stock rows are kept.
* A negative stock count prints its status without the number.
* The number of variation rows is capped. The filter cloudflare_ai_search_stock_max_variations changes the cap.
* Development files are kept out of distributed archives by .distignore and by .gitattributes export-ignore.

= 0.2.2 =
* The text domain is skyphusion-ai, matching the permalink to request. Saved settings stay on the same option.
* Search snippet markup is escaped before it is printed. Workers AI error text is escaped before it is thrown.
* A direct request for the WP-CLI file exits.

= 0.2.1 =
* The plugin name is Skyphusion AI for Cloudflare. The author is Conrad Rockenhaus (skyphusion).
* On a block theme, the search-results answer is inserted in the results block so it stays inside the document.
* Direct requests for the endpoint and route files exit before they define anything.

= 0.2.0 =
* Register a WordPress AI Client provider for Workers AI text, vision, images, and speech, through AI Gateway when a gateway id is set.
* Link Workers AI, AI Gateway, AI Search, and Connectors from the settings screen.
* Add embeddings and public AI Search helpers for WordPress and WooCommerce.
* Optional AI Search answer above search results, off by default. The helper asks /chat/completions and reads chunks as sources. Search feeds and embeds do not print that answer.

= 0.1.0 =
* Settings screen, WP-CLI command, search box, keyboard search, and optional chat button.
