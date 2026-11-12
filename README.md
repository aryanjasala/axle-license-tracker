<p align="center"><img src=".wordpress-org/icon-256x256.png" alt="" width="88"></p>

<h1 align="center">Axle License Tracker</h1>

<p align="center">
  <a href="https://try.axlewp.com/axle-license-tracker"><img src="https://img.shields.io/badge/Try%20in-WordPress%20Playground-3858e9?logo=wordpress&logoColor=white" alt="Try in WordPress Playground"></a>
  <a href="https://github.com/aryanjasala/axle-license-tracker/releases/latest"><img src="https://img.shields.io/github/v/release/aryanjasala/axle-license-tracker?label=release&color=2271b1" alt="Latest release"></a>
  <img src="https://img.shields.io/badge/WordPress-6.6%2B-3858e9?logo=wordpress&logoColor=white" alt="WordPress 6.6+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white" alt="PHP 7.4+">
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue" alt="License: GPL-2.0-or-later"></a>
  <br>
  <a href="https://github.com/aryanjasala/axle-license-tracker/actions/workflows/phpcs.yml"><img src="https://github.com/aryanjasala/axle-license-tracker/actions/workflows/phpcs.yml/badge.svg" alt="PHPCS"></a>
  <a href="https://github.com/aryanjasala/axle-license-tracker/actions/workflows/phpstan.yml"><img src="https://github.com/aryanjasala/axle-license-tracker/actions/workflows/phpstan.yml/badge.svg" alt="PHPStan"></a>
  <a href="https://github.com/aryanjasala/axle-license-tracker/actions/workflows/unit-tests.yml"><img src="https://github.com/aryanjasala/axle-license-tracker/actions/workflows/unit-tests.yml/badge.svg" alt="Unit tests"></a>
  <a href="https://github.com/aryanjasala/axle-license-tracker/actions/workflows/e2e-tests.yml"><img src="https://github.com/aryanjasala/axle-license-tracker/actions/workflows/e2e-tests.yml/badge.svg" alt="E2E tests"></a>
  <a href="https://github.com/aryanjasala/axle-license-tracker/actions/workflows/plugin-check.yml"><img src="https://github.com/aryanjasala/axle-license-tracker/actions/workflows/plugin-check.yml/badge.svg" alt="Plugin Check"></a>
</p>

<p align="center">See which premium plugins and themes are licensed, which lapsed, which stopped getting updates, and what renews when.</p>

<p align="center">
  <img src=".github/media/demo.gif" alt="Plugins > Licenses: an expired license and a blocked update, then a renewal date and cost added by hand" width="880">
</p>

---

A typical client site runs 5 to 15 paid plugins and a paid theme, each with its own license screen and its own renewal email, sent to whoever bought it. When a license lapses, updates stop, WordPress mostly says nothing, and security scanners that compare versions with WordPress.org skip premium plugins. Axle License Tracker lists every premium plugin and theme under **Plugins > Licenses** with its license state and how old that is, whether updates actually arrive, the renewal date, the cost and the owner. It only reads what is already on the site: it never activates, checks or changes a key.

