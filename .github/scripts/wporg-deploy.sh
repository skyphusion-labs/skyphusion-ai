#!/usr/bin/env bash
# Put one tagged release of the plugin on WordPress.org SVN.
#
# Called by .github/workflows/wporg-deploy.yml, and by tests/wporg-deploy-test.sh
# against a local svn repository. It refuses before it commits when anything is
# wrong, and it commits once.
#
# Environment
#   TAG            Release tag, for example v0.7.0. Required.
#   RELEASE_DIR    Git checkout that holds TAG. Default: the current directory.
#   DRY_RUN        "true" does every step except the commit and the read-back.
#   SVN_URL        Default https://plugins.svn.wordpress.org/skyphusion-ai
#   READBACK_BASE  Where to read the result. Default: SVN_URL, read over https.
#                  A test points it at a file:// svn repository.
#   SVN_USERNAME   Required for a real commit.
#   SVN_PASSWORD   Required for a real commit. Read from the environment only.
#                  It reaches svn through stdin, never a command line.
#   GITLEAKS_BIN   Default: gitleaks
#   WORK_DIR       Scratch directory. Default: a new mktemp directory.
#
# Never run with `set -x`: that would print the password.
set -euo pipefail
set +x

SLUG="skyphusion-ai"
TAG="${TAG:-}"
RELEASE_DIR="${RELEASE_DIR:-$PWD}"
DRY_RUN="${DRY_RUN:-false}"
SVN_URL="${SVN_URL:-https://plugins.svn.wordpress.org/${SLUG}}"
READBACK_BASE="${READBACK_BASE:-$SVN_URL}"
GITLEAKS_BIN="${GITLEAKS_BIN:-gitleaks}"
WORK_DIR="${WORK_DIR:-$(mktemp -d)}"
mkdir -p "$WORK_DIR"

die() {
    echo "wporg-deploy: REFUSED: $*" >&2
    exit 1
}
say() {
    echo "wporg-deploy: $*"
}

