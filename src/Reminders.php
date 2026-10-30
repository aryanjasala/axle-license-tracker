<?php
/**
 * Renewal reminders, alerts and the monthly digest.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Runs daily. Sends one email per component and reminder day (30, 14, 7 and 1
 * days before the renewal date by default), one alert when a license is newly
 * expired or its updates are blocked or at risk, and a monthly digest when it
 * is turned on. What was sent is kept in the component's record, so a cron
 * hiccup or a second run sends nothing twice.
 */
class Reminders implements Service {

	/**
	 * Rows.
	 *
	 * @var Tracker
	 */
	private Tracker $tracker;

	/**
	 * Per-component records.
	 *
	 * @var Licenses
	 */
	private Licenses $licenses;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Detection store (the digest month).
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Sets up reminders.
	 *
	 * @param Tracker  $tracker  Rows.
	 * @param Licenses $licenses Per-component records.
	 * @param Settings $settings Settings.
	 * @param Store    $store    Detection store.
	 */
	public function __construct( Tracker $tracker, Licenses $licenses, Settings $settings, Store $store ) {
		$this->tracker  = $tracker;
		$this->licenses = $licenses;
		$this->settings = $settings;
		$this->store    = $store;
	}

	/**
	 * Hooks the daily run.
	 */
	public function register(): void {
		add_action( Lifecycle::DAILY, array( $this, 'cron' ) );
		add_action( 'admin_init', array( Lifecycle::class, 'schedule' ) );
	}

	/**
	 * The cron event.
	 */
	public function cron(): void {
		$this->daily();
	}

	/**
	 * The daily run: sync, then send what is due.
	 *
	 * @return array[] What was sent.
	 */
	public function daily(): array {
		$this->tracker->flush();
		$this->tracker->sync();

		return $this->run( false );
	}

	/**
	 * Works out what is due and sends it.
	 *
	 * @param bool $dry_run Only list what would be sent.
	 * @return array[] Each: component, kind (renewal, alert, digest), threshold, subject, recipients.
	 */
	public function run( bool $dry_run ): array {
		$planned = array();
		$days    = $this->tracker->reminder_days();
		$alerts  = (bool) $this->settings->get( 'alerts' );

		foreach ( $this->tracker->tracked() as $id => $row ) {
			if ( $row['ignored'] ) {
				continue;
			}

			// Renewal reminders: the tightest reminder day the date is within, once.
			if ( null !== $row['days_left'] && $row['days_left'] >= 0 && 'lifetime' !== $row['cycle'] ) {
				$due = null;
				foreach ( $days as $threshold ) {
					if ( $row['days_left'] <= $threshold ) {
						$due = $threshold;
					}
				}
				$sent = $this->sent( $id );
				$key  = 'renewal:' . $row['renewal'] . ':' . $due;
				if ( null !== $due && ! in_array( $key, $sent, true ) ) {
					$planned[] = $this->message( $row, 'renewal', $due );
					if ( ! $dry_run ) {
						// Every wider reminder day is covered by this one too.
						foreach ( $days as $threshold ) {
							if ( $threshold >= $due ) {
								$sent[] = 'renewal:' . $row['renewal'] . ':' . $threshold;
							}
						}
						$this->deliver( end( $planned ), $row );
						$this->licenses->set_meta( $id, '_alc_reminders_sent', array_values( array_unique( $sent ) ) );

						/**
						 * Fires when a renewal reminder goes out.
						 *
						 * @param array $row  Component row.
						 * @param int   $days Days left until the renewal date.
						 */
						do_action( 'axle_license_tracker_license_expiring', $row, (int) $row['days_left'] );
					}
				}
			}

			// Alerts: newly expired, blocked or at risk. Cleared when the state changes.
			if ( $alerts ) {
				$conditions = array();
				if ( 'expired' === $row['state'] ) {
					$conditions[] = 'expired';
				}
				if ( in_array( $row['risk'], array( 'blocked', 'at-risk' ), true ) ) {
					$conditions[] = $row['risk'];
				}
				$alerted = $this->licenses->meta( $id, '_alc_alerts_sent', array() );
				$alerted = is_array( $alerted ) ? $alerted : array();
				foreach ( $conditions as $condition ) {
					if ( in_array( $condition, $alerted, true ) ) {
						continue;
					}
					$planned[] = $this->message( $row, 'alert', $condition );
					if ( ! $dry_run ) {
						$alerted[] = $condition;
						$this->deliver( end( $planned ), $row );
						$this->licenses->set_meta( $id, '_alc_alerts_sent', array_values( array_unique( $alerted ) ) );
					}
				}
			}
		}

		$digest = $this->digest( $dry_run );
		if ( $digest ) {
			$planned[] = $digest;
		}

		return $planned;
	}

