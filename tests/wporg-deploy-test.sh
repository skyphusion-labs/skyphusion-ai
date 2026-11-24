#!/usr/bin/env bash
# Runs .github/scripts/wporg-deploy.sh against a local svn repository.
#
#   bash tests/wporg-deploy-test.sh
#
# Nothing here reaches wordpress.org: SVN_URL is a file:// repository made with
# svnadmin. Exit 0 = every check passed, 1 = a check failed, 2 = COULD NOT
# MEASURE (a tool is missing). Exit 2 is never a pass.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
SCRIPT="$ROOT/.github/scripts/wporg-deploy.sh"
for tool in svn svnadmin rsync unzip git gitleaks tar curl; do
    command -v "$tool" >/dev/null 2>&1 || {
        echo "wporg-deploy-test: COULD NOT MEASURE: $tool is not installed" >&2
        exit 2
    }
done
[[ -f "$SCRIPT" ]] || {
    echo "FAIL the deploy script exists at .github/scripts/wporg-deploy.sh"
    exit 1
}

T="$(cd "$(mktemp -d)" && pwd -P)"
trap 'rm -rf "$T"' EXIT
FAILS=0
CHECKS=0
check() {
    CHECKS=$((CHECKS + 1))
    if [[ "$2" == "$3" ]]; then
        echo "ok $1"
    else
        echo "FAIL $1"
        echo "  actual:   $2"
        echo "  expected: $3"
        FAILS=$((FAILS + 1))
    fi
}

# A copy of this tree as a git repository, so the archive filters are the real ones.
BASE="$T/base"
mkdir -p "$BASE"
(cd "$ROOT" && git ls-files -z | tar --null -T - -cf -) | tar -x -C "$BASE"
git -C "$BASE" init -q
git -C "$BASE" config user.email t@example.org
git -C "$BASE" config user.name t
git -C "$BASE" config core.hooksPath /dev/null
git -C "$BASE" add -A
git -C "$BASE" commit -q -m base

