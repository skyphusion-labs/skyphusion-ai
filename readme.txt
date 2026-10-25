=== Skyphusion AI for Cloudflare ===
Contributors: skyphusion
Tags: search, cloudflare, ai, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Workers AI, AI Gateway, and AI Search for WordPress and WooCommerce.

== Description ==

This plugin connects your site to Cloudflare® AI Search. Open Settings, then Skyphusion AI. Paste your public endpoint address there. You can set the same options with `wp skyphusion-ai`.

Why this plugin was written: other plugin solutions did not meet our needs. We wanted other people to be able to harness the capabilities of AI Search and agents on their WordPress and WooCommerce site without paying a fee to get the harness. So here you go.

Visitors get a search box. They can also open the keyboard search with Ctrl+K or Command+K. You can turn on a chat button as well.

WordPress and WooCommerce call Workers AI through the AI Client. The provider id is `cloudflare`. Open Settings, then Connectors. Save the API token there. This plugin does not store the token.

Set the account id on the Skyphusion AI screen. Add a gateway id only when calls should go through AI Gateway. The screen also links to Workers AI, AI Gateway, and AI Search.

One switch prints an AI Search answer above WordPress and WooCommerce search results. The switch is off until you turn it on. Errors stay off the page.

A single product page also prints its stock as text. Each variation names its attributes, its SKU, its price, and its stock. A simple product names its stock. The text sits in the page itself. A crawler that does not run JavaScript can index it.

The same page adds the SKU, the stock status, and the managed stock count to the meta description. Rank Math and Yoast both receive that sentence. The AI Search dropdown quotes it. A product that does not manage stock gets the SKU and the status, and no count. You do not add a custom field for this sentence.

You can also add a stock_quantity number field on the instance. That field is a filter. It is not what the dropdown prints. The steps, including Wrangler, are in the FAQ.

This plugin does three jobs, and it does not charge a licence for any of them.

Search. The box is Cloudflare AI Search. The card shows the product image, the title, and the description. The image is the page image. You do not add a field for it. On a product page the description gains the SKU, the stock status, and the managed stock count. SearchWP, FiboSearch, Relevanssi, and YITH WooCommerce Ajax Search are keyword or instant search. They charge a licence. They are not this search.

Chat. You can turn on a chat button. It answers in a sentence. It is not a staff inbox, and it has no seats. You can expand that button with Cloudflare Workers AI or with Cloudflare AI Gateway. Workers AI is the provider on the AI Client. It covers text, vision, JSON, tools, images, speech, and embeddings. Leave the gateway id blank to call api.cloudflare.com. Set a gateway id to send those calls through gateway.ai.cloudflare.com. Tidio is a help desk. Its Starter plan was $24.17 a month when read on 2026-10-25, and its AI agent, Lyro, starts at $32.50 a month.

Agents. WordPress and WooCommerce call Workers AI through the AI Client. The provider covers text, vision, JSON, tools, images, and speech. Embeddings are included. You can expand those calls the same way, with Workers AI or with AI Gateway. This plugin does not edit posts. It does not open an MCP server in this release. An MCP server is planned. AI Engine is free, and AI Engine Pro starts at $69 a year for one site. Pro adds MCP. You still pay the model vendor there. Here the plugin fee is $0, and the usage is Cloudflare's.

The tables, the renewal figures, and the page each price came from are in the Compared section of README.md. Every price was read on 2026-10-25 from that vendor's own page.

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

= Does the plugin stop at a neuron budget? =

Yes. The default is 10,000 neurons a day, the free allowance. You can raise it under Settings, then Skyphusion AI. At that number, the next Workers AI call stays on the site until 00:00 UTC. The plugin mails the site admin at 80% and at 100%. The public search box and the chat button are not in the total. The AI Search rate limit is their cap.

= Does AI Gateway see which feature ran? =

Yes, when a gateway id is saved. Each Workers AI call sends the site, the feature, the model, and the user id. A visitor sends an empty user id. The prompt is not in that tag. A direct Workers AI call does not send the tag.

= Does the search answer show its sources? =

Yes. The search results page lists the pages under the sentence. A page with an address is a link. A page with no address stays as text. A failed search shows no sentence and no list.

= Can I choose the model? =

Yes. Settings, then Skyphusion AI, has a picker for writing, vision, image, speech, and embeddings. The plugin reads the models on your Cloudflare account and keeps that list for 12 hours. If the list cannot be read, the built-in models stay. An empty picker uses the built-in model for that job.