	/**
	 * Recipients for a row: the site admin email, the extra recipients in
	 * settings and the row's owner email, filtered.
	 *
	 * @param array|null $row  Component row, null for the digest.
	 * @param string     $kind renewal, alert or digest.
	 * @return string[]
	 */
	public function recipients( ?array $row, string $kind ): array {
		$list = array_merge( array( (string) get_option( 'admin_email' ) ), (array) $this->settings->get( 'recipients' ) );
		if ( $row && '' !== $row['owner_email'] ) {
			$list[] = $row['owner_email'];
		}

		/**
		 * Filters who gets a reminder, alert or digest.
		 *
		 * @param string[]   $recipients Email addresses.
		 * @param array|null $row        Component row; null for the digest.
		 * @param string     $kind       renewal, alert or digest.
		 */
		$list = (array) apply_filters( 'axle_license_tracker_reminder_recipients', $list, $row, $kind );

		return array_values( array_unique( array_filter( array_map( 'sanitize_email', array_map( 'strval', $list ) ), 'is_email' ) ) );
	}

	/**
	 * Reminder keys already sent for a component.
	 *
	 * @param string $id Component id.
	 * @return string[]
	 */
	private function sent( string $id ): array {
		$sent = $this->licenses->meta( $id, '_alc_reminders_sent', array() );

		return is_array( $sent ) ? array_map( 'strval', $sent ) : array();
	}

	/**
	 * Builds one message.
	 *
	 * @param array      $row       Component row.
	 * @param string     $kind      renewal or alert.
	 * @param int|string $threshold Reminder day, or the alert condition.
	 * @return array
	 */
	private function message( array $row, string $kind, $threshold ): array {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$link = admin_url( 'plugins.php?page=' . Admin\Page::SLUG . '&edit=' . rawurlencode( $row['id'] ) );

		if ( 'renewal' === $kind ) {
			$subject = sprintf(
				/* translators: 1: site name, 2: plugin or theme name, 3: number of days. */
				_n( '[%1$s] %2$s renews in %3$d day', '[%1$s] %2$s renews in %3$d days', (int) $row['days_left'], 'axle-license-tracker' ),
				$site,
				$row['name'],
				(int) $row['days_left']
			);
			$lines = array(
				/* translators: 1: plugin or theme name, 2: date. */
				sprintf( __( 'The license for %1$s renews on %2$s.', 'axle-license-tracker' ), $row['name'], $row['renewal'] ),
			);
			if ( null !== $row['cost'] && '' !== $row['currency'] ) {
				/* translators: 1: amount, 2: currency code. */
				$lines[] = sprintf( __( 'Cost: %1$s %2$s.', 'axle-license-tracker' ), Details::from_minor( (int) $row['cost'], $row['currency'] ), $row['currency'] );
			}
			if ( '' !== $row['auto_renew'] ) {
				$lines[] = 'yes' === $row['auto_renew'] ? __( 'Auto-renew is on.', 'axle-license-tracker' ) : __( 'Auto-renew is off. Renew it by hand or it lapses.', 'axle-license-tracker' );
			}
		} else {
			$labels  = array(
				/* translators: %s: plugin or theme name. */
				'expired' => __( 'The license for %s has expired.', 'axle-license-tracker' ),
				/* translators: %s: plugin or theme name. */
				'blocked' => __( 'A newer version of %s is out, but this site cannot download it.', 'axle-license-tracker' ),
				/* translators: %s: plugin or theme name. */
				'at-risk' => __( '%s is not getting updates.', 'axle-license-tracker' ),
			);
			$subject = sprintf(
				/* translators: 1: site name, 2: plugin or theme name, 3: what happened. */
				__( '[%1$s] %2$s: %3$s', 'axle-license-tracker' ),
				$site,
				$row['name'],
				'expired' === $threshold ? Messages::get( 'state_expired' ) : Messages::get( 'risk_' . $threshold )
			);
			$lines = array_merge( array( sprintf( $labels[ $threshold ] ?? '%s', $row['name'] ) ), $row['risk_signals'] );
		}

		if ( '' !== $row['owner'] ) {
			/* translators: %s: owner name. */
			$lines[] = sprintf( __( 'Owner: %s.', 'axle-license-tracker' ), $row['owner'] );
		}
		/* translators: %s: link to the license screen. */
		$lines[] = sprintf( __( 'Details: %s', 'axle-license-tracker' ), $link );

		$email = array(
			'subject' => $subject,
			'body'    => implode( "\n\n", $lines ),
		);

		/**
		 * Filters a reminder or alert email.
		 *
		 * @param array      $email     subject and body.
		 * @param array      $row       Component row.
		 * @param int|string $threshold Reminder day, or expired, blocked or at-risk for an alert.
		 * @param string     $kind      renewal or alert.
		 */
		$filtered = (array) apply_filters( 'axle_license_tracker_reminder_email', $email, $row, $threshold, $kind );
		if ( ! empty( $filtered['subject'] ) && isset( $filtered['body'] ) ) {
			$email = array(
				'subject' => (string) $filtered['subject'],
				'body'    => (string) $filtered['body'],
			);
		}

		return array(
			'component'  => $row['id'],
			'name'       => $row['name'],
			'kind'       => $kind,
			'threshold'  => $threshold,
			'subject'    => $email['subject'],
			'body'       => $email['body'],
			'recipients' => $this->recipients( $row, $kind ),
		);
	}

