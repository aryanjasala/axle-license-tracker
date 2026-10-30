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

### JavaScript (`wp.hooks`, on Plugins > Licenses)

Read when the page has loaded, so scripts that load later can still hook in.

| Hook | Kind | Arguments |
| --- | --- | --- |
| `axleLicenseTracker.rowActions` | filter | `( actions, row )`: actions is an array of `{ key, label, url }`, row is `{ id, state, risk, vendor }` |
| `axleLicenseTracker.costFormat` | filter | `( text, minor, currency )`: how a cost is shown. Default `Intl.NumberFormat` in the user's locale |
| `axleLicenseTracker.detailsSaved` | action | `( component, fields )` after details were saved |

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
| `axle-license-tracker/recheck-licenses` | idempotent | none | Totals after checking again |
| `axle-license-tracker/update-license-details` | idempotent | `component`, `details` | The component after saving. An empty string clears a field |
| `axle-license-tracker/ignore-component` | idempotent | `component`, `ignore` (default true), `note` | The component |

```sh
curl -u agent:"app password" "https://example.com/wp-json/wp-abilities/v1/abilities/axle-license-tracker/list-expiring/run?input[days]=14"
```

## REST report and the License reporter role

`GET /wp-json/axle-license-tracker/v1/report` returns the same JSON as the `get-license-report` ability, without emails, account links or notes. It needs the `axle_license_tracker_read_report` capability (or the manage capability). Activation adds a **License reporter** role with only `read` and that capability, so an agency can create one user with an application password that reads the report and nothing else. Use HTTPS.

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

## Data

| Where | What |
| --- | --- |
| `axle_license_tracker_settings` option | Reminder days, extra recipients, alerts, digest |
| `axle_license_tracker_detection` option (not autoloaded) | Scan results per component version, when each updater last answered, installed versions and since when, the last digest month |
| `alc_license` posts (not public, not in REST) | One per tracked component. The component id is the title and `_alc_component`. Meta: the manual fields (`_alc_renewal`, `_alc_cost`, ...), `_alc_key_fp`, `_alc_ignored`, `_alc_reminders_sent`, `_alc_alerts_sent`, `_alc_state`, `_alc_risk`, `_alc_state_since`, `_alc_saved_by`, `_alc_saved_at` |
| `axle_license_reporter` role, `axle_license_tracker_read_report` capability | Added on activation |
| `axle_license_tracker_daily` and `axle_license_tracker_scan` cron events | The daily check and the background scan |

Uninstalling deletes the options, the `alc_license` posts and their meta, the cron events, the role and the capability, on every site of a network.

## Privacy

Owner and account emails in license details are personal data, as is the user who saved them. **Tools > Export Personal Data** lists the records that hold an email or were saved by its user; **Tools > Erase Personal Data** blanks those emails and forgets who saved. Settings > Privacy gets suggested text. License keys are never stored, only fingerprints.

## Limits

- No vendor is contacted, so a stored state can be out of date. Each row says how old it is.
- Only the active theme's TGMPA list is known, so bundled plugins of an inactive theme show as custom.
- On multisite each site has its own list of the same network-wide plugins. A Network Admin screen and the agency hub that collects client reports are planned for 1.1; the data model and the report route are ready for them.
- Adapters read storage formats checked against each vendor's public code or documented behaviour. A vendor can change its format; an unreadable state shows as unknown, never as valid.
