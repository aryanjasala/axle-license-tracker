<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

use AryanX\Axle\LicenseTracker\Admin\CostsScreen;
use AryanX\Axle\LicenseTracker\Admin\DashboardWidget;
use AryanX\Axle\LicenseTracker\Admin\HubScreen;
use AryanX\Axle\LicenseTracker\Admin\Page;
use AryanX\Axle\LicenseTracker\Admin\PluginsScreen;
use AryanX\Axle\LicenseTracker\Cli\Command;
use AryanX\Axle\LicenseTracker\Cli\FeedCommand;
use AryanX\Axle\LicenseTracker\Cli\HubCommand;
use AryanX\Axle\LicenseTracker\Health\SiteHealth;
use AryanX\Axle\LicenseTracker\Hub\Hub;
use AryanX\Axle\LicenseTracker\Hub\Remote;
use AryanX\Axle\LicenseTracker\Hub\Sites;
use AryanX\Axle\LicenseTracker\Rest\ReportController;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the shared objects once and registers their hooks.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Detection store.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Installed components.
	 *
	 * @var Inventory
	 */
	private Inventory $inventory;

	/**
	 * Update transient reader.
	 *
	 * @var Updates
	 */
	private Updates $updates;

	/**
	 * Fingerprint scan.
	 *
	 * @var Scanner
	 */
	private Scanner $scanner;

	/**
	 * Per-component records.
	 *
	 * @var Licenses
	 */
	private Licenses $licenses;

	/**
	 * Manual details.
	 *
	 * @var Details
	 */
	private Details $details;

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
	 * Reminders.
	 *
	 * @var Reminders
	 */
	private Reminders $reminders;

	/**
	 * Exports.
	 *
	 * @var Export
	 */
	private Export $export;

	/**
	 * The hub.
	 *
	 * @var Hub
	 */
	private Hub $hub;

	/**
	 * The calendar feed.
	 *
	 * @var Calendar
	 */
	private Calendar $calendar;

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->settings  = new Settings();
		$this->store     = new Store();
		$this->inventory = new Inventory();
		$this->updates   = new Updates( $this->store );
		$this->scanner   = new Scanner( $this->store, $this->inventory );
		$this->licenses  = new Licenses();
		$this->details   = new Details( $this->licenses );
		$registry        = new Registry();
		$vendors         = new Vendors();
		$this->tracker   = new Tracker(
			$this->inventory,
			$this->store,
			$this->updates,
			$this->scanner,
			new Classifier( $this->updates, $this->scanner, $vendors, $registry ),
			$registry,
			$vendors,
			$this->licenses,
			new Bundles(),
			$this->settings
		);
		$this->report    = new Report( $this->tracker );
		$this->reminders = new Reminders( $this->tracker, $this->licenses, $this->settings, $this->store );
		$this->export    = new Export( $this->report );
		$sites           = new Sites();
		$this->hub       = new Hub( $this->settings, $sites, new Remote( $sites ), $this->tracker );
		$this->calendar  = new Calendar( $this->tracker, $this->hub );
	}

	/**
	 * The rows, for other plugins.
	 *
	 * @return Tracker
	 */
	public function tracker(): Tracker {
		return $this->tracker;
	}

	/**
	 * The report, for other plugins.
	 *
	 * @return Report
	 */
	public function report(): Report {
		return $this->report;
	}

	/**
	 * The hub, for other plugins.
	 *
	 * @return Hub
	 */
	public function hub(): Hub {
		return $this->hub;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		$hub_screen   = new HubScreen( $this->hub );
		$costs_screen = new CostsScreen( $this->hub, $this->calendar );
		$services     = array(
			$this->inventory,
			$this->updates,
			$this->scanner,
			$this->licenses,
			$this->reminders,
			new Privacy( $this->licenses ),
			$this->hub,
			$this->calendar,
			$hub_screen,
			$costs_screen,
			new Page( $this->tracker, $this->details, $this->settings, $this->export, $this->hub, $hub_screen, $costs_screen ),
			new PluginsScreen( $this->tracker ),
			new DashboardWidget( $this->tracker ),
			new SiteHealth( $this->tracker, $this->hub ),
			new ReportController( $this->report ),
			new Abilities( $this->tracker, $this->report, $this->details, $this->hub ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		// A new plugin or a new version gets its fingerprint scan in the background.
		add_action( 'upgrader_process_complete', array( $this->scanner, 'schedule' ) );
		add_action( 'activated_plugin', array( $this->scanner, 'schedule' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'axle-license-tracker', new Command( $this->tracker, $this->details, $this->reminders, $this->export, $this->hub ) );
			\WP_CLI::add_command( 'axle-license-tracker hub', new HubCommand( $this->hub ) );
			\WP_CLI::add_command( 'axle-license-tracker feed', new FeedCommand( $this->calendar ) );
		}
	}
}
