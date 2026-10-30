<?php
/**
 * What an adapter read about one license.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Adapters;

use AryanX\Axle\LicenseTracker\Fingerprint;

defined( 'ABSPATH' ) || exit;

/**
 * A value object: the license state, expiry and what else the vendor stored.
 * It never holds a key, only the key's fingerprint.
 */
final class LicenseState {

	public const STATES = array( 'valid', 'expired', 'invalid', 'missing', 'unknown' );

	/**
	 * One of valid, expired, invalid, missing or unknown.
	 *
	 * @var string
	 */
	public string $state;

	/**
	 * Expiry date as Y-m-d, `lifetime`, or '' when not stored.
	 *
	 * @var string
	 */
	public string $expires;

	/**
	 * When the vendor last checked the license, as Unix time, or 0 when not stored.
	 *
	 * @var int
	 */
	public int $checked;

	/**
	 * Whether the vendor's own cache of this state has run out.
	 *
	 * @var bool
	 */
	public bool $stale;

	/**
	 * Fingerprint of the key, or '' for none.
	 *
	 * @var string
	 */
	public string $fingerprint;

	/**
	 * Newest version the vendor's cached data mentions, or ''.
	 *
	 * @var string
	 */
	public string $latest;

	/**
	 * Auto-renew as the vendor stored it: yes, no or ''.
	 *
	 * @var string
	 */
	public string $auto_renew;

	/**
	 * Seats bought, 0 when not stored.
	 *
	 * @var int
	 */
	public int $seats;

	/**
	 * Seats in use, 0 when not stored.
	 *
	 * @var int
	 */
	public int $seats_used;

	/**
	 * One-line detail for people.
	 *
	 * @var string
	 */
	public string $note;

	/**
	 * Builds a state. Pass the key as `key` to have it fingerprinted; it is not kept.
	 *
	 * @param array $fields state, expires, checked, stale, key or fingerprint, latest, auto_renew, seats, seats_used, note.
	 */
	public function __construct( array $fields = array() ) {
		$state             = (string) ( $fields['state'] ?? 'unknown' );
		$this->state       = in_array( $state, self::STATES, true ) ? $state : 'unknown';
		$this->expires     = self::date( $fields['expires'] ?? '' );
		$this->checked     = max( 0, (int) ( $fields['checked'] ?? 0 ) );
		$this->stale       = ! empty( $fields['stale'] );
		$this->fingerprint = isset( $fields['key'] ) && is_scalar( $fields['key'] ) ? Fingerprint::of( (string) $fields['key'] ) : (string) ( $fields['fingerprint'] ?? '' );
		$this->latest      = (string) ( $fields['latest'] ?? '' );
		$auto              = $fields['auto_renew'] ?? '';
		$this->auto_renew  = is_bool( $auto ) ? ( $auto ? 'yes' : 'no' ) : ( in_array( $auto, array( 'yes', 'no' ), true ) ? $auto : '' );
		$this->seats       = max( 0, (int) ( $fields['seats'] ?? 0 ) );
		$this->seats_used  = max( 0, (int) ( $fields['seats_used'] ?? 0 ) );
		$this->note        = (string) ( $fields['note'] ?? '' );

		// A stored "valid" with an expiry in the past is expired.
		if ( 'valid' === $this->state && self::is_past( $this->expires ) ) {
			$this->state = 'expired';
		}
	}

	/**
	 * The state as an array, for storage and reports.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'state'       => $this->state,
			'expires'     => $this->expires,
			'checked'     => $this->checked,
			'stale'       => $this->stale,
			'fingerprint' => $this->fingerprint,
			'latest'      => $this->latest,
			'auto_renew'  => $this->auto_renew,
			'seats'       => $this->seats,
			'seats_used'  => $this->seats_used,
			'note'        => $this->note,
		);
	}

	/**
	 * An expiry from a Unix time, a date string or `lifetime`, as Y-m-d.
	 *
	 * @param mixed $value Raw value.
	 * @return string Y-m-d, lifetime or ''.
	 */
	public static function date( $value ): string {
		if ( is_string( $value ) ) {
			$value = trim( $value );
			if ( '' === $value ) {
				return '';
			}
			if ( in_array( strtolower( $value ), array( 'lifetime', 'never', 'unlimited' ), true ) ) {
				return 'lifetime';
			}
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $value ) ) {
				return substr( $value, 0, 10 );
			}
			if ( ctype_digit( $value ) ) {
				$value = (int) $value;
			} else {
				$time = strtotime( $value );

				return false === $time ? '' : gmdate( 'Y-m-d', $time );
			}
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value > 0 ? gmdate( 'Y-m-d', (int) $value ) : '';
		}

		return '';
	}

	/**
	 * Whether a Y-m-d date is before today (UTC).
	 *
	 * @param string $date Y-m-d, lifetime or ''.
	 * @return bool
	 */
	public static function is_past( string $date ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && $date < gmdate( 'Y-m-d' );
	}
}
