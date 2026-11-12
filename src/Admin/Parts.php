<?php
/**
 * Small pieces the license screens share.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Admin;

use AryanX\Axle\LicenseTracker\Details;

defined( 'ABSPATH' ) || exit;

/**
 * Markup helpers: a nonce field with no duplicate id, a time for a date, the
 * in-place confirm step and money lists.
 */
final class Parts {

	/**
	 * A nonce field without an id, so several forms can share a screen.
	 *
	 * @param string $action Nonce action.
	 */
	public static function nonce( string $action ): void {
		printf( '<input type="hidden" name="_wpnonce" value="%s">', esc_attr( wp_create_nonce( $action ) ) );
	}

	/**
	 * The action URL of admin-post.php.
	 *
	 * @return string
	 */
	public static function post_url(): string {
		return esc_url( admin_url( 'admin-post.php' ) );
	}

	/**
	 * A date such as 2026-12-01 as a timestamp at the start of that day on the site.
	 *
	 * @param string $date Y-m-d.
	 * @return int 0 when it is not a date.
	 */
	public static function date_timestamp( string $date ): int {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return 0;
		}

		try {
			return ( new \DateTimeImmutable( $date . ' 00:00:00', wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	/**
	 * A date through AxleUi::relative_time(), as HTML.
	 *
	 * @param string $date     Y-m-d.
	 * @param string $fallback Text when there is no date.
	 * @return string
	 */
	public static function date( string $date, string $fallback = '' ): string {
		return AxleUi::relative_time( self::date_timestamp( $date ), $fallback );
	}

	/**
	 * Amounts in several currencies, one per line.
	 *
	 * @param array<string, int> $totals Minor units per currency.
	 * @return string HTML, escaped.
	 */
	public static function money_lines( array $totals ): string {
		if ( ! $totals ) {
			return '<span class="axle-muted">' . esc_html__( 'None', 'axle-license-tracker' ) . '</span>';
		}

		$lines = array();
		foreach ( $totals as $currency => $minor ) {
			$lines[] = esc_html( Display::money( (int) $minor, (string) $currency ) );
		}

		return implode( '<br>', $lines );
	}

	/**
	 * A destructive action with its in-place confirm step.
	 *
	 * Without JavaScript the confirm step is simply shown and the trigger hidden,
	 * so the action is always one click away. With it, the step opens on the
	 * trigger and moves focus into itself.
	 *
	 * @param string $id      Unique id.
	 * @param string $trigger Label of the link that opens the step.
	 * @param string $title   What will change, in a few words.
	 * @param string $text    What happens and whether it can be undone.
	 * @param string $action  admin-post action, also the nonce action.
	 * @param array  $fields  Hidden fields: name => value.
	 * @param string $submit  Label of the confirming button.
	 */
	public static function confirm( string $id, string $trigger, string $title, string $text, string $action, array $fields, string $submit ): void {
		printf(
			'<button type="button" class="button-link alc-confirm-trigger" data-alc-confirm="%1$s" aria-controls="%1$s" aria-expanded="false" hidden>%2$s</button>',
			esc_attr( $id ),
			esc_html( $trigger )
		);
		printf( '<div class="axle-confirm axle-confirm--danger alc-confirm" id="%1$s" role="group" aria-labelledby="%1$s-text" tabindex="-1">', esc_attr( $id ) );
		printf( '<p id="%1$s-text"><strong>%2$s</strong> %3$s</p>', esc_attr( $id ), esc_html( $title ), esc_html( $text ) );
		printf( '<form method="post" action="%s" class="axle-actions"><input type="hidden" name="action" value="%s">', self::post_url(), esc_attr( $action ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- post_url() escapes.
		self::nonce( $action );
		foreach ( $fields as $name => $value ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( (string) $name ), esc_attr( (string) $value ) );
		}
		printf( '<button type="submit" class="button axle-button--danger">%s</button> ', esc_html( $submit ) );
		printf( '<button type="button" class="button alc-confirm-cancel" data-alc-confirm-cancel="%s" hidden>%s</button>', esc_attr( $id ), esc_html__( 'Cancel', 'axle-license-tracker' ) );
		echo '</form></div>';
	}

	/**
	 * Cost with a cycle, as plain escaped text.
	 *
	 * @param int|null $minor    Amount in minor units.
	 * @param string   $currency Currency.
	 * @param string   $cycle    Billing cycle.
	 * @return string
	 */
	public static function cost_text( ?int $minor, string $currency, string $cycle = '' ): string {
		if ( null === $minor || '' === $currency ) {
			return '<span class="axle-muted">' . esc_html__( 'Not set', 'axle-license-tracker' ) . '</span>';
		}

		$cycles = Page::cycles();

		return esc_html( Details::from_minor( $minor, $currency ) . ' ' . $currency ) . ( '' !== $cycle ? ' <span class="alc-muted">/ ' . esc_html( $cycles[ $cycle ] ?? $cycle ) . '</span>' : '' );
	}

	/**
	 * Keeps a notice for the next page load and goes there.
	 *
	 * @param array  $args Query arguments of the screen to go to.
	 * @param string $type success, error or warning.
	 * @param string $text What happened.
	 * @param string $undo URL that undoes it, or an empty string.
	 */
	public static function redirect( array $args, string $type, string $text, string $undo = '' ): void {
		set_transient(
			'axle_license_tracker_notice_' . get_current_user_id(),
			array(
				'type' => $type,
				'text' => $text,
				'undo' => $undo,
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( Page::url( array_merge( $args, array( 'alc_notice' => 'custom' ) ) ) );
		exit;
	}

	/**
	 * Reads and forgets the notice kept by redirect().
	 *
	 * @return array{type: string, text: string, undo: string}|null
	 */
	public static function pull_notice(): ?array {
		$key    = 'axle_license_tracker_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['text'] ) ) {
			return null;
		}

		return array(
			'type' => in_array( $notice['type'] ?? '', array( 'success', 'error', 'warning' ), true ) ? (string) $notice['type'] : 'success',
			'text' => (string) $notice['text'],
			'undo' => (string) ( $notice['undo'] ?? '' ),
		);
	}

	/**
	 * Checks the capability and the nonce of an admin-post request, or stops.
	 *
	 * @param string $action Nonce action.
	 */
	public static function verify( string $action ): void {
		if ( ! \AryanX\Axle\LicenseTracker\Capability::can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change licenses.', 'axle-license-tracker' ), 403 );
		}
		check_admin_referer( $action );
	}
}
