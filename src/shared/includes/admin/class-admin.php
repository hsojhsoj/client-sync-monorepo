<?php
/**
 * File: src/shared/includes/admin/class-admin.php -> client-sync/includes/admin/class-admin.php
 * The admin-specific functionality of the plugin.
 *
 * This file defines the plugin name, version, and registers all hooks for the admin area.
 * This class coordinates various manager classes to handle specific responsibilities.
 *
 * UPDATED: Added HIPAA Compliance settings tab with setup guide and configuration options.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Services\FormRenderer;
use DependentMedia\ClientSync\Admin\Asset_Manager;
use DependentMedia\ClientSync\Admin\Settings_Manager;
use DependentMedia\ClientSync\Admin\Form_Handler;
use DependentMedia\ClientSync\Admin\Menu_Manager;
use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	private $plugin_name;
	private $version;
	private $db_manager;

	/**
	 * @var Reporting_Engine
	 */
	private $reporting_engine;

	/**
	 * @var Dashboard_Manager
	 */
	private $dashboard_manager;

	/**
	 * @var HIPAA_Compliance_Manager
	 */
	private $hipaa_manager;

	public function __construct( string $plugin_name, string $version, Database_Manager $db_manager ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->db_manager  = $db_manager;

		// Instantiate extracted sub-managers.
		$this->reporting_engine  = new Reporting_Engine();
		$this->dashboard_manager = new Dashboard_Manager( $db_manager, $this->reporting_engine );
		$this->hipaa_manager     = new HIPAA_Compliance_Manager();
	}

	public function register_hooks() {
		$asset_manager    = new Asset_Manager( $this->plugin_name, $this->version, $this->db_manager );
		$settings_manager = new Settings_Manager();
		$form_handler     = new Form_Handler( $this->db_manager );
		$menu_manager     = new Menu_Manager( $this );

		$asset_manager->register_hooks();
		$settings_manager->register_hooks();
		$form_handler->register_hooks();
		( new Dashboard_Widget() )->register_hooks();
		( new Fullscreen_Layout() )->register_hooks();
		add_action( 'admin_init', [ '\DependentMedia\ClientSync\Admin\Onboarding_Wizard', 'handle_post_on_init' ] );

		// Register hooks for extracted sub-managers.
		$this->reporting_engine->register_hooks();
		$this->hipaa_manager->register_hooks();

		add_action( 'admin_menu', [ $menu_manager, 'register_menus' ], 10 );
		add_action( 'admin_menu', [ $menu_manager, 'modify_and_reorder_submenu' ], 99 );
		add_action( 'admin_menu', [ $this, 'setup_wizard_page' ] );
		add_action( 'admin_init', [ $this, 'handle_activation_redirect' ] );

		// Hook format is load-{parent_slug}_page_{menu_slug}
		add_action( 'load-client-sync_page_clisyc-available-slots-list', [ $this, 'add_available_slots_screen_options' ] );
		add_action( 'load-client-sync_page_clisyc-calendars', [ $this, 'add_calendars_page_help_tabs' ] );

		add_filter( 'set-screen-option', [ $this, 'set_available_slots_screen_option' ], 10, 3 );
		add_action( 'wp_ajax_clisyc_reset_convert_tz_detection', [ $this, 'ajax_reset_convert_tz_detection' ] );
		add_filter( 'parent_file', [ $this, 'fix_admin_menu_highlight' ] );
		add_action( 'admin_footer', [ $this, 'render_global_icon_picker_modal' ] );
	}

	public function render_dimensions_page() {
		require_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-dimensions-page.php';
	}

	public function render_settings_page() {
		// Handle Google OAuth Callback.
		// Nonce verification is not possible here as this is an OAuth redirect from Google's servers.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback from external service.
		if ( isset( $_GET['action'] ) && 'google_oauth_callback' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) && isset( $_GET['code'], $_GET['state'] ) ) {
			if ( class_exists( '\ClientSyncPro\Services\Google_Sync_Service' ) ) {
				$sync_service = new \ClientSyncPro\Services\Google_Sync_Service();
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback from external service.
				$oauth_code  = sanitize_text_field( wp_unslash( $_GET['code'] ) );
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback from external service.
				$oauth_state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
				$result      = $sync_service->handle_oauth_callback( $oauth_code, $oauth_state );

				if ( is_wp_error( $result ) ) {
					// Display error and stop.
					echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Google Sync Error:', 'client-sync' ) . '</strong> ' . esc_html( $result->get_error_message() ) . '</p></div>';
					return;
				} else {
					// Redirect to the Employee CPT edit screen of the user who just connected.
					$decoded_state = json_decode( base64_decode( $oauth_state ), true );
					if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded_state ) ) {
						$decoded_state = [];
					}
					$user_id       = $decoded_state['user_id'] ?? 0;
					if ( $user_id && ( $employee_post_id = get_user_meta( $user_id, 'clisyc_employee_post_id', true ) ) ) {
						set_transient( 'clisyc_admin_feedback', [ 'message' => 'Successfully connected to Google Calendar for ' . $result['email'] ], Constants::TRANSIENT_ADMIN_FEEDBACK_TTL );
						wp_safe_redirect( get_edit_post_link( $employee_post_id, 'raw' ) );
						exit;
					}
				}
			}
		}

		// Setup Checklist and Directions have been moved to the standalone Guide page (clisyc-guide).
		$tabs = [
			'appearance'    => __( 'Appearance', 'client-sync' ),
			'behavior'      => __( 'Behavior', 'client-sync' ),
			'notifications' => __( 'Notifications', 'client-sync' ),
			'payments'      => __( 'Payments', 'client-sync' ),
			'automation'    => __( 'Automation', 'client-sync' ),
			'integrations'  => __( 'Integrations', 'client-sync' ),
			'import_export' => __( 'Import / Export', 'client-sync' ),
			'shortcodes'    => __( 'Shortcodes', 'client-sync' ),
			'hipaa'         => __( 'HIPAA Compliance', 'client-sync' ),
		];

		// Nonce verification is not required here as we are just reading a URL parameter for navigation.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$unslashed_get = wp_unslash( $_GET );
		
		// UPDATED: Default to 'appearance' instead of 'styles'
		$active_tab = isset( $unslashed_get['tab'] ) && array_key_exists( $unslashed_get['tab'], $tabs ) ? sanitize_key( $unslashed_get['tab'] ) : 'appearance';
		?>
