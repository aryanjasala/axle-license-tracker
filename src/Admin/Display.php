<?php
/**
 * Small pieces of markup shared by the screens.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Admin;

use AryanX\Axle\LicenseTracker\Details;
use AryanX\Axle\LicenseTracker\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * Pills, badges, ages and money, escaped and ready to print.
 */
final class Display {

	/**
	 * The license state pill.
	 *
	 * @param string $state State.
	 * @return string HTML.
	 */
	public static function state( string $state ): string {
		return sprintf( '<span class="alc-pill alc-state-%1$s">%2$s</span>', esc_attr( $state ), esc_html( Messages::get( 'state_' . $state ) ) );
	}

	/**
	 * The update risk badge.
	 *
	 * @param string $risk Risk, or '' for none.
	 * @return string HTML.
	 */
	public static function risk( string $risk ): string {
		if ( '' === $risk ) {
			return '';
		}

		return sprintf( '<span class="alc-pill alc-risk-%1$s">%2$s</span>', esc_attr( $risk ), esc_html( Messages::get( 'risk_' . $risk ) ) );
	}

	/**
	 * How old a state is: "as of 3 days ago" from the vendor, "first read 3 days ago" otherwise.
	 *
	 * @param array $row Row.
	 * @return string Plain text.
	 */
	public static function age( array $row ): string {
		$ago = human_time_diff( (int) $row['as_of'], time() );
		/* translators: %s: time span, such as 3 days. */
		$text = 'vendor' === $row['as_of_source'] ? sprintf( __( 'as of %s ago', 'axle-license-tracker' ), $ago ) : sprintf( __( 'first read %s ago', 'axle-license-tracker' ), $ago );
		if ( $row['stale'] ) {
			$text .= ', ' . __( 'vendor cache expired', 'axle-license-tracker' );
		}

		return $text;
	}

	/**
	 * Money from minor units, as plain text: 199.00 USD.
	 *
	 * @param int|null $minor    Amount in minor units.
	 * @param string   $currency Currency code.
	 * @return string
	 */
	public static function money( ?int $minor, string $currency ): string {
		if ( null === $minor || '' === $currency ) {
			return '';
		}

		return Details::from_minor( $minor, $currency ) . ' ' . $currency;
	}

	/**
	 * A cost cell that admin.js formats for the viewer's locale.
	 *
	 * @param int|null $minor    Amount in minor units.
	 * @param string   $currency Currency code.
	 * @param string   $suffix   Text after the amount, such as "/ year".
	 * @return string HTML.
	 */
	public static function cost( ?int $minor, string $currency, string $suffix = '' ): string {
		if ( null === $minor || '' === $currency ) {
			return '';
		}

		return sprintf(
			'<span class="alc-cost" data-minor="%1$d" data-currency="%2$s" data-decimals="%3$d">%4$s</span>%5$s',
			$minor,
			esc_attr( $currency ),
			Details::decimals( $currency ),
			esc_html( self::money( $minor, $currency ) ),
			'' !== $suffix ? ' <span class="alc-muted">' . esc_html( $suffix ) . '</span>' : ''
		);
	}

	/**
	 * The "set by hand" marker for a field entered manually.
	 *
	 * @param array  $row   Row.
	 * @param string $field Field.
	 * @return string HTML.
	 */
	public static function source( array $row, string $field ): string {
		if ( 'manual' !== ( $row['sources'][ $field ] ?? '' ) ) {
			return '';
		}

		return ' <span class="alc-src">' . esc_html__( 'set by hand', 'axle-license-tracker' ) . '</span>';
	}

	/**
	 * The fingerprint as shown: hash prefix and last four characters.
	 *
	 * @param string $fingerprint Fingerprint.
	 * @return string Plain text.
	 */
	public static function fingerprint( string $fingerprint ): string {
		return str_replace( '-', ' ... ', $fingerprint );
	}
}
