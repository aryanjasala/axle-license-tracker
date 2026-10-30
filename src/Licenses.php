<?php
/**
 * Per-component records: manual details and what was last detected.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * One `alc_license` post per tracked component. The component id
 * (plugin:dir/file.php or theme:stylesheet) is the title and the
 * `_alc_component` meta; WordPress sanitizes post_name, so it is not used to
 * look rows up. Meta holds the manual fields, the key fingerprint, the ignore
 * note, the reminders already sent, and the last state and risk.
 */
class Licenses implements Service {

	public const POST_TYPE = 'alc_license';

	/**
	 * Post ids keyed by component id, loaded once per request.
	 *
	 * @var array<string, int>|null
	 */
	private ?array $ids = null;

	/**
	 * Registers the post type.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Registers alc_license: not public, not in REST, no screens of its own.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'           => __( 'Licenses', 'axle-license-tracker' ),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Post ids keyed by component id.
	 *
	 * @return array<string, int>
	 */
	public function ids(): array {
		if ( null === $this->ids ) {
			$this->ids = array();
			$page      = 1;
			do {
				$posts = get_posts(
					array(
						'post_type'      => self::POST_TYPE,
						'post_status'    => 'any',
						'posts_per_page' => 100,
						'paged'          => $page,
						'orderby'        => 'ID',
						'order'          => 'ASC',
						'fields'         => 'ids',
						'no_found_rows'  => true,
					)
				);
				foreach ( $posts as $post_id ) {
					$component = (string) get_post_meta( (int) $post_id, '_alc_component', true );
					if ( '' !== $component && ! isset( $this->ids[ $component ] ) ) {
						$this->ids[ $component ] = (int) $post_id;
					}
				}
				++$page;
				$more = 100 === count( $posts ) && $page <= 50;
			} while ( $more );
		}

		return $this->ids;
	}

	/**
	 * Post id of a component, or 0.
	 *
	 * @param string $component Component id.
	 * @return int
	 */
	public function find( string $component ): int {
		$ids = $this->ids();

		return $ids[ $component ] ?? 0;
	}

	/**
	 * Post id of a component, creating the record when missing.
	 *
	 * @param string $component Component id.
	 * @return int 0 when the post could not be created.
	 */
	public function ensure( string $component ): int {
		$id = $this->find( $component );
		if ( $id ) {
			return $id;
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $component,
				'post_name'   => sanitize_title( $component ),
				'meta_input'  => array( '_alc_component' => $component ),
			),
			false
		);
		if ( ! $id ) {
			return 0;
		}

		$this->ids();
		$this->ids[ $component ] = $id;

		return $id;
	}

	/**
	 * One meta value of a component.
	 *
	 * @param string $component Component id.
	 * @param string $key       Meta key.
	 * @param mixed  $fallback  Value when not set.
	 * @return mixed
	 */
	public function meta( string $component, string $key, $fallback = '' ) {
		$id = $this->find( $component );
		if ( ! $id || ! metadata_exists( 'post', $id, $key ) ) {
			return $fallback;
		}

		return get_post_meta( $id, $key, true );
	}

	/**
	 * Sets one meta value, creating the record when missing.
	 *
	 * @param string $component Component id.
	 * @param string $key       Meta key.
	 * @param mixed  $value     Value; null deletes it.
	 */
	public function set_meta( string $component, string $key, $value ): void {
		$id = $this->ensure( $component );
		if ( ! $id ) {
			return;
		}
		if ( null === $value ) {
			delete_post_meta( $id, $key );
			return;
		}
		update_post_meta( $id, $key, $value );
	}

	/**
	 * Manual details of a component.
	 *
	 * @param string $component Component id.
	 * @return array Field name => value, only the fields that are set.
	 */
	public function details( string $component ): array {
		$details = array();
		if ( ! $this->find( $component ) ) {
			return $details;
		}
		foreach ( array_keys( Details::fields() ) as $field ) {
			$value = $this->meta( $component, '_alc_' . $field, null );
			if ( null !== $value && '' !== $value ) {
				$details[ $field ] = $value;
			}
		}

		return $details;
	}

	/**
	 * Every component that has a record, with its post id.
	 *
	 * @return array<string, int>
	 */
	public function all(): array {
		return $this->ids();
	}

	/**
	 * Forgets loaded ids.
	 */
	public function flush(): void {
		$this->ids = null;
	}
}