# release_repo <name> <version> [header] [const] [stable]: a repo tagged v<version>.
release_repo() {
    local name="$1" ver="$2" header="${3:-$2}" const="${4:-$2}" stable="${5:-$2}" d="$T/rel-$1"
    cp -R "$BASE" "$d"
    sed -i.bak "s/^ \* Version: .*/ * Version: $header/; s/define( 'SKYPHUSION_AI_VERSION', '[^']*' )/define( 'SKYPHUSION_AI_VERSION', '$const' )/" "$d/skyphusion-ai.php"
    sed -i.bak "s/^Stable tag: .*/Stable tag: $stable/" "$d/readme.txt"
    rm -f "$d"/*.bak
    git -C "$d" add -A
    git -C "$d" commit -q -m "release $ver" --allow-empty
    git -C "$d" tag "v$ver"
    echo "$d"
}

# new_svn <name>: a repository shaped like WordPress.org's, with old trunk content.
new_svn() {
    local r="$T/svn-$1"
    svnadmin create "$r"
    svn mkdir -q -m init "file://$r/trunk" "file://$r/tags" "file://$r/assets" "file://$r/branches"
    local w="$T/seed-$1"
    svn checkout -q "file://$r" "$w"
    echo "old" >"$w/trunk/removed-in-new-release.php"
    echo "old" >"$w/assets/stale-banner.png"
    svn add -q "$w/trunk/removed-in-new-release.php" "$w/assets/stale-banner.png"
    svn copy -q "$w/trunk" "$w/tags/0.0.1"
    svn commit -q -m seed "$w"
    echo "$r"
}
rev() { svn info --show-item revision "file://$1"; }

# deploy <repo-dir> <tag> <svn-repo> [VAR=value ...]: sets OUT and RC.
deploy() {
    local dir="$1" tag="$2" repo="$3"
    shift 3
    OUT=$(env TAG="$tag" RELEASE_DIR="$dir" SVN_URL="file://$repo" SVN_USERNAME=bot \
        SVN_PASSWORD=sentinel-password-9f3a WORK_DIR="$T/work-$RANDOM" READBACK_SLEEP=0 "$@" \
        bash "$SCRIPT" 2>&1)
    RC=$?
}

# 1. A good release lands once, with the right contents.
R1=$(release_repo ok 9.9.9)
S1=$(new_svn ok)
before=$(rev "$S1")
deploy "$R1" v9.9.9 "$S1"
check "a good release exits 0" "$RC" "0"
check "it makes exactly one commit" "$(($(rev "$S1") - before))" "1"
check "trunk has the plugin file" "$(svn ls "file://$S1/trunk" | grep -c '^skyphusion-ai.php$')" "1"
check "trunk has the Worker file" "$(svn ls "file://$S1/trunk/worker" | grep -c '^index.js$')" "1"
check "trunk lost the file the release no longer has" "$(svn ls "file://$S1/trunk" | grep -c 'removed-in-new-release')" "0"
check "the tag exists" "$(svn ls "file://$S1/tags" | grep -c '^9.9.9/$')" "1"
check "the tag has the plugin file" "$(svn ls "file://$S1/tags/9.9.9" | grep -c '^skyphusion-ai.php$')" "1"
check "trunk holds no tests" "$(svn ls -R "file://$S1/trunk" | grep -c '^tests/')" "0"
check "trunk holds no workflow" "$(svn ls -R "file://$S1/trunk" | grep -c '^\.github')" "0"
check "trunk holds no CLAUDE.md" "$(svn ls -R "file://$S1/trunk" | grep -c '^CLAUDE.md$')" "0"
check "trunk holds no composer file" "$(svn ls -R "file://$S1/trunk" | grep -c '^composer\.')" "0"
check "assets has the icon" "$(svn ls "file://$S1/assets" | grep -c '^icon-128x128.png$')" "1"
check "assets lost the stale file" "$(svn ls "file://$S1/assets" | grep -c 'stale-banner')" "0"
check "assets has no README" "$(svn ls "file://$S1/assets" | grep -c 'README')" "0"
check "an asset png has the png mime type" "$(svn propget svn:mime-type "file://$S1/assets/icon-128x128.png")" "image/png"
check "the svg has the svg mime type" "$(svn propget svn:mime-type "file://$S1/assets/icon.svg")" "image/svg+xml"
check "the output never shows the password" "$(printf '%s' "$OUT" | grep -c 'sentinel-password-9f3a')" "0"
check "the read-back ran and passed" "$(printf '%s' "$OUT" | grep -c 'read back ok')" "1"

# 2. The tag is the only version source. Each of the three must agree.
for variant in "header|9.9.8|0.0.1|9.9.8|9.9.8" "constant|9.9.7|9.9.7|0.0.1|9.9.7" "stable|9.9.6|9.9.6|9.9.6|0.0.1"; do
    IFS='|' read -r name ver h c s <<<"$variant"
    R=$(release_repo "mm-$name" "$ver" "$h" "$c" "$s")
    S=$(new_svn "mm-$name")
    before=$(rev "$S")
    deploy "$R" "v$ver" "$S"
    check "a wrong $name is refused" "$RC" "1"
    check "a wrong $name names the cause" "$(printf '%s' "$OUT" | grep -c "REFUSED:.*'0.0.1' is not '$ver'")" "1"
    check "a wrong $name leaves svn alone" "$(rev "$S")" "$before"
done

# 3. A tag that already exists on svn is never overwritten.
R=$(release_repo exists 0.0.1)
S=$(new_svn exists)
before=$(rev "$S")
deploy "$R" v0.0.1 "$S"
check "an existing svn tag is refused" "$RC" "1"
check "an existing svn tag names the cause" "$(printf '%s' "$OUT" | grep -c 'already exists')" "1"
check "an existing svn tag leaves svn alone" "$(rev "$S")" "$before"

# 4. The archive must not hold test files, and must hold the runtime files.
R=$(release_repo leaktests 9.9.5)
: >"$R/.gitattributes"
git -C "$R" add -A
git -C "$R" commit -q -m "drop export-ignore"
git -C "$R" tag -f v9.9.5 >/dev/null
S=$(new_svn leaktests)
before=$(rev "$S")
deploy "$R" v9.9.5 "$S"
check "an archive that holds tests/ is refused" "$RC" "1"
check "it names the files that must not ship" "$(printf '%s' "$OUT" | grep -c 'must not ship')" "1"
check "it leaves svn alone" "$(rev "$S")" "$before"

R=$(release_repo noworker 9.9.4)
git -C "$R" rm -q -r worker
git -C "$R" commit -q -m "drop worker"
git -C "$R" tag -f v9.9.4 >/dev/null
S=$(new_svn noworker)
before=$(rev "$S")
deploy "$R" v9.9.4 "$S"
check "an archive without worker/index.js is refused" "$RC" "1"
check "it names the missing file" "$(printf '%s' "$OUT" | grep -c 'lacks skyphusion-ai/worker/index.js')" "1"
check "a missing file leaves svn alone" "$(rev "$S")" "$before"

# 5. A secret in the tree stops the deploy. The token is made here at run time,
# so this file holds nothing a scanner would flag.
R=$(release_repo secret 9.9.3)
printf 'token=ghp_%s\n' "$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 36)" >"$R/includes/leaked.txt"
git -C "$R" add -A
git -C "$R" commit -q -m "oops"
git -C "$R" tag -f v9.9.3 >/dev/null
S=$(new_svn secret)
before=$(rev "$S")
deploy "$R" v9.9.3 "$S"
check "a secret in the archive is refused" "$RC" "1"
check "it names gitleaks" "$(printf '%s' "$OUT" | grep -c 'gitleaks found a secret')" "1"
check "a secret leaves svn alone" "$(rev "$S")" "$before"

# 6. Dry run does everything except the commit.
R=$(release_repo dry 9.9.2)
S=$(new_svn dry)
before=$(rev "$S")
deploy "$R" v9.9.2 "$S" DRY_RUN=true
check "a dry run exits 0" "$RC" "0"
check "a dry run commits nothing" "$(rev "$S")" "$before"
check "a dry run says so" "$(printf '%s' "$OUT" | grep -c 'dry run: no commit')" "1"
check "a dry run lists what it would change" "$(printf '%s' "$OUT" | grep -qE '^ +A .*tags/9.9.2$' && echo yes || echo no)" "yes"

# 6b. A tag with no images leaves the directory images alone.
R=$(release_repo noimg 9.9.11)
git -C "$R" rm -q -r .wordpress-org
git -C "$R" commit -q -m "no images"
git -C "$R" tag -f v9.9.11 >/dev/null
S=$(new_svn noimg)
deploy "$R" v9.9.11 "$S"
check "a tag with no images deploys" "$RC" "0"
check "and leaves the svn assets alone" "$(svn ls "file://$S/assets" | grep -c '^stale-banner.png$')" "1"

# 7. A malformed tag never reaches a path.
R=$(release_repo ok2 9.9.1)
S=$(new_svn badtag)
deploy "$R" 'v9.9.1; touch /tmp/x' "$S"
check "a malformed tag is refused" "$RC" "1"
deploy "$R" '../v9.9.1' "$S"
check "a path in the tag is refused" "$RC" "1"
deploy "$R" '' "$S"
check "an empty tag is refused" "$RC" "1"
deploy "$R" v8.8.8 "$S"
check "a tag that is not in the repo is refused" "$RC" "1"

# 8. A read-back that does not match fails the job.
R=$(release_repo rb 9.9.0)
S=$(new_svn rb)
OTHER=$(new_svn rbother)
deploy "$R" v9.9.0 "$S" READBACK_BASE="file://$OTHER"
check "a read-back mismatch fails" "$RC" "1"
check "it says what differed" "$(printf '%s' "$OUT" | grep -c 'read-back mismatch')" "1"

# 9. The password never rides a command line, and nothing traces the shell.
check "no svn call passes --password on a command line" "$(grep -v '^ *#' "$SCRIPT" | grep -c -- '--password[ =]')" "0"
check "the script never turns tracing on" "$(grep -v '^ *#' "$SCRIPT" | grep -cE '^ *set -[a-z]*x')" "0"
check "the password goes in on stdin" "$(grep -c -- '--password-from-stdin' "$SCRIPT")" "1"

echo "$CHECKS checks, $FAILS failed"
[[ "$FAILS" -eq 0 ]] || exit 1
echo "all passed"