<div class="wrap">
		<h1><?php esc_html_e( 'Client Sync Settings', 'client-sync' ); ?></h1>
		<?php
		// This is the correct place to show settings update messages for a tabbed page.
		// It will catch the redirect from options.php and display the "Settings saved." notice.
		settings_errors();
		?>

		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="?page=clisyc-settings&tab=<?php echo esc_attr( $slug ); ?>" class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<div class="tab-content" style="margin-top: 1em;">
			<?php
			switch ( $active_tab ) {
				case 'notifications':
					include_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-notifications-page.php';
					break;
				case 'payments':
					include_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-payments-page.php';
					break;
				case 'automation':
					include_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-automation-page.php';
					break;
				case 'integrations':
					include_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-integrations-page.php';
					break;
				case 'import_export':
					include_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-import-export-page.php';
					break;
				case 'shortcodes':
					include_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-shortcodes-page.php';
					break;
				// NEW: Appearance tab (visual styling, colors, calendar display)
				case 'appearance':
					include_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-appearance-page.php';
					break;
				// NEW: Behavior tab (booking rules, links, self-service, spam)
				case 'behavior':
					include_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-behavior-page.php';
					break;
				// NEW: HIPAA Compliance tab (setup guide, encryption status, audit settings)
				case 'hipaa':
					include_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-hipaa-settings-page.php';
					break;
				default:
					// Fallback to appearance if unknown tab
					include_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-appearance-page.php';
					break;
			}
			?>
		</div>
	</div>
	<?php
}

public function setup_wizard_page() {
	add_submenu_page( 'options.php', __( 'Client Sync Setup Wizard', 'client-sync' ), __( 'Setup Wizard', 'client-sync' ), 'manage_options', 'clisyc-setup', [ $this, 'render_wizard_page_wrapper' ] );
}

