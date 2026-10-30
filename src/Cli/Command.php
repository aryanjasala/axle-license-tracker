<?php
/**
 * `wp axle-license-tracker` commands.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Cli;

use AryanX\Axle\LicenseTracker\Abilities;
use AryanX\Axle\LicenseTracker\Details;
use AryanX\Axle\LicenseTracker\Export;
use AryanX\Axle\LicenseTracker\Reminders;
use AryanX\Axle\LicenseTracker\Report;
use AryanX\Axle\LicenseTracker\Tracker;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, checks and annotates premium plugin and theme licenses.
 */
final class Command {

	/**
	 * Columns in `list`.
	 */
	private const COLUMNS = array( 'id', 'name', 'version', 'type', 'vendor', 'state', 'expires', 'risk', 'renewal', 'cost', 'currency', 'owner' );

	/**
	 * Rows.
	 *
	 * @var Tracker
	 */
	private Tracker $tracker;

	/**
	 * Manual details.
	 *
	 * @var Details
	 */
	private Details $details;

	/**
	 * Reminders.
	 *
	 * @var Reminders
	 */
	private Reminders $reminders;

	/**
	 * Exports.
	 *
	 * @var Export
	 */
	private Export $export;

	/**
	 * Sets up the command.
	 *
	 * @param Tracker   $tracker   Rows.
	 * @param Details   $details   Manual details.
	 * @param Reminders $reminders Reminders.
	 * @param Export    $export    Exports.
	 */
	public function __construct( Tracker $tracker, Details $details, Reminders $reminders, Export $export ) {
		$this->tracker   = $tracker;
		$this->details   = $details;
		$this->reminders = $reminders;
		$this->export    = $export;
	}

