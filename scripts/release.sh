#!/bin/sh
# Publish a Foundry Toolkit release (ADR 0025, S13).
#
# usage: scripts/release.sh VERSION        (or: make release VERSION=1.2.0)
#
# Bumps the version, runs make check, commits, builds the zip with Site
# Manager's public key baked in, signs it with Site Manager's private key,
# tags, pushes and creates the GitHub release with the zip and its .sig.
# The key lives only on the Site Manager server: the public key is read there
# over ssh, and Site Manager's deploy/sign-release.sh signs the zip there.
# A version with a suffix (1.3.0-rc1) becomes a pre-release, offered only to
# sites that define FOUNDRY_TOOLKIT_PRERELEASES.
#
# NOTES_FILE=path uses that file as the release notes; otherwise GitHub
# writes them from the commits since the last release.
# SM_DEPLOY_HOST is the server's ssh destination (default sitemanager, the
# alias deploy.sh uses). SITEMANAGER_REPO is the Site Manager checkout that
# holds deploy/sign-release.sh (default ~/Projects/go/site-manager).
set -eu

die() { echo "release: $*" >&2; exit 1; }

VERSION=${1:-}
SM_DEPLOY_HOST=${SM_DEPLOY_HOST:-sitemanager}
SITEMANAGER_REPO=${SITEMANAGER_REPO:-"$HOME/Projects/go/site-manager"}
SIGN="$SITEMANAGER_REPO/deploy/sign-release.sh"

echo "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$' || die "usage: scripts/release.sh X.Y.Z[-suffix]"
[ -x "$SIGN" ] || die "no $SIGN (set SITEMANAGER_REPO to your Site Manager checkout)"
[ -z "$(git status --porcelain)" ] || die "commit or stash your changes first"
[ "$(git rev-parse --abbrev-ref HEAD)" = main ] || die "release from main"
if git rev-parse -q --verify "refs/tags/v$VERSION" >/dev/null; then die "v$VERSION already exists"; fi
git fetch -q origin main
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || die "main is not the same as origin/main; pull or push first"

# Before any change, so a server that cannot be reached stops the release
# while the tree is still clean.
PUB=$(ssh "$SM_DEPLOY_HOST" 'sudo -u sitemanager /usr/local/bin/sitemanager public-key /var/lib/sitemanager') ||
	die "could not read Site Manager's public key on $SM_DEPLOY_HOST"
# Base64 only, one line, 44 characters (32 bytes), since make zip puts it
# into the plugin with sed. A case test sees the whole value, newlines and
# all, where grep would pass any one good line.
case "$PUB" in
	*[!A-Za-z0-9+/=]* | '') die "unexpected public key from $SM_DEPLOY_HOST" ;;
esac
[ "${#PUB}" -eq 44 ] || die "unexpected public key length from $SM_DEPLOY_HOST: ${#PUB}"

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
SM_DEPLOY_HOST="$SM_DEPLOY_HOST" ZIP=build/foundry-toolkit.zip VERSION="$VERSION" "$SIGN"
[ -s build/foundry-toolkit.zip.sig ] || die "signing on $SM_DEPLOY_HOST left no build/foundry-toolkit.zip.sig"

git tag -a "v$VERSION" -m "Foundry Toolkit $VERSION"
git push -q origin main "v$VERSION"

set -- "v$VERSION" build/foundry-toolkit.zip build/foundry-toolkit.zip.sig --title "Foundry Toolkit $VERSION"
case "$VERSION" in *-*) set -- "$@" --prerelease ;; esac
if [ -n "${NOTES_FILE:-}" ]; then set -- "$@" --notes-file "$NOTES_FILE"; else set -- "$@" --generate-notes; fi
gh release create "$@"
echo "released Foundry Toolkit $VERSION"
