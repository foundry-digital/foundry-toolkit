# Foundry Toolkit

The WordPress plugin on every Foundry Digital client site: the Site Manager agent plus hardening (ADR 0025 in the Site Manager repository, `~/Projects/go/site-manager`). Read `README.md` first.

Run `make check` before calling anything done.

## Rules

- PHP 7.4 compatible. WordPress coding standards (phpcs), PHPStan level 8, PHPUnit with Brain Monkey; tests before code.
- The agent protocol is `docs/protocol.md` in the Site Manager repository. Change it there first, then here. `tests/fixtures/protocol/` is a copy of Site Manager's `testdata/protocol/`: never edit it here, copy it across.
- Nothing may break a WooCommerce or Gravity Forms payment. Keep `tests/HardeningTest.php` honest.
- The plugin never updates a site on its own: automatic updates stay off for it, and the update endpoint only runs from a signed request James triggered.
- No runtime dependencies. Ask before adding a dev dependency.
- Never use em-dashes in any output, code comments or docs. Australian English.
