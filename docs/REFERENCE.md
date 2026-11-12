# Reference

## How a component is classified

Every installed plugin and theme is a component, with an id such as `plugin:folder/file.php` or `theme:folder`. Detection goes from the cheapest signal to the most expensive:

1. A plugin the active theme registers with TGM Plugin Activation from a local zip: **bundled**. It only gets updates when the theme ships a new zip.
2. WordPress.org answers for it in the `update_plugins` or `update_themes` site transient: **directory**. No license needed, no row in the premium list.
3. A license reader (adapter) recognises it: **premium**.
4. An `Update URI` header that is not WordPress.org: **premium**, with that host as the source.
5. An entry in the update transient that did not come from WordPress.org, so a vendor updater answers for it: **premium**.
6. An updater or licensing SDK in its code: **premium**. The scan reads PHP files only, never runs them, up to 3 folders deep and 512 KB per file. Signatures live in `data/signatures.json`: Freemius, EDD Software Licensing, Plugin Update Checker, WooCommerce API Manager, StellarWP Uplink, SureCart Licensing, Appsero, Brainstorm Force, and TGM Plugin Activation (which marks a component that bundles others).
7. A known vendor by folder name or author URL, from `data/vendors.json`: **premium**.
8. None of these after WordPress checked for updates and the scan ran: **custom** code, probably bespoke. Before that: **unknown**.

The scan runs in the background, 5 components per cron run, and is cached per component version, so a plugin is scanned again only after it changes. "Check again" and `wp axle-license-tracker scan` run it for up to 20 seconds first.

## License state

A built-in adapter reads what each vendor already stores. No vendor code runs and no request goes out.

| Adapter | Reads | Gives |
| --- | --- | --- |
| Freemius | `fs_accounts`: the install's `license_id`, licenses in `all_licenses`, `file_slug_map` | state, expiry or lifetime, seats, fingerprint of `secret_key` |
| EDD Software Licensing | `{prefix}_license_key` and `{prefix}_license_status` (a word or the whole response), the updater's `edd_sl_` version cache | state, expiry, newest version, seats |
| WooCommerce.com | the `Woo` header, `woocommerce_helper_data` (connection) and the `_woocommerce_helper_subscriptions` transient | state, expiry or lifetime, auto-renew, seats |
| Elementor Pro | `elementor_pro_license_key`, `_elementor_pro_license_v2_data` and its fallback | state, expiry, when it was checked, stale cache |
| ACF PRO | `acf_pro_license` (read with a pattern, never unserialized) or `ACF_PRO_LICENSE`, `acf_pro_license_status` | state, expiry or lifetime |
| Gravity Forms | `rg_gforms_key` (an md5) or `GF_LICENSE_KEY`, `gform_version_info` | state, expiry, newest version, when it was checked |
| Envato Market | `envato_market` token | whether a token is there; Envato purchases do not expire |

The EDD prefix is the folder name with underscores (`my-addon` reads `my_addon_license_key`), then the folder name as is, then the main file's name.

States: `valid`, `expiring` (a valid or unknown license whose renewal date is within the largest reminder day, 30 by default), `expired`, `invalid` (deactivated, wrong site, refunded, cancelled), `missing` (wants a key, none stored), `unknown` (no reader, or nothing readable) and `not-applicable` (directory, custom and bundled components). A stored "valid" whose expiry date has passed counts as expired.

Each row says how old its state is: "as of 3 days ago" when the vendor stored when it checked, "first read 3 days ago" when it did not, and "vendor cache expired" when the vendor's own cache ran out.

## Update risk

For premium and bundled components, whatever the license says:

| Risk | When |
| --- | --- |
| `blocked` | WordPress offers a newer version, but the update entry has no package to download |
| `at-risk` | The vendor's own cached data knows a newer version WordPress is not offering; the updater has not answered for 30 days (`axle_license_tracker_silent_days`); or a vulnerability flag came in through `axle_license_tracker_vulnerability_flags` |
| `watch` | The installed version has not changed for 180 days (`axle_license_tracker_stale_version_days`), a weak signal; or a bundled plugin |
| `ok` | None of these |

"Has not answered" is measured: every time WordPress saves the update transient, the plugin notes which components a vendor updater answered for.

## Details entered by hand