> [!NOTE]
> Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.
>
> Part of [Axle](https://axlewp.com/) by AryanX, a family of free plugins for running WordPress sites safely.

## Features

- **Finds the premium ones**: WordPress.org plugins are recognised from the update data WordPress already has. Premium ones from an `Update URI` header, a vendor updater in the update data, an updater or licensing SDK in their code (Freemius, EDD Software Licensing, Plugin Update Checker, WooCommerce API Manager, StellarWP Uplink, SureCart, Appsero, Brainstorm Force) or a known vendor. Plugins a theme ships through TGM Plugin Activation are marked bundled
- **Reads license state without touching it**: built-in readers for Freemius, EDD Software Licensing, WooCommerce.com, Elementor Pro, ACF PRO, Gravity Forms and Envato Market. No vendor code runs, no request goes out, no seat is used. Every state says how old it is
- **Update risk**: blocked when a newer version is out but cannot be downloaded, at risk when the vendor knows a newer version the site is not offered or the updater has been silent for 30 days, watch for a version unchanged for 180 days
- **Renewals and costs**: renewal date, cost and currency (stored in minor units, never converted), billing cycle, owner, seats, account and invoice links. What you enter wins, and the list shows which values were set by hand
- **Reminders**: 30, 14, 7 and 1 days before a renewal date, each sent once, plus an email when a license expires or an update is blocked, and an optional monthly digest
- **Everywhere you look**: a License column on Plugins, a dashboard widget with the next renewals, and a Site Health check that goes critical when an update is blocked
- **Keys stay on the site**: never stored, shown, sent or logged. Only a fingerprint (a 12-character hash prefix and the last 4 characters) to spot one license used on several sites
- **For agencies**: a read-only REST report and a License reporter role that can read it and nothing else. The report leaves out emails, paths and notes
- **A hub for every client site** (off by default): add other sites by address and an application password, and one screen shows all their licenses, renewals and costs, which sites could not be reached and why, and the same license key on more sites than its plan allows. Passwords are encrypted, only https and public addresses are allowed, and the hub only ever reads
- **A year of costs and a calendar feed**: what the next 12 months of renewals will cost per month, vendor and currency (never converted), with a CSV download, and a private .ics link for your calendar app with every renewal, its product, vendor and cost
- **WP-CLI** for everything (`scan` exits 1 for monitoring), **abilities** for AI agents, CSV and JSON export, and privacy export and erase

## Install

1. Download `axle-license-tracker.zip` from the [latest release](https://github.com/aryanjasala/axle-license-tracker/releases/latest).
2. In WordPress, go to **Plugins > Add New Plugin > Upload Plugin**, choose the zip and activate it.

Or with WP-CLI:

```sh
wp plugin install https://github.com/aryanjasala/axle-license-tracker/releases/latest/download/axle-license-tracker.zip --activate
```

Needs WordPress 6.6+ and PHP 7.4+. To try it first, [try.axlewp.com/axle-license-tracker](https://try.axlewp.com/axle-license-tracker) opens a private WordPress in your browser with the latest release and some demo content. Nothing to install.

## Quick look

```sh
wp axle-license-tracker scan
# 4 premium: 1 expired, 1 expiring, 0 missing a key, 2 not getting updates (1 blocked).
# - ALC Blocked Fixture: unknown, blocked

wp axle-license-tracker list --risk=at-risk
wp axle-license-tracker set plugin:elementor-pro/elementor-pro.php --renewal=2027-03-01 --cost=59 --currency=USD --cycle=yearly --owner="Bean There"
wp axle-license-tracker expiring --days=30
wp axle-license-tracker remind --dry-run

echo "abcd efgh ijkl mnop" | wp axle-license-tracker hub add https://client.example.com --username=reporter --password-stdin
wp axle-license-tracker hub licenses --days=30
wp axle-license-tracker costs --format=csv
```

Or open **Plugins > Licenses**.

## Screenshots

<table>
  <tr>
    <td width="50%" valign="top"><img src=".wordpress-org/screenshot-1.png" alt="Plugins > Licenses: license state and age, update risk, renewal dates, costs per currency and owners"><br><sub>Plugins > Licenses: license state and age, update risk, renewal dates, costs per currency and owners</sub></td>
    <td width="50%" valign="top"><img src=".wordpress-org/screenshot-2.png" alt="Editing the details detection cannot know, next to what was detected"><br><sub>Editing the details detection cannot know, next to what was detected</sub></td>
  </tr>
  <tr>
    <td width="50%" valign="top"><img src=".wordpress-org/screenshot-3.png" alt="The License column on the Plugins screen"><br><sub>The License column on the Plugins screen</sub></td>
    <td width="50%" valign="top"><img src=".wordpress-org/screenshot-4.png" alt="The dashboard widget: what needs a look and the next renewals"><br><sub>The dashboard widget: what needs a look and the next renewals</sub></td>
  </tr>
</table>

## Documentation

| Doc | What it covers |
| --- | --- |
| [Reference](docs/REFERENCE.md) | Detection layers, adapters, update risk, reminders, the hub, costs and the calendar feed, hooks, the adapter API, abilities, REST, WP-CLI, data and privacy |
| [Development](docs/DEVELOPMENT.md) | Local setup, commands and tests |
| [Contributing](docs/CONTRIBUTING.md) | Pull requests, required checks and PR titles |
| [Releasing](docs/RELEASING.md) | How merged pull requests become releases |
| [Security](docs/SECURITY.md) | Reporting a vulnerability |
| [readme.txt](readme.txt) | What WordPress.org shows, with the FAQ and changelog |

## Project structure

```
axle-license-tracker.php  plugin header and PSR-4 autoloader
data/
├── signatures.json      updater and SDK signatures for the scan
└── vendors.json         known vendors
src/
├── Plugin.php           creates the services
├── Inventory.php        installed plugins and themes
├── Updates.php          the update transients, and when updaters answered
├── Scanner.php          the bounded code scan
├── Classifier.php       directory, premium, bundled, custom
├── Adapters/            the seven license readers
├── Risk.php             update risk
├── Tracker.php          one row per component, and syncing
├── Licenses.php         alc_license records
├── Details.php          details entered by hand
├── Reminders.php        reminders, alerts and the digest
├── Report.php           the report, Export.php CSV and JSON
├── Forecast.php         the 12-month cost forecast
├── Calendar.php         the renewals .ics feed
├── Hub/                 remote sites: store, encryption, address guard, client, sync
├── Rest/                the read-only report route
├── Abilities.php        abilities for agents
├── Privacy.php          export, erase and policy text
├── Health/SiteHealth.php the Site Health check
├── Admin/               Plugins > Licenses, the column, the widget
└── Cli/                 wp axle-license-tracker, hub and feed
assets/                  admin.css, admin.js
tests/
├── Unit/                PHPUnit
└── e2e/                 Playwright specs, fixtures and the Docker site
docs/                    reference, development, contributing, releasing
```

## More free plugins

Small plugins that each fill one gap in WordPress, all free with no pro version. Try them all together at [demo.axlewp.com](https://demo.axlewp.com/).

| | Plugin | What it does |
| --- | --- | --- |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-page-template-usage/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Page Template Usage](https://github.com/aryanjasala/axle-page-template-usage) | See which template every page uses, and fix the pages on a missing template |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-staging-guard/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Staging Guard](https://github.com/aryanjasala/axle-staging-guard) | Stops staging copies from emailing real people, calling live APIs or getting indexed |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-category-tag-trash/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Category & Tag Trash](https://github.com/aryanjasala/axle-category-tag-trash) | A trash for categories, tags and custom terms, with undo and full restore |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-read-only-mode/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Read-Only Mode](https://github.com/aryanjasala/axle-read-only-mode) | Read-only mode for WordPress during migrations and deploys |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-block-restore/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Block Restore](https://github.com/aryanjasala/axle-block-restore) | Restore a single block from an old revision, not the whole post |
| <img src="https://raw.githubusercontent.com/aryanjasala/activity-log-with-undo/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Activity Log with Undo](https://github.com/aryanjasala/activity-log-with-undo) | An activity log where every change you can reverse has an Undo button |
| <img src="https://raw.githubusercontent.com/aryanjasala/agent-governance/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Agent Governance](https://github.com/aryanjasala/agent-governance) | Hold risky AI agent calls for approval, preview what they change, and roll back a session |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-ai-guard/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle AI Guard](https://github.com/aryanjasala/axle-ai-guard) | See and control every AI call your plugins make: cost, data sent, budgets and a kill switch |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-config-sync/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Config Sync](https://github.com/aryanjasala/axle-config-sync) | Keep settings in git, diff them against the live site, apply with a preview and undo |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-leftovers/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Leftovers](https://github.com/aryanjasala/axle-leftovers) | Find what deleted plugins and themes left behind, see who owns it and why, and clean it up with undo |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-review/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Review](https://github.com/aryanjasala/axle-review) | Share drafts through private links, collect outside feedback as block Notes, and know when an approval is out of date |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-runtime-doctor/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Runtime Doctor](https://github.com/aryanjasala/axle-runtime-doctor) | Check OPcache coverage, the object cache, PHP support and limits, cron and more, with the fix for your host |
| <img src="https://raw.githubusercontent.com/aryanjasala/axle-smoke-test/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Axle Smoke Test](https://github.com/aryanjasala/axle-smoke-test) | Check key pages after every update or deploy, see what broke and roll it back |
| <img src="https://raw.githubusercontent.com/aryanjasala/block-doctor/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Block Doctor](https://github.com/aryanjasala/block-doctor) | Scan the site for broken blocks and fix them in bulk, with undo. See which posts use each block before you switch a plugin off |
| <img src="https://raw.githubusercontent.com/aryanjasala/block-theme-ops/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Block Theme Ops](https://github.com/aryanjasala/block-theme-ops) | Find Site Editor changes that override your theme files, see the diff, reset them or send them to Git |
| <img src="https://raw.githubusercontent.com/aryanjasala/role-right-sizer/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Role Right-Sizer](https://github.com/aryanjasala/role-right-sizer) | Recommends the smallest role each user needs, from what they actually do |
| <img src="https://raw.githubusercontent.com/aryanjasala/update-rehearsal/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [Update Rehearsal](https://github.com/aryanjasala/update-rehearsal) | Try plugin, theme, core and PHP updates on a disposable copy of your site in WordPress Playground first |
| <img src="https://raw.githubusercontent.com/aryanjasala/user-session-manager/main/.wordpress-org/icon-128x128.png" alt="" width="32"> | [User Session Manager](https://github.com/aryanjasala/user-session-manager) | See every device signed in, sign out one, and limit sessions per role |

## License

Copyright Aryan Jasala. Licensed under [GPL-2.0-or-later](LICENSE.md).
