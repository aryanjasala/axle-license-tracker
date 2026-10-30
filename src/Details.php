<?php
/**
 * Layer 4: details entered by hand.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * The manual fields, their validation and saving. A manual field always beats
 * what detection found. Costs are integers in minor units (cents), never floats.
 */
class Details {

	public const CYCLES = array( 'yearly', 'monthly', 'lifetime', 'one-off' );

	/**
	 * Currencies with no minor unit.
	 */
	private const ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/**
	 * Per-component records.
	 *
	 * @var Licenses
	 */
	private Licenses $licenses;

	/**
	 * Sets up the details store.
	 *
	 * @param Licenses $licenses Per-component records.
	 */
	public function __construct( Licenses $licenses ) {
		$this->licenses = $licenses;
	}

	/**
	 * The manual fields: name => kind.
	 *
	 * @return array<string, string>
	 */
	public static function fields(): array {
		return array(
			'vendor'          => 'text',
			'plan'            => 'text',
			'purchase_source' => 'source',
			'owner'           => 'text',
			'owner_email'     => 'email',
			'renewal'         => 'date',
			'cycle'           => 'cycle',
			'cost'            => 'money',
			'currency'        => 'currency',
			'auto_renew'      => 'yesno',
			'seats'           => 'int',
			'account_url'     => 'url',
			'account_email'   => 'email',
			'invoice_url'     => 'url',
			'notes'           => 'textarea',
		);
	}