Vendor, plan, bought from, owner, owner email, renewal date, billing cycle (yearly, monthly, lifetime, one-off), cost and currency, auto-renew, seats bought, where the license is managed, account email, invoice link and notes. "Bought from" is vendor, Envato, reseller or GPL club. The last two get a warning, since those builds usually have no official updates.

What you enter wins over what was detected, and the list marks it "set by hand". Costs are stored as whole numbers in minor units (19900 for 199.00 USD), never as floats. The yearly cost tile adds monthly costs times 12 and yearly costs per currency; lifetime and one-off costs are left out. Currencies are never converted.

## Reminders

A daily cron event (`axle_license_tracker_daily`) checks every tracked component again, then sends:

- a reminder before a renewal date, at 30, 14, 7 and 1 days by default (Plugins > Licenses > Reminders, or `axle_license_tracker_reminder_days`). A date 7 days out gets the 7-day reminder only, not the 30 and 14 ones as well.
- an alert when a license is expired, or its updates are blocked or at risk (on by default)
- a monthly digest of every license (off by default)

Each reminder goes out once per component, date and reminder day, and each alert once until the state changes. That is kept in the component's record, so a second cron run or a cron hiccup sends nothing twice. Reminders go to the site admin email, the extra recipients in the settings and the license's owner email. Ignored components get none.

## The hub: every site's licenses on one screen

Any site with the plugin can be a hub. It is off by default: turn it on under Plugins > Licenses > Settings, on the Sites tab, or with `wp axle-license-tracker hub enable`. Then add each site you look after:

1. On the other site, install Axle License Tracker and create a user with the **License reporter** role.
2. Create an application password for that user (Users > Profile).
3. On the hub, open Plugins > Licenses > Sites > Add a site, or run `echo "<password>" | wp axle-license-tracker hub add https://client.example.com --username=reporter --password-stdin`.

The hub fetches `axle-license-tracker/v1/report` of each site: one `GET` per site, nothing else, so it never changes anything on a remote site. A cron event (`axle_license_tracker_hub_sync`) runs every hour and syncs the sites that are due, 5 at a time. A good site is fetched again after a day plus a delay of its own (up to 6 hours, from its id) so sites are not all fetched in the same minute. A site that fails is tried again after 1 hour, then 2, 4, 8 and at most 24 hours. "Sync all sites now" and `hub sync --all` fetch on demand.

The Sites tab shows: sites and whether each could be reached (with the reason and what to do), every license across all sites with filters and search, "expired or renewing in 30 days", yearly and monthly cost per vendor and per currency (never converted), and the same key on several sites. This site is included next to the others.

**Duplicate keys.** Keys are compared by fingerprint, never by the key. A key on more than one site is listed with its sites. When a reader knows how many sites the plan allows (the `seats` it reports or you entered), a key on more sites than that is marked over its limit. When it does not, the screen only says "On 6 sites".

**Credentials.** The application password is encrypted with AES-256-GCM using a key derived from the site's salts (and, when defined, the `AXLE_LICENSE_TRACKER_HUB_KEY` constant in wp-config.php). It is never shown again, never in the report, an export, an ability or the REST API, and it is deleted with the site. If the salts change, the saved password cannot be read any more: remove the site and add it again.