	/**
	 * Sends one message.
	 *
	 * @param array      $message Message.
	 * @param array|null $row     Component row, null for the digest.
	 */
	private function deliver( array $message, ?array $row ): void {
		if ( ! $message['recipients'] ) {
			return;
		}
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One email per license and reminder day, to the site's own people.
		wp_mail( $message['recipients'], $message['subject'], $message['body'] );

		/**
		 * Fires after a reminder, alert or digest was sent.
		 *
		 * @param array|null $row        Component row; null for the digest.
		 * @param int|string $threshold  Reminder day, alert condition, or `digest`.
		 * @param string[]   $recipients Who got it.
		 */
		do_action( 'axle_license_tracker_reminder_sent', $row, $message['threshold'], $message['recipients'] );
	}

	/**
	 * The monthly digest, once per calendar month when turned on.
	 *
	 * @param bool $dry_run Only build it.
	 * @return array|null The digest, or null when none is due.
	 */
	private function digest( bool $dry_run ): ?array {
		/**
		 * Filters whether the monthly digest is sent.
		 *
		 * @param bool $enabled Default the setting, off.
		 */
		if ( ! apply_filters( 'axle_license_tracker_digest_enabled', (bool) $this->settings->get( 'digest' ) ) ) {
			return null;
		}
		$month = current_time( 'Y-m' );
		if ( $this->store->get( 'digest' ) === $month ) {
			return null;
		}

		$rows   = $this->tracker->tracked();
		$totals = Tracker::totals( $rows );
		$lines  = array(
			/* translators: 1: premium count, 2: expired count, 3: expiring count, 4: at-risk count. */
			sprintf( __( '%1$d premium components: %2$d expired, %3$d expiring, %4$d not getting updates.', 'axle-license-tracker' ), $totals['premium'], $totals['expired'], $totals['expiring'], $totals['at_risk'] ),
		);
		foreach ( $rows as $row ) {
			if ( $row['ignored'] ) {
				continue;
			}
			$lines[] = sprintf( '- %1$s: %2$s%3$s', $row['name'], Messages::get( 'state_' . $row['state'] ), '' !== $row['renewal'] ? ', ' . $row['renewal'] : '' );
		}

		$message = array(
			'component'  => '',
			'name'       => '',
			'kind'       => 'digest',
			'threshold'  => 'digest',
			/* translators: %s: site name. */
			'subject'    => sprintf( __( '[%s] Monthly license digest', 'axle-license-tracker' ), wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'body'       => implode( "\n", $lines ),
			'recipients' => $this->recipients( null, 'digest' ),
		);
		if ( ! $dry_run ) {
			$this->deliver( $message, null );
			$this->store->set( 'digest', $month );
		}

		return $message;
	}
}
