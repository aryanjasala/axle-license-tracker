<?php
/**
 * Abilities: license data for AI agents and other Abilities API clients.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the license abilities on WordPress 6.9 and later. On older
 * WordPress nothing here runs. No ability returns a license key: only the
 * fingerprint the screen shows.
 */
class Abilities implements Service {

	public const CATEGORY = 'axle-license-tracker';

	/**
	 * Rows.
	 *
	 * @var Tracker
	 */
	private Tracker $tracker;

	/**
	 * The report.
	 *
	 * @var Report
	 */
	private Report $report;

	/**
	 * Manual details.
	 *
	 * @var Details
	 */
	private Details $details;

	/**
	 * Sets up the abilities.
	 *
	 * @param Tracker $tracker Rows.
	 * @param Report  $report  The report.
	 * @param Details $details Manual details.
	 */
	public function __construct( Tracker $tracker, Report $report, Details $details ) {
		$this->tracker = $tracker;
		$this->report  = $report;
		$this->details = $details;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Axle License Tracker', 'axle-license-tracker' ),
				'description' => __( 'Premium plugin and theme licenses: state, expiry, update risk, renewal dates and costs.', 'axle-license-tracker' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$can     = array( Capability::class, 'can_manage' );
		$report  = array(
			'type'       => 'object',
			'properties' => array(
				'site'       => array( 'type' => 'string' ),
				'name'       => array( 'type' => 'string' ),
				'generated'  => array( 'type' => 'string' ),
				'plugin'     => array( 'type' => 'string' ),
				'totals'     => array( 'type' => 'object' ),
				'components' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
			),
		);
		$id      = array(
			'type'        => 'string',
			'minLength'   => 3,
			'maxLength'   => 300,
			'description' => __( 'Component id: plugin:folder/file.php or theme:folder, as the report lists it.', 'axle-license-tracker' ),
		);
		$entry   = array( 'type' => 'object' );
		$entries = array(
			'type'  => 'array',
			'items' => $entry,
		);

		$this->ability(
			'get-license-report',
			__( 'Get the license report', 'axle-license-tracker' ),
			__( 'Every premium plugin and theme on the site with its license state (and how old that information is), expiry, update risk, renewal date, cost and owner. Never includes license keys.', 'axle-license-tracker' ),
			array(),
			$report,
			array( $this, 'get_report' ),
			$can,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'list-expiring',
			__( 'List licenses that renew soon', 'axle-license-tracker' ),
			__( 'Premium plugins and themes whose renewal or expiry date falls within the given days, soonest first, plus the ones already expired.', 'axle-license-tracker' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'days' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 365,
						'default' => 30,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			$entries,
			array( $this, 'list_expiring' ),
			$can,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'get-component',
			__( 'Get one component\'s license', 'axle-license-tracker' ),
			__( 'License state, update risk and details of one plugin or theme.', 'axle-license-tracker' ),
			array(
				'type'                 => 'object',
				'properties'           => array( 'component' => $id ),
				'required'             => array( 'component' ),
				'additionalProperties' => false,
			),
			$entry,
			array( $this, 'get_component' ),
			$can,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'recheck-licenses',
			__( 'Check licenses again', 'axle-license-tracker' ),
			__( 'Reads the stored license data again and asks WordPress for updates, as it does twice a day on its own. Never contacts a vendor or changes a key.', 'axle-license-tracker' ),
			array(),
			array(
				'type'       => 'object',
				'properties' => array( 'totals' => array( 'type' => 'object' ) ),
			),
			array( $this, 'recheck' ),
			$can,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);

		$fields = array();
		foreach ( Details::fields() as $field => $kind ) {
			$fields[ $field ] = array(
				'type'        => in_array( $kind, array( 'int' ), true ) ? array( 'integer', 'string' ) : 'string',
				'description' => 'money' === $kind ? __( 'Decimal amount, such as 199.00. Needs currency.', 'axle-license-tracker' ) : ( 'date' === $kind ? __( 'YYYY-MM-DD.', 'axle-license-tracker' ) : '' ),
			);
		}
		$fields['cycle']['enum']           = array_merge( Details::CYCLES, array( '' ) );
		$fields['auto_renew']['enum']      = array( 'yes', 'no', '' );
		$fields['purchase_source']['enum'] = array_merge( array_keys( Details::purchase_sources() ), array( '' ) );

		$this->ability(
			'update-license-details',
			__( 'Update license details', 'axle-license-tracker' ),
			__( 'Sets details detection cannot know, such as the renewal date, cost and owner. Fields left out stay as they are; an empty string clears one. Manual details win over detected ones.', 'axle-license-tracker' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'component' => $id,
					'details'   => array(
						'type'                 => 'object',
						'properties'           => $fields,
						'additionalProperties' => false,
					),
				),
				'required'             => array( 'component', 'details' ),
				'additionalProperties' => false,
			),
			$entry,
			array( $this, 'update_details' ),
			$can,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);

		$this->ability(
			'ignore-component',
			__( 'Ignore a component', 'axle-license-tracker' ),
			__( 'Ignores a plugin or theme, so it gets no reminders and does not count in the totals, or tracks it again.', 'axle-license-tracker' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'component' => $id,
					'ignore'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'note'      => array(
						'type'      => 'string',
						'maxLength' => 500,
					),
				),
				'required'             => array( 'component' ),
				'additionalProperties' => false,
			),
			$entry,
			array( $this, 'ignore' ),
			$can,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);
	}