**What a request can reach.** Only https addresses, with no user name or password in them, and no loopback, private or reserved addresses (checked when you add a site and again when the request is made, with core's own safe request). Requests wait 15 seconds, follow no redirects and read at most 1 MB. Use the `axle_license_tracker_hub_allow_private` filter to allow http and private addresses for local testing only.

**What is kept from a report.** Only known fields, each cleaned and cut to length: product, version, vendor, license state, update risk, renewal date, billing cycle, cost, currency, seats and key fingerprint, up to 500 components per site. Owner names and emails are not kept.

## Costs for the next 12 months

Plugins > Licenses > Costs adds up the renewals of the next 12 months from the renewal dates, costs and currencies you entered (or that a reader found), per month, per vendor and per currency. A yearly license counts in the month it renews. A monthly one counts every month from its date. A date in the past is rolled forward only for a license that renews by itself and has not expired; otherwise the license is listed under "Needs a renewal date". One-off and lifetime costs, and licenses with no cost, are listed apart and are not in the totals, so nothing is hidden. With the hub on, "All sites in the hub" adds the other sites. **Download CSV** (or `wp axle-license-tracker costs --format=csv`) has one line per charge, then the items left out, with a `kind` column.

## The calendar feed

Off by default. On the Costs tab, "Turn on the calendar feed" makes a private link such as `https://example.com/?axle-license-tracker-ics=<40 random characters>` that Google Calendar, Apple Calendar or Outlook can subscribe to. Each renewal is an all-day event with the product, vendor, cost and site, and a reminder 7 days before (`axle_license_tracker_feed_alarm_days`). An event never carries a license key, a fingerprint, an email address or a link into wp-admin. With the hub on, the feed can include the other sites' renewals. The link is a secret: anyone who has it can read your renewal dates and costs. "Make a new link" stops the old one at once, and turning the feed off forgets it. A wrong or old token gets a plain 404. From the command line: `wp axle-license-tracker feed status|enable|disable|regenerate`.

## Hooks

### Filters

| Filter | Arguments | What it changes |
| --- | --- | --- |
| `axle_license_tracker_capability` | `( string $capability )` | Who sees Plugins > Licenses, changes details and runs the abilities. Default `manage_options` |
| `axle_license_tracker_report_capability` | `( string $capability )` | Who may read `axle-license-tracker/v1/report`. Default `axle_license_tracker_read_report` |
| `axle_license_tracker_adapters` | `( Adapter[] $adapters )` | License readers, most specific first. Add your own (example below) |
| `axle_license_tracker_signatures` | `( array $signatures )` | Code signatures for the scan: `id`, `label`, and `path` or `pattern` (a regular expression) |
| `axle_license_tracker_vendors` | `( array $vendors )` | Known vendors: `id`, `name`, `slugs`, `authors`, `license_url` |
| `axle_license_tracker_component_type` | `( string $type, array $component, array $detection )` | A component's type: directory, premium, bundled, custom or unknown |
| `axle_license_tracker_license_state` | `( string $state, array $component, array $license )` | A component's license state, before renewal dates make it expiring |
| `axle_license_tracker_update_risk` | `( string $risk, array $component, string[] $signals )` | A component's update risk: ok, watch, at-risk or blocked |
| `axle_license_tracker_silent_days` | `( int $days )` | Days an updater may stay silent. Default 30, 1 to 365 |
| `axle_license_tracker_stale_version_days` | `( int $days )` | Days on one version before "watch". Default 180, 1 to 3650 |
| `axle_license_tracker_scan_max_depth` | `( int $depth )` | Folders deep the scan reads. Default 3, 0 to 10 |
| `axle_license_tracker_scan_max_file_bytes` | `( int $bytes )` | Largest file the scan reads. Default 524288, 1 KB to 5 MB |
| `axle_license_tracker_scan_batch_size` | `( int $size )` | Components scanned per cron run. Default 5, 1 to 50 |
| `axle_license_tracker_reminder_days` | `( int[] $days )` | Days before a renewal date for reminders. Default the setting, 30, 14, 7 and 1 |
| `axle_license_tracker_reminder_recipients` | `( string[] $recipients, ?array $row, string $kind )` | Who gets a reminder, alert or digest. Kind is `renewal`, `alert` or `digest` |
| `axle_license_tracker_reminder_email` | `( array $email, array $row, int\|string $threshold, string $kind )` | Subject and body of a reminder or alert |
| `axle_license_tracker_digest_enabled` | `( bool $enabled )` | Whether the monthly digest goes out. Default the setting, off |
| `axle_license_tracker_vendor_license_url` | `( string $url, array $row )` | Where the "Open license page" row action goes |
| `axle_license_tracker_purchase_sources` | `( array $sources )` | The "Bought from" choices: key => `label`, `flag` |
| `axle_license_tracker_report` | `( array $report, bool $full )` | The report before it is served or exported. `$full` is true for the admin's own export, with emails and notes |
| `axle_license_tracker_vulnerability_flags` | `( string[] $flags, array $component )` | Vulnerability notes from a scanner you use. Any flag makes the component at risk |
| `axle_license_tracker_messages` | `( array $messages )` | Labels and notices on the screens |
| `axle_license_tracker_hub_enabled` | `( bool $enabled )` | Whether this site acts as a hub. Default the setting, off |
| `axle_license_tracker_hub_allow_private` | `( bool $allow, string $url )` | Allow http and loopback or private addresses for hub sites. Default false. For local testing only |
| `axle_license_tracker_hub_max_sites` | `( int $limit )` | Sites a hub can hold. Default 100, 1 to 500 |
| `axle_license_tracker_hub_interval` | `( int $seconds )` | Seconds between two syncs of a site. Default one day, one hour to a week |
| `axle_license_tracker_hub_backoff` | `( int $seconds, int $fails, string $site )` | Seconds to wait after a failed sync. Default 1 hour doubled for each failure in a row, at most a day |
| `axle_license_tracker_hub_batch` | `( int $batch )` | Sites per sync run. Default 5, 1 to 25 |
| `axle_license_tracker_hub_timeout` | `( int $seconds )` | How long to wait for a site. Default 15, 3 to 60 |
| `axle_license_tracker_hub_max_bytes` | `( int $bytes )` | Largest report the hub reads. Default 1 MB, 10 KB to 10 MB |
| `axle_license_tracker_hub_max_components` | `( int $max )` | Components kept from one site. Default 500, 10 to 2000 |
| `axle_license_tracker_hub_health_fails` | `( int $fails )` | Failed syncs in a row before Site Health complains about a site. Default 3 |
| `axle_license_tracker_forecast` | `( array $forecast, string $today )` | The 12-month cost forecast: `months`, `totals`, `vendors`, `one_off`, `unknown_cost`, `no_date` |
| `axle_license_tracker_feed_events` | `( array[] $events )` | The calendar feed's events: `uid`, `date`, `summary`, `description`. Keep keys and emails out of what you add |
| `axle_license_tracker_feed_alarm_days` | `( int $days )` | Days before a renewal the calendar app reminds you. Default 7, 0 to 60, 0 for none |

Every filtered value is checked again afterwards: numbers are clamped, unknown states and types fall back to the detected one, and an empty capability falls back to the default.

### Actions

| Action | Arguments | When |
| --- | --- | --- |
| `axle_license_tracker_detected` | `( array $rows )` | After each detection run, with every component row |
| `axle_license_tracker_state_changed` | `( array $row, string $old, string $new )` | A license state changed. `$old` is `''` the first time |
| `axle_license_tracker_risk_changed` | `( array $row, string $old, string $new )` | An update risk changed |
| `axle_license_tracker_license_expired` | `( array $row )` | A license was found expired |
| `axle_license_tracker_license_expiring` | `( array $row, int $days )` | A renewal reminder went out |
| `axle_license_tracker_reminder_sent` | `( ?array $row, int\|string $threshold, string[] $recipients )` | Any email went out: the reminder day, `expired`, `blocked`, `at-risk` or `digest` |
| `axle_license_tracker_details_saved` | `( array $component, array $fields, int $user_id )` | Details were saved by hand |
| `axle_license_tracker_ignored` | `( string $id, bool $ignored, string $note, int $user_id )` | A component was ignored or tracked again |
| `axle_license_tracker_hub_toggled` | `( bool $enabled )` | The hub was turned on or off |
| `axle_license_tracker_hub_site_added` | `( array $site )` | A site was added to the hub (without its password) |
| `axle_license_tracker_hub_site_removed` | `( string $id, array $site )` | A site, its password and its data were removed |
| `axle_license_tracker_hub_synced` | `( string $id, array $report )` | A site was fetched: name, plugin, generated, fetched, components |
| `axle_license_tracker_hub_sync_failed` | `( string $id, WP_Error $error, int $fails )` | A site could not be fetched. `$fails` counts failures in a row |
| `axle_license_tracker_feed_enabled` | none | The calendar feed was turned on |
| `axle_license_tracker_feed_regenerated` | none | The calendar feed got a new link |
| `axle_license_tracker_feed_disabled` | none | The calendar feed was turned off |

### JavaScript (`wp.hooks`, on Plugins > Licenses)

Read when the page has loaded, so scripts that load later can still hook in.

| Hook | Kind | Arguments |
| --- | --- | --- |
| `axleLicenseTracker.rowActions` | filter | `( actions, row )`: actions is an array of `{ key, label, url }`, row is `{ id, state, risk, vendor }` |
| `axleLicenseTracker.costFormat` | filter | `( text, minor, currency )`: how a cost is shown. Default `Intl.NumberFormat` in the user's locale |
| `axleLicenseTracker.detailsSaved` | action | `( component, fields )` after details were saved |
| `axleLicenseTracker.announce` | filter | `( text, kind )`: the sentence read to screen readers after an action. Kind is `notice`, `busy` or `copied` |
| `axleLicenseTracker.confirmOpened` | action | `( id )` when an in-place confirm step opens |
| `axleLicenseTracker.confirmClosed` | action | `( id )` when it is cancelled |

```js
wp.hooks.addFilter( 'axleLicenseTracker.rowActions', 'my-crm', ( actions, row ) =>
	'expired' === row.state ? [ ...actions, { key: 'crm', label: 'Open in CRM', url: 'https://crm.example.com/?q=' + encodeURIComponent( row.id ) } ] : actions
);
```

## Adding a reader for your own plugin

A vendor can teach the tracker its license storage. The adapter only reads; it must never call your servers or change anything.

```php
use AryanX\Axle\LicenseTracker\Adapters\Adapter;
use AryanX\Axle\LicenseTracker\Adapters\LicenseState;

add_filter( 'axle_license_tracker_adapters', function ( array $adapters ) {
	array_unshift( $adapters, new class() implements Adapter {
		public function id(): string { return 'acme-forms'; }
		public function label(): string { return 'Acme Forms'; }
		public function detect( array $component, array $signals ): bool {
			return 'acme-forms-pro/acme-forms-pro.php' === $component['file'];
		}
		public function read( array $component ): LicenseState {
			$data = (array) get_option( 'acme_forms_license', array() );
			return new LicenseState( array(
				'state'   => $data['status'] ?? 'missing', // valid, expired, invalid, missing or unknown.
				'expires' => $data['expires'] ?? '',        // Y-m-d, a Unix time or "lifetime".
				'key'     => $data['key'] ?? '',            // Only its fingerprint is kept.
				'checked' => (int) ( $data['checked_at'] ?? 0 ),
			) );
		}
		public function license_url( array $component ): string {
			return admin_url( 'admin.php?page=acme-forms-license' );
		}
	} );
	return $adapters;
} );
```

## Abilities (WordPress 6.9+)

Category `axle-license-tracker`. Each needs the capability from `axle_license_tracker_capability`. None returns a key.

| Ability | Annotations | Input | Returns |
| --- | --- | --- | --- |
| `axle-license-tracker/get-license-report` | readonly | none | The report: site, totals, components |
| `axle-license-tracker/list-expiring` | readonly | `days` (0 to 365, default 30) | Components that renew within the days, and expired ones, soonest first |
| `axle-license-tracker/get-component` | readonly | `component` | One component |
| `axle-license-tracker/get-hub-report` | readonly | `days` (0 to 365, default 30) | Sites and whether each was reached, costs per currency and vendor, what renews soon and keys on several sites. Needs the hub on. No keys, no passwords |
| `axle-license-tracker/get-cost-forecast` | readonly | `months` (1 to 24, default 12), `sites` (also count the hub's sites) | The cost forecast per month, vendor and currency, with what is left out |
| `axle-license-tracker/sync-hub` | idempotent | `all` (default false) | Fetches the sites that are due, or all. Read only on the remote sites |
| `axle-license-tracker/recheck-licenses` | idempotent | none | Totals after checking again |
| `axle-license-tracker/update-license-details` | idempotent | `component`, `details` | The component after saving. An empty string clears a field |
| `axle-license-tracker/ignore-component` | idempotent | `component`, `ignore` (default true), `note` | The component |

```sh
curl -u agent:"app password" "https://example.com/wp-json/wp-abilities/v1/abilities/axle-license-tracker/list-expiring/run?input[days]=14"
```

## REST report and the License reporter role

`GET /wp-json/axle-license-tracker/v1/report` returns the same JSON as the `get-license-report` ability, without emails, account links or notes. Free-text fields are also cleaned of email addresses and server paths, so a License reporter sees nothing a hub does not need. It needs the `axle_license_tracker_read_report` capability (or the manage capability). Activation adds a **License reporter** role with only `read` and that capability, so an agency can create one user with an application password that reads the report and nothing else. Use HTTPS.

## WP-CLI

| Command | What it does |
| --- | --- |
| `wp axle-license-tracker list [--state=] [--risk=] [--vendor=] [--all] [--fields=] [--format=]` | Premium components, or every component with `--all` |
| `wp axle-license-tracker show <component> [--format=]` | Everything about one component |
| `wp axle-license-tracker scan` | Checks again; exits with 1 when a license is expired or an update is blocked or at risk |
| `wp axle-license-tracker expiring [--days=30] [--format=]` | What renews soon, and what expired |
| `wp axle-license-tracker set <component> [--renewal=] [--cost=] [--currency=] [--cycle=] [--owner=] [--owner-email=] [--seats=] [--auto-renew=] [--vendor=] [--plan=] [--purchase-source=] [--account-url=] [--account-email=] [--invoice-url=] [--notes=]` | Saves details |
| `wp axle-license-tracker ignore <component> [--note=] [--undo]` | Ignores a component, or tracks it again |
| `wp axle-license-tracker export [--format=csv\|json]` | The table, with fingerprints and no keys |
| `wp axle-license-tracker remind [--dry-run] [--format=]` | Sends what is due, or shows what the daily run would send |
| `wp axle-license-tracker costs [--months=12] [--sites] [--format=table\|csv\|json\|yaml]` | What the next months cost, per month and currency, and what is left out |
| `wp axle-license-tracker hub add <url> --username= [--password=\|--password-stdin] [--label=] [--skip-sync]` | Adds a remote site and fetches it |
| `wp axle-license-tracker hub list [--format=]` | The remote sites and whether each was reached |
| `wp axle-license-tracker hub remove <id> [--yes]` | Removes a site, its password and its data |
| `wp axle-license-tracker hub sync [<id>] [--all]` | Fetches a site, the sites that are due, or all |
| `wp axle-license-tracker hub licenses [--duplicates] [--days=] [--format=]` | Every license across sites, or only keys on several sites |
| `wp axle-license-tracker hub enable\|disable` | Turns the hub on or off. Sites and data are kept |
| `wp axle-license-tracker feed status\|enable [--hub]\|disable\|regenerate [--yes]` | The calendar feed and its secret link |

## Data

| Where | What |
| --- | --- |
| `axle_license_tracker_settings` option | Reminder days, extra recipients, alerts, digest, hub on or off |
| `axle_license_tracker_hub_sites` option (not autoloaded) | The remote sites: address, user name, label, the encrypted application password and when each was last fetched |
| `axle_license_tracker_hub_data_<id>` options (not autoloaded) | What each remote site last reported, cleaned |
| `axle_license_tracker_feed` option (not autoloaded) | The calendar feed's secret token, whether the hub's renewals are included, when the link was made |
| `axle_license_tracker_steps_dismissed` user meta | Whether a person hid the Get started card |
| `AXLE_LICENSE_TRACKER_HUB_KEY` constant (optional) | Extra secret mixed into the key that encrypts hub passwords. Define it in wp-config.php |
| `axle_license_tracker_detection` option (not autoloaded) | Scan results per component version, when each updater last answered, installed versions and since when, the last digest month |
| `alc_license` posts (not public, not in REST) | One per tracked component. The component id is the title and `_alc_component`. Meta: the manual fields (`_alc_renewal`, `_alc_cost`, ...), `_alc_key_fp`, `_alc_ignored`, `_alc_reminders_sent`, `_alc_alerts_sent`, `_alc_state`, `_alc_risk`, `_alc_state_since`, `_alc_saved_by`, `_alc_saved_at` |
| `axle_license_reporter` role, `axle_license_tracker_read_report` capability | Added on activation |
| `axle_license_tracker_daily`, `axle_license_tracker_scan` and `axle_license_tracker_hub_sync` cron events | The daily check, the background scan and the hourly hub run (only while the hub is on) |

Uninstalling deletes the options (hub sites, their data and the feed token included), the `alc_license` posts and their meta, the user meta, the cron events, the role and the capability, on every site of a network.

## Privacy

Owner and account emails in license details are personal data, as is the user who saved them. **Tools > Export Personal Data** lists the records that hold an email or were saved by its user; **Tools > Erase Personal Data** blanks those emails and forgets who saved. Settings > Privacy gets suggested text. License keys are never stored, only fingerprints. The hub stores no personal data of its own: what it keeps from a remote report has no email addresses and no owner names, and the user name saved for a site is a login on that other site. Settings > Privacy text mentions the hub and the calendar feed.

## Limits

- No vendor is contacted, so a stored state can be out of date. Each row says how old it is.
- Only the active theme's TGMPA list is known, so bundled plugins of an inactive theme show as custom.
- On multisite each site has its own list of the same network-wide plugins, its own hub and its own calendar feed. A Network Admin screen is not part of this version.
- The hub shows what each site last reported, up to a day old. A site you cannot reach keeps showing its last good report, with the failure and its reason beside it.
- Adapters read storage formats checked against each vendor's public code or documented behaviour. A vendor can change its format; an unreadable state shows as unknown, never as valid.
