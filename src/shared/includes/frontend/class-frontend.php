<?php
/**
 * File: src/shared/includes/frontend/class-frontend.php
 * The public-facing functionality of the plugin.
 *
 * UPDATED: Added CSS variable injection via wp_add_inline_style on :root scope.
 * This makes color settings available globally to all components (Calendar, Cards, Modal, etc.)
 *
 * @package    ClientSync
 * @subpackage ClientSync/Frontend
 */

namespace DependentMedia\ClientSync\Frontend;

use DependentMedia\ClientSync\Frontend\Shortcodes;
use DependentMedia\ClientSync\Frontend\Form_Handler;
use DependentMedia\ClientSync\Admin\Settings_Manager;
use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Frontend {

	private $plugin_name;
	private $version;

	public function __construct( string $plugin_name, string $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function register_hooks() {
		// Register all assets early, but don't load them on every page.
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );

		add_action( 'init', [ $this, 'handle_self_service_actions' ] );
		add_action( 'pre_get_posts', [ $this, 'modify_archive_query_for_availability_search' ] );
		add_filter( 'template_include', [ $this, 'override_primary_dimension_archive_template' ] );
		add_filter( 'the_content', [ $this, 'append_booking_tools_to_dimension_content' ] );

		$shortcodes = new Shortcodes( $this->plugin_name, $this->version );
		$shortcodes->register_all();

		$form_handler = new Form_Handler();
		$form_handler->register_hooks();
	}

	/**
	 * Registers all scripts and styles used by the plugin on the frontend.
	 * This allows them to be enqueued on-demand by the shortcode rendering functions.
	 *
	 * UPDATED: Added CSS variable injection via wp_add_inline_style.
	 */
	public function register_assets() {
		// --- Styles ---
		wp_register_style( 'clisyc-fontawesome', clisyc_PLUGIN_URL . 'assets/vendor/fontawesome/css/all.min.css', [], '6.5.1' );
		wp_register_style( 'clisyc-frontend-style', clisyc_PLUGIN_URL . 'assets/css/clisyc-frontend-style.css', [ 'dashicons', 'clisyc-fontawesome' ], $this->version );
		wp_register_style( 'clisyc-jquery-ui', clisyc_PLUGIN_URL . 'assets/vendor/jquery-ui/jquery-ui.css', [], '1.13.2' );
		wp_register_style( 'clisyc-image-marker', clisyc_PLUGIN_URL . 'assets/css/clisyc-image-marker.css', [], $this->version );
		wp_register_style( 'clisyc-calendar', clisyc_PLUGIN_URL . 'assets/css/clisyc-calendar.css', [], $this->version );
		wp_register_style( 'clisyc-litepicker', clisyc_PLUGIN_URL . 'assets/vendor/litepicker/litepicker.css', [], '2.0.12' );
		wp_register_style( 'clisyc-appointment-detail', clisyc_PLUGIN_URL . 'assets/css/clisyc-appointment-detail.css', [ 'dashicons', 'clisyc-frontend-style' ], $this->version );

		// QR code renderer for appointment detail page.
		$qr_asset_file = CLISYC_SHARED_DIR . '../assets/dist/frontend-qr/index.asset.php';
		$qr_asset      = file_exists( $qr_asset_file ) ? require $qr_asset_file : [ 'dependencies' => [], 'version' => $this->version ];
		wp_register_script( 'clisyc-frontend-qr', clisyc_PLUGIN_URL . 'assets/dist/frontend-qr/index.js', $qr_asset['dependencies'], $qr_asset['version'], true );

		// Venue map highlighter for appointment detail page.
		$venue_map_asset_file = CLISYC_SHARED_DIR . '../assets/dist/frontend-venue-map/index.asset.php';
		$venue_map_asset      = file_exists( $venue_map_asset_file ) ? require $venue_map_asset_file : [ 'dependencies' => [], 'version' => $this->version ];
		wp_register_script( 'clisyc-frontend-venue-map', clisyc_PLUGIN_URL . 'assets/dist/frontend-venue-map/index.js', $venue_map_asset['dependencies'], $venue_map_asset['version'], true );

		// Staff dashboard styles
		wp_register_style( 'clisyc-staff-dashboard', clisyc_PLUGIN_URL . 'assets/css/clisyc-staff-dashboard.css', [ 'dashicons' ], $this->version );

		// Collapsible header styles for booking form (must load AFTER frontend-style)
		wp_register_style( 'clisyc-collapsible-header', clisyc_PLUGIN_URL . 'assets/css/clisyc-collapsible-header.css', [ 'clisyc-frontend-style' ], $this->version );

		// Membership plans pricing cards (frontend shortcode)
		wp_register_style( 'clisyc-membership-plans', clisyc_PLUGIN_URL . 'assets/css/clisyc-membership-plans.css', [ 'dashicons', 'clisyc-frontend-style' ], $this->version );

		// --- Inject CSS Variables into :root scope ---
		// This makes colors available globally to all components.
		$this->inject_css_variables();

		// --- Scripts (Registered with dependencies and defer strategy) ---
		$script_handles = [
			'clisyc-fullcalendar'                 => [
				'src'  => 'assets/vendor/fullcalendar/fullcalendar.global.min.js',
				'deps' => [ 'jquery' ],
				'ver'  => '6.1.11',
			],
			'clisyc-litepicker-script'            => [
				'src'  => 'assets/vendor/litepicker/litepicker.js',
				'deps' => [],
				'ver'  => '2.0.12',
			],
			'clisyc-custom-dropdown'              => [
				'src'  => 'assets/js/clisyc-custom-dropdown.js',
				'deps' => [ 'jquery' ],
				'ver'  => $this->version,
			],
			'clisyc-frontend-appointment-calendar' => [
				'src'  => 'assets/js/clisyc-frontend-appointment-calendar.js',
				'deps' => [ 'jquery', 'clisyc-fullcalendar', 'jquery-ui-datepicker', 'clisyc-custom-dropdown', 'clisyc-litepicker-script' ],
				'ver'  => $this->version,
			],
			'clisyc-frontend-user-calendar'       => [
				'src'  => 'assets/js/clisyc-frontend-user-calendar.js',
				'deps' => [ 'jquery', 'clisyc-fullcalendar', 'clisyc-custom-dropdown' ],
				'ver'  => $this->version,
			],
			'clisyc-frontend-appointment-list'    => [
				'src'  => 'assets/js/clisyc-frontend-appointment-list.js',
				'deps' => [ 'jquery' ],
				'ver'  => $this->version,
			],
			'clisyc-frontend-image-marker'        => [
				'src'  => 'assets/js/clisyc-frontend-image-marker.js',
				'deps' => [ 'jquery' ],
				'ver'  => $this->version,
			],
			'clisyc-frontend-date-range-booking'  => [
				'src'  => 'assets/js/clisyc-frontend-date-range-booking.js',
				'deps' => [ 'jquery', 'clisyc-litepicker-script', 'clisyc-custom-dropdown' ],
				'ver'  => $this->version,
			],
			'clisyc-manager-calendar'             => [
				'src'  => 'assets/js/clisyc-manager-calendar.js',
				'deps' => [ 'jquery', 'clisyc-fullcalendar' ],
				'ver'  => $this->version,
			],
			'clisyc-manager-appointment-list'     => [
				'src'  => 'assets/js/clisyc-manager-appointment-list.js',
				'deps' => [ 'jquery', 'underscore' ],
				'ver'  => $this->version,
			],
			'clisyc-staff-dashboard'              => [
				'src'  => 'assets/js/clisyc-staff-dashboard.js',
				'deps' => [ 'jquery' ],
				'ver'  => $this->version,
			],
		];

		foreach ( $script_handles as $handle => $details ) {
			wp_register_script( $handle, clisyc_PLUGIN_URL . $details['src'], $details['deps'], $details['ver'], true );
			wp_script_add_data( $handle, 'defer', true );
		}
	}

	/**
	 * Injects CSS custom properties (variables) into the :root scope.
	 *
	 * This is the best practice approach for WordPress plugins:
	 * - Variables are available globally to all components
	 * - No need for shortcodes to manage this individually
	 * - Easy to override in theme CSS if needed
	 * - Supports both admin-configured colors and smart defaults
	 */
	private function inject_css_variables() {
		// Get merged color settings (saved values merged with defaults)
		$colors = Settings_Manager::get_color_settings();

		// Build the CSS custom properties
		$css_vars = ":root {\n";

		// Slot colors
		$css_vars .= "    --clisyc-available-bg: {$colors['available_bg']};\n";
		$css_vars .= "    --clisyc-available-text: {$colors['available_text']};\n";
		$css_vars .= "    --clisyc-booked-bg: {$colors['booked_bg']};\n";
		$css_vars .= "    --clisyc-booked-text: {$colors['booked_text']};\n";
		$css_vars .= "    --clisyc-blocked-bg: {$colors['blocked_bg']};\n";
		$css_vars .= "    --clisyc-blocked-text: {$colors['blocked_text']};\n";

		// Accent colors
		$css_vars .= "    --clisyc-accent-bg: {$colors['accent_normal_bg']};\n";
		$css_vars .= "    --clisyc-accent-text: {$colors['accent_normal_text']};\n";
		$css_vars .= "    --clisyc-accent-hover-bg: {$colors['accent_hover_bg']};\n";
		$css_vars .= "    --clisyc-accent-hover-text: {$colors['accent_hover_text']};\n";

		// Icon colors
		$css_vars .= "    --clisyc-icon-bg: {$colors['icon_bg']};\n";
		$css_vars .= "    --clisyc-icon-text: {$colors['icon_text']};\n";

		// --- Text Size Scale ---
		// Get text_size setting (small, medium, large, x-large)
		$text_size = $colors['text_size'] ?? 'medium';

		// Define base sizes for each preset (in pixels)
		$size_scales = [
			'small'   => [
				'base' => 13,
				'xs'   => 11,
				'sm'   => 12,
				'md'   => 14,
				'lg'   => 16,
				'xl'   => 18,
				'2xl'  => 22,
			],
			'medium'  => [
				'base' => 14,
				'xs'   => 12,
				'sm'   => 13,
				'md'   => 15,
				'lg'   => 17,
				'xl'   => 20,
				'2xl'  => 24,
			],
			'large'   => [
				'base' => 16,
				'xs'   => 13,
				'sm'   => 14,
				'md'   => 17,
				'lg'   => 19,
				'xl'   => 22,
				'2xl'  => 28,
			],
			'x-large' => [
				'base' => 18,
				'xs'   => 14,
				'sm'   => 16,
				'md'   => 19,
				'lg'   => 22,
				'xl'   => 26,
				'2xl'  => 32,
			],
		];

		$scale = $size_scales[ $text_size ] ?? $size_scales['medium'];

		// Inject font size variables
		$css_vars .= "    /* Text Size: {$text_size} */\n";
		$css_vars .= "    --clisyc-font-size-xs: {$scale['xs']}px;\n";
		$css_vars .= "    --clisyc-font-size-sm: {$scale['sm']}px;\n";
		$css_vars .= "    --clisyc-font-size-base: {$scale['base']}px;\n";
		$css_vars .= "    --clisyc-font-size-md: {$scale['md']}px;\n";
		$css_vars .= "    --clisyc-font-size-lg: {$scale['lg']}px;\n";
		$css_vars .= "    --clisyc-font-size-xl: {$scale['xl']}px;\n";
		$css_vars .= "    --clisyc-font-size-2xl: {$scale['2xl']}px;\n";
		// Legacy variable for backward compatibility
		$css_vars .= "    --clisyc-event-font-size: {$scale['sm']}px;\n";

		// Computed colors for hover states and borders
		$css_vars .= "    --clisyc-available-border: {$colors['available_bg']};\n";
		$css_vars .= "    --clisyc-booked-border: {$colors['booked_bg']};\n";
		$css_vars .= "    --clisyc-blocked-border: {$colors['blocked_bg']};\n";

		// --- Calendar Slot Height ---
		// Get slot height setting (pixels per hour)
		$slot_height_per_hour = get_option( Constants::OPTION_CALENDAR_SLOT_HEIGHT, '80' );
		$slot_height_per_hour = intval( $slot_height_per_hour );
		if ( $slot_height_per_hour < 48 || $slot_height_per_hour > 150 ) {
			$slot_height_per_hour = 80; // Default to 80px/hour
		}
		
		// Get slot duration (Calendar Grid Precision) to calculate per-slot height
		// Format: HH:MM:SS (e.g., '00:15:00' for 15 minutes)
		$slot_duration = get_option( Constants::OPTION_CALENDAR_SLOT_DURATION, '00:30:00' );
		$duration_parts = explode( ':', $slot_duration );
		$duration_minutes = 30; // Default 30 minutes
		if ( count( $duration_parts ) >= 2 ) {
			$duration_minutes = ( intval( $duration_parts[0] ) * 60 ) + intval( $duration_parts[1] );
		}
		if ( $duration_minutes < 5 ) {
			$duration_minutes = 30; // Sanity check
		}
		
		// Calculate height per slot based on duration
		// e.g., 80px/hour with 15-min slots = 80 * (15/60) = 20px per slot
		// e.g., 80px/hour with 30-min slots = 80 * (30/60) = 40px per slot
		$slot_height = ( $slot_height_per_hour * $duration_minutes ) / 60;
		
		$css_vars .= "    /* Calendar Slot Height: {$slot_height_per_hour}px/hour, {$duration_minutes}min slots */\n";
		$css_vars .= "    --clisyc-slot-height-per-hour: {$slot_height_per_hour}px;\n";
		$css_vars .= "    --clisyc-slot-height: {$slot_height}px;\n";
		$css_vars .= "    --clisyc-slot-duration-minutes: {$duration_minutes};\n";

		$css_vars .= "}\n";

		// --- Typography Overrides ---
		// Apply font sizes to plugin elements
		$typography_css = "
/* Client Sync Typography Scale */
.clisyc-container,
.clisyc-form-section,
.clisyc-booking-form-app,
.clisyc-faceted-booking-app,
.clisyc-archive-wrapper,
.clisyc-appointment-detail-card,
.clisyc-appointments-cards,
#clisyc-appointments-cards-dashboard {
    font-size: var(--clisyc-font-size-base);
    line-height: 1.5;
}

/* Form labels */
.clisyc-form-section label,
.clisyc-booking-form-app label,
.clisyc-faceted-booking-app label,
.clisyc-search-form label {
    font-size: var(--clisyc-font-size-base);
    font-weight: 500;
}

/* Form inputs */
.clisyc-form-section input,
.clisyc-form-section select,
.clisyc-form-section textarea,
.clisyc-booking-form-app input,
.clisyc-booking-form-app select,
.clisyc-search-form input,
.clisyc-search-form select {
    font-size: var(--clisyc-font-size-base);
}

/* Buttons */
.clisyc-submit-button,
.clisyc-result-card__actions .button,
.clisyc-detail-actions .button {
    font-size: var(--clisyc-font-size-base);
}

/* Calendar events */
.fc-event,
.fc-event-title,
.fc-event-time {
    font-size: var(--clisyc-font-size-sm);
}

/* Calendar time axis */
.fc .fc-timegrid-slot-label {
    font-size: var(--clisyc-font-size-sm);
}

/* Calendar day headers */
.fc .fc-col-header-cell-cushion {
    font-size: var(--clisyc-font-size-base);
}

/* Headings */
.clisyc-form-section h2,
.clisyc-form-section h3,
.clisyc-booking-form-app h2,
.clisyc-faceted-booking-app h2 {
    font-size: var(--clisyc-font-size-xl);
}

.clisyc-result-card__title {
    font-size: var(--clisyc-font-size-lg);
}

/* Description text */
.clisyc-result-card__description,
.clisyc-form-section .description,
p.description {
    font-size: var(--clisyc-font-size-sm);
}

/* Attribute pills/tags */
.clisyc-result-card__attributes li {
    font-size: var(--clisyc-font-size-sm);
}

/* Appointment cards */
.clisyc-appointment-card__title {
    font-size: var(--clisyc-font-size-lg);
}

.clisyc-appointment-card__details {
    font-size: var(--clisyc-font-size-base);
}

/* Detail page */
.clisyc-appointment-detail-card h2 {
    font-size: var(--clisyc-font-size-2xl);
}

.clisyc-core-info .clisyc-info-text strong {
    font-size: var(--clisyc-font-size-sm);
}

.clisyc-core-info .clisyc-info-text span {
    font-size: var(--clisyc-font-size-lg);
}

.clisyc-detail-section h3 {
    font-size: var(--clisyc-font-size-md);
}

.clisyc-details-list li {
    font-size: var(--clisyc-font-size-base);
}
";

		// --- Page Builder Compatibility Overrides ---
		// These rules have higher specificity to override page builder default button styles.
		// Beaver Builder, Divi, Elementor, etc. often apply aggressive button styling.

		$builder_overrides = "
/* Page Builder Compatibility - Submit Button Overrides */
/* Beaver Builder */
.fl-page button.button.clisyc-submit-button,
.fl-page button.clisyc-submit-button,
.fl-page .clisyc-submit-button,
.fl-responsive-preview-content button.clisyc-submit-button,
.fl-builder-content button.clisyc-submit-button,
/* Divi */
.et-db #et-main-area button.clisyc-submit-button,
.et_pb_section button.clisyc-submit-button,
/* Elementor */
.elementor button.clisyc-submit-button,
.elementor-widget-container button.clisyc-submit-button,
/* Generic high-specificity */
body button.button.clisyc-submit-button,
body .clisyc-submit-button,
button.button.clisyc-submit-button {
    color: var(--clisyc-accent-text, #ffffff);
    background: var(--clisyc-accent-bg, #3b82f6);
    background-image: none;
    border-color: var(--clisyc-accent-bg, #3b82f6);
    border-style: solid;
    border-width: 2px;
}

.fl-page button.button.clisyc-submit-button:hover,
.fl-page button.button.clisyc-submit-button:focus,
.fl-page button.clisyc-submit-button:hover,
.fl-page button.clisyc-submit-button:focus,
.fl-page .clisyc-submit-button:hover,
.fl-page .clisyc-submit-button:focus,
.fl-responsive-preview-content button.clisyc-submit-button:hover,
.fl-builder-content button.clisyc-submit-button:hover,
.et-db #et-main-area button.clisyc-submit-button:hover,
.et_pb_section button.clisyc-submit-button:hover,
.elementor button.clisyc-submit-button:hover,
.elementor-widget-container button.clisyc-submit-button:hover,
body button.button.clisyc-submit-button:hover,
body .clisyc-submit-button:hover,
button.button.clisyc-submit-button:hover {
    color: var(--clisyc-accent-hover-text, #ffffff);
    background: var(--clisyc-accent-hover-bg, #2563eb);
    background-image: none;
    border-color: var(--clisyc-accent-hover-bg, #2563eb);
}

/* Primary buttons in result cards - Page Builder Compatibility */
.fl-page .clisyc-result-card__actions .button,
.fl-page .clisyc-result-card__actions a.button,
.fl-page .clisyc-result-card__actions .button-primary,
.fl-page .clisyc-result-card__actions a.button-primary,
.fl-builder-content .clisyc-result-card__actions .button,
.fl-builder-content .clisyc-result-card__actions a.button,
.et_pb_section .clisyc-result-card__actions .button,
.et_pb_section .clisyc-result-card__actions .button-primary,
.elementor .clisyc-result-card__actions .button,
.elementor .clisyc-result-card__actions .button-primary,
body .clisyc-result-card__actions .button,
body .clisyc-result-card__actions a.button,
body .clisyc-result-card__actions .button-primary,
body .clisyc-result-card__actions a.button-primary {
    color: var(--clisyc-accent-text, #ffffff);
    background: var(--clisyc-accent-bg, #3b82f6);
    border-color: var(--clisyc-accent-bg, #3b82f6);
    font-size: var(--clisyc-font-size-base, 14px);
    font-weight: 600;
}

.fl-page .clisyc-result-card__actions .button:hover,
.fl-page .clisyc-result-card__actions a.button:hover,
.fl-page .clisyc-result-card__actions .button-primary:hover,
.fl-page .clisyc-result-card__actions a.button-primary:hover,
.fl-builder-content .clisyc-result-card__actions .button:hover,
.fl-builder-content .clisyc-result-card__actions a.button:hover,
.et_pb_section .clisyc-result-card__actions .button:hover,
.et_pb_section .clisyc-result-card__actions .button-primary:hover,
.elementor .clisyc-result-card__actions .button:hover,
.elementor .clisyc-result-card__actions .button-primary:hover,
body .clisyc-result-card__actions .button:hover,
body .clisyc-result-card__actions a.button:hover,
body .clisyc-result-card__actions .button-primary:hover,
body .clisyc-result-card__actions a.button-primary:hover {
    color: var(--clisyc-accent-hover-text, #ffffff);
    background: var(--clisyc-accent-hover-bg, #2563eb);
    border-color: var(--clisyc-accent-hover-bg, #2563eb);
}
";

		// Combine variables, typography, and builder overrides
		$full_css = $css_vars . $typography_css . $builder_overrides;

		// Attach to the frontend style handle
		// This ensures variables are available before any component CSS loads
		wp_add_inline_style( 'clisyc-frontend-style', $full_css );

		// Also attach to calendar style for pages that only load calendar
		wp_add_inline_style( 'clisyc-calendar', $css_vars . $typography_css );
	}

	/**
	 * Get color settings for JavaScript components.
	 * Can be used by shortcodes to pass colors to JS if needed.
	 *
	 * @return array Color settings array.
	 */
	public static function get_js_color_settings(): array {
		$colors = Settings_Manager::get_color_settings();

		return [
			'available_bg'      => $colors['available_bg'],
			'available_text'    => $colors['available_text'],
			'booked_bg'         => $colors['booked_bg'],
			'booked_text'       => $colors['booked_text'],
			'blocked_bg'        => $colors['blocked_bg'],
			'blocked_text'      => $colors['blocked_text'],
			'accent_bg'         => $colors['accent_normal_bg'],
			'accent_text'       => $colors['accent_normal_text'],
			'accent_hover_bg'   => $colors['accent_hover_bg'],
			'accent_hover_text' => $colors['accent_hover_text'],
			'icon_bg'           => $colors['icon_bg'],
			'icon_text'         => $colors['icon_text'],
		];
	}

	public function handle_self_service_actions() {
		if ( ! isset( $_GET['clisyc_self_service_action'], $_GET['appointment_id'], $_GET['_wpnonce'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action         = sanitize_key( $_GET['clisyc_self_service_action'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$appointment_id = absint( $_GET['appointment_id'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce = sanitize_key( $_GET['_wpnonce'] );

		$nonce_action_string = "clisyc_{$action}_appt_{$appointment_id}";
		if ( 'reschedule_initiate' === $action ) {
			$nonce_action_string = 'clisyc_reschedule_initiate_appt_' . $appointment_id;
		}

		if ( ! wp_verify_nonce( $nonce, $nonce_action_string ) ) {
			wp_die( esc_html__( 'Security check failed.', 'client-sync' ) );
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'edit_post', $appointment_id ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this appointment.', 'client-sync' ) );
		}

		// Handle leave_waitlist action
		if ( 'leave_waitlist' === $action ) {
			$result = \DependentMedia\ClientSync\Core\Waitlist_Manager::leave_waitlist( $appointment_id );
			$redirect_url = remove_query_arg( [ 'clisyc_self_service_action', 'appointment_id', '_wpnonce' ] );
			if ( $result['success'] ) {
				set_transient( 'clisyc_booking_feedback_success', $result['message'], Constants::TRANSIENT_BOOKING_FEEDBACK_TTL );
			} else {
				set_transient( 'clisyc_booking_feedback_error', $result['message'], Constants::TRANSIENT_BOOKING_FEEDBACK_TTL );
			}
			// Redirect to My Appointments page if we have one, otherwise back to current page.
			$my_appointments_page = get_option( Constants::OPTION_MY_APPOINTMENTS_PAGE, 0 );
			if ( $my_appointments_page ) {
				$redirect_url = get_permalink( $my_appointments_page );
			}
			wp_safe_redirect( $redirect_url );
			exit;
		}

		require_once clisyc_PLUGIN_DIR . 'includes/core/class-cancellation-manager.php';
		$manager = new \DependentMedia\ClientSync\Core\Cancellation_Manager( $appointment_id );
		$result  = [];

		if ( 'cancel' === $action ) {
			$result = $manager->process_cancellation();
		} elseif ( 'reschedule_initiate' === $action ) {
			$result = $manager->process_cancellation( true );
		}

		if ( empty( $result ) ) {
			return;
		}

		$redirect_url = remove_query_arg( [ 'clisyc_self_service_action', 'appointment_id', '_wpnonce' ] );

		if ( 'reschedule_initiate' === $action && $result['success'] ) {
			$booking_page_url = get_permalink( get_option( Constants::OPTION_BOOKING_PAGE_ID, 0 ) ) ?: home_url( '/' );
			set_transient( 'clisyc_booking_feedback_notice', __( 'Your previous appointment has been cancelled. Please select a new time.', 'client-sync' ), Constants::TRANSIENT_BOOKING_FEEDBACK_TTL );
			wp_safe_redirect( $booking_page_url );
			exit;
		}

		if ( $result['success'] ) {
			set_transient( 'clisyc_booking_feedback_success', $result['message'], Constants::TRANSIENT_BOOKING_FEEDBACK_TTL );
		} else {
			set_transient( 'clisyc_booking_feedback_error', $result['message'], Constants::TRANSIENT_BOOKING_FEEDBACK_TTL );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Modifies the main WordPress query.
	 * 1. Contains the crucial fix for page builder compatibility.
	 * 2. Handles availability and attribute filtering for search results.
	 *
	 * @param \WP_Query $query The main WP_Query object.
	 */
	public function modify_archive_query_for_availability_search( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$registry         = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_dim_slug = null;
		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) ) {
				$primary_dim_slug = $slug;
				break;
			}
		}

		if ( ! $primary_dim_slug || ! $query->is_post_type_archive( $primary_dim_slug ) ) {
			return;
		}

		// --- PAGE BUILDER COMPATIBILITY FIX ---
		$cpt_object = get_post_type_object( $primary_dim_slug );
		$page       = $cpt_object ? get_page_by_path( $cpt_object->rewrite['slug'] ) : null;

		if ( $page && $this->is_page_builder_mode() ) {
			$query->set( 'post_type', 'page' );
			$query->set( 'p', $page->ID );
			$query->is_singular = true;
			$query->is_page     = true;
			$query->is_archive  = false;
			return;
		}

		// --- Availability Search Filtering ---
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search_params = wp_unslash( $_GET );

		if ( empty( $search_params['clisyc_start_date'] ) || empty( $search_params['clisyc_end_date'] ) ) {
			return;
		}

		$start_date = sanitize_text_field( $search_params['clisyc_start_date'] );
		$end_date   = sanitize_text_field( $search_params['clisyc_end_date'] );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
			return;
		}

		// Get available property IDs
		$available_ids = $this->get_available_property_ids( $start_date, $end_date );

		if ( empty( $available_ids ) ) {
			$query->set( 'post__in', [ 0 ] );
			return;
		}

		$query->set( 'post__in', $available_ids );

		// Apply attribute filters
		$meta_query       = [];
		$dimension_fields = get_option( Constants::OPTION_DIMENSION_FIELDS, [] );

		foreach ( $dimension_fields as $field_key => $field_config ) {
			$param_key = 'clisyc_' . $field_key;
			if ( ! empty( $search_params[ $param_key ] ) ) {
				$filter_value = sanitize_text_field( $search_params[ $param_key ] );

				if ( 'number' === $field_config['type'] ) {
					$meta_query[] = [
						'key'     => '_clisyc_' . $field_key,
						'value'   => $filter_value,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					];
				} else {
					$meta_query[] = [
						'key'     => '_clisyc_' . $field_key,
						'value'   => $filter_value,
						'compare' => '=',
					];
				}
			}
		}

		if ( ! empty( $meta_query ) ) {
			$meta_query['relation'] = 'AND';
			$query->set( 'meta_query', $meta_query );
		}
	}

	/**
	 * Get property IDs that are available for the given date range.
	 *
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 * @return array Array of available property IDs.
	 */
	private function get_available_property_ids( string $start_date, string $end_date ): array {
		global $wpdb;

		$registry         = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_dim_slug = null;
		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) ) {
				$primary_dim_slug = $slug;
				break;
			}
		}

		if ( ! $primary_dim_slug ) {
			return [];
		}

		// Get all published primary dimension posts
		$all_properties = get_posts(
			[
				'post_type'      => $primary_dim_slug,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			]
		);

		if ( empty( $all_properties ) ) {
			return [];
		}

		// Find properties with conflicting bookings
		$slots_table    = $wpdb->prefix . 'clisyc_time_slots';
		$dims_table     = $wpdb->prefix . 'clisyc_slot_dimensions';
		$bookings_table = $wpdb->prefix . 'clisyc_bookings';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are safely constructed from $wpdb->prefix.
		$booked_property_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT sd.dimension_value
                FROM {$slots_table} s
                INNER JOIN {$dims_table} sd ON s.slot_id = sd.slot_id
                INNER JOIN {$bookings_table} b ON s.slot_id = b.slot_id
                INNER JOIN {$wpdb->posts} p ON b.appointment_id = p.ID
                WHERE sd.dimension_key = %s
                AND s.is_block = 0
                AND p.post_status IN ('publish', 'confirmed', 'clisyc_pending_pay', 'clisyc_paid_on_day', 'wc-processing', 'wc-completed')
                AND (
                    (DATE(s.start_time) <= %s AND DATE(s.end_time) >= %s)
                )",
				$primary_dim_slug,
				$end_date,
				$start_date
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$booked_property_ids = array_map( 'intval', $booked_property_ids );

		// Return properties without bookings in this range
		return array_values( array_diff( $all_properties, $booked_property_ids ) );
	}

	/**
	 * Override the archive template for the primary dimension CPT.
	 *
	 * @param string $template The template path.
	 * @return string Modified template path.
	 */
	public function override_primary_dimension_archive_template( $template ) {
		$registry         = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_dim_slug = null;
		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) ) {
				$primary_dim_slug = $slug;
				break;
			}
		}

		if ( $primary_dim_slug && is_post_type_archive( $primary_dim_slug ) ) {
			$plugin_template = clisyc_PLUGIN_DIR . 'includes/frontend/views/view-archive-primary-dimension.php';
			if ( file_exists( $plugin_template ) ) {
				return $plugin_template;
			}
		}

		return $template;
	}

	/**
	 * Check if the current request is in page builder editing mode.
	 *
	 * @return bool
	 */
	private function is_page_builder_mode() {
		$known_builder_params = [
			'fl_builder',        // Beaver Builder
			'et_fb',             // Divi Builder
			'elementor-preview', // Elementor
			'brizy-edit',        // Brizy
			'brizy-edit-iframe',
			'vc_editable',       // WPBakery
			'vc_action',
			'ct_builder',        // Oxygen
			'builder',           // Generic or Visual Composer
			'tve',               // Thrive Architect
			'bricks',            // Bricks Builder
		];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$unslashed_get = wp_unslash( $_GET );

		foreach ( $known_builder_params as $param ) {
			if ( isset( $unslashed_get[ $param ] ) ) {
				if ( current_user_can( 'edit_pages' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Automatically appends booking tools to single primary dimension CPT content.
	 *
	 * @param string $content The original post content.
	 * @return string The modified post content.
	 */
	public function append_booking_tools_to_dimension_content( $content ) {
		if ( $this->is_page_builder_mode() ) {
			return $content;
		}

		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_type = get_post_type();
		if ( ! $post_type ) {
			return $content;
		}

		$registry         = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_dim_slug = null;
		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) ) {
				$primary_dim_slug = $slug;
				break;
			}
		}

		if ( $post_type !== $primary_dim_slug ) {
			return $content;
		}

		// Don't append if user has manually added a booking shortcode
		if ( has_shortcode( $content, 'clisyc_single_item_booking' ) || has_shortcode( $content, 'clisyc_appointment' ) || has_shortcode( $content, 'clisyc_booking_form' ) ) {
			return $content;
		}

		$append_html = '';
		$item_id     = get_the_ID();

		$all_dimension_fields = get_option( Constants::OPTION_DIMENSION_FIELDS, [] );

		$attributes_html = '';
		if ( ! empty( $all_dimension_fields ) ) {
			foreach ( $all_dimension_fields as $key => $field ) {
				if ( ! in_array( $post_type, $field['applies_to'] ?? [], true ) ) {
					continue;
				}

				$meta_value = get_post_meta( $item_id, '_clisyc_' . $key, true );
				if ( empty( $meta_value ) ) {
					continue;
				}

				$icon_class = ! empty( $field['icon'] ) ? $field['icon'] : 'dashicons-admin-generic';

				$label         = $field['label'];
				$value_display = '';

				if ( 'number' === $field['type'] ) {
					if ( (int) $meta_value !== 1 && substr( $label, -1 ) !== 's' ) {
						$label .= 's';
					}
					$value_display = esc_html( $meta_value . ' ' . $label );
				} else {
					$value_display = esc_html( $meta_value );
				}

				$attributes_html .= '<li><span class="dashicons ' . esc_attr( $icon_class ) . '"></span>' . $value_display . '</li>';
			}
		}

		if ( ! empty( $attributes_html ) ) {
			$append_html .= '<div class="clisyc-single-attributes-wrapper clisyc-form-section">';
			$append_html .= '<h3>' . esc_html__( 'Property Details', 'client-sync' ) . '</h3>';
			$append_html .= '<ul class="clisyc-result-card__attributes">' . $attributes_html . '</ul>';
			$append_html .= '</div>';
		}

		$append_html .= do_shortcode( '[clisyc_booking_form]' );

		return $content . $append_html;
	}

}