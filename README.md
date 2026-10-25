# Skyphusion AI for Cloudflare

WordPress plugin for Cloudflare® [Workers AI](https://developers.cloudflare.com/workers-ai/), [AI Gateway](https://developers.cloudflare.com/ai-gateway/), and [AI Search](https://developers.cloudflare.com/ai-search/).

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

Search results stay as WordPress or WooCommerce left them by default. They change once you turn on **Show an AI Search answer above WordPress and WooCommerce search results**. That switch is off by default. Visitors never see a failed request. The cart and checkout stay quiet when that option is on.

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

A variable product that manages stock at the parent uses the parent count. When the parent does not manage stock, the line is the sum of variations that manage their own stock. A variation that defers to the parent is not added, because that figure is the pool and not the variation. If the variation list is longer than the row cap, the sum would be short, so the line is left off.

The search box is Cloudflare's snippet. That snippet has no field for stock, and this plugin does not draw the dropdown. The count the shopper sees is on the search results list. The product page already contains the same sentence, so a crawl can index it and an answer can cite it.

A single product page also prints `<meta name="stock_quantity" content="N">`. AI Search reads that tag only after the instance schema defines `stock_quantity` as a number. An instance allows five custom fields. Until the schema names the field, the tag is ignored. The snippet does not display it. The tag is for filtering, not for the dropdown.

The default text model is `@cf/meta/llama-3.3-70b-instruct-fp8-fast`. A prompt that includes an image uses `@cf/mistralai/mistral-small-3.1-24b-instruct` first. `@cf/meta/llama-3.2-11b-vision-instruct` needs a one-time `{"prompt":"agree"}` request on that model before it will answer. It does not take tools or JSON.

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

Keyword search plugins charge their own licence. They are not semantic search. Prices below were read on 2026-10-25 from each vendor's own page. They are introductory or listed prices, in the currency that page uses, and they renew on that vendor's terms.

| plugin | listed price | stock in its own results |
| --- | --- | --- |
| SearchWP Standard | $99 for the first year, 1 site. The page says the renewal is the full price, shown there as $199. | keyword search, not this plugin |
| SearchWP Pro | $199 for the first year, 3 sites. WooCommerce product data is on this tier. The page says the renewal is $399. | keyword search |
| SearchWP All Access | $399 for the first year. The page says the renewal is $699. | keyword search |
| FiboSearch | free. Pro is $59, $99, or $249 for the first year, for 1, 3, or 25 sites. The page says renewals are 15% off those figures. | image, price, and SKU. Not a stock count on the free comparison table |
| Relevanssi | free. Premium Annual is 109 EUR. Premium Permanent is 379 EUR. Unlimited sites. The page says prices exclude tax. | keyword search |
| YITH WooCommerce Ajax Search | a free plugin, plus a premium licence. The product page did not print a number. | premium can show stock in its own result list |
| Skyphusion AI | $0 | the count is on the search results page. The snippet dropdown has no stock field |

Sources: <https://developers.cloudflare.com/ai-search/platform/limits-pricing/> (page updated 2026-10-01), <https://developers.cloudflare.com/workers-ai/platform/pricing/> (page updated 2026-10-20), <https://searchwp.com/pricing/>, <https://fibosearch.com/pricing/>, <https://www.relevanssi.com/buy-premium/>, <https://yithemes.com/themes/plugins/yith-woocommerce-ajax-search/>.

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
