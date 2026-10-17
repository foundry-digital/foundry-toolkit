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
| `FDHARDEN_LOCK_REGISTRATION` | true | Visitors register through `wp-login.php`. WooCommerce and Gravity Forms are unaffected either way. On or off, the default role is never one that can do a held capability: an administrator, editor or shop manager default reads as subscriber (1.15.0). |
| `FDHARDEN_LOCK_PLUGINS` | true (false on a local site) | You need to add a plugin or theme: set it to false for a moment, then take the line out. While it is on, nobody can add, upload, delete or edit plugins and themes in wp-admin, or deactivate Foundry Toolkit; no account is exempt (1.12.0). Updating still works for every administrator: the Update buttons, WordPress's automatic updates and Site Manager's. |
| `FDHARDEN_REQUIRE_2FA` | true | Only in an emergency, such as every administrator locked out. Administrators, editors, shop managers and anyone given administrator-level powers must set up an authenticator app before they can use the site (below). |

## Required 2FA for privileged accounts

On every site with the WordPress.org [Two Factor](https://wordpress.org/plugins/two-factor/) plugin (0.17.0 or later) active, a privileged account without an authenticator app is sent to a setup wizard as soon as they log in, and every other page sends them back to it until they finish: scan the QR code, enter a code, save the backup codes (ADR 0035 in the Site Manager repository). It is like a building that issues every key holder a swipe card at the front desk before they reach the lifts: the card system is the Two Factor plugin's, the Toolkit only stands at the desk.

- On by default. Installing the Two Factor plugin is what switches it on for a site, so reset the administrators' and editors' passwords first: a password leaked earlier could otherwise enrol the attacker's phone.
- Only the plugin's authenticator app (TOTP) counts. Email codes do not.
- Decided by capability, not role name (ticket 071): anyone who can `manage_options`, `promote_users`, `edit_users`, `create_users`, `delete_users`, `activate_plugins` or `edit_others_posts`, and super admins. That is administrators, editors and shop managers, plus any role given one of those. No exemptions, a host's support account included. Customers, members and portal roles (subscriber capabilities) are never touched. Nor are WP-CLI and cron.
- While someone is held, only the wizard, logging out, the plugin's own REST routes (`two-factor/1.0`) and the Heartbeat get through. Any other REST request is refused with a 403.
- Without the plugin, nobody is held, and the Site Manager report says so (`users.two_factor_plugin_missing`).

Getting back in: a backup code at login after a lost phone; another administrator resetting the user's 2FA on their profile; or, with everyone locked out, `define( 'FDHARDEN_REQUIRE_2FA', false );` in `wp-config.php`, or `wp user meta delete <id> _two_factor_enabled_providers` with WP-CLI.

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

`scripts/release.sh` reads Site Manager's public key on the server (`ssh sitemanager 'sudo -u sitemanager /usr/local/bin/sitemanager public-key /var/lib/sitemanager'`), sets the version, runs `make check`, commits, builds the zip with that public key baked in, signs it on the server with Site Manager's `deploy/sign-release.sh` (the zip goes to the server and the signature comes back; the private key never leaves `/var/lib/sitemanager`), tags, pushes and creates the GitHub release with `foundry-toolkit.zip` and `foundry-toolkit.zip.sig`.

It needs `ssh sitemanager` to work (the same alias Site Manager's `make deploy` uses), a Site Manager checkout at `~/Projects/go/site-manager`, and `gh` signed in. `SM_DEPLOY_HOST` names a different ssh destination and `SITEMANAGER_REPO` a different checkout, for example `SITEMANAGER_REPO=~/src/site-manager make release VERSION=1.4.0`.

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
