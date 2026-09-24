# Foundry Toolkit

Foundry Digital's plugin for client WordPress sites. One plugin, two jobs:

- **The Site Manager agent.** Answers Ed25519-signed requests from Site Manager (James's Mac app) with an inventory report, and applies WordPress's own updates when the site opts in with `SM_ALLOW_UPDATES`. It holds only the public key: it can check a signature, never make one.
- **Hardening.** XML-RPC, comments, file editors, author enumeration, anonymous user endpoints, security headers, SSL for admin, emojis, oEmbed and feeds. Ported from Foundry Security Hardening 1.6.3, with the same `FDHARDEN_*` switches.

It replaces two must-use files, `foundry-sitemanager.php` and `foundry-hardening.php`. While either is still in `wp-content/mu-plugins/`, the toolkit leaves that job to it and shows a notice asking you to delete it.

## Install

1. Plugins, Add New, Upload Plugin, choose `foundry-toolkit.zip` from the latest release, then Activate.
2. To let Site Manager run updates, add this to `wp-config.php` above "That's all, stop editing":

   ```php
   define( 'SM_ALLOW_UPDATES', true );
   ```

On activation the plugin writes `wp-content/mu-plugins/0-foundry-toolkit.php`. That small loader includes the toolkit before any other plugin, the way a bouncer is at the door before the guests arrive: hardening's constants (`DISALLOW_FILE_EDIT`, the HTTPS fix) only count if they are set first. Deactivating removes it. If the folder is not writable, a notice says so.

## Switches

Define any of these in `wp-config.php` to opt a site out. All default to on except the first.

| Constant | Default | Turn it off (or on) when |
| --- | --- | --- |
| `FDHARDEN_DISALLOW_FILE_MODS` | false | On only for a site deployed by git: it also removes the installer and updates, including Site Manager's. |
| `FDHARDEN_DISABLE_XMLRPC` | true | The site needs XML-RPC beyond Jetpack. While Jetpack is connected, its own methods get through anyway. |
| `FDHARDEN_DISABLE_COMMENTS` | true | The site runs comments. WooCommerce product reviews stay while reviews are on in WooCommerce. |
| `FDHARDEN_DISABLE_FEEDS` | true | Anything reads the site's RSS. |
| `FDHARDEN_DISABLE_APP_PASSWORDS` | true | An integration signs in with an application password. |
| `FDHARDEN_LOCK_REGISTRATION` | true | Visitors register through `wp-login.php`. WooCommerce and Gravity Forms are unaffected either way. |

Filters: `fdharden_security_headers`, `fdharden_blocked_anon_rest_routes`, `fdharden_keep_comments_post_types`, `fdharden_jetpack_xmlrpc_prefixes`.

## Payments

Nothing here may break a WooCommerce or Gravity Forms payment. `tests/HardeningTest.php` guards the parts that could: the Permissions-Policy never restricts `payment` (Apple Pay and Google Pay), the anonymous REST block only touches core `/wp/v2` and `/oembed` routes, and product reviews survive comments being off.

## Updates

The plugin checks this repository's latest GitHub release whenever WordPress runs its own update check, at most every six hours. A newer release shows as an update in WordPress and in Site Manager, like any other plugin. It installs only if the zip's signature verifies against the Site Manager key built into the plugin (S13). A zip from anywhere else, or one changed after it was signed, is refused with a message on the update screen. Automatic updates stay off: it updates when you click.

Define `FOUNDRY_TOOLKIT_PRERELEASES` as `true` in `wp-config.php` on a test site to be offered pre-releases such as `1.3.0-rc1`.

## Releasing

```bash
make release VERSION=1.2.1
```

`scripts/release.sh` sets the version, runs `make check`, commits, builds the zip with Site Manager's public key baked in, signs it with Site Manager's private key (`sitemanager sign-release`, which never leaves the Mac), tags, pushes and creates the GitHub release with `foundry-toolkit.zip` and `foundry-toolkit.zip.sig`. It needs the Site Manager app installed (`make app` in site-manager) and `gh` signed in.

## Development

```bash
make check
```

Runs PHP_CodeSniffer (WordPress ruleset), PHPStan level 8 with PHP 7.4 semantics and PHPUnit with Brain Monkey. No WordPress install is needed. `TESTING.md` is the one test against a real site.

`tests/fixtures/protocol/` is a copy of `testdata/protocol/` in the Site Manager repository, which is the source of truth; Site Manager's `make check` fails if the two differ.

```bash
make zip PUBLIC_KEY=<base64 public key>
```

Builds `build/foundry-toolkit.zip` with that key baked in.
