#!/usr/bin/env bash
# php -l over every tracked PHP file.
#
# This is the syntax gate that actually runs. The coverage job in
# .github/workflows/ci.yml is the same check, and that workflow stays.
# When Actions can start jobs again, that job should pass unchanged.
# Do not delete it, and do not rename the job: coverage is a required
# status check. The job does not call this script, on purpose, so it
# does not depend on a file this script could later break.
#
# THE FILE LIST COMES FROM git ls-files, never a hand-maintained list.
# A list is a second definition and it drifts. THE FLOOR matches the
# workflow (15). An empty or truncated listing must fail, not pass
# quietly over nothing.
#
# It lints the working-tree copies of tracked files. A syntax error
# that is not committed still blocks a push.
#
# The first run installs tests/hooks/pre-push at .git/hooks/pre-push
# (a symlink), unless that path is already some other hook. Git runs
# that file on push when core.hooksPath is unset. A seat whose estate
# hook is installed chains that same path. Do not set core.hooksPath
# to tests/hooks. On a seat that already has an estate hook, that
# setting would replace it.
#
# EXIT: 0 every file parses. 1 a syntax error, a short listing, or a
# plant target that is not a tracked PHP file. 2 COULD NOT MEASURE.
# Exit 2 is never a pass.
#
# Env:
#   PHP_LINT_ROOT        repo root (default: the repo holding this script)
#   PHP_LINT_PLANT       path:text. Append text to a temp copy of that
#                        tracked file and lint the copy. Proves the gate
#                        fails without editing the tree.
#   PHP_LINT_NO_INSTALL  set to 1 to skip installing the pre-push hook.

set -u
set -o pipefail

ROOT="${PHP_LINT_ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
FLOOR=15

cannot() { echo "php-lint: COULD NOT MEASURE: $*" >&2; exit 2; }

command -v git >/dev/null 2>&1 || cannot "missing tool 'git'"
command -v php >/dev/null 2>&1 || cannot "missing tool 'php'"

cd "$ROOT" || cannot "cannot cd to $ROOT"
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || cannot "not a git work tree"

install_hook() {
    [ "${PHP_LINT_NO_INSTALL:-}" = "1" ] && return 0
    local src git_dir git_dir_abs root_abs dest desired
    src="$ROOT/tests/hooks/pre-push"
    if [ ! -f "$src" ]; then
        echo "php-lint: $src is missing; pre-push hook not installed" >&2
        return 0
    fi
    git_dir="$(git rev-parse --git-dir)" || cannot "git rev-parse --git-dir failed"
    git_dir_abs="$(cd "$git_dir" && pwd)" || cannot "cannot cd to $git_dir"
    root_abs="$(cd "$ROOT" && pwd)" || cannot "cannot cd to $ROOT"
    mkdir -p "$git_dir_abs/hooks" || cannot "cannot create $git_dir_abs/hooks"
    dest="$git_dir_abs/hooks/pre-push"
    if [ "$git_dir_abs" = "$root_abs/.git" ]; then
        desired="../../tests/hooks/pre-push"
    else
        desired="$root_abs/tests/hooks/pre-push"
    fi
    if [ -L "$dest" ] && [ "$(readlink "$dest")" = "$desired" ]; then
        return 0
    fi
    if [ -e "$dest" ] || [ -L "$dest" ]; then
        echo "php-lint: $dest is not this repo's pre-push hook; left it in place" >&2
        return 0
    fi
    ln -sfn "$desired" "$dest" || cannot "cannot install pre-push hook"
    echo "php-lint: installed pre-push hook -> $desired"
}

install_hook

n="$(git ls-files '*.php' | wc -l | tr -d '[:space:]')" || cannot "git ls-files failed"
case "$n" in
    ''|*[!0-9]*) cannot "could not count tracked PHP files (got '$n')" ;;
esac
if [ "$n" -lt "$FLOOR" ]; then
    echo "php-lint: only $n tracked PHP file(s); the listing is wrong, not the tree" >&2
    exit 1
fi

plant_path=""
plant_text=""
if [ -n "${PHP_LINT_PLANT:-}" ]; then
    plant_path="${PHP_LINT_PLANT%%:*}"
    plant_text="${PHP_LINT_PLANT#*:}"
    if [ -z "$plant_path" ] || [ "$plant_path" = "${PHP_LINT_PLANT}" ] || [ -z "$plant_text" ]; then
        cannot "PHP_LINT_PLANT must be path:text"
    fi
fi

fail=0
plant_seen=0
seen=0
tmp=""
cleanup() { [ -n "$tmp" ] && rm -f "$tmp"; }
trap cleanup EXIT

while IFS= read -r -d '' f; do
    seen=$((seen + 1))
    target="$f"
    if [ -n "$plant_path" ] && [ "$f" = "$plant_path" ]; then
        plant_seen=1
        tmp="$(mktemp "${TMPDIR:-/tmp}/php-lint.XXXXXX")" || cannot "mktemp failed"
        cp "$f" "$tmp" || cannot "cannot copy $f"
        printf '\n%s\n' "$plant_text" >> "$tmp" || cannot "cannot plant into $f"
        target="$tmp"
        echo "php-lint: planted into a copy of $f" >&2
    fi
    if ! php -l "$target"; then
        echo "php-lint: syntax error: $f" >&2
        fail=1
    fi
    if [ -n "$tmp" ]; then
        rm -f "$tmp"
        tmp=""
    fi
done < <(git ls-files -z '*.php')

if [ "$seen" -ne "$n" ]; then
    cannot "listing length changed ($seen vs $n)"
fi

if [ -n "$plant_path" ] && [ "$plant_seen" -ne 1 ]; then
    echo "php-lint: plant target is not a tracked PHP file: $plant_path" >&2
    exit 1
fi

if [ "$fail" -ne 0 ]; then
    exit 1
fi
echo "php-lint: linted $n tracked PHP file(s)"
