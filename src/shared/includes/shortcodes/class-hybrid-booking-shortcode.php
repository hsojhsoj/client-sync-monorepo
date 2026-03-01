<?php
/**
 * File: src/shared/includes/shortcodes/class-hybrid-booking-shortcode.php
 * Handles the [clisyc_hybrid_booking] shortcode.
 *
 * FIXED (2026-01-07): Added generationLookahead to localized data for "No Availability" modal check.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hybrid_Booking_Shortcode {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Instance counter for unique IDs.
	 *
	 * @var int
	 */
	private static $instance_count = 0;

	/**
	 * Constructor.
	 *
	 * @param string $version Plugin version.
	 */
	public function __construct( string $version ) {
		$this->version = $version;
		add_shortcode( 'clisyc_hybrid_booking', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Parse a string to boolean.
	 *
	 * @param mixed $value The value to parse.
	 * @param bool  $default The default value if parsing fails.
	 * @return bool
	 */
	private function parse_bool( $value, $default = true ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( in_array( $value, [ 'true', '1', 'yes', 'on' ], true ) ) {
				return true;
			}
			if ( in_array( $value, [ 'false', '0', 'no', 'off' ], true ) ) {
				return false;
			}
		}
		return $default;
	}

	/**
	 * Render the hybrid booking shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		// Increment instance counter
		self::$instance_count++;
		$instance_id = self::$instance_count;

		// Parse shortcode attributes
		$atts = shortcode_atts(
			[
				'initial_view'  => 'week',
				'show_filters'  => 'true',
				'show_booked'   => 'false',
				'show_blocked'  => 'true',
			],
			$atts,
			'clisyc_hybrid_booking'
		);

		// Parse boolean options
		$display_options = [
			'showFilters' => $this->parse_bool( $atts['show_filters'], true ),
			'showBooked'  => $this->parse_bool( $atts['show_booked'], false ),
			'showBlocked' => $this->parse_bool( $atts['show_blocked'], true ),
		];

		// Debug logging
		Debug_Logger::log( 'Instance #' . $instance_id . ' - Shortcode atts: ' . var_export( $atts, true ), 'HybridBooking' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		Debug_Logger::log( 'Instance #' . $instance_id . ' - Display Options: ' . var_export( $display_options, true ), 'HybridBooking' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export

		// Enqueue assets (only once)
		$this->enqueue_assets();

		// Prepare the base data (shared across all instances)
		$this->localize_base_script_data();

		// Inject CSS variables for dynamic coloring
		$color_settings = get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] );
		$inline_style   = $this->build_inline_styles( $color_settings );

		// Build instance-specific data as JSON for the data attribute
		$instance_data = wp_json_encode( [
			'showFilters' => $display_options['showFilters'],
			'showBooked'  => $display_options['showBooked'],
			'showBlocked' => $display_options['showBlocked'],
		] );

		// Return the root container with instance-specific data attribute
		return sprintf(
			'<div id="clisyc-hybrid-booking-root-%d" class="clisyc-hybrid-booking-root" data-instance="%d" data-display-options="%s" style="%s"></div>',
			$instance_id,
			$instance_id,
			esc_attr( $instance_data ),
			$inline_style
		);
	}

	/**
	 * Build inline CSS styles from color settings and text size.
	 *
	 * @param array $color_settings Color settings array.
	 * @return string Inline style string.
	 */
	private function build_inline_styles( $color_settings ) {
		$styles = [];

		// Color mappings
		$mappings = [
			'available_bg'       => '--clisyc-available-bg',
			'available_text'     => '--clisyc-available-text',
			'booked_bg'          => '--clisyc-unavailable-bg',
			'booked_text'        => '--clisyc-unavailable-text',
			'accent_normal_bg'   => [ '--clisyc-accent-normal-bg', '--clisyc-accent-normal-border' ],
			'accent_normal_text' => '--clisyc-accent-normal-text',
			'accent_hover_bg'    => [ '--clisyc-accent-hover-bg', '--clisyc-accent-hover-border' ],
			'accent_hover_text'  => '--clisyc-accent-hover-text',
			'blocked_bg'         => '--clisyc-blocked-bg',
			'blocked_text'       => '--clisyc-blocked-text',
		];

		foreach ( $mappings as $key => $css_vars ) {
			if ( ! empty( $color_settings[ $key ] ) ) {
				$css_vars = is_array( $css_vars ) ? $css_vars : [ $css_vars ];
				foreach ( $css_vars as $var ) {
					$styles[] = $var . ': ' . esc_attr( $color_settings[ $key ] );
				}
			}
		}

		// Add font-size CSS variables based on Text Size setting
		$font_sizes = $this->get_font_size_scale();
		foreach ( $font_sizes as $var => $value ) {
			$styles[] = $var . ': ' . $value;
		}

		return implode( '; ', $styles );
	}

	/**
	 * Get font size scale based on Text Size setting.
	 *
	 * @return array CSS variable => value pairs.
	 */
	private function get_font_size_scale() {
		// Text size is stored inside clisyc_calendar_color_settings array
		$settings  = get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] );
		$text_size = $settings['text_size'] ?? 'medium';

		// Define font size scales for each setting
		$scales = [
			'small'   => [
				'--clisyc-font-size-xs'   => '10px',
				'--clisyc-font-size-sm'   => '11px',
				'--clisyc-font-size-base' => '12px',
				'--clisyc-font-size-md'   => '13px',
				'--clisyc-font-size-lg'   => '14px',
				'--clisyc-font-size-xl'   => '16px',
				'--clisyc-font-size-2xl'  => '20px',
			],
			'medium'  => [
				'--clisyc-font-size-xs'   => '11px',
				'--clisyc-font-size-sm'   => '13px',
				'--clisyc-font-size-base' => '14px',
				'--clisyc-font-size-md'   => '15px',
				'--clisyc-font-size-lg'   => '16px',
				'--clisyc-font-size-xl'   => '20px',
				'--clisyc-font-size-2xl'  => '24px',
			],
			'large'   => [
				'--clisyc-font-size-xs'   => '12px',
				'--clisyc-font-size-sm'   => '14px',
				'--clisyc-font-size-base' => '16px',
				'--clisyc-font-size-md'   => '17px',
				'--clisyc-font-size-lg'   => '18px',
				'--clisyc-font-size-xl'   => '22px',
				'--clisyc-font-size-2xl'  => '28px',
			],
			'x-large' => [
				'--clisyc-font-size-xs'   => '14px',
				'--clisyc-font-size-sm'   => '16px',
				'--clisyc-font-size-base' => '18px',
				'--clisyc-font-size-md'   => '19px',
				'--clisyc-font-size-lg'   => '20px',
				'--clisyc-font-size-xl'   => '26px',
				'--clisyc-font-size-2xl'  => '32px',
			],
		];

		return $scales[ $text_size ] ?? $scales['medium'];
	}

	/**
	 * Enqueue JavaScript and CSS assets.
	 */
	private function enqueue_assets() {
		// Only enqueue once
		if ( wp_script_is( 'clisyc-hybrid-booking-app', 'enqueued' ) ) {
			return;
		}

		// Ensure frontend styles (with global CSS variables) are loaded
		wp_enqueue_style( 'clisyc-frontend-style' );

		$asset_file_path = clisyc_PLUGIN_DIR . 'assets/dist/hybrid-booking/index.asset.php';
		$asset_file      = file_exists( $asset_file_path )
			? include $asset_file_path
			: [ 'dependencies' => [ 'wp-element', 'wp-api-fetch' ], 'version' => $this->version ];

		$dependencies = $asset_file['dependencies'] ?? [ 'wp-element', 'wp-api-fetch' ];

		wp_enqueue_script(
			'clisyc-hybrid-booking-app',
			clisyc_PLUGIN_URL . 'assets/dist/hybrid-booking/index.js',
			$dependencies,
			$asset_file['version'],
			true
		);

		wp_enqueue_style(
			'clisyc-hybrid-booking-style',
			clisyc_PLUGIN_URL . 'assets/dist/hybrid-booking/style-index.css',
			[ 'clisyc-frontend-style' ],
			$asset_file['version']
		);
		// Entry-level styles (sidebar, modal, calendar, filters) extracted by webpack.
		wp_enqueue_style(
			'clisyc-hybrid-booking-entry-style',
			clisyc_PLUGIN_URL . 'assets/dist/hybrid-booking/style-index.css',
			[ 'clisyc-hybrid-booking-style' ],
			$asset_file['version']
		);
	}

	/**
	 * Localize base script data (shared across all instances).
	 */
	private function localize_base_script_data() {
		// Only localize once
		static $localized = false;
		if ( $localized ) {
			return;
		}
		$localized = true;

		// Get dimension registry
		$registry     = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$filter_order = $registry['filter_order'] ?? [];

		// Build availability dimensions data
		$availability_dimensions = [];
		$dimension_types         = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );

		$enabled_dimensions = array_filter( $registry['dimensions'] ?? [], fn( $dim ) => ! empty( $dim['enabled'] ) );
		foreach ( array_keys( $enabled_dimensions ) as $slug ) {
			if ( isset( $dimension_types[ $slug ] ) ) {
				$availability_dimensions[ $slug ] = [
					'label' => $dimension_types[ $slug ]['plural'] ?? ucfirst( str_replace( '_', ' ', $slug ) ),
					'icon'  => $dimension_types[ $slug ]['icon'] ?? 'dashicons-admin-generic',
				];
			}
		}

		$color_settings = get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] );

		// Smart Start Date
		$next_available_date = null;
		$smart_start_enabled = get_option( Constants::OPTION_CALENDAR_SMART_START, false );

		if ( $smart_start_enabled ) {
			$db_manager      = new \DependentMedia\ClientSync\Core\Database_Manager();
			$available_dates = $db_manager->get_distinct_available_slot_dates();

			if ( ! empty( $available_dates ) ) {
				$next_available_date = $available_dates[0];
			}
		}

		$data_to_localize = [
			'restUrl'                => esc_url_raw( rest_url( 'clisyc/v1/' ) ),
			'restNonce'              => wp_create_nonce( 'wp_rest' ),
			'formNonce'              => wp_create_nonce( Constants::POST_TYPE_APPOINTMENT ),
			'filterOrder'            => $filter_order,
			'availabilityDimensions' => $availability_dimensions,
			'colorSettings'          => $color_settings,
			'startOfWeek'            => ( get_option( Constants::OPTION_CALENDAR_WEEK_STARTS, '-1' ) === '-1' )
				? (int) get_option( 'start_of_week', 0 )
				: (int) get_option( Constants::OPTION_CALENDAR_WEEK_STARTS, 0 ),
			'nextAvailableDate'      => $next_available_date,
			'generationLookahead'    => (int) get_option( Constants::OPTION_AUTO_GEN_LOOKAHEAD, 14 ), // Days ahead for availability check
			'paymentRequired'        => get_option( Constants::OPTION_WC_ENABLED, false ),
			'contactPageUrl'         => ( $contact_page = get_option( Constants::OPTION_CONTACT_PAGE ) ) ? get_permalink( $contact_page ) : null,
			'calendarOptions'        => [
				'slotMinTime' => get_option( Constants::OPTION_CALENDAR_START_TIME, '08:00' ),
				'slotMaxTime' => get_option( Constants::OPTION_CALENDAR_END_TIME, '18:00' ),
			],
			'l10n'                   => [
				'confirmBooking'      => __( 'Confirm Your Booking', 'client-sync' ),
				'scheduleAppointment' => __( 'Schedule Appointment', 'client-sync' ),
				'proceedToCheckout'   => __( 'Proceed to Checkout', 'client-sync' ),
				'cancel'              => __( 'Cancel', 'client-sync' ),
				'selectedSlot'        => __( 'Selected Slot:', 'client-sync' ),
				'none'                => __( 'None', 'client-sync' ),
				'available'           => __( 'Available', 'client-sync' ),
				'unavailable'         => __( 'Unavailable', 'client-sync' ),
				'blocked'             => __( 'Blocked', 'client-sync' ),
				'booked'              => __( 'Booked', 'client-sync' ),
				'resetAllFilters'     => __( 'Reset All Filters', 'client-sync' ),
				'totalSlots'          => __( 'Total Available Slots', 'client-sync' ),
				'slotsInView'         => __( 'slots in current view', 'client-sync' ),
				// Smart Date Modal strings
				'headsUp'             => __( 'Heads Up!', 'client-sync' ),
				'jumpedAheadMessage'  => __( "We've jumped ahead to the first available date for you.", 'client-sync' ),
				'gotIt'               => __( 'Got it', 'client-sync' ),
				// No Availability Modal strings
				'noAvailabilityTitle'   => __( 'No Availability', 'client-sync' ),
				'noAvailabilityMessage' => __( 'There are currently no available appointment times. Please check back later or contact us for assistance.', 'client-sync' ),
				'contactUs'             => __( 'Contact Us', 'client-sync' ),
				'close'                 => __( 'Close', 'client-sync' ),
			],
		];

		// Pre-selection from [clisyc_dimension_grid] CTA links.
		// URL params use "select_" prefix (e.g., ?select_clisyc_service=376).
		if ( ! empty( $filter_order ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$unslashed_get = wp_unslash( $_GET );
			foreach ( $filter_order as $slug ) {
				$param_key = 'select_' . $slug;
				if ( ! empty( $unslashed_get[ $param_key ] ) ) {
					$item_id = absint( $unslashed_get[ $param_key ] );
					if ( $item_id > 0 ) {
						$data_to_localize['preselectedFilters'] = [
							$slug => [ (string) $item_id ],
						];
						break; // Only one pre-selection at a time.
					}
				}
			}
		}

		wp_localize_script(
			'clisyc-hybrid-booking-app',
			'clisycHybridBookingData',
			$data_to_localize
		);
	}
}