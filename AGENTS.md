# Skyphusion AI

This file is the guide for any coding agent and any contributor. Follow it.

## What this project is

Skyphusion AI is a WordPress plugin. The license is GPL-2.0-or-later. It connects WordPress and WooCommerce to Cloudflare Workers AI, AI Gateway, and AI Search. The visitor page does not hold an API token. Read [README.md](README.md) and `readme.txt`.

The release names use `skyphusion-ai` and `skyphusion_ai_`. That covers the zip directory, the main file, the function prefix, the option key, and the WP-CLI command. `includes/deprecated.php` keeps the old `cloudflare_ai_search_*` names for one release. Leave that file until the storefront has moved. Do not change the shipped prefix as a side effect.

The optional assistant Worker script name is `skyphusion-ai`. The source file is `worker/index.js`. This repo has no Wrangler config. `wp skyphusion-ai worker deploy` uses the same deploy as the settings button.

The version of record is the plugin header and the latest `v*` tag.

## Gates

Run these before a pull request:

```bash
php tests/endpoint-test.php
bash tests/phpcs.sh
bash tests/php-lint.sh
bash tests/plugin-check.sh
```

CI job `ci` runs the endpoint test and the coding-standards gate. See [`.github/workflows/ci.yml`](.github/workflows/ci.yml). CI job `coverage` runs `php -l` on tracked PHP files. Do not rename the `coverage` job. That name is the required status check.

`tests/phpcs.sh` checks shipped PHP only. `tests/phpcs-baseline.txt` is the owed list. A new pair fails the gate. A higher count fails the gate. Do not raise a count to get a green run.

`bash tests/php-lint.sh` lints every tracked PHP file. It can install `tests/hooks/pre-push` as `.git/hooks/pre-push`. Do not set `core.hooksPath` to `tests/hooks`. That setting would replace another local hook. Exit 0 means the files parse. Exit 1 means a file does not parse. Exit 2 means the script could not measure. Treat exit 2 as a failed run.

Prove the lint can fail:

```bash
PHP_LINT_PLANT='uninstall.php:this is not php {' bash tests/php-lint.sh
```

That command must exit 1 and name `uninstall.php`. Without the variable, the script must exit 0.

## Plugin Check

`tests/plugin-check.sh` checks the release archive. `.gitattributes` strips `tests/` and dotfiles from that archive with `export-ignore`. The script builds the archive with `git archive`. The prefix is `skyphusion-ai/`. It reads a committed ref. The default ref is `HEAD`. Commit before you run it.

Exit 0 means no error past the baseline. Exit 1 means a new error. Exit 2 means the script could not measure. Treat exit 2 as a failed run.

`tests/plugin-check-baseline.txt` is the owed list. Each line is a file, a code, and a count. A new pair fails. A higher count fails. Do not add a line to get a green run. When a listed error is fixed, the script prints `FIXED`. Delete that line in the same pull request. Warnings print. Warnings do not fail the run.

Prove the gate can fail:

```bash
PLUGIN_CHECK_PLANT='includes/frontend.php:function x() { return strip_tags($_GET["a"]); }' tests/plugin-check.sh
```

That command must exit 1 and name the planted error. Without the variable, the script must exit 0.

`wp-cli` on PHP 8.5 may print a deprecation from a bundled library. That line is noise. The script uses the wordpress.org `latest.zip` file. Do not use `wp core download` for this gate.

## Docs

Write `README.md` and `readme.txt` in Simplified Technical English. Use short sentences. Use one idea per sentence. Use the active present tense. Use plain words. Use the same word every time.

Do not use an em dash (U+2014). Do not use an en dash (U+2013). Use a comma, a semicolon, parentheses, or two hyphens (`--`).

State the limit next to the capability. The plugin fee is $0. Cloudflare plan limits still apply. See the cost section of the README. Do not turn a measured result into a promise.

## Safety

Do not put a secret in a tracked file, a log, a test trace, or an issue. Do not print a Cloudflare API token.

## Release

A published GitHub Release deploys that tag to WordPress.org. The workflow is [`.github/workflows/wporg-deploy.yml`](.github/workflows/wporg-deploy.yml). A version moves only in a release pull request. Feature work does not bump the version. Fix work does not bump the version.