public function render_wizard_page_wrapper() {
	require_once clisyc_PLUGIN_DIR . 'includes/admin/class-onboarding-wizard.php';
	$wizard = new \DependentMedia\ClientSync\Admin\Onboarding_Wizard();
	$wizard->render_wizard_page();
}

public function handle_activation_redirect() {
	if ( get_transient( 'clisyc_activation_redirect' ) ) {
		delete_transient( 'clisyc_activation_redirect' );

		// This is a standard WordPress check on activation and does not require a nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! wp_doing_ajax() && ! isset( $_GET['activate-multi'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=clisyc-setup' ) );
			exit;
		}
	}
}

public function render_dashboard_page() {
	$this->dashboard_manager->render_dashboard_page();
}

public function render_getting_started_page() {
	$this->dashboard_manager->render_getting_started_page();
}

public function render_guide_page() {
	$this->dashboard_manager->render_guide_page();
}

public function render_custom_fields_page() {
	$client_custom_fields      = get_option( Constants::OPTION_CUSTOM_FIELDS, [] );
	$client_field_order        = get_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, [] );
	$appointment_custom_fields = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
	$appointment_field_order   = get_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, [] );
	$dimension_fields          = get_option( Constants::OPTION_DIMENSION_FIELDS, [] );

	// Reading the 'cf_tab' GET parameter is for displaying the correct navigational tab, a non-state-changing action.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$unslashed_get = wp_unslash( $_GET );
	$active_tab    = isset( $unslashed_get['cf_tab'] ) ? sanitize_key( $unslashed_get['cf_tab'] ) : 'clients-cf';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Custom Fields', 'client-sync' ); ?></h1>
		<p><?php esc_html_e( 'Add custom fields to store extra information about your clients or appointments.', 'client-sync' ); ?></p>
		
		<?php
		$feedback = get_transient( 'clisyc_custom_field_feedback' );
		if ( $feedback && is_array( $feedback ) && ! empty( $feedback['message'] ) ) {
			$notice_class = 'error' === ( $feedback['type'] ?? 'success' ) ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . wp_kses_post( $feedback['message'] ) . '</p></div>';
			delete_transient( 'clisyc_custom_field_feedback' );
		}
		?>
		
		<nav class="nav-tab-wrapper">
			<a href="?page=clisyc-custom-fields&cf_tab=clients-cf" class="nav-tab <?php echo $active_tab === 'clients-cf' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Client Fields', 'client-sync' ); ?></a>
			<a href="?page=clisyc-custom-fields&cf_tab=appointments-cf" class="nav-tab <?php echo $active_tab === 'appointments-cf' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Booking Fields', 'client-sync' ); ?></a>
			<a href="?page=clisyc-custom-fields&cf_tab=dimensions-cf" class="nav-tab <?php echo $active_tab === 'dimensions-cf' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Dimension Attributes', 'client-sync' ); ?></a>
		</nav>
		<div class="clisyc-fields-tab-content" style="margin-top: 1em;">
			<div class="clisyc-fields-tab-pane" style="display: <?php echo $active_tab === 'clients-cf' ? 'block' : 'none'; ?>;"><?php include clisyc_PLUGIN_DIR . 'includes/admin/views/template-parts/part-client-custom-fields.php'; ?></div>
			<div class="clisyc-fields-tab-pane" style="display: <?php echo $active_tab === 'appointments-cf' ? 'block' : 'none'; ?>;"><?php include clisyc_PLUGIN_DIR . 'includes/admin/views/template-parts/part-appointment-custom-fields.php'; ?></div>
			<div class="clisyc-fields-tab-pane" style="display: <?php echo $active_tab === 'dimensions-cf' ? 'block' : 'none'; ?>;"><?php include clisyc_PLUGIN_DIR . 'includes/admin/views/template-parts/part-dimension-custom-fields.php'; ?></div>
		</div>
	</div>
	<?php
}

