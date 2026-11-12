<?php
/**
 * `wp axle-license-tracker feed` commands.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Cli;

use AryanX\Axle\LicenseTracker\Calendar;

defined( 'ABSPATH' ) || exit;

/**
 * The renewals calendar feed (.ics) behind a secret address.
 */
final class FeedCommand {

	/**
	 * The feed.
	 *
	 * @var Calendar
	 */
	private Calendar $calendar;

	/**
	 * Sets up the command.
	 *
	 * @param Calendar $calendar The feed.
	 */
	public function __construct( Calendar $calendar ) {
		$this->calendar = $calendar;
	}

	/**
	 * Says whether the feed is on and prints its address.
	 *
	 * The address is a secret: anyone who has it can read the renewal dates.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( $args, $assoc_args ): void {
		unset( $args, $assoc_args );
		if ( ! $this->calendar->enabled() ) {
			\WP_CLI::line( 'off' );
			return;
		}
		\WP_CLI::line( $this->calendar->url() );
	}

	/**
	 * Turns the feed on and prints its address.
	 *
	 * ## OPTIONS
	 *
	 * [--hub]
	 * : Also include the renewals of the hub's remote sites.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function enable( $args, $assoc_args ): void {
		unset( $args );
		\WP_CLI::line( $this->calendar->enable( ! empty( $assoc_args['hub'] ) ) );
	}

	/**
	 * Turns the feed off. Its address stops working.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function disable( $args, $assoc_args ): void {
		unset( $args, $assoc_args );
		$this->calendar->disable();
		\WP_CLI::success( 'The feed is off.' );
	}

	/**
	 * Replaces the secret address. The old one stops working at once.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask first.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function regenerate( $args, $assoc_args ): void {
		unset( $args );
		if ( ! $this->calendar->enabled() ) {
			\WP_CLI::error( 'The feed is off. Turn it on with `wp axle-license-tracker feed enable`.' );
		}
		\WP_CLI::confirm( 'Calendars subscribed to the old address stop updating. Continue?', $assoc_args );
		\WP_CLI::line( $this->calendar->regenerate() );
	}
}
