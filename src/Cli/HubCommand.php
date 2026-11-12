<?php
/**
 * `wp axle-license-tracker hub` commands.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Cli;

use AryanX\Axle\LicenseTracker\Hub\Hub;
use AryanX\Axle\LicenseTracker\Hub\Overview;

defined( 'ABSPATH' ) || exit;

/**
 * Watches the licenses of other sites from this one. Read only: the hub never
 * changes anything on a remote site.
 */
final class HubCommand {

	/**
	 * The hub.
	 *
	 * @var Hub
	 */
	private Hub $hub;

	/**
	 * Sets up the command.
	 *
	 * @param Hub $hub The hub.
	 */
	public function __construct( Hub $hub ) {
		$this->hub = $hub;
	}

	/**
	 * Adds a remote site.
	 *
	 * The user needs the License reporter role on that site. The password is
	 * stored encrypted and is never shown again.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The site address, https only.
	 *
	 * --username=<username>
	 * : User name on that site, from its License reporter role. Not --user, which WP-CLI keeps for itself.
	 *
	 * [--password=<password>]
	 * : Application password. Prefer --password-stdin, which keeps it out of the shell history.
	 *
	 * [--password-stdin]
	 * : Read the application password from standard input.
	 *
	 * [--label=<label>]
	 * : Name to show. Default the host.
	 *
	 * [--skip-sync]
	 * : Do not fetch the site right away.
	 *
	 * ## EXAMPLES
	 *
	 *     echo "abcd efgh ijkl mnop" | wp axle-license-tracker hub add https://client.example.com --username=reporter --password-stdin
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function add( $args, $assoc_args ): void {
		$password = (string) ( $assoc_args['password'] ?? '' );
		if ( ! empty( $assoc_args['password-stdin'] ) ) {
			$password = trim( (string) fgets( STDIN ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_get_contents_file_get_contents -- Reads one line from the shell.
		}

		$site = $this->hub->sites()->add( (string) $args[0], (string) ( $assoc_args['username'] ?? '' ), $password, (string) ( $assoc_args['label'] ?? '' ) );
		if ( is_wp_error( $site ) ) {
			\WP_CLI::error( $site->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Added %s (%s).', $site['label'], $site['id'] ) );
		if ( ! $this->hub->enabled() ) {
			\WP_CLI::warning( 'The hub is off, so nothing is fetched on a schedule. Turn it on with `wp axle-license-tracker hub enable`.' );
		}
		if ( empty( $assoc_args['skip-sync'] ) ) {
			$result = $this->hub->sync( $site['id'] );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( sprintf( 'The first check failed: %s The site stays in the hub and is tried again later.', $result->get_error_message() ) );
				return;
			}
			\WP_CLI::success( sprintf( '%s reported %d licenses.', $result['label'], $result['count'] ) );
		}
	}

	/**
	 * Lists the remote sites.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml, ids or count. Default table.
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_( $args, $assoc_args ): void {
		unset( $args );
		$items = array();
		foreach ( $this->hub->sites()->all() as $site ) {
			$items[] = array(
				'id'         => $site['id'],
				'label'      => $site['label'],
				'url'        => $site['url'],
				'user'       => $site['user'],
				'licenses'   => $site['count'],
				'last_ok'    => $site['ok'] ? gmdate( 'c', $site['ok'] ) : '',
				'failures'   => $site['fails'],
				'last_error' => $site['error'],
				'next_sync'  => $site['next'] ? gmdate( 'c', $site['next'] ) : '',
			);
		}

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', wp_list_pluck( $items, 'id' ) ) );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $items, array( 'id', 'label', 'url', 'licenses', 'last_ok', 'failures', 'last_error' ) );
	}

	/**
	 * Removes a remote site, its password and everything it reported.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Site id, from `hub list`.
	 *
	 * [--yes]
	 * : Do not ask first.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function remove( $args, $assoc_args ): void {
		$site = $this->hub->sites()->get( (string) $args[0] );
		if ( ! $site ) {
			\WP_CLI::error( sprintf( 'No site "%s" in the hub. `wp axle-license-tracker hub list` shows the ids.', $args[0] ) );
		}

		\WP_CLI::confirm( sprintf( 'Remove %s and forget its password and licenses? This cannot be undone.', $site['label'] ), $assoc_args );
		$this->hub->sites()->remove( $site['id'] );
		\WP_CLI::success( sprintf( 'Removed %s.', $site['label'] ) );
	}

	/**
	 * Fetches the remote sites now (read only).
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : One site. Default every site that is due, or every site with --all.
	 *
	 * [--all]
	 * : Every site, not only the due ones.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function sync( $args, $assoc_args ): void {
		if ( isset( $args[0] ) ) {
			$this->report( (string) $args[0] );
			return;
		}

		$results = $this->hub->sync_due( ! empty( $assoc_args['all'] ) );
		if ( ! $results ) {
			\WP_CLI::success( 'No site is due. Use --all to fetch every site.' );
			return;
		}
		$failed = 0;
		foreach ( $results as $id => $result ) {
			if ( is_wp_error( $result ) ) {
				++$failed;
				\WP_CLI::warning( sprintf( '%s: %s', $id, $result->get_error_message() ) );
			} else {
				\WP_CLI::log( sprintf( '%s: %d licenses.', $result['label'], $result['count'] ) );
			}
		}
		if ( $failed ) {
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::success( sprintf( 'Fetched %d sites.', count( $results ) ) );
	}

	/**
	 * Lists every license across this site and the remote sites.
	 *
	 * ## OPTIONS
	 *
	 * [--duplicates]
	 * : Only keys used on more than one site.
	 *
	 * [--days=<days>]
	 * : Only licenses renewing within this many days, and expired ones.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml or count. Default table.
	 *
	 * @subcommand licenses
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function licenses( $args, $assoc_args ): void {
		unset( $args );
		$rows   = $this->hub->rows();
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		if ( ! empty( $assoc_args['duplicates'] ) ) {
			$items = array();
			foreach ( Overview::duplicates( $rows ) as $dup ) {
				$items[] = array(
					'fingerprint' => $dup['fingerprint'],
					'sites'       => implode( '; ', $dup['sites'] ),
					'count'       => $dup['count'],
					'limit'       => $dup['limit'] ? $dup['limit'] : '',
					'over_limit'  => $dup['over'] ? 'yes' : 'no',
					'products'    => implode( '; ', $dup['names'] ),
				);
			}
			\WP_CLI\Utils\format_items( $format, $items, array( 'fingerprint', 'count', 'limit', 'over_limit', 'sites', 'products' ) );
			return;
		}

		if ( isset( $assoc_args['days'] ) ) {
			$rows = Overview::expiring( $rows, max( 0, (int) $assoc_args['days'] ) );
		}
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = Command::flat(
				array(
					'site'      => $row['site_label'],
					'name'      => $row['name'],
					'vendor'    => $row['vendor'],
					'state'     => $row['state'],
					'renewal'   => $row['renewal'],
					'days_left' => $row['days_left'],
					'cost'      => $row['cost'],
					'currency'  => $row['currency'],
				)
			);
		}
		\WP_CLI\Utils\format_items( $format, $items, array( 'site', 'name', 'vendor', 'state', 'renewal', 'days_left', 'cost', 'currency' ) );
	}

	/**
	 * Turns the hub on. Sites are then fetched about once a day.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function enable( $args, $assoc_args ): void {
		unset( $args, $assoc_args );
		$this->hub->set_enabled( true );
		\WP_CLI::success( 'The hub is on.' );
	}

	/**
	 * Turns the hub off. The sites and what they reported are kept.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function disable( $args, $assoc_args ): void {
		unset( $args, $assoc_args );
		$this->hub->set_enabled( false );
		\WP_CLI::success( 'The hub is off. Sites and their data are kept.' );
	}

	/**
	 * Fetches one site and says what happened.
	 *
	 * @param string $id Site id.
	 */
	private function report( string $id ): void {
		$result = $this->hub->sync( $id );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( sprintf( '%s reported %d licenses.', $result['label'], $result['count'] ) );
	}
}