public function render_calendars_page() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading for non-state-changing tab navigation.
	$unslashed_get = wp_unslash( $_GET );
	$default_tab   = 'appointment-calendar';
	$current_tab   = isset( $unslashed_get['tab'] ) ? sanitize_key( $unslashed_get['tab'] ) : $default_tab;

	$tabs = [
		'appointment-calendar' => __( 'Appointment Calendar', 'client-sync' ),
		'master-schedule'      => __( 'Master Schedule', 'client-sync' ),
		'time-slots'           => __( 'Manage Time Slots', 'client-sync' ),
		'blocked-periods'      => __( 'Global Blocked Periods', 'client-sync' ),
		'timeline'             => __( 'Timeline Overview', 'client-sync' ),
	];

	if ( ! array_key_exists( $current_tab, $tabs ) ) {
		$current_tab = $default_tab;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Calendars & Availability', 'client-sync' ); ?></h1>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="?page=clisyc-calendars&tab=<?php echo esc_attr( $slug ); ?>" class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<div class="tab-content" style="margin-top: 1em;">
			<?php
			$template_parts = [
				'appointment-calendar' => clisyc_PLUGIN_DIR . 'includes/admin/views/template-parts/part-appointment-calendar.php',
				'master-schedule'      => clisyc_PLUGIN_DIR . 'includes/admin/views/template-parts/part-master-schedule.php',
				'time-slots'           => clisyc_PLUGIN_DIR . 'includes/admin/views/template-parts/part-manage-time-slots.php',
				'blocked-periods'      => clisyc_PLUGIN_DIR . 'includes/admin/views/template-parts/part-blocked-periods.php',
				'timeline'             => clisyc_PLUGIN_DIR . 'includes/admin/views/template-parts/part-timeline-overview.php',
			];

			if ( isset( $template_parts[ $current_tab ] ) ) {
				include_once $template_parts[ $current_tab ];
			}
			?>
		</div>
	</div>
	<?php
}

public function render_blocked_periods_page() {
	require_once clisyc_PLUGIN_DIR . 'includes/admin/views/template-parts/part-blocked-periods.php';
}

public function render_available_slots_list_page() {
	require_once clisyc_PLUGIN_DIR . 'includes/admin/list-tables/class-available-slots-list-table.php';
	require_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-available-slots-list-page.php';
}

public function render_reports_page() {
	$this->reporting_engine->render_reports_page();
}

public function render_testing_page() {
	require_once clisyc_PLUGIN_DIR . 'includes/admin/views/view-testing-page.php';
}

public function render_global_icon_picker_modal() {
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, [ 'client-sync_page_clisyc-dimensions', 'client-sync_page_clisyc-custom-fields' ], true ) ) {
		return;
	}
	?>
	<div id="clisyc-global-icon-picker-modal" title="<?php esc_attr_e( 'Choose an Icon', 'client-sync' ); ?>" style="display:none;">
		<nav class="nav-tab-wrapper" style="margin-bottom: 1em;">
			<a href="#clisyc-icon-tab-fa" class="nav-tab nav-tab-active" data-icon-set="fa"><?php esc_html_e( 'Font Awesome', 'client-sync' ); ?></a>
			<a href="#clisyc-icon-tab-dashicons" class="nav-tab" data-icon-set="dashicons"><?php esc_html_e( 'Dashicons', 'client-sync' ); ?></a>
		</nav>
		<div class="clisyc-icon-picker-controls">
			<input type="search" id="clisyc-global-icon-picker-search" placeholder="<?php esc_attr_e( 'Search icons...', 'client-sync' ); ?>" class="widefat">
		</div>
		<div id="clisyc-global-icon-picker-grid" class="clisyc-icon-picker-grid"></div>
	</div>

	<?php // ================== START: THE FIX ================== ?>
	<script type="text/javascript">
		jQuery(document).ready(function($) {
			// This code now runs AFTER the modal HTML is on the page.
			if (typeof ClisycGlobalIconPicker !== 'undefined' && typeof clisycIconPickerData !== 'undefined') {
				ClisycGlobalIconPicker.init(clisycIconPickerData.icons);
			}
		});
	</script>
	<?php // =================== END: THE FIX =================== ?>
	<?php
}