	/**
	 * Where a license was bought, filtered. Sources marked `flag` usually ship
	 * builds with no official updates.
	 *
	 * @return array<string, array{label: string, flag: bool}>
	 */
	public static function purchase_sources(): array {
		$defaults = array(
			'vendor'   => array(
				'label' => __( 'Vendor, direct', 'axle-license-tracker' ),
				'flag'  => false,
			),
			'envato'   => array(
				'label' => __( 'Envato', 'axle-license-tracker' ),
				'flag'  => false,
			),
			'reseller' => array(
				'label' => __( 'Reseller', 'axle-license-tracker' ),
				'flag'  => true,
			),
			'gpl-club' => array(
				'label' => __( 'GPL club', 'axle-license-tracker' ),
				'flag'  => true,
			),
		);

		/**
		 * Filters the purchase sources offered for a license.
		 *
		 * @param array $sources Key => array( 'label' => string, 'flag' => bool ). Flagged sources get a warning.
		 */
		$sources = apply_filters( 'axle_license_tracker_purchase_sources', $defaults );
		$clean   = array();
		foreach ( (array) $sources as $key => $source ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $source ) || empty( $source['label'] ) ) {
				continue;
			}
			$clean[ $key ] = array(
				'label' => (string) $source['label'],
				'flag'  => ! empty( $source['flag'] ),
			);
		}

		return $clean ? $clean : $defaults;
	}

	/**
	 * Validates and cleans submitted details.
	 *
	 * @param array $input Raw input: field => value. Cost is a decimal string such as 199.00.
	 * @return array{clean: array, errors: array<string, string>} Clean values ('' clears a field) and errors per field.
	 */
	public static function validate( array $input ): array {
		$clean  = array();
		$errors = array();
		$fields = self::fields();

		foreach ( $fields as $field => $kind ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$raw = is_scalar( $input[ $field ] ) ? trim( (string) $input[ $field ] ) : '';
			if ( '' === $raw ) {
				$clean[ $field ] = '';
				continue;
			}

			switch ( $kind ) {
				case 'email':
					$email = sanitize_email( $raw );
					if ( ! is_email( $email ) ) {
						$errors[ $field ] = __( 'Enter a valid email address.', 'axle-license-tracker' );
						break;
					}
					$clean[ $field ] = $email;
					break;
				case 'url':
					$url = esc_url_raw( $raw, array( 'http', 'https' ) );
					if ( '' === $url || ! wp_http_validate_url( $url ) ) {
						$errors[ $field ] = __( 'Enter a full web address that starts with https://.', 'axle-license-tracker' );
						break;
					}
					$clean[ $field ] = $url;
					break;
				case 'date':
					$date = \DateTime::createFromFormat( '!Y-m-d', $raw );
					if ( ! $date || $date->format( 'Y-m-d' ) !== $raw ) {
						$errors[ $field ] = __( 'Enter a date as YYYY-MM-DD.', 'axle-license-tracker' );
						break;
					}
					$clean[ $field ] = $raw;
					break;
				case 'cycle':
					if ( ! in_array( $raw, self::CYCLES, true ) ) {
						$errors[ $field ] = __( 'Choose yearly, monthly, lifetime or one-off.', 'axle-license-tracker' );
						break;
					}
					$clean[ $field ] = $raw;
					break;
				case 'source':
					if ( ! isset( self::purchase_sources()[ $raw ] ) ) {
						$errors[ $field ] = __( 'Choose a purchase source from the list.', 'axle-license-tracker' );
						break;
					}
					$clean[ $field ] = $raw;
					break;
				case 'currency':
					$code = strtoupper( $raw );
					if ( ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
						$errors[ $field ] = __( 'Enter a three-letter currency code, such as USD or EUR.', 'axle-license-tracker' );
						break;
					}
					$clean[ $field ] = $code;
					break;
				case 'yesno':
					if ( ! in_array( $raw, array( 'yes', 'no' ), true ) ) {
						$errors[ $field ] = __( 'Choose yes or no.', 'axle-license-tracker' );
						break;
					}
					$clean[ $field ] = $raw;
					break;
				case 'int':
					if ( ! ctype_digit( $raw ) || (int) $raw > 100000 ) {
						$errors[ $field ] = __( 'Enter a whole number.', 'axle-license-tracker' );
						break;
					}
					$clean[ $field ] = (int) $raw;
					break;
				case 'money':
					// Converted below, once the currency is known.
					$clean[ $field ] = $raw;
					break;
				case 'textarea':
					$clean[ $field ] = mb_substr( sanitize_textarea_field( $raw ), 0, 2000 );
					break;
				default:
					$clean[ $field ] = mb_substr( sanitize_text_field( $raw ), 0, 200 );
			}
		}

		if ( isset( $clean['cost'] ) && '' !== $clean['cost'] ) {
			$minor = self::to_minor( (string) $clean['cost'], (string) ( $clean['currency'] ?? 'USD' ) );
			if ( null === $minor ) {
				$errors['cost'] = __( 'Enter the cost as a number, such as 199 or 199.00.', 'axle-license-tracker' );
				unset( $clean['cost'] );
			} else {
				$clean['cost'] = $minor;
			}
			if ( empty( $clean['currency'] ) && ! isset( $errors['currency'] ) ) {
				$errors['currency'] = __( 'Enter the currency of the cost.', 'axle-license-tracker' );
			}
		}

		return array(
			'clean'  => $clean,
			'errors' => $errors,
		);
	}

	/**
	 * Saves details for a component. Nothing is saved when a field is invalid.
	 *
	 * @param array $component Component.
	 * @param array $input     Raw input.
	 * @param int   $user_id   Who saved them.
	 * @return array<string, string> Errors per field; empty when saved.
	 */
	public function save( array $component, array $input, int $user_id ): array {
		$result = self::validate( $input );
		if ( $result['errors'] ) {
			return $result['errors'];
		}

		$id = (string) $component['id'];
		foreach ( $result['clean'] as $field => $value ) {
			$this->licenses->set_meta( $id, '_alc_' . $field, '' === $value ? null : $value );
		}
		$this->licenses->set_meta( $id, '_alc_saved_by', $user_id );
		$this->licenses->set_meta( $id, '_alc_saved_at', time() );

		/**
		 * Fires after license details were saved by hand.
		 *
		 * @param array $component Component.
		 * @param array $fields    The fields saved; '' means cleared.
		 * @param int   $user_id   Who saved them.
		 */
		do_action( 'axle_license_tracker_details_saved', $component, $result['clean'], $user_id );

		return array();
	}

	/**
	 * A decimal amount as an integer in minor units, without floats.
	 *
	 * @param string $amount   Such as 199, 199.5 or 1,299.00.
	 * @param string $currency Currency code.
	 * @return int|null Null when it is not a valid amount.
	 */
	public static function to_minor( string $amount, string $currency ): ?int {
		$amount   = str_replace( array( ',', ' ' ), '', trim( $amount ) );
		$decimals = self::decimals( $currency );
		if ( ! preg_match( '/^(\d{1,9})(?:\.(\d{1,' . max( 1, $decimals ) . '}))?$/', $amount, $match ) ) {
			return null;
		}
		if ( 0 === $decimals && isset( $match[2] ) && '' !== trim( $match[2], '0' ) ) {
			return null;
		}
		$fraction = 0 === $decimals ? '' : str_pad( $match[2] ?? '', $decimals, '0' );

		return (int) ( $match[1] . $fraction );
	}

	/**
	 * Minor units as a decimal string, such as 19900 => 199.00.
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function from_minor( int $minor, string $currency ): string {
		$decimals = self::decimals( $currency );
		if ( 0 === $decimals ) {
			return (string) $minor;
		}
		$digits = str_pad( (string) abs( $minor ), $decimals + 1, '0', STR_PAD_LEFT );

		return ( $minor < 0 ? '-' : '' ) . substr( $digits, 0, -$decimals ) . '.' . substr( $digits, -$decimals );
	}

	/**
	 * Digits after the decimal point for a currency.
	 *
	 * @param string $currency Currency code.
	 * @return int
	 */
	public static function decimals( string $currency ): int {
		return in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ? 0 : 2;
	}

	/**
	 * What a license costs per year in minor units, or null when it does not recur.
	 *
	 * @param int    $cost  Cost in minor units.
	 * @param string $cycle Billing cycle.
	 * @return int|null
	 */
	public static function yearly( int $cost, string $cycle ): ?int {
		if ( 'monthly' === $cycle ) {
			return $cost * 12;
		}
		if ( 'yearly' === $cycle || '' === $cycle ) {
			return $cost;
		}

		return null;
	}
}