# 1. The tag. It becomes a path and a version, so it is checked before use.
check_tag() {
    [[ -n "$TAG" ]] || die "TAG is empty"
    [[ "$TAG" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "tag '$TAG' is not vX.Y.Z"
    VER="${TAG#v}"
    git -C "$RELEASE_DIR" rev-parse -q --verify "refs/tags/${TAG}^{commit}" >/dev/null \
        || die "tag '$TAG' does not exist in $RELEASE_DIR"
}

# 2. Header Version, SKYPHUSION_AI_VERSION and Stable tag all equal the tag.
# Read from the tagged commit, not the working tree.
check_versions() {
    local main readme header const stable
    main=$(git -C "$RELEASE_DIR" show "${TAG}:${SLUG}.php") || die "cannot read ${SLUG}.php at $TAG"
    readme=$(git -C "$RELEASE_DIR" show "${TAG}:readme.txt") || die "cannot read readme.txt at $TAG"
    header=$(printf '%s\n' "$main" | sed -n 's/^ \* Version: *//p' | head -n1 | tr -d '\r ')
    const=$(printf '%s\n' "$main" | sed -n "s/^define('SKYPHUSION_AI_VERSION', '\\([^']*\\)');.*/\\1/p" | head -n1)
    stable=$(printf '%s\n' "$readme" | sed -n 's/^Stable tag: *//p' | head -n1 | tr -d '\r ')
    say "tag=$VER header=$header constant=$const stable=$stable"
    [[ "$header" == "$VER" ]] || die "plugin header Version '$header' is not '$VER'"
    [[ "$const" == "$VER" ]] || die "SKYPHUSION_AI_VERSION '$const' is not '$VER'"
    [[ "$stable" == "$VER" ]] || die "readme.txt Stable tag '$stable' is not '$VER'"
}

# 3. Build the zip the way the release does, then check what it holds.
build_zip() {
    ZIP="$WORK_DIR/${SLUG}-${VER}.zip"
    git -C "$RELEASE_DIR" archive --format=zip --prefix="${SLUG}/" -o "$ZIP" "$TAG"
    local listing bad
    listing=$(unzip -Z1 "$ZIP")
    bad=$(printf '%s\n' "$listing" | grep -E "^${SLUG}/(tests/|\\.github/|CLAUDE\\.md$|composer\\.|phpcs\\.xml\\.dist$)" || true)
    [[ -z "$bad" ]] || die "the archive holds files that must not ship: $(printf '%s' "$bad" | head -n5 | tr '\n' ' ')"
    local need
    for need in "${SLUG}/${SLUG}.php" "${SLUG}/readme.txt" "${SLUG}/worker/index.js"; do
        printf '%s\n' "$listing" | grep -qxF "$need" || die "the archive lacks $need"
    done
    STAGE="$WORK_DIR/stage"
    rm -rf "$STAGE"
    mkdir -p "$STAGE"
    unzip -q "$ZIP" -d "$STAGE"
    say "archive ok: $(printf '%s\n' "$listing" | grep -vc '/$') files"
}

# 4. Secret scan of the tree that will be uploaded.
scan_secrets() {
    command -v "$GITLEAKS_BIN" >/dev/null 2>&1 || die "gitleaks is not installed"
    "$GITLEAKS_BIN" dir --no-banner --redact --exit-code 1 "$STAGE/${SLUG}" \
        || die "gitleaks found a secret in the archive tree"
    say "gitleaks: no finding"
}

# 5. svn: trunk, the new tag, assets. Nothing is committed here.
svn_prepare() {
    WC="$WORK_DIR/wc"
    rm -rf "$WC"
    if svn ls "${SVN_URL}/tags/${VER}" >/dev/null 2>&1; then
        die "tags/${VER} already exists on $SVN_URL"
    fi
    svn checkout --quiet --depth immediates "$SVN_URL" "$WC"
    svn update --quiet --set-depth infinity "$WC/trunk"
    svn update --quiet --set-depth infinity "$WC/assets"
    svn update --quiet --set-depth immediates "$WC/tags"

    rsync -a --delete --exclude='.svn' "$STAGE/${SLUG}/" "$WC/trunk/"
    svn_sync_adds_and_removes "$WC/trunk"

    svn copy --quiet "$WC/trunk" "$WC/tags/${VER}"

    # Directory images only. The README in .wordpress-org is for this repo.
    # An empty image set leaves assets alone: rsync --delete would clear them.
    if compgen -G "$RELEASE_DIR_ASSETS/*" >/dev/null; then
        rsync -a --delete --exclude='.svn' \
            --include='*.png' --include='*.jpg' --include='*.jpeg' --include='*.gif' --include='*.svg' \
            --exclude='*' "$RELEASE_DIR_ASSETS/" "$WC/assets/"
        svn_sync_adds_and_removes "$WC/assets"
        svn_set_mime_types "$WC/assets"
    else
        say "no images in the tag; assets left alone"
    fi
    say "svn status:"
    svn status "$WC" | sed 's/^/  /'
}

svn_sync_adds_and_removes() {
    local dir="$1" f
    svn add --quiet --force "$dir"
    # A trailing @ keeps svn from reading an @ in a file name as a revision.
    svn status "$dir" | sed -n 's/^! *//p' | while IFS= read -r f; do
        svn rm --quiet --force -- "${f}@"
    done
}

svn_set_mime_types() {
    local dir="$1" f type
    for f in "$dir"/*; do
        [[ -f "$f" ]] || continue
        case "${f##*.}" in
            png) type="image/png" ;;
            jpg | jpeg) type="image/jpeg" ;;
            gif) type="image/gif" ;;
            svg) type="image/svg+xml" ;;
            *) continue ;;
        esac
        svn propset --quiet svn:mime-type "$type" "$f"
    done
}

# 6. One commit. The password goes in on stdin.
svn_commit() {
    if [[ "$DRY_RUN" == "true" ]]; then
        say "dry run: no commit"
        return 0
    fi
    [[ -n "${SVN_USERNAME:-}" ]] || die "SVN_USERNAME is empty"
    [[ -n "${SVN_PASSWORD:-}" ]] || die "SVN_PASSWORD is empty"
    printf '%s' "$SVN_PASSWORD" | svn commit "$WC" \
        --message "Release ${VER}" \
        --username "$SVN_USERNAME" \
        --password-from-stdin \
        --non-interactive \
        --no-auth-cache
}

# Read one file back. The release reads over https. A test points READBACK_BASE
# at a local svn repository (file://), which only svn can read.
fetch() {
    case "$1" in
        https://* | http://*) curl -fsS "$1" ;;
        *) svn cat "$1" ;;
    esac
}

# 7. Read back what landed. Trunk and the tag must both say VER.
read_back() {
    if [[ "$DRY_RUN" == "true" ]]; then
        return 0
    fi
    local stable header
    for _ in 1 2 3; do
        stable=$(fetch "${READBACK_BASE}/trunk/readme.txt" | sed -n 's/^Stable tag: *//p' | head -n1 | tr -d '\r ') || stable=""
        header=$(fetch "${READBACK_BASE}/tags/${VER}/${SLUG}.php" | sed -n 's/^ \* Version: *//p' | head -n1 | tr -d '\r ') || header=""
        if [[ "$stable" == "$VER" && "$header" == "$VER" ]]; then
            say "read back ok: trunk Stable tag $stable, tags/${VER} Version $header"
            return 0
        fi
        sleep "${READBACK_SLEEP:-5}"
    done
    die "read-back mismatch: trunk Stable tag '${stable}', tags/${VER} Version '${header}', wanted '${VER}'"
}

# The directory images, read from the tag. git archive would skip them, because
# .gitattributes marks .wordpress-org export-ignore, so they are read one by one.
collect_assets() {
    RELEASE_DIR_ASSETS="$WORK_DIR/assets-src"
    mkdir -p "$RELEASE_DIR_ASSETS"
    local path
    while IFS= read -r -d '' path; do
        git -C "$RELEASE_DIR" show "${TAG}:${path}" >"$RELEASE_DIR_ASSETS/$(basename "$path")"
    done < <(git -C "$RELEASE_DIR" ls-tree -r -z --name-only "$TAG" -- .wordpress-org)
}

main() {
    check_tag
    check_versions
    build_zip
    scan_secrets
    collect_assets
    svn_prepare
    svn_commit
    read_back
    say "done: ${SLUG} ${VER} (dry run: ${DRY_RUN})"
}

main "$@"
