=== Axle License Tracker ===
Contributors: aryanjasala
Tags: license, premium plugins, renewals, updates, agency
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which premium plugins and themes are licensed, which lapsed, which stopped getting updates, and what renews when.

== Description ==

A typical client site runs 5 to 15 paid plugins and a paid theme. Each vendor has its own license screen and sends its renewal email to whoever bought it. When a license lapses, updates stop, and WordPress mostly says nothing. Security scanners that compare versions with WordPress.org skip premium plugins too. You find out when the plugin has a public CVE.

Axle License Tracker puts every premium plugin and theme in one list under **Plugins > Licenses**: the license state, how old that information is, whether updates are actually arriving, the renewal date, the cost and who owns it.

Part of Axle by AryanX, a family of plugins for running WordPress sites safely.

**Finds the premium ones:**

* WordPress.org plugins and themes are recognised from the update data WordPress already has, so they get no row.
* Premium ones are found from an `Update URI` header, a vendor updater answering in the update data, an updater or licensing SDK in their code (Freemius, EDD Software Licensing, Plugin Update Checker, WooCommerce API Manager, StellarWP Uplink, SureCart, Appsero, Brainstorm Force), or a known vendor.
* Plugins a theme ships as a zip through TGM Plugin Activation are marked bundled: they only update when the theme does.

**Reads the license state, never changes it:**

* Built-in readers for Freemius, EDD Software Licensing, WooCommerce.com, Elementor Pro, ACF PRO, Gravity Forms and Envato Market read what the vendor already stores on the site. No vendor code runs and no request goes out, so reading can never use up a seat.
* States: valid, expiring, expired, invalid, no key, unknown. Each one says how old it is, such as "as of 3 days ago", because stored status is what the vendor said last time, not now.

**Tells you when updates stop arriving:**

* **Update blocked**: a newer version is out, but the site cannot download it. The clearest sign of a lapsed license, for any vendor.
* **At risk**: the vendor's own data knows a newer version this site is not offered, or the updater has not answered for 30 days.
* **Watch**: same version for 180 days, or a bundled plugin.

**Renewals and costs:**

* Add what detection cannot know: renewal date, cost and currency, billing cycle, owner, seats, where the key is managed, invoice link, notes. What you enter wins over what was detected, and the list shows which is which.
* Yearly cost per currency, never converted.
* Reminder emails 30, 14, 7 and 1 days before a renewal date, each sent once, to the site admin and the license's owner. An email when a license expires or updates get blocked. A monthly digest if you want one.

**Also:**

* A License column on the Plugins screen and a dashboard widget with the next renewals.
* A Site Health check: critical when an update is blocked.
* CSV and JSON export.
* A read-only REST report and a License reporter role, for an agency site that collects reports from client sites.
* WP-CLI for everything, abilities for AI agents (WordPress 6.9+), and privacy export and erase.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

= Keys stay on the site =

The plugin never stores, shows, sends or logs a license key. It reads a vendor's key option only to know a key exists and to make a fingerprint: the first 12 characters of its SHA-256 hash plus the last 4 characters. The fingerprint is enough to spot the same license used on several sites, and not enough to rebuild the key.

= WP-CLI =

* `wp axle-license-tracker list [--state=] [--risk=] [--vendor=] [--all] [--format=]` lists licenses.
* `wp axle-license-tracker scan` checks again and exits with 1 when a license is expired or an update is blocked or at risk, for monitoring.
* `wp axle-license-tracker expiring [--days=30]`, `show <component>`, `export [--format=csv|json]`.
* `wp axle-license-tracker set <component> --renewal=2027-03-01 --cost=59 --currency=USD --cycle=yearly --owner="Client"` adds details.
* `wp axle-license-tracker ignore <component> [--note=] [--undo]` and `remind [--dry-run]`.

= For developers =

Every default, limit, list and message has a filter, and every change fires an action. Vendors can add their own reader through `axle_license_tracker_adapters`: an object with `detect()` and `read()`. The full list is in [the reference](https://github.com/aryanjasala/axle-license-tracker/blob/main/docs/REFERENCE.md).

== External services ==

None. The plugin makes no requests of its own. "Check again" asks WordPress to check for plugin and theme updates, which WordPress does twice a day anyway; that request goes to WordPress.org and to any vendor updater already installed on the site.

== Frequently Asked Questions ==

= Does it activate or check licenses with the vendor? =

No. It only reads what each vendor already stored on the site. Activating can use up a paid seat and every vendor does it differently, so the plugin leaves that to the vendor's own screen and links to it.

= My premium plugin shows "Unknown". Why? =

There is no shared license API in WordPress. When no built-in reader knows the vendor, the plugin still finds out whether updates arrive, and you can add the renewal date by hand. Vendors can ship their own reader through the `axle_license_tracker_adapters` filter.

= What does "first read 3 days ago" mean? =

The vendor did not store when it last checked the license, so the age is counted from when this plugin first saw that state.

= Can I see all my client sites in one place? =

Not yet in this version. Each site serves a read-only report at `/wp-json/axle-license-tracker/v1/report` to a user with the License reporter role, which can read that and nothing else. A hub screen that collects those reports is planned.

= Does it work on multisite? =

Yes, per site. Plugins are network-wide on multisite, so every site lists the same plugins. A Network Admin screen is planned.

= Does it slow down the site? =

No. The code scan reads at most 3 folders deep and 512 KB per file, runs 5 plugins at a time in the background, and is cached until a plugin changes version. Nothing runs on the front end.

== Screenshots ==

1. Plugins > Licenses: license state and age, update risk, renewal dates, costs per currency and owners.
2. Editing the details detection cannot know, next to what was detected.
3. The License column on the Plugins screen.
4. The dashboard widget: what needs a look and the next renewals.

== Changelog ==

= 1.0.0 =
* First release.