	/**
	 * Lists premium plugins and themes with their license state and update risk.
	 *
	 * ## OPTIONS
	 *
	 * [--state=<state>]
	 * : Only this license state: valid, expiring, expired, invalid, missing, unknown or not-applicable.
	 *
	 * [--risk=<risk>]
	 * : Only this update risk: ok, watch, at-risk or blocked.
	 *
	 * [--vendor=<vendor>]
	 * : Only this vendor (case-insensitive).
	 *
	 * [--all]
	 * : Every installed plugin and theme, directory ones too.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields to show.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml, ids or count. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-license-tracker list --risk=at-risk
	 *     wp axle-license-tracker list --all --format=json
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_( $args, $assoc_args ): void {
		unset( $args );
		$rows = \WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false ) ? $this->tracker->rows() : $this->tracker->tracked();
		$rows = self::filter( $rows, $assoc_args );

		$this->output( array_values( array_map( array( self::class, 'flat' ), $rows ) ), $assoc_args );
	}

	/**
	 * Shows everything known about one plugin or theme.
	 *
	 * ## OPTIONS
	 *
	 * <component>
	 * : Component id, such as plugin:folder/file.php or theme:folder.
	 *
	 * [--format=<format>]
	 * : table, json or yaml. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-license-tracker show plugin:elementor-pro/elementor-pro.php
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function show( $args, $assoc_args ): void {
		$row = $this->row( (string) $args[0] );
		$out = Report::component( $row, true );
		$fmt = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'table' === $fmt ) {
			$items = array();
			foreach ( $out as $field => $value ) {
				$items[] = array(
					'field' => $field,
					'value' => is_array( $value ) ? (string) wp_json_encode( $value ) : ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value ),
				);
			}
			\WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
			return;
		}
		\WP_CLI::print_value( $out, array( 'format' => $fmt ) );
	}

	/**
	 * Runs detection again and prints a summary.
	 *
	 * Asks WordPress for updates, reads every vendor's stored license data
	 * again and runs the fingerprint scan. Exits with 1 when a license is
	 * expired or an update is blocked or at risk, for monitoring.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-license-tracker scan || echo "Something needs a look"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function scan( $args, $assoc_args ): void {
		unset( $args, $assoc_args );
		$rows   = $this->tracker->recheck();
		$totals = Tracker::totals( $rows );

		\WP_CLI::log(
			sprintf(
				'%d premium: %d expired, %d expiring, %d missing a key, %d not getting updates (%d blocked).',
				$totals['premium'],
				$totals['expired'],
				$totals['expiring'],
				$totals['missing'],
				$totals['at_risk'],
				$totals['blocked']
			)
		);

		$problems = array_filter(
			$rows,
			static function ( array $row ): bool {
				return $row['tracked'] && ! $row['ignored'] && ( 'expired' === $row['state'] || in_array( $row['risk'], array( 'at-risk', 'blocked' ), true ) );
			}
		);
		foreach ( $problems as $row ) {
			\WP_CLI::log( sprintf( '- %s: %s, %s', $row['name'], $row['state'], '' !== $row['risk'] ? $row['risk'] : 'no risk data' ) );
		}

		if ( $problems ) {
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::success( 'No expired licenses and no blocked updates.' );
	}

	/**
	 * Lists licenses that renew within some days, and expired ones.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Days ahead. Default 30.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml or count. Default table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function expiring( $args, $assoc_args ): void {
		unset( $args );
		$list = Abilities::expiring( $this->tracker->tracked(), (int) ( $assoc_args['days'] ?? 30 ) );
		$this->output(
			array_map(
				static function ( array $entry ): array {
					return self::flat( $entry );
				},
				$list
			),
			$assoc_args,
			array( 'id', 'name', 'state', 'renewal', 'days_left', 'cost', 'currency', 'owner' )
		);
	}

	/**
	 * Sets license details detection cannot know.
	 *
	 * ## OPTIONS
	 *
	 * <component>
	 * : Component id.
	 *
	 * [--renewal=<date>]
	 * : Renewal date, YYYY-MM-DD.
	 *
	 * [--cost=<amount>]
	 * : Cost per cycle, such as 199.00. Needs --currency.
	 *
	 * [--currency=<code>]
	 * : Currency code, such as USD.
	 *
	 * [--cycle=<cycle>]
	 * : yearly, monthly, lifetime or one-off.
	 *
	 * [--owner=<owner>]
	 * : Who owns the license: the agency or the client.
	 *
	 * [--owner-email=<email>]
	 * : Who gets reminders for it, besides the site admin.
	 *
	 * [--seats=<seats>]
	 * : Seats bought.
	 *
	 * [--auto-renew=<yes-or-no>]
	 * : Whether it renews on its own.
	 *
	 * [--vendor=<vendor>]
	 * : Vendor name.
	 *
	 * [--plan=<plan>]
	 * : Plan name.
	 *
	 * [--purchase-source=<source>]
	 * : vendor, envato, reseller or gpl-club.
	 *
	 * [--account-url=<url>]
	 * : Where the license is managed.
	 *
	 * [--account-email=<email>]
	 * : The vendor account's email.
	 *
	 * [--invoice-url=<url>]
	 * : Link to the invoice.
	 *
	 * [--notes=<notes>]
	 * : Notes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-license-tracker set plugin:elementor-pro/elementor-pro.php --renewal=2027-03-01 --cost=59 --currency=USD --cycle=yearly --owner="Bean There"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function set( $args, $assoc_args ): void {
		$row   = $this->row( (string) $args[0] );
		$input = array();
		foreach ( array_keys( Details::fields() ) as $field ) {
			$flag = str_replace( '_', '-', $field );
			if ( 'renewal' === $field && isset( $assoc_args['renewal'] ) ) {
				$input['renewal'] = $assoc_args['renewal'];
			} elseif ( isset( $assoc_args[ $flag ] ) ) {
				$input[ $field ] = $assoc_args[ $flag ];
			}
		}
		if ( ! $input ) {
			\WP_CLI::error( 'Nothing to set. Pass at least one field, such as --renewal=2027-03-01.' );
		}
		if ( isset( $input['cost'] ) && ! isset( $input['currency'] ) && '' !== $row['currency'] ) {
			$input['currency'] = $row['currency'];
		}

		$errors = $this->details->save( $row, $input, get_current_user_id() );
		if ( $errors ) {
			foreach ( $errors as $field => $message ) {
				\WP_CLI::warning( $field . ': ' . $message );
			}
			\WP_CLI::error( 'Nothing was saved.' );
		}

		$this->tracker->flush();
		$this->tracker->sync();
		\WP_CLI::success( sprintf( 'Saved details for %s.', $row['name'] ) );
	}

	/**
	 * Ignores a plugin or theme: no reminders, not in the totals.
	 *
	 * ## OPTIONS
	 *
	 * <component>
	 * : Component id.
	 *
	 * [--note=<note>]
	 * : Why.
	 *
	 * [--undo]
	 * : Track it again.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function ignore( $args, $assoc_args ): void {
		$row  = $this->row( (string) $args[0] );
		$undo = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'undo', false );
		$this->tracker->ignore( $row['id'], ! $undo, (string) ( $assoc_args['note'] ?? '' ), get_current_user_id() );
		\WP_CLI::success( $undo ? sprintf( 'Tracking %s again.', $row['name'] ) : sprintf( 'Ignoring %s.', $row['name'] ) );
	}

	/**
	 * Exports the license table. Never includes license keys.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : csv or json. Default csv.
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-license-tracker export --format=json > licenses.json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function export( $args, $assoc_args ): void {
		unset( $args );
		$format = 'json' === ( $assoc_args['format'] ?? 'csv' ) ? 'json' : 'csv';
		\WP_CLI::line( rtrim( $this->export->render( $format ) ) );
	}

	/**
	 * Sends the renewal reminders, alerts and digest that are due, or shows them.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what the daily run would send, and send nothing.
	 *
	 * [--format=<format>]
	 * : table, json or count. Default table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function remind( $args, $assoc_args ): void {
		unset( $args );
		$dry = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		if ( $dry ) {
			$this->tracker->flush();
			$this->tracker->sync();
			$planned = $this->reminders->run( true );
		} else {
			$planned = $this->reminders->daily();
		}

		$items  = array_map(
			static function ( array $message ): array {
				return array(
					'component'  => $message['component'],
					'kind'       => $message['kind'],
					'threshold'  => (string) $message['threshold'],
					'subject'    => $message['subject'],
					'recipients' => implode( ', ', $message['recipients'] ),
				);
			},
			$planned
		);
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'table' === $format && ! $items ) {
			\WP_CLI::log( $dry ? 'Nothing is due.' : 'Nothing was due, so nothing was sent.' );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $items, array( 'component', 'kind', 'threshold', 'subject', 'recipients' ) );
	}

	/**
	 * A row, or an error when the component is not installed.
	 *
	 * @param string $id Component id.
	 * @return array
	 */
	private function row( string $id ): array {
		$row = $this->tracker->row( $id );
		if ( ! $row ) {
			\WP_CLI::error( sprintf( 'No plugin or theme "%s" is installed. Ids look like plugin:folder/file.php or theme:folder; `wp axle-license-tracker list --all --fields=id` lists them.', $id ) );
		}

		return (array) $row;
	}