/**
 * Adds contextual help tabs to the Calendars & Availability page.
 */
public function add_calendars_page_help_tabs() {
    $screen = get_current_screen();
    if ( ! $screen ) {
        return;
    }

    // Tab 1: Overview of Global Blocks
    $screen->add_help_tab(
        [
            'id'      => 'clisyc_global_blocks_overview',
            'title'   => __( 'Global Blocked Periods', 'client-sync' ),
            'content' => '<p><strong>' . __( 'What are Global Blocked Periods?', 'client-sync' ) . '</strong></p>' .
                    '<p>' . __( 'These are "Master Rules" used for broad date ranges where your entire business is closed (e.g., Christmas, New Years, or a week for renovations).', 'client-sync' ) . '</p>' .
                    '<p>' . __( 'When a date falls within a Global Blocked Period, the system will mark the entire day as unavailable on the frontend, regardless of what is in your time slot database.', 'client-sync' ) . '</p>',
        ]
    );

    // Tab 2: Comparison (The explanation you requested)
    $screen->add_help_tab(
        [
            'id'      => 'clisyc_blocking_comparison',
            'title'   => __( 'Understanding Blocking', 'client-sync' ),
            'content' => '<p><strong>' . __( 'Two Ways to Block Time', 'client-sync' ) . '</strong></p>' .
                    '<p>' . __( 'Client Sync uses two different methods for blocking time. It is important to choose the right one for your situation:', 'client-sync' ) . '</p>' .
                    '<ul>' .
                    '<li><strong>' . __( 'Global Blocked Periods (Current Tab):', 'client-sync' ) . '</strong> ' . 
                        __( 'Used for broad date ranges (days/weeks). These live in your Settings and act as a master override for the whole system.', 'client-sync' ) . '</li>' .
                    '<li><strong>' . __( 'Slot-Level Blocks (Manage Time Slots Tab):', 'client-sync' ) . '</strong> ' . 
                        __( 'Used for granular, one-off changes (specific hours). For example, if you need to block off 2:00 PM to 3:00 PM next Tuesday for a personal appointment.', 'client-sync' ) . '</li>' .
                    '</ul>' .
                    '<p><em>' . __( 'Note: Granular blocks created on the "Manage Time Slots" calendar will not appear here as date ranges, as they are stored as individual units in the slot database.', 'client-sync' ) . '</em></p>',
        ]
    );

    // Add Help Sidebar
    $screen->set_help_sidebar(
        '<p><strong>' . __( 'Need more help?', 'client-sync' ) . '</strong></p>' .
        '<p><a href="https://dependentmedia.com/" target="_blank">' . __( 'Documentation', 'client-sync' ) . '</a></p>'
    );
}

public function add_available_slots_screen_options() {
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}

	// 1. Add Screen Option (Pagination)
	$option = 'per_page';
	$args   = [
		'label'   => __( 'Slots per page', 'client-sync' ),
		'default' => 20,
		'option'  => 'clisyc_slots_per_page',
	];
	add_screen_option( $option, $args );

	// 2. Add Help Tabs
	$screen->add_help_tab(
		[
			'id'      => 'clisyc_slots_overview',
			'title'   => __( 'Overview', 'client-sync' ),
			'content' => '<p>' . __( 'This screen provides a raw database view of every single time slot generated by the system. It includes both "Available" slots (which can be booked) and "Blocked" slots (admin overrides).', 'client-sync' ) . '</p>' .
					'<p>' . __( 'You can use this screen to audit your schedule or bulk-delete specific slots if necessary.', 'client-sync' ) . '</p>',
		]
	);

	$screen->add_help_tab(
		[
			'id'      => 'clisyc_slots_filtering',
			'title'   => __( 'Filtering', 'client-sync' ),
			'content' => '<p>' . __( 'Use the filters above the table to narrow down the list:', 'client-sync' ) . '</p>' .
					'<ul>' .
					'<li><strong>' . __( 'Dates:', 'client-sync' ) . '</strong> ' . __( 'Select a start and end date to see slots within a specific range.', 'client-sync' ) . '</li>' .
					'<li><strong>' . __( 'Dimensions:', 'client-sync' ) . '</strong> ' . __( 'Filter slots that belong to a specific Service, Staff member, or Room.', 'client-sync' ) . '</li>' .
					'</ul>',
		]
	);

	$screen->add_help_tab(
		[
			'id'      => 'clisyc_slots_cleanup',
			'title'   => __( 'Cleanup', 'client-sync' ),
			'content' => '<p>' . __( '<strong>Cleanup Past Available Slots:</strong> This button deletes all "Available" slots in the past. It does NOT delete Blocked slots or Booked Appointments (which are stored separately). This is useful for keeping your database size small.', 'client-sync' ) . '</p>',
		]
	);

	// Add Help Sidebar
	$screen->set_help_sidebar(
		'<p><strong>' . __( 'For more information:', 'client-sync' ) . '</strong></p>' .
		'<p><a href="https://dependentmedia.com/" target="_blank">' . __( 'Documentation', 'client-sync' ) . '</a></p>' .
		'<p><a href="https://dependentmedia.com/support/" target="_blank">' . __( 'Support', 'client-sync' ) . '</a></p>'
	);
}

