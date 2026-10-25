# Skyphusion AI for Cloudflare

WordPress plugin for Cloudflare® [Workers AI](https://developers.cloudflare.com/workers-ai/), [AI Gateway](https://developers.cloudflare.com/ai-gateway/), and [AI Search](https://developers.cloudflare.com/ai-search/).

Why this plugin was written: other plugin solutions did not meet our needs. We wanted other people to be able to harness the capabilities of AI Search and agents on their WordPress and WooCommerce site without paying a fee to get the harness. So here you go.

The plugin does three jobs. Visitors get a search box and, if you turn it on, a chat button. WordPress and WooCommerce get a Cloudflare provider on the AI Client, so other code can call text, vision, JSON, tools, images, speech, and embeddings without a plugin licence. You can expand the chat button and those agent calls with Cloudflare Workers AI or with Cloudflare AI Gateway. An MCP server is planned. This release does not open one. The comparison below is those three jobs against the plugins that charge for each one.

Visitors get a search box. They can also open the keyboard search with Ctrl+K or Command+K. You can turn on a chat button as well. You connect search from **Settings, then Skyphusion AI**. You can set the same options with WP-CLI.

WordPress and WooCommerce call Workers AI through the WordPress AI Client. The provider id is `cloudflare`. That provider covers text, vision, JSON, tools, images, and speech. Plugin code can also call embeddings and the public AI Search endpoint. This plugin does not take over another provider that is already set up. A caller that wants Cloudflare uses `using_provider('cloudflare')`.

The search box does not send an API token. Workers AI reads the `CLOUDFLARE_API_KEY` environment variable first. It then tries a PHP constant of the same name. It falls back to the token from **Settings, then Connectors**. This plugin does not store the token. The Skyphusion AI screen says which source is in use. It says when the saved token is not being used. It does not show the token.

The license is GPL-2.0-or-later. The plugin needs WordPress 6.4 and PHP 8.0. The AI Client provider registers on WordPress 7.0 and later. It needs the text and image generation interfaces to be present. Search and the embeddings helper still run without those interfaces.

## Connect a site

1. In the Cloudflare dashboard, create an AI Search instance that crawls the site. Turn on the public endpoint.
2. In WordPress, open **Settings, then Skyphusion AI**.
3. Paste the endpoint address (`https://something.search.ai.cloudflare.com/`) and save.
4. Choose the search box, the keyboard search, and the chat button. Save again.
5. Press **Check connection**.

The same screen links to Workers AI, AI Gateway, and AI Search. It links to both the dashboard and the docs. Paste the account id there. The account id is 32 hex characters.

Leave the gateway blank to call `api.cloudflare.com` directly. Set a gateway id to send model calls through `gateway.ai.cloudflare.com` instead.

Then open **Settings, then Connectors**. Save the Workers AI API token for the Cloudflare provider. You can also put the account id in front of the token as `account-id:api-token`. Only the token half is sent. The plugin accepts `CLOUDFLARE_ACCOUNT_ID` from the environment, then from a PHP constant, when the setting is empty.

Put the search box in one spot with the shortcode `[cloudflare_ai_search]`. Then set placement to "Only where I place the shortcode".

Search results stay as WordPress or WooCommerce left them by default. They change once you turn on **Show an AI Search answer above WordPress and WooCommerce search results**. That switch is off by default. Visitors never see a failed request. The search results page lists the pages under the sentence. A page with an address is a link. A page with no address stays as text. The cart and checkout stay quiet when that option is on. The settings screen and `wp skyphusion-ai status` show how many searches used AI Search or the site results.

A published post can be stored in Cloudflare Vectorize. The settings screen shows the neuron estimate before the first run. A post that did not change is not sent again. A password-protected post is left out. Deleting the plugin removes the saved choice and leaves the index in the Cloudflare account.

A single post or product can list related pages. The list uses the stored vectors. A page view does not call the model. The settings screen has a switch for this list. The shortcode is `[skyphusion_ai_related]`.

The settings screen lists searches that found no page. The words stay in this site's database. An IP address is not stored. A repeat from the same visitor within one hour is skipped. Rows older than 90 days are removed.

A site assistant can answer from the stored pages and list those pages. It is off until you turn it on. The chat button stays as it is. A saved chat needs the visitor to agree. An IP address is not stored. The assistant stops when the daily neuron budget is spent.

An hourly job can fill empty alt text and an empty SEO description. It is off until you turn it on. It does not replace text you wrote. It writes the field used by Yoast, Rank Math, All in One SEO, or SEOPress. It stops when the daily neuron budget is spent.

A theme or plugin can translate one string with `skyphusion_ai_translate()`. Pass a two letter language code. The call stops when the daily neuron budget is spent.

If the box does not appear, check the site's content security policy. It has to allow `https://*.search.ai.cloudflare.com` for scripts and connections.

## WordPress and WooCommerce

```php
wp_ai_client_prompt( 'Write a product summary.' )->using_provider( 'cloudflare' );

$vectors = cloudflare_ai_provider_embeddings( array( 'linen shirt' ) );

$answer = skyphusion_ai_query( 'shipping times' );
```

`cloudflare_ai_provider_embeddings()` returns a list of vectors, or a `WP_Error`. That error message never includes the token. The model is `@cf/baai/bge-large-en-v1.5`.

`skyphusion_ai_query()` posts to the public `{endpoint}/chat/completions` address. It returns `answer` and `sources`, or a `WP_Error`. It does not send a token. `{endpoint}/search` returns pages and no sentence. The connection check uses that path. `cloudflare_ai_search_query()` still calls the same helper.

WooCommerce code that calls `wp_ai_client_prompt()` can use the same provider. The shop already shows the search box when the public endpoint is set.

A single product page also prints its stock as text. Each variation names its attributes, its SKU, its price, and its stock. A simple product names its stock. The text sits in the page itself. A crawler that does not run JavaScript can index it.

Out-of-stock and hidden variations are included, because the index has to see them. The plugin reads the rows with `get_children()` rather than `get_available_variations()`. That second method drops those rows when the catalog hides them.

The plugin prints the section inside `main`, once per request. On a block theme it sits next to the add to cart form. On a classic theme it goes on `woocommerce_single_product_summary`. The section does not turn on browser rendering, image indexing, OCR, or query rewriting.

The plugin reads at most 100 variations per page, because each row costs one product load. Raise the cap on a store that needs every row indexed:

```php
add_filter( 'cloudflare_ai_search_stock_max_variations', fn() => 400 );
```

The visible strings go through `__()` and `_n()` on the `skyphusion-ai` text domain. Those strings are `Stock`, `In stock.`, `Out of stock.`, `On backorder.`, `SKU %s.` and the unit count. The `cloudflare-ai-search-stock` class name does not, because a stylesheet and a crawler match on it.

Search results print that same count on each product. The line is `N available.` It is printed only when the product manages stock and the count is a whole number of zero or more. A product that does not manage stock prints nothing. A negative count is not printed. The class is `cloudflare-ai-search-stock-count`.

A variable product that manages stock at the parent uses the parent count. When each variation manages its own stock, the search card does not add those counts together. That total is not the quantity of any one variation. The product page already lists each variation with its own count.

The search box is Cloudflare's snippet. The card shows the product image, the title, and the product page meta description. The image is the page image. The snippet already quotes it. You do not add a field for the image. This plugin does not draw the dropdown, and the snippet does not draw its own stock line. On a single product page this plugin adds the SKU, the stock status, and the managed stock count to that description. Rank Math and Yoast both receive it. A product that does not manage stock gets the SKU and the status, and no count. A variable product adds a count only when the parent manages stock. Variation counts are not added together. A negative count is not printed. Zero is printed.

The dropdown shows the new sentence after the next crawl. A browser refresh does not start that crawl. You do not add a custom field for this sentence. The description is already one of the fields the snippet quotes.

A single product page prints `<meta name="stock_quantity" content="N">` in the page head. The plugin prints that tag only when the product manages stock and the count is a whole number of zero or more. A product that does not manage stock prints no tag.

AI Search does not read the tag until you add the field on the instance. You do this in the Cloudflare account that owns the instance. The plugin cannot do it. The plugin has no token for that account.

1. In WooCommerce, open the product. Turn on stock management. Enter the quantity. Save the product.
2. In the Cloudflare dashboard, go to AI, then AI Search, then your instance.
3. Open Parser options, then Custom metadata. These controls are in the instance settings.
4. Add a field. Set the name to `stock_quantity`. Set the type to `number`. Keep every field you already have.
5. Save. Cloudflare indexes the site again. This is not instant.

An instance allows five custom fields. This field uses one of them. The name is stored in lowercase. Do not use the names `timestamp`, `folder`, or `filename`.

The search box does not show this field. The field is a filter on the search API. The SKU, the status, and the count that the dropdown quotes are in the meta description, not in this field. The product page also prints the same facts as text inside `main`.

If you set the field with the API, add this object to `custom_metadata` together with the fields you already have:

```json
{ "field_name": "stock_quantity", "data_type": "number" }
```

Do not send this object as the only field. That removes the other fields. Cloudflare documents the control at https://developers.cloudflare.com/ai-search/configuration/indexing/metadata/ and the page tag at https://developers.cloudflare.com/ai-search/configuration/data-source/website/custom-metadata/.

### Crawl the new sentence

Install this plugin, then crawl the site again. The dropdown keeps the old sentence until that crawl finishes.

In the Cloudflare dashboard, open the instance and choose Sync index. The same job from Wrangler, logged into the account that owns the instance:

```bash
npx wrangler ai-search jobs create your-instance
npx wrangler ai-search jobs list your-instance
```

Replace `your-instance` with the instance name. The namespace defaults to `default`. The job is not instant. A new job can be started every 30 seconds. The commands are documented at https://developers.cloudflare.com/ai-search/wrangler-commands/ (that page was updated on 2026-07-08).

### Wrangler and the stock filter

`npx wrangler ai-search update` does not set custom metadata. Do not use it to add `stock_quantity`.

The create command does, and only when you are creating the instance. Repeat every field you want to keep. Five is the maximum.

```bash
npx wrangler ai-search create your-instance --custom-metadata title:text --custom-metadata description:text --custom-metadata image:text --custom-metadata stock_quantity:number
```

Do not create a second instance to add this one field. On an instance you already have, use the dashboard or the API object above. You still do not need this field for the dropdown.

The default text model is `@cf/meta/llama-3.3-70b-instruct-fp8-fast`. A prompt that includes an image uses `@cf/mistralai/mistral-small-3.1-24b-instruct` first. Settings, then Skyphusion AI, has one picker for writing, vision, image, speech, and embeddings. The plugin reads the account's model list and keeps that list for 12 hours. A failed read keeps these built-in ids. An empty picker uses the built-in id for that job. A model id that is not in the built-in list and not on the account list is refused with the same catalog error as before. `@cf/meta/llama-3.2-11b-vision-instruct` needs a one-time `{"prompt":"agree"}` request on that model before it will answer. It does not take tools or JSON.

The default image model is `@cf/black-forest-labs/flux-1-schnell`, and it takes a JSON prompt. FLUX.2 dev and FLUX.2 klein 4B are also listed. Their docs say multipart. This plugin still sends a JSON prompt. So only those two fail if Cloudflare rejects it.

Speech is `@cf/deepgram/aura-1` (MP3). Voices: angus, asteria, arcas, orion, orpheus, athena, luna, zeus, perseus, helios, hera, stella.

## Command line

`wp skyphusion-ai` writes the same option the dashboard saves. A later save in **Settings, then Skyphusion AI** shows those values.

```bash
wp skyphusion-ai status
wp skyphusion-ai set --endpoint=https://abc.search.ai.cloudflare.com/ --accent-color=#1B3A32 --chat=0
wp skyphusion-ai set --account-id=0123456789abcdef0123456789abcdef --gateway-id=store --lead-search=0
```

Flags:

- `--endpoint`, `--placeholder`, `--accent-color`, `--max-results`
- `--search-bar`, `--modal`, `--chat`, `--hide-checkout`, `--hide-branding`, `--lead-search`
- `--placement`, which takes `top` or `shortcode`
- `--theme`, which takes `auto`, `light`, or `dark`
- `--account-id`, `--gateway-id`

Use `0` or `1` for the on and off flags. Omitted flags stay as they are.

`--accent-color` sets the accent colour. `--color` is a deprecated alias and still works. WP-CLI reserves `--color` as a global flag and consumes it, so the plugin reads that one from the raw arguments. Pass `--accent-color` instead and WP-CLI parses it normally. Passing both uses `--accent-color`.

The search box only lists pages. The chat button answers in a sentence. It uses more of the Cloudflare allowance. Leave `--chat=0` unless that cost is accepted.

## Cost

This plugin does not charge a price. There is no subscription. The figures below are Cloudflare's, read 2026-10-25 from the pages linked at the end of this section. They are the marginal cost of search on the account that owns the AI Search instance. Confirm them on those pages before you budget, because Cloudflare changes them.

AI Search billing begins on 2026-11-01. Until that date the meters below are not billed. After that date every account includes, each month, 5 million ingestion tokens, 10 GB-month of storage, 1,000 semantic queries, and 1,000 full-text queries. Image-processing tokens share the ingestion allotment.

The derivation for a small catalog: indexing a few dozen product pages is far under 5 million tokens and under 10 GB. One thousand semantic queries a month is the included search allowance. A shop that stays inside those four caps adds $0 of AI Search usage. A larger catalog or a busier search pays the rates in the table. Embedding and reranking inside AI Search are included. They do not also appear on the Workers AI bill. Generation does. The chat button and the search-results answer are generation. Query rewriting and any external model are generation too.

Workers AI is priced at $0.011 per 1,000 Neurons, with 10,000 Neurons per day included on the Free plan and on the Paid plan. The text model this plugin uses by default is `@cf/meta/llama-3.3-70b-instruct-fp8-fast`, at $0.293 per million input tokens and $2.253 per million output tokens. The embeddings helper uses `@cf/baai/bge-large-en-v1.5` at $0.204 per million input tokens. That embeddings call is Workers AI. It is separate from the embeddings AI Search runs while it indexes.

The plugin stops Workers AI at a daily neuron budget. The default is 10,000 neurons. Settings, then Skyphusion AI, can raise it. The settings screen and the admin bar show today's total. The plugin mails the site admin once at 80% and once at 100%, each UTC day. At the budget, the next Workers AI call stays on the site until 00:00 UTC. The public search box and the chat button are not in this total. PHP does not see those calls. The AI Search instance rate limit remains their cap. A search-results answer is counted when Cloudflare reports neurons or tokens. A missing figure does not stop that answer. The call ceiling on that answer stays.

| meter | included | this plugin | marginal |
| --- | --- | --- | --- |
| Plugin price | | no subscription | $0 |
| AI Search ingestion | 5 million tokens / month | a crawl of the catalog | $0.75 / million tokens past the cap |
| AI Search image processing | shares the ingestion cap | off unless you enable OCR | $0.50 / million tokens extra |
| AI Search storage | 10 GB-month | the indexed pages | $2.00 / GB-month past the cap |
| AI Search semantic, vector, or hybrid query | 1,000 / month | one per search | $0.75 / 1,000 past the cap |
| AI Search full-text query | 1,000 / month | one per keyword search | $0.10 / 1,000 past the cap |
| Workers AI Neurons | 10,000 / day | chat and the search-results answer | $0.011 / 1,000 past the daily cap |
| Default text model | the Neuron cap above | `@cf/meta/llama-3.3-70b-instruct-fp8-fast` | $0.293 / $2.253 per million input / output tokens |
| Embeddings helper | the Neuron cap above | `@cf/baai/bge-large-en-v1.5` | $0.204 per million input tokens |

**At rest, with no crawl and no query, and with the index inside the included 10 GB, the AI Search marginal cost is $0.** The search box sends no API token. The token for Workers AI stays in the site owner's Cloudflare account. Nothing is sent to Skyphusion.

## Compared with other plugins

Prices below were read on 2026-10-25 from each vendor's own page. They are introductory or listed prices, in the currency that page uses, and they renew on that vendor's terms. Confirm them before you budget.

This plugin is the harness for three jobs: search, chat, and the AI Client. It does not charge a licence for any of them. The other rows are plugins that sell one of those jobs. A blank cell means that page did not say.

### Search

These plugins are keyword or instant search. They are not Cloudflare AI Search.

| plugin | licence | what a result shows |
| --- | --- | --- |
| SearchWP Standard | $99 for the first year, renewal $199, 1 site | the pricing page does not list SKU or stock |
| SearchWP Pro | $199 for the first year, renewal $399, 3 sites. WooCommerce product data is on this tier | the pricing page does not list a stock count |
| SearchWP All Access | $399 for the first year, renewal $699, up to 100 sites. Includes WPFilters | the pricing page does not list a stock count |
| FiboSearch | free. Pro is $59, $99, or $249 for the first year, for 1 site (10,000 products), 3 sites (50,000 each), or 25 sites (150,000 each). Renewals are 15% off: $50.15, $84.15, and $211.65 | the pricing page lists image, price, and SKU. It does not list a stock count |
| Relevanssi | free. Premium is €100 per year, or €335 once. Unlimited sites. The page says prices exclude tax | the buy page does not list SKU or a stock count |
| YITH WooCommerce Ajax Search | a free plugin, plus a premium licence. The product page did not print a price | the Free vs Premium table lists an optional price, SKU, and stock line in YITH's own results |
| Skyphusion AI | $0 | the card shows the product image, the title, and the description. The image is the page image. The description carries the SKU, the stock status, and the managed count. The search results page also prints the managed count |

### Chat

The chat button in this plugin is the Cloudflare snippet. It answers in a sentence from the indexed site. That answer is generation, so it spends Workers AI Neurons. The button is off until you turn it on. It is not a staff inbox. It has no seats, no tickets, and no handoff to a person. You can expand that button with Cloudflare Workers AI or with Cloudflare AI Gateway. Other code calls the Cloudflare provider for text, vision, JSON, tools, images, speech, and embeddings. Leave the gateway id blank to call `api.cloudflare.com`. Set a gateway id to send those calls through `gateway.ai.cloudflare.com`.

| plugin | licence | what the visitor gets |
| --- | --- | --- |
| Tidio | Free is $0, with 50 billable conversations and 10 seats. Starter is $24.17 a month on annual billing, for 100 conversations. Growth starts at $49.17 a month, from 250 conversations. Plus starts at $300 a month plus usage. Premium did not print a price. Lyro, the AI agent, starts at $32.50 a month for 50 AI conversations. The first 50 Lyro conversations are one-off, not a monthly allowance | live chat, tickets, and an AI agent that can hand the thread to a person. Tidio ships a WordPress plugin. This is a help desk |
| Skyphusion AI | $0 for the plugin. Generation uses the Neuron cap above, then $0.011 per 1,000 Neurons | one chat button and a sentence answer. No inbox. You can expand that with Workers AI or with AI Gateway |

### Agents

The agent harness in WordPress is the AI Client. This plugin registers the provider id `cloudflare`. A caller can ask for text, vision, JSON, tools, images, or speech. Embeddings are a separate helper. The call is `using_provider('cloudflare')`. You can expand those calls the same way, with Cloudflare Workers AI or with Cloudflare AI Gateway. Leave the gateway id blank to call `api.cloudflare.com`. Set a gateway id to send them through `gateway.ai.cloudflare.com`. A saved gateway id adds four fields to each Workers AI log. The fields are the site, the feature, the model, and the user id. A visitor has an empty user id. The prompt is not a field. A direct call to api.cloudflare.com does not add the fields. This plugin does not edit posts and it does not install other plugins. It does not open an MCP server in this release. An MCP server is planned. You still do not pay a plugin fee to make the Cloudflare call.

| plugin | licence | what the agent can do |
| --- | --- | --- |
| AI Engine (Meow Apps) | the plugin is free. Pro is $69 a year for 1 site, $99 for 5 sites, or $179 for 20 sites. Lifetime is $499 for 5 sites, $799 for 20, $999 for 500, or $1,699 for 2,500. You still pay the model vendor. The Pro page says a refund is available within 14 days | a chatbot, and, on Pro, MCP so Claude or ChatGPT can act on the site. Pro also lists embeddings, AI forms, and voice |
| Skyphusion AI | $0 for the plugin. Workers AI rates are in the table above | the Cloudflare provider those calls use. You can expand them with Workers AI or with AI Gateway. Tools are part of the provider. This release has no MCP server. An MCP server is planned |

Sources: <https://developers.cloudflare.com/ai-search/platform/limits-pricing/> (page updated 2026-10-01), <https://developers.cloudflare.com/workers-ai/platform/pricing/> (page updated 2026-10-20), <https://searchwp.com/pricing/>, <https://fibosearch.com/pricing/>, <https://www.relevanssi.com/buy-premium/>, <https://yithemes.com/themes/plugins/yith-woocommerce-ajax-search/>, <https://www.tidio.com/pricing/>, <https://meowapps.com/products/ai-engine-pro/>.

## External services

- AI Search. The browser loads the snippet from the public endpoint address you paste. The site sends a question to `{endpoint}/search` when you check the connection. `skyphusion_ai_query()` and the search-results switch send the question to `{endpoint}/chat/completions`. No API token is sent. Search feeds and embeds do not print the answer.
- Workers AI and AI Gateway. Prompts, images, speech text, and embedding texts go to `api.cloudflare.com`. They go to `gateway.ai.cloudflare.com` instead when a gateway id is set. The request carries the Connectors API token as a bearer token. Account ids and gateway ids are not secrets. The plugin reads that token from `CLOUDFLARE_API_KEY` in the environment first. It then tries a PHP constant of the same name. The Connectors option `connectors_ai_cloudflare_api_key` is the fallback. The Skyphusion AI screen says which of those is in use. It does not show the token.

## Directory

The display name is Skyphusion AI for Cloudflare. Submit it under the permalink `skyphusion-ai`. That permalink is permanent, and it must not begin with `cloudflare`. The text domain is `skyphusion-ai`, the same as that permalink.

The settings page slug stays `cloudflare-ai-search`. The saved option is `skyphusion_ai_settings`. An install that has only `cloudflare_ai_search_settings` is copied to `skyphusion_ai_settings` on the first request after an update. Until that copy, a read uses the old key. A save on the settings screen writes the new key. `wp skyphusion-ai` writes it too. `Contributors` in readme.txt stays the wordpress.org username `skyphusion`.

## Develop

```bash
php tests/endpoint-test.php
```

The test does not need WordPress. It defines ABSPATH before it loads the endpoint and route files, so their direct-access guards stay in place. It checks the AI Search address, the Workers AI and AI Gateway URLs, the credential split, and the response parsers. It uses stand-in account ids and tokens, never a real secret.

The test also covers the stock text builder. It checks the row wording. It checks that an out-of-stock variation survives. It checks that a negative quantity prints its status without a count. It checks that a translated label really goes through `__()`. It also checks that neither product data nor a translation file can open a tag. `tests/stock-test.php` reads the ABSPATH the harness already defined, and does not set its own.

### Plugin Check

Run `tests/plugin-check.sh` before you open a pull request. It runs `wp plugin check` on the plugin as it ships. It builds that archive with `git archive`, so it checks the last commit and not your edits. Commit first.

The script builds a WordPress harness with SQLite in `~/.cache/skyphusion-ai-plugin-check` the first time. It needs `wp`, `php`, `jq`, `curl` and `unzip`. It locks that directory for the run. A second run waits, and says so, instead of deleting the plugin directory the first run is checking.

It exits 0 when no error is new, 1 when a new error appears, and 2 when it could not measure. Exit 2 is never a pass.

`tests/plugin-check-baseline.txt` lists the errors the plugin still owes. A listed error does not fail the run. Any other error does. Fix an error, then delete its line.

### Syntax

Run `bash tests/php-lint.sh` before you open a pull request. It checks every PHP file that git tracks. A file that does not parse fails the run. The first run installs a pre-push hook, so a later push runs the same check.

The script exits 0 when every file parses, 1 when a file does not parse or the file list is too short, and 2 when it could not measure. Exit 2 is never a pass.

The CI job named `coverage` runs the same check. The job stays in the workflow. Branch protection requires a check by that name.

### Coding standards

Run `bash tests/phpcs.sh` before you open a pull request. It runs the WordPress coding standard on the plugin PHP. It does not check the test files.

It exits 0 when no finding is new, 1 when a finding is new or a count went up, and 2 when it could not measure. Exit 2 is never a pass.

`tests/phpcs-baseline.txt` lists the findings the plugin still owes. A listed count does not fail the run. A higher count does. Fix a finding, then lower its count in that file. Do not raise a count to make the run pass.

## Distribution

A distributed build carries the runtime only. That is `skyphusion-ai.php`, `includes/`, `assets/`, `readme.txt`, `README.md`, `LICENSE` and `uninstall.php`. The test files, the workflow and the dotfiles stay out of it. The coding-standard config stays out of it too. That is `composer.json`, `composer.lock` and `phpcs.xml.dist`.

`tests/endpoint-test.php` and `tests/account-order.php` define `ABSPATH` themselves, so the harness can load `includes/` directly. That is the opposite of what a shipped file should do. `tests/provider-test.php` also calls `shell_exec()`. None of that belongs in an archive a site owner installs.

Two files hold that list, because two mechanisms build archives here. Neither one reads the other's file.

- `.distignore` is read by `wp dist-archive` and by the WordPress.org deploy action.
- `.gitattributes` (`export-ignore`) is what GitHub applies to the "Source code" zip and tarball. GitHub generates those for every tag and release. They are published automatically and cannot be removed. Without `export-ignore` they carry the test files, whatever the attached asset contains.

Keep the two lists in step. To check what a build would contain:

```bash
git archive --format=zip --prefix=skyphusion-ai/ HEAD | tar -tf - 2>/dev/null || \
  git archive --format=tar --prefix=skyphusion-ai/ HEAD | tar -tf -
```

## Trademarks

Cloudflare, the Cloudflare logo, and Cloudflare Workers are trademarks and/or registered trademarks of Cloudflare, Inc. in the United States and other jurisdictions. This plugin is an independent work. Cloudflare, Inc. does not sponsor or endorse it. WordPress and WooCommerce are trademarks of their respective owners.
