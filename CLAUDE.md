# skyphusion-ai (agent contract)

WordPress plugin, public, GPL-2.0, a revenue item. The release zip directory,
the main file, the function prefix, the option key and the WP-CLI command are
`skyphusion-ai` / `skyphusion_ai_`. `includes/deprecated.php` keeps the old
`cloudflare_ai_search_*` names for one release so the storefront keeps working.
Do not delete that file until the storefront has moved. Do not change the
shipped prefix as a side effect of other work.

## Gates

- `php tests/endpoint-test.php` is the unit gate (CI job `ci`).
- `bash tests/phpcs.sh` is the WordPress Coding Standards gate, also in CI job `ci`.
  It checks the shipped PHP only. Findings already listed in
  `tests/phpcs-baseline.txt` are owed. A new pair, or a higher count, fails.
  Never raise a count to get green.
- `bash tests/php-lint.sh` is the syntax gate that runs. It lints every
  tracked PHP file (`git ls-files '*.php'`, floor 15) and installs
  `tests/hooks/pre-push` at `.git/hooks/pre-push` so a push runs it.
  Do not set `core.hooksPath` to `tests/hooks`. A seat that already has
  the estate hook would lose that hook. Exit 0 parses, exit 1 does not,
  exit 2 could not measure. Exit 2 is never a pass.
  Prove it: `PHP_LINT_PLANT='uninstall.php:this is not php {' bash tests/php-lint.sh`
  must exit 1 and name `uninstall.php`. Without the variable it must exit 0.
- CI job `coverage` is the same `php -l` check. The workflow stays, and it
  does not call the script. The job is named `coverage` because that is the
  required status check. Do not rename it without a branch-protection change,
  and do not delete the workflow because a run currently fails before any
  step. When Actions starts jobs again, that job should pass unchanged.
- `tests/plugin-check.sh` is the WordPress Plugin Check gate. **Run it on every change
  to the plugin, before opening the PR.** Conrad's standard (2026-10-23).

## Plugin Check: check the ARTIFACT, never the tree

The tree carries `tests/` and dotfiles that `.gitattributes` `export-ignore` strips
from every archive. Measured 2026-10-23: the tree reports 38 errors and 104 warnings,
the shipped archive 7 errors and 3 warnings. The script therefore builds the archive
with `git archive` (prefix `skyphusion-ai/`, the release zip prefix)
and checks that. It archives a committed ref (`PLUGIN_CHECK_REF`, default HEAD), so
commit before you run it.

- Exit 0: no error beyond the baseline. Exit 1: a new error. Exit 2: COULD NOT
  MEASURE (missing tool, no network, harness broken). Exit 2 is not a pass; report it.
- `tests/plugin-check-baseline.txt` is the owed list: file, code, count, per line.
  A new (file, code) pair, or a higher count, fails. Never raise a count or add a line
  to get green; fix the finding or escalate. When a listed error is fixed the script
  prints FIXED; delete that line in the same PR.
- Warnings print and never gate.
- Prove the gate can fail after you touch it:
  `PLUGIN_CHECK_PLANT='includes/frontend.php:function x() { return strip_tags($_GET["a"]); }' tests/plugin-check.sh`
  must exit 1 and name the planted error; without the variable it must exit 0.
- `wp-cli` under PHP 8.5 prints `Deprecated: Case statements followed by a semicolon`
  from its bundled react/promise. That is noise.
- Do not use `wp core download` (phar memory exhaustion); the script unzips
  wordpress.org's latest.zip. The sqlite-database-integration URL has no version in it
  on purpose; a versioned URL 404s.

## Style

Docs the end user reads (`README.md`, `readme.txt`) are Simplified Technical English.
This file is not. No em-dashes or en-dashes anywhere.
