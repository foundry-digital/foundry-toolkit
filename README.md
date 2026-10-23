# Foundry Toolkit

Foundry Digital's plugin for client WordPress sites. One plugin, two jobs:

- **The Site Manager agent.** Answers Ed25519-signed requests from Site Manager (James's Mac app) with an inventory report, and applies WordPress's own updates when James clicks Update in Site Manager. It holds only the public key: it can check a signature, never make one.
- **Hardening.** XML-RPC, comments, file editors, author enumeration, anonymous user endpoints, security headers, SSL for admin, emojis, oEmbed and feeds. Ported from Foundry Security Hardening 1.6.3, then the plugin lock, the admin lock and required 2FA. Each is unlocked per site with a `FOUNDRY_ALLOW_*` switch (below).

It replaces two must-use files, `foundry-sitemanager.php` and `foundry-hardening.php`. While either is still in `wp-content/mu-plugins/`, the toolkit leaves that job to it and shows a notice asking you to delete it.

## Install

Plugins, Add New, Upload Plugin, choose `foundry-toolkit.zip` from the latest release, then Activate. Nothing goes in `wp-config.php`: Site Manager can update the site as soon as the Toolkit is active (1.16.0).

On activation the plugin writes `wp-content/mu-plugins/0-foundry-toolkit.php`. That small loader includes the toolkit before any other plugin, the way a bouncer is at the door before the guests arrive: hardening's constants (`DISALLOW_FILE_EDIT`, the HTTPS fix) only count if they are set first. Deactivating removes it. If the folder is not writable, a notice says so.

## Switches

Everything is locked by default. Each switch unlocks one thing: add `define( 'FOUNDRY_ALLOW_…', true );` to `wp-config.php`, do the job, then take the line out. A line in `wp-config.php` is always an unlock, never a lock (ADR 0037 in the Site Manager repository). It is like a building where every door is locked and each key is in the manager's safe: a stolen staff badge (a leaked password) opens nothing, because the keys need file access to the server.

| Constant | Unlocks |
| --- | --- |
| `FOUNDRY_ALLOW_PLUGIN_INSTALLS` | Adding, uploading, deleting and editing plugins and themes, and deactivating Foundry Toolkit (1.12.0). Unlocked by default on a local site (below). Updating works either way: the Update buttons, WordPress's automatic updates and Site Manager's. |
| `FOUNDRY_ALLOW_TOOLS` | Tools, Export and Import, the personal-data export and erase tools, and Site Health (1.16.0). WooCommerce's Products, Export keeps working either way. |
| `FOUNDRY_ALLOW_SETTINGS` | Changing the site address, admin email, theme or upload path; Settings, All Settings (1.16.0). Additional CSS, in the Customizer, the Site Editor or a block, is never locked. |
| `FOUNDRY_ALLOW_ADMIN_CHANGES` | Creating an administrator or giving any account or role administrator-level powers (1.17.0), and editing, resetting the password of, deleting or changing the role of another privileged account (1.16.0). |
| `FOUNDRY_ALLOW_REGISTRATION` | Visitors registering through `wp-login.php`. WooCommerce and Gravity Forms create accounts themselves and are unaffected either way. Locked or not, the default role is never one that can do a held capability: an administrator, editor or shop manager default reads as subscriber (1.15.0). |
| `FOUNDRY_ALLOW_XMLRPC` | XML-RPC beyond Jetpack, such as the WordPress mobile app. While Jetpack is connected, its own methods get through anyway. |
| `FOUNDRY_ALLOW_COMMENTS` | Comments. WooCommerce product reviews stay while reviews are on in WooCommerce. |
| `FOUNDRY_ALLOW_FEEDS` | The RSS feeds, for anything that reads them (Mailchimp RSS campaigns, podcasts). |
| `FOUNDRY_ALLOW_APP_PASSWORDS` | Application passwords, for an integration that signs in to the REST API with one. |
| `FOUNDRY_ALLOW_LOGIN_WITHOUT_2FA` | Only in an emergency, such as every administrator locked out: privileged accounts no longer have to set up an authenticator app (below). Unlocked by default on a local site. |
| `FOUNDRY_ALLOW_PRERELEASES` | On a test site only: be offered pre-releases such as `1.3.0-rc1`. |

A **local site** is one whose site address (Settings, General) ends in `.local`, as every site Local makes does. Its address is read from WordPress's settings, never from the request, so a visitor cannot make a live site look local. A local site can add plugins and skips 2FA without any line in `wp-config.php`; everything else stays locked.

A site deployed only by git can define WordPress's own `DISALLOW_FILE_MODS`, which also removes updates, Site Manager's included.

Before 1.16.0 the switches were `FDHARDEN_*` (each pointing its own way) and Site Manager's updates needed `SM_ALLOW_UPDATES`. None of those is read any more: after a site updates, take those lines out of its `wp-config.php`.

## Locked tools and settings

After the Sydney Contemporary hack (a client administrator's leaked password), the tools and settings such a login would use, and our sites don't need day to day, are locked for everyone, administrators included (ADR 0036). What still works: every normal Settings page (the site title saves; the address and admin email keep their value, with a notice naming `FOUNDRY_ALLOW_SETTINGS`), menus, widgets and the Customizer, editing your own profile, creating and editing portal users, subscribers, authors and customers, Gravity Forms entry export, Elementor, and updates.

- **Administrators.** Only James adds administrators (ticket 075, 1.17.0): no role picker offers Administrator or any role with administrator-level powers, and giving one is refused with a message naming `FOUNDRY_ALLOW_ADMIN_CHANGES`. Editors, shop managers (WooCommerce gives them `edit_users` only at run time, for customers), authors, customers and portal users can still be created. Two backstops cover plugins' own code and visitors' requests too, so a registration bug cannot make an administrator: an account cannot gain an administrator-level role or capability it did not have (a new user written that way is left with no role), and a role cannot gain one (`manage_options` added to Editor by a role editor plugin is taken out; WooCommerce adding its own capabilities saves). Opening, editing, resetting the password of, deleting or changing the role of another privileged account (another administrator, an editor, a shop manager, or any role with administrator-level powers) is refused with a message naming `FOUNDRY_ALLOW_ADMIN_CHANGES`; so is promoting an existing editor or shop manager, since that changes another privileged account's role. As a backstop for plugins with their own user screens or imports, a logged-in request can never change another privileged account's password or email. Every new privileged account raises an alert in Site Manager (ticket 071).
- **Never removed:** the administrator-level capabilities themselves (`manage_options` and the rest of ticket 071's list), so the 2FA hold, the privileged-account count and AMA Portals behave as before.
- **Only for a logged-in user,** except the two role backstops, which leave alone only WP-CLI (with or without `--user`), cron and WordPress's install. WP-CLI without `--user`, cron, WordPress's own writes and Site Manager's signed requests are untouched. So `wp user create jo jo@example.com --role=administrator`, `wp option update admin_email …`, `wp theme activate …` and `wp export` work while everything is locked.
- **Known limits:** on multisite, a super admin can still change a site's domain, the network admin email and who is a super admin. Someone with file access to the server (SFTP, a backdoor) can add a switch. The locks stop what a stolen password can do, not code already on the server.

## Required 2FA for privileged accounts

On every site with the WordPress.org [Two Factor](https://wordpress.org/plugins/two-factor/) plugin (0.17.0 or later) active, a privileged account without an authenticator app is sent to a setup wizard as soon as they log in, and every other page sends them back to it until they finish: scan the QR code, enter a code, save the backup codes (ADR 0035 in the Site Manager repository). It is like a building that issues every key holder a swipe card at the front desk before they reach the lifts: the card system is the Two Factor plugin's, the Toolkit only stands at the desk.

- On by default. Installing the Two Factor plugin is what switches it on for a site, so reset the administrators' and editors' passwords first: a password leaked earlier could otherwise enrol the attacker's phone.
- Only the plugin's authenticator app (TOTP) counts. Email codes do not.
- Decided by capability, not role name (ticket 071): anyone who can `manage_options`, `promote_users`, `edit_users`, `create_users`, `delete_users`, `activate_plugins` or `edit_others_posts`, and super admins. That is administrators, editors and shop managers, plus any role given one of those. No exemptions, a host's support account included. Customers, members and portal roles (subscriber capabilities) are never touched. Nor are WP-CLI and cron.
- While someone is held, only the wizard, logging out, the plugin's own REST routes (`two-factor/1.0`) and the Heartbeat get through. The Heartbeat answers with the time alone: none of its handlers runs, so it hands out no fresh nonces and saves no autosaves. Any other REST request is refused with a 403.
- Off on a local site (above), where nobody is held and the report says 2FA is not required.
- Without the plugin, nobody is held, and the Site Manager report says so (`users.two_factor_plugin_missing`).

Getting back in: a backup code at login after a lost phone; `wp user meta delete <id> _two_factor_enabled_providers` with WP-CLI; or another administrator resetting the user's 2FA on their profile, which needs `FOUNDRY_ALLOW_ADMIN_CHANGES` for a moment, since editing another administrator is locked (1.16.0). With everyone locked out, `define( 'FOUNDRY_ALLOW_LOGIN_WITHOUT_2FA', true );` in `wp-config.php`.

Filters: `fdharden_security_headers`, `fdharden_blocked_anon_rest_routes`, `fdharden_keep_comments_post_types`, `fdharden_jetpack_xmlrpc_prefixes`.

## Payments

Nothing here may break a WooCommerce or Gravity Forms payment. `tests/HardeningTest.php` guards the parts that could: the Permissions-Policy never restricts `payment` (Apple Pay and Google Pay), the anonymous REST block only touches core `/wp/v2` and `/oembed` routes, and product reviews survive comments being off.

## Updates

The plugin checks this repository's latest GitHub release whenever WordPress runs its own update check, at most every six hours. A newer release shows as an update in WordPress and in Site Manager, like any other plugin. It installs only if the zip's signature verifies against the Site Manager key built into the plugin (S13). A zip from anywhere else, or one changed after it was signed, is refused with a message on the update screen. Automatic updates stay off: it updates when you click.

Define `FOUNDRY_ALLOW_PRERELEASES` as `true` in `wp-config.php` on a test site to be offered pre-releases such as `1.3.0-rc1`.

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

Runs PHP_CodeSniffer with the WordPress ruleset and PHPCompatibilityWP (PHP 7.4 and later) over the plugin and its tests (`phpcs.xml` lists the few rules the tests skip, and why), PHPStan at level `max` with PHP 7.4 semantics, and PHPUnit with Brain Monkey. No WordPress install is needed. `TESTING.md` is the one test against a real site.

`tests/fixtures/protocol/` is a copy of `testdata/protocol/` in the Site Manager repository, which is the source of truth; Site Manager's `make check` fails if the two differ.

```bash
make zip PUBLIC_KEY=<base64 public key>
```

Builds `build/foundry-toolkit.zip` with that key baked in.
