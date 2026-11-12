<?php
/**
 * Fetches the read-only report of a remote site.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Hub;

use AryanX\Axle\LicenseTracker\Details;
use AryanX\Axle\LicenseTracker\Tracker;

use const AryanX\Axle\LicenseTracker\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * One GET request to /axle-license-tracker/v1/report with an application
 * password. Nothing is ever sent to the remote site except that request, so
 * the hub cannot change anything there. The answer is treated as untrusted
 * input: only known fields are kept, each cleaned and cut to length.
 */
class Remote {

	private const RISKS = array( 'ok', 'watch', 'at-risk', 'blocked', '' );

	/**
	 * Sites.
	 *
	 * @var Sites
	 */
	private Sites $sites;

	/**
	 * Sets up the client.
	 *
	 * @param Sites $sites Sites.
	 */
	public function __construct( Sites $sites ) {
		$this->sites = $sites;
	}

	/**
	 * Fetches and cleans a site's report.
	 *
	 * @param array $site A site, from Sites::get().
	 * @return array|\WP_Error name, plugin, generated and components, or why it failed in words.
	 */
	public function fetch( array $site ) {
		$url = Guard::check( (string) $site['url'] );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$password = $this->sites->password( (string) $site['id'] );
		if ( null === $password ) {
			return new \WP_Error( 'axle_license_tracker_secret', __( 'The saved password cannot be read any more, for instance because the salts changed. Remove the site and add it again.', 'axle-license-tracker' ) );
		}

		$endpoint = add_query_arg( 'rest_route', '/axle-license-tracker/v1/report', $url );
		$cap      = self::size_cap();

		/**
		 * Filters how many seconds the hub waits for a remote site.
		 *
		 * @param int $seconds Default 15, from 3 to 60.
		 */
		$timeout = max( 3, min( 60, (int) apply_filters( 'axle_license_tracker_hub_timeout', 15 ) ) );

		$args = array(
			'timeout'             => $timeout,
			'redirection'         => 0,
			'limit_response_size' => $cap + 1,
			'user-agent'          => 'AxleLicenseTracker/' . VERSION . '; hub',
			'headers'             => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( $site['user'] . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
			),
		);

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Only when the owner allowed private addresses for local testing; vip_safe_wp_remote_get() exists only on VIP.
		$response = Guard::allow_private( $url ) ? wp_remote_get( $endpoint, $args ) : wp_safe_remote_get( $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: the error from the connection. */
			return new \WP_Error( 'axle_license_tracker_unreachable', sprintf( __( 'The site could not be reached: %s', 'axle-license-tracker' ), wp_strip_all_tags( $response->get_error_message() ) ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code || 403 === $code ) {
			return new \WP_Error( 'axle_license_tracker_denied', __( 'The site refused the login. Check the user name and the application password, and that the user has the License reporter role.', 'axle-license-tracker' ) );
		}
		if ( 404 === $code ) {
			return new \WP_Error( 'axle_license_tracker_missing', __( 'The site has no license report. Check that Axle License Tracker is active there and that the REST API is not blocked.', 'axle-license-tracker' ) );
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return new \WP_Error( 'axle_license_tracker_status', sprintf( __( 'The site answered with HTTP %d. Try again later.', 'axle-license-tracker' ), $code ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > $cap ) {
			return new \WP_Error( 'axle_license_tracker_size', __( 'The report is larger than the hub accepts.', 'axle-license-tracker' ) );
		}

		return self::sanitize( json_decode( $body, true ) );
	}

	/**
	 * Largest answer the hub reads.
	 *
	 * @return int Bytes.
	 */
	public static function size_cap(): int {
		/**
		 * Filters the largest report the hub reads, in bytes.
		 *
		 * @param int $bytes Default 1 MB, from 10 KB to 10 MB.
		 */
		return max( 10 * KB_IN_BYTES, min( 10 * MB_IN_BYTES, (int) apply_filters( 'axle_license_tracker_hub_max_bytes', MB_IN_BYTES ) ) );
	}

	/**
	 * Cleans a decoded report.
	 *
	 * @param mixed $body Decoded JSON.
	 * @return array|\WP_Error name, plugin, generated and components.
	 */
	public static function sanitize( $body ) {
		if ( ! is_array( $body ) || ! isset( $body['components'] ) || ! is_array( $body['components'] ) ) {
			return new \WP_Error( 'axle_license_tracker_shape', __( 'The answer was not a license report. Check the address.', 'axle-license-tracker' ) );
		}

		/**
		 * Filters how many components the hub keeps from one site.
		 *
		 * @param int $max Default 500, from 10 to 2000.
		 */
		$max        = max( 10, min( 2000, (int) apply_filters( 'axle_license_tracker_hub_max_components', 500 ) ) );
		$components = array();
		foreach ( $body['components'] as $component ) {
			if ( count( $components ) >= $max ) {
				break;
			}
			$clean = is_array( $component ) ? self::component( $component ) : null;
			if ( $clean ) {
				$components[] = $clean;
			}
		}

		return array(
			'name'       => self::text( $body['name'] ?? '', 100 ),
			'plugin'     => self::text( $body['plugin'] ?? '', 20 ),
			'generated'  => self::text( $body['generated'] ?? '', 40 ),
			'components' => $components,
		);
	}

	/**
	 * One component, or null when it is not usable.
	 *
	 * @param array $in What the site sent.
	 * @return array|null
	 */
	private static function component( array $in ): ?array {
		$id = self::text( $in['id'] ?? '', 200 );
		if ( '' === $id || ! empty( $in['ignored'] ) ) {
			return null;
		}

		$state    = (string) ( $in['state'] ?? '' );
		$risk     = (string) ( $in['risk'] ?? '' );
		$renewal  = (string) ( $in['renewal'] ?? '' );
		$cycle    = (string) ( $in['cycle'] ?? '' );
		$currency = strtoupper( (string) ( $in['currency'] ?? '' ) );
		$cost     = $in['cost'] ?? null;
		$expires  = (string) ( $in['expires'] ?? '' );

		return array(
			'id'          => $id,
			'kind'        => 'theme' === ( $in['kind'] ?? '' ) ? 'theme' : 'plugin',
			'name'        => self::text( $in['name'] ?? $id, 150 ),
			'version'     => self::text( $in['version'] ?? '', 40 ),
			'type'        => in_array( $in['type'] ?? '', array( 'premium', 'bundled', 'directory', 'custom', 'unknown' ), true ) ? (string) $in['type'] : 'unknown',
			'vendor'      => self::text( $in['vendor'] ?? '', 100 ),
			'state'       => in_array( $state, Tracker::STATES, true ) ? $state : 'unknown',
			'risk'        => in_array( $risk, self::RISKS, true ) ? $risk : '',
			'expires'     => 'lifetime' === $expires || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires ) ? $expires : '',
			'renewal'     => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $renewal ) ? $renewal : '',
			'cycle'       => in_array( $cycle, Details::CYCLES, true ) ? $cycle : '',
			'cost'        => is_int( $cost ) && $cost >= 0 && $cost < 1000000000 ? $cost : null,
			'currency'    => preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : '',
			'auto_renew'  => in_array( $in['auto_renew'] ?? '', array( 'yes', 'no' ), true ) ? (string) $in['auto_renew'] : '',
			'seats'       => max( 0, min( 1000000, (int) ( $in['seats'] ?? 0 ) ) ),
			'seats_used'  => max( 0, min( 1000000, (int) ( $in['seats_used'] ?? 0 ) ) ),
			'fingerprint' => preg_match( '/^[A-Za-z0-9._-]{1,40}$/', (string) ( $in['fingerprint'] ?? '' ) ) ? (string) $in['fingerprint'] : '',
		);
	}

	/**
	 * A plain text value cut to length.
	 *
	 * @param mixed $value  Value.
	 * @param int   $length Longest length in characters.
	 * @return string
	 */
	private static function text( $value, int $length ): string {
		$text = is_scalar( $value ) ? trim( wp_strip_all_tags( (string) $value ) ) : '';

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}
}