	/**
	 * Ability: the report.
	 *
	 * @return array
	 */
	public function get_report(): array {
		return $this->report->build();
	}

	/**
	 * Ability: what renews within some days.
	 *
	 * @param array|null $input Input.
	 * @return array[]
	 */
	public function list_expiring( $input = null ): array {
		return self::expiring( $this->tracker->tracked(), (int) ( ( (array) $input )['days'] ?? 30 ) );
	}

	/**
	 * Ability: one component.
	 *
	 * @param array|null $input Input.
	 * @return array|\WP_Error
	 */
	public function get_component( $input = null ) {
		$row = $this->tracker->row( (string) ( ( (array) $input )['component'] ?? '' ) );
		if ( ! $row ) {
			return self::not_found();
		}

		return Report::component( $row );
	}

	/**
	 * Ability: checks again.
	 *
	 * @return array
	 */
	public function recheck(): array {
		return array( 'totals' => Tracker::totals( $this->tracker->recheck() ) );
	}

	/**
	 * Ability: saves details.
	 *
	 * @param array|null $input Input.
	 * @return array|\WP_Error
	 */
	public function update_details( $input = null ) {
		$input = (array) $input;
		$row   = $this->tracker->row( (string) ( $input['component'] ?? '' ) );
		if ( ! $row ) {
			return self::not_found();
		}

		$errors = $this->details->save( $row, (array) ( $input['details'] ?? array() ), get_current_user_id() );
		if ( $errors ) {
			return new \WP_Error( 'axle_license_tracker_invalid', implode( ' ', $errors ), array( 'fields' => $errors ) );
		}

		$this->tracker->flush();
		$this->tracker->sync();

		return Report::component( (array) $this->tracker->row( $row['id'] ) );
	}

	/**
	 * Ability: ignores or tracks again.
	 *
	 * @param array|null $input Input.
	 * @return array|\WP_Error
	 */
	public function ignore( $input = null ) {
		$input = (array) $input;
		$row   = $this->tracker->row( (string) ( $input['component'] ?? '' ) );
		if ( ! $row ) {
			return self::not_found();
		}

		$this->tracker->ignore( $row['id'], ! isset( $input['ignore'] ) || (bool) $input['ignore'], (string) ( $input['note'] ?? '' ), get_current_user_id() );

		return Report::component( (array) $this->tracker->row( $row['id'] ) );
	}

	/**
	 * Rows that renew within some days, or are already expired, soonest first.
	 *
	 * @param array<string, array> $rows Rows.
	 * @param int                  $days Days ahead.
	 * @return array[] Report entries.
	 */
	public static function expiring( array $rows, int $days ): array {
		$days = max( 0, min( 365, $days ) );
		$list = array_filter(
			$rows,
			static function ( array $row ) use ( $days ): bool {
				return ! $row['ignored'] && ( 'expired' === $row['state'] || ( null !== $row['days_left'] && $row['days_left'] >= 0 && $row['days_left'] <= $days ) );
			}
		);
		usort(
			$list,
			static function ( array $a, array $b ): int {
				return ( $a['days_left'] ?? -1 ) <=> ( $b['days_left'] ?? -1 );
			}
		);

		return array_map( array( Report::class, 'component' ), $list );
	}

	/**
	 * The error for an unknown component.
	 *
	 * @return \WP_Error
	 */
	private static function not_found(): \WP_Error {
		return new \WP_Error( 'axle_license_tracker_not_found', Messages::get( 'not_found' ), array( 'status' => 404 ) );
	}

	/**
	 * Registers one ability in the category.
	 *
	 * @param string   $name        Name without the namespace.
	 * @param string   $label       Label.
	 * @param string   $description What it does, for people and agents.
	 * @param array    $input       Input schema, or empty for none.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Execute callback.
	 * @param callable $permission  Permission callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, callable $permission, array $annotations ): void {
		$args = array(
			'label'               => $label,
			'description'         => $description,
			'category'            => self::CATEGORY,
			'output_schema'       => $output,
			'execute_callback'    => $execute,
			'permission_callback' => $permission,
			'meta'                => array(
				'annotations'  => $annotations,
				'show_in_rest' => true,
				// Clients such as the MCP adapter only list abilities marked public:
				// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
				'public'       => true,
				'mcp'          => array( 'public' => true ),
			),
		);
		if ( $input ) {
			$args['input_schema'] = $input;
		}

		wp_register_ability( self::CATEGORY . '/' . $name, $args );
	}
}