= Does the chat button cost extra? =

This plugin does not charge a subscription. Cloudflare bills the account that owns the AI Search instance. The search box only lists pages. The chat button answers in a sentence and uses more of that allowance. You can leave the chat button off. You can expand that button with Cloudflare Workers AI or with Cloudflare AI Gateway. The prices are in the Cost section of README.md.

= Does search show how many are in stock? =

Yes. The dropdown card shows the product image, the title, and the meta description. The image is the page image. You do not add a field for it. On a single product page the plugin adds the SKU, the stock status, and the managed stock count to that description. The card quotes the new description after the next crawl. A browser refresh does not start that crawl.

The search results page also prints the managed count under each product, for example "4 available." A product that does not manage stock prints the status and no count. A variable product prints a count only when the parent manages stock. Variation counts are not added together. A negative count is not printed. Zero is printed.

= How do I give AI Search the stock count? =

The dropdown does not need a new field. It already quotes the meta description. Install this plugin. Turn on stock management in WooCommerce and save a quantity when you want a number. Then crawl the site again.

In the Cloudflare dashboard, open the instance and choose Sync index. With Wrangler, in the account that owns the instance:

`npx wrangler ai-search jobs create your-instance`

Replace your-instance with the instance name. The namespace defaults to default. Then list the job:

`npx wrangler ai-search jobs list your-instance`

The job is not instant. A new job can be started every 30 seconds.

The stock_quantity meta tag is a separate filter. AI Search ignores that tag until you add the field. You add it in the Cloudflare account that owns the instance. The plugin cannot add it. You do not add it for the dropdown.

1. In WooCommerce, open the product. Turn on stock management. Enter the quantity. Save the product.
2. In the Cloudflare dashboard, go to AI, then AI Search, then your instance.
3. Open Parser options, then Custom metadata. These controls are in the instance settings.
4. Add a field. Set the name to stock_quantity. Set the type to number. Keep every field you already have.
5. Save. Cloudflare indexes the site again. This is not instant.

An instance allows five custom fields. This field uses one of them.

`npx wrangler ai-search update` does not set custom metadata. Do not use it to add this field. The create command does, and only for a new instance. Repeat every field you want to keep:

`npx wrangler ai-search create your-instance --custom-metadata title:text --custom-metadata description:text --custom-metadata image:text --custom-metadata stock_quantity:number`

Do not create a second instance to add this one field. On an instance you already have, use the dashboard or the API.

If you use the API, add `{"field_name": "stock_quantity", "data_type": "number"}` to custom_metadata with the fields you already have. Do not send that object as the only field. That removes the other fields.

The Wrangler commands are documented at https://developers.cloudflare.com/ai-search/wrangler-commands/ . That page was updated on 2026-07-08.

= Does this replace another AI provider? =

No. A caller that wants Cloudflare uses `using_provider('cloudflare')`. You can expand those calls with Workers AI or with AI Gateway. An MCP server is planned. This release does not open one.

== Changelog ==

= 0.5.3 =

* The comparison says the dropdown already shows the product image, with the title and the description. The image is the page image. You do not add a field for it. The description still carries the SKU, the stock status, and the managed count.
* The comparison says you can expand the chat button and the agent calls with Workers AI or with AI Gateway. An MCP server is planned. This release does not open one.

= 0.5.2 =

* A single product page adds the SKU, the stock status, and the managed stock count to the meta description. The AI Search dropdown quotes that description. A product that does not manage stock gets no count. A variable product prints a count only when the parent manages stock. Variation counts are not added together. A negative count is not printed.
* The readme says how to start the next crawl with Wrangler, and how to add the optional stock_quantity filter. The dropdown does not need that field. `wrangler ai-search update` does not set custom metadata.
* The readme compares this plugin with search plugins, with Tidio for chat, and with AI Engine for agents. This plugin is the search box, the chat button, and the Cloudflare provider on the AI Client. It does not charge a licence for those.

= 0.5.1 =

* The readme tells you how to add the stock_quantity field on your AI Search instance. You add that field in the Cloudflare account that owns the instance. The plugin already prints the meta tag. Nothing else changed.

= 0.5.0 =

* Search results print the managed stock count on each product. The sentence is the same "N available." line the product page already uses.
* A product that does not manage stock prints no count. A negative count is not printed. A variable product prints a count only when the parent manages stock. Variation counts are not added together on the search card.
* A single product page adds a stock_quantity meta tag. AI Search reads it only after the instance schema defines that number field. The search snippet still does not display the tag.

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