	/**
	 * Filters rows by state, risk and vendor.
	 *
	 * @param array<string, array> $rows       Rows.
	 * @param array                $assoc_args Named arguments.
	 * @return array<string, array>
	 */
	public static function filter( array $rows, array $assoc_args ): array {
		return array_filter(
			$rows,
			static function ( array $row ) use ( $assoc_args ): bool {
				if ( isset( $assoc_args['state'] ) && $row['state'] !== $assoc_args['state'] ) {
					return false;
				}
				if ( isset( $assoc_args['risk'] ) && $row['risk'] !== $assoc_args['risk'] ) {
					return false;
				}
				if ( isset( $assoc_args['vendor'] ) && 0 !== strcasecmp( $row['vendor'], (string) $assoc_args['vendor'] ) ) {
					return false;
				}
				return true;
			}
		);
	}

	/**
	 * A row with scalar values, for format_items.
	 *
	 * @param array $row Row or report entry.
	 * @return array
	 */
	public static function flat( array $row ): array {
		$flat = array();
		foreach ( $row as $key => $value ) {
			if ( 'cost' === $key && null !== $value ) {
				$value = Details::from_minor( (int) $value, (string) ( $row['currency'] ?? '' ) );
			}
			$flat[ $key ] = is_array( $value ) ? implode( '; ', array_map( 'strval', $value ) ) : ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : $value );
		}

		return $flat;
	}

	/**
	 * Prints items.
	 *
	 * @param array[]  $items      Items.
	 * @param array    $assoc_args Named arguments.
	 * @param string[] $columns    Default columns.
	 */
	private function output( array $items, array $assoc_args, array $columns = self::COLUMNS ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$fields = isset( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', (string) $assoc_args['fields'] ) ) : $columns;
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', wp_list_pluck( $items, 'id' ) ) );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}
}
