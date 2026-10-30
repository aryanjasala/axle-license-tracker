<?php
/**
 * Personal data export, erase and the privacy policy text.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * License details can hold an owner email and an account email, and record
 * who saved them. The exporter lists the records that hold a person's email
 * or were saved by them; the eraser blanks those emails and forgets who saved.
 */
class Privacy implements Service {

	public const ID = 'axle-license-tracker';

	/**
	 * Per-component records.
	 *
	 * @var Licenses
	 */
	private Licenses $licenses;

	/**
	 * Sets up the privacy tools.
	 *
	 * @param Licenses $licenses Per-component records.
	 */
	public function __construct( Licenses $licenses ) {
		$this->licenses = $licenses;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function add_exporter( $exporters ): array {
		$exporters             = (array) $exporters;
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'License details', 'axle-license-tracker' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function add_eraser( $erasers ): array {
		$erasers             = (array) $erasers;
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'License details', 'axle-license-tracker' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports the records that mention an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1. Every record fits on one page.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		unset( $page );
		$data = array();
		foreach ( $this->matches( (string) $email ) as $component => $fields ) {
			$items = array(
				array(
					'name'  => __( 'Component', 'axle-license-tracker' ),
					'value' => $component,
				),
			);
			foreach ( $fields as $field ) {
				$items[] = array(
					'name'  => 'saved_by' === $field ? __( 'Details saved by', 'axle-license-tracker' ) : ( 'owner_email' === $field ? __( 'Owner email', 'axle-license-tracker' ) : __( 'Account email', 'axle-license-tracker' ) ),
					'value' => 'saved_by' === $field ? (string) $email : (string) $this->licenses->meta( $component, '_alc_' . $field, '' ),
				);
			}
			$data[] = array(
				'group_id'    => 'axle-license-tracker',
				'group_label' => __( 'License details', 'axle-license-tracker' ),
				'item_id'     => 'alc-' . md5( $component ),
				'data'        => $items,
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Blanks an email address in license details, and forgets who saved them.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$removed = false;
		foreach ( $this->matches( (string) $email ) as $component => $fields ) {
			foreach ( $fields as $field ) {
				$this->licenses->set_meta( $component, '_alc_' . $field, null );
				$removed = true;
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Records that hold an email, and which fields.
	 *
	 * @param string $email Email address.
	 * @return array<string, string[]> Fields keyed by component id.
	 */
	private function matches( string $email ): array {
		$email   = strtolower( trim( $email ) );
		$user    = '' !== $email ? get_user_by( 'email', $email ) : false;
		$matches = array();
		if ( '' === $email ) {
			return $matches;
		}
		foreach ( array_keys( $this->licenses->all() ) as $component ) {
			$fields = array();
			foreach ( array( 'owner_email', 'account_email' ) as $field ) {
				if ( strtolower( (string) $this->licenses->meta( $component, '_alc_' . $field, '' ) ) === $email ) {
					$fields[] = $field;
				}
			}
			if ( $user && (int) $this->licenses->meta( $component, '_alc_saved_by', 0 ) === (int) $user->ID ) {
				$fields[] = 'saved_by';
			}
			if ( $fields ) {
				$matches[ (string) $component ] = $fields;
			}
		}

		return $matches;
	}

	/**
	 * Suggested text for Settings > Privacy.
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			'Axle License Tracker',
			wp_kses_post(
				'<p>' . __( 'Administrators can note who owns each premium plugin or theme license, with an owner email and the email of the vendor account. These are kept with the license details on this site, together with the user who last saved them, and are used to send renewal reminders. License keys are never stored, only a short fingerprint. Nothing is sent to other services.', 'axle-license-tracker' ) . '</p>'
			)
		);
	}
}
