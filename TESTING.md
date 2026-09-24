# Testing Foundry Toolkit against a real site

The unit tests under `tests/` run against mocks (Brain Monkey) and never need WordPress. This file is the one test that does: the agent installed on a Local for WordPress site, driven with hand-signed requests, before the agent goes anywhere near a client site (protocol P55, ticket 029 step 5).

## What you need

- Local for WordPress with a site running. The examples use `sandbox.local`; swap in the site you pick. The site must answer at its `.local` address (Local's router returns 502 when the site is stopped).
- Two plugins on that site pinned to a version older than the one WordPress.org offers, so there is something to update. Hello Dolly and Classic Editor are safe choices: download an older release zip from wordpress.org, unzip it into `wp-content/plugins/`, and let WordPress notice the update (Dashboard, Updates, Check again).
- `php` on the PATH with the sodium and zip extensions (Local's PHP binaries have both), `curl`, `openssl` 3 and `xxd`.

## 1. Install Foundry Toolkit with the fixture key

The fixture key is the RFC 8032 test vector from `tests/fixtures/protocol/vectors.json`. The app refuses it as a real key (S8), which is exactly why it is safe to use on a test site.

```bash
PUB=$(python3 -c "import json;print(json.load(open('tests/fixtures/protocol/vectors.json'))['keys']['public_key_base64'])")
make zip PUBLIC_KEY="$PUB"
```

`python3 -c` reads the key out of the fixture; `make zip` copies the plugin into `build/foundry-toolkit/` with the key baked in and zips it. Upload `build/foundry-toolkit.zip` in Plugins, Add New, Upload Plugin, and activate it. Check that `wp-content/mu-plugins/0-foundry-toolkit.php` appeared.

Add the opt-in to `wp-config.php`, above the "That's all, stop editing" line:

```php
define( 'SM_ALLOW_UPDATES', true );
```

## 2. A signing helper

Save this as `sign.sh` in the repo root. It prints the three headers for one request, following P19 and P54: the six canonical lines joined with newlines, signed with the seed as a PKCS#8 key.

```bash
#!/bin/sh
# usage: sign.sh METHOD ROUTE HOME_URL [BODY]
SEED=9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60
METHOD=$1; ROUTE=$2; HOME=$3; BODY=${4:-}
TS=$(date +%s)
NONCE=""
[ "$METHOD" = "POST" ] && NONCE=$(openssl rand -hex 16)
HASH=$(printf '%s' "$BODY" | shasum -a 256 | cut -d' ' -f1)
printf '302e020100300506032b657004220420%s' "$SEED" | xxd -r -p > /tmp/sm-seed.der
SIG=$(printf '%s\n%s\n%s\n%s\n%s\n%s' "$METHOD" "$ROUTE" "$TS" "$NONCE" "$HOME" "$HASH" | openssl pkeyutl -sign -inkey /tmp/sm-seed.der -keyform DER -rawin | base64)
echo "-H X-SM-Timestamp:$TS -H X-SM-Nonce:$NONCE -H X-SM-Signature:$SIG"
```

- `openssl rand -hex 16` makes the 32-character nonce a POST needs (P16).
- `shasum -a 256` hashes the body, empty for GET (P17).
- `openssl pkeyutl -sign ... -rawin` signs the exact bytes, which is what Ed25519 wants.

## 3. Fetch a report

Writes need HTTPS (P21a), so turn on SSL for Sandbox in Local (the site's SSL row, then Trust) and use the `https://` address throughout.

```bash
HOME_URL=https://sandbox.local
curl -s $(sh sign.sh GET /sitemanager/v1/report $HOME_URL) "$HOME_URL/wp-json/sitemanager/v1/report" | python3 -m json.tool | head -40
```

Expected: JSON with `"protocol": 1` and `"updates_enabled": true`. The `plugins` list shows your pinned plugin with an `update_version`.

A tampered request must get the P22 body. Reuse the headers but change the route:

```bash
curl -s $(sh sign.sh GET /sitemanager/v1/report $HOME_URL) "$HOME_URL/wp-json/sitemanager/v1/update"
```

Expected: `{"code":"rest_no_route","message":"No route was found matching the URL and request method.","data":{"status":404}}`.

## 4. Update one plugin

Before the first update with agent 1.0.7, make a stand-in for an old agent's snapshot folder, so you can see it go:

```bash
mkdir -p "$HOME/Local Sites/sandbox/app/public/wp-content/uploads/foundry-site-manager"
```

`mkdir -p` makes the folder and any missing parents, and says nothing if it is already there.

```bash
BODY='{"type":"plugin","item":"hello.php","expected_version":"1.7.3"}'
curl -s -X POST -H 'Content-Type: application/json' $(sh sign.sh POST /sitemanager/v1/update $HOME_URL "$BODY") -d "$BODY" "$HOME_URL/wp-json/sitemanager/v1/update"
```

Use the `update_version` from the report as `expected_version`. Expected: `"ok": true`, `from_version` the old one, `to_version` the new one, no `rollback_id`, and messages with no `?key=` query strings. `wp-content/uploads/foundry-site-manager/` is gone and nothing new was written under `uploads` (S12).

Send the same body again (with fresh headers): expected `sm_no_update_available`. Send it with the wrong `expected_version`: expected `sm_version_mismatch` and `data.offered_version` (S1).

## 5. The rollback route is gone

```bash
BODY='{"type":"plugin","item":"hello.php","rollback_id":"0123456789abcdef"}'
curl -s -X POST -H 'Content-Type: application/json' $(sh sign.sh POST /sitemanager/v1/rollback $HOME_URL "$BODY") -d "$BODY" "$HOME_URL/wp-json/sitemanager/v1/rollback"
```

Expected: the P22 `rest_no_route` body, although the request is correctly signed (ADR 0023).

## 6. No writes over plain HTTP

The agent refuses a write when its own `home_url()` is `http://`, however well it is signed (P21a). Seeing that needs Sandbox switched back to `http://` in Settings, General, so the fixture `post_update_http_site` covers it in `make check` instead. Sending the section 4 request to `http://sandbox.local` while the site is on `https://` also fails, but only because the signature names the wrong address (S4).

## 7. Without the opt-in

Remove the `SM_ALLOW_UPDATES` line and repeat the update request: expected the P22 `rest_no_route` body (S2). Put it back afterwards.

## Record

| Date | Site | Result |
| --- | --- | --- |
| not yet run | | Every Local site was stopped when ticket 029 was built (Local's router answered 502). Run this before ticket 039's rollout and record the outcome here. |