public function set_available_slots_screen_option( $status, $option, $value ) {
	if ( 'clisyc_slots_per_page' === $option ) { return $value; } return $status;
}

public function ajax_reset_convert_tz_detection() {
	check_ajax_referer('clisyc_reset_convert_tz_nonce', 'security');
	if (!current_user_can('manage_options')) {
		wp_send_json_error(['message' => __('Permission denied.', 'client-sync')]);
	}
	update_option(Constants::OPTION_MYSQL_CONVERT_TZ, 'unknown');
	delete_transient('clisyc_convert_tz_notice_shown');
	wp_send_json_success([
		'message' => __('MySQL timezone conversion auto-detection status has been reset. The plugin will attempt to re-evaluate on the next relevant calendar operation.', 'client-sync'),
		'new_status' => __('Unknown', 'client-sync')
	]);
}

// Methods extracted to Reporting_Engine, Dashboard_Manager, and HIPAA_Compliance_Manager.


public function fix_admin_menu_highlight( $parent_file ) {
	global $current_screen, $pagenow;

	// Reading the 'page' GET parameter is for menu highlighting only, a non-state-changing action.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

	if ( 'admin.php' === $pagenow && in_array( $page, [ 'clisyc-calendars', 'clisyc-blocked-periods' ], true ) ) {
		return 'clisyc-settings';
	}
	
	if ( 'admin.php' === $pagenow && 'clisyc-setup' === $page ) {
		return 'clisyc-settings';
	}

	$registry        = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
	$dimension_slugs = array_keys( $registry['dimensions'] ?? [] );
	$pro_cpts        = [ 'clisyc_output_tmpl', 'clisyc_form', 'clisyc_member_plan', 'clisyc_package' ];
	$plugin_cpts     = array_merge( [ Constants::POST_TYPE_APPOINTMENT ], $dimension_slugs, $pro_cpts );

	if ( $current_screen && in_array( $current_screen->post_type, $plugin_cpts, true ) ) {
		return 'client-sync';
	}

	return $parent_file;
}


/**
 * Recursively scans the plugin directory and generates a text-based file tree.
 *
 * @param string $path      The absolute path to the directory to scan.
 * @param int    $max_depth Optional maximum traversal depth (0 = unlimited). Limits exposure of server structure.
 * @return string The formatted file and folder tree.
 */
public static function generate_file_tree( string $path, int $max_depth = 0 ): string {
    $output = basename( rtrim( $path, '/' ) ) . "/\n";

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
        \RecursiveIteratorIterator::SELF_FIRST
    );

    if ( $max_depth > 0 ) {
        $iterator->setMaxDepth( $max_depth );
    }

    foreach ( $iterator as $fileInfo ) {
        $depth = $iterator->getDepth();
        $indent = str_repeat( '|   ', $depth ) . '|-- ';
        $output .= $indent . $fileInfo->getFilename() . "\n";
    }

    return $output;
}

}