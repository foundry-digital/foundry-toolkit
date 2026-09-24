#!/bin/sh
# Publish a Foundry Toolkit release (ADR 0025, S13).
#
# usage: scripts/release.sh VERSION        (or: make release VERSION=1.2.0)
#
# Bumps the version, runs make check, commits, builds the zip with Site
# Manager's public key baked in, signs it with Site Manager's private key,
# tags, pushes and creates the GitHub release with the zip and its .sig.
# A version with a suffix (1.3.0-rc1) becomes a pre-release, offered only to
# sites that define FOUNDRY_TOOLKIT_PRERELEASES.
#
# NOTES_FILE=path uses that file as the release notes; otherwise GitHub
# writes them from the commits since the last release.
set -eu

die() { echo "release: $*" >&2; exit 1; }

VERSION=${1:-}
SITEMANAGER=${SITEMANAGER:-"/Applications/Site Manager.app/Contents/Resources/sitemanager"}
SM_DATA_DIR=${SM_DATA_DIR:-"$HOME/Library/Application Support/SiteManager"}

echo "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$' || die "usage: scripts/release.sh X.Y.Z[-suffix]"
[ -x "$SITEMANAGER" ] || die "no Site Manager binary at $SITEMANAGER (make app in site-manager, or set SITEMANAGER)"
[ -z "$(git status --porcelain)" ] || die "commit or stash your changes first"
[ "$(git rev-parse --abbrev-ref HEAD)" = main ] || die "release from main"
if git rev-parse -q --verify "refs/tags/v$VERSION" >/dev/null; then die "v$VERSION already exists"; fi
git fetch -q origin main
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || die "main is not the same as origin/main; pull or push first"

PUB=$("$SITEMANAGER" public-key "$SM_DATA_DIR") || die "could not read Site Manager's public key"

# The version lives in the plugin header and in the constant.
sed -i '' \
	-e "s/^ \* Version: .*/ * Version: $VERSION/" \
	-e "s/^define( 'FOUNDRY_TOOLKIT_VERSION', '.*' );/define( 'FOUNDRY_TOOLKIT_VERSION', '$VERSION' );/" \
	foundry-toolkit.php
grep -q "^ \* Version: $VERSION\$" foundry-toolkit.php || die "could not set the header version"
grep -q "^define( 'FOUNDRY_TOOLKIT_VERSION', '$VERSION' );" foundry-toolkit.php || die "could not set the version constant"

make check
git commit -q -am "Release $VERSION"

make zip PUBLIC_KEY="$PUB"
"$SITEMANAGER" sign-release "$SM_DATA_DIR" build/foundry-toolkit.zip "$VERSION"

git tag -a "v$VERSION" -m "Foundry Toolkit $VERSION"
git push -q origin main "v$VERSION"

set -- "v$VERSION" build/foundry-toolkit.zip build/foundry-toolkit.zip.sig --title "Foundry Toolkit $VERSION"
case "$VERSION" in *-*) set -- "$@" --prerelease ;; esac
if [ -n "${NOTES_FILE:-}" ]; then set -- "$@" --notes-file "$NOTES_FILE"; else set -- "$@" --generate-notes; fi
gh release create "$@"
echo "released Foundry Toolkit $VERSION"
