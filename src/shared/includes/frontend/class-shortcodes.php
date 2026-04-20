<?php
/**
 * File: /client-sync-monorepo/src/shared/includes/frontend/class-shortcodes.php
 * Handles the registration and rendering of all public-facing shortcodes.
 * MODIFIED: Now enqueues assets on-demand for performance.
 * 
 * FIXED (2026-01-07): Added generationLookahead to calendar data for "No Availability" modal check.
 * UPDATED (2026-01-XX): Added pricing metadata (WC product links, calc types) to JS data layer.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Frontend
 */

namespace DependentMedia\ClientSync\Frontend;

use DependentMedia\ClientSync\Services\FormRenderer;
use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Utility\Debug_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shortcodes {

	private $plugin_name;
	private $version;

	public function __construct( string $plugin_name, string $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function register_all() {
		// ── Booking form shortcodes (remain in this class) ──
		add_shortcode( 'clisyc_booking_form', [ $this, 'render_universal_booking_form' ] );
		add_shortcode( 'clisyc_dimensions_faceted_booking', [ $this, 'render_faceted_booking_form' ] );

		// ── Extracted shortcode classes ──
		// Phase 1: Booking wizard
		( new \DependentMedia\ClientSync\Shortcodes\Booking_Wizard_Shortcode() )->register();

		// Phase 2: Standalone shortcodes
		( new \DependentMedia\ClientSync\Shortcodes\Debug_Appointments_Shortcode() )->register();
		( new \DependentMedia\ClientSync\Shortcodes\Search_Results_Shortcode() )->register();
		( new \DependentMedia\ClientSync\Shortcodes\Appointments_Cards_Shortcode( $this->version ) )->register();
		( new \DependentMedia\ClientSync\Shortcodes\User_Calendar_Shortcode() )->register();
		( new \DependentMedia\ClientSync\Shortcodes\User_Appointment_List_Shortcode() )->register();

		// Phase 3: FormRenderer shortcodes
		( new \DependentMedia\ClientSync\Shortcodes\Registration_Shortcode() )->register();
		( new \DependentMedia\ClientSync\Shortcodes\User_Account_Shortcode() )->register();
		( new \DependentMedia\ClientSync\Shortcodes\Appointment_Detail_Shortcode() )->register();

		// Previously extracted shortcode classes
		( new \DependentMedia\ClientSync\Shortcodes\Booking_Confirmation_Shortcode() )->register();
		( new \DependentMedia\ClientSync\Shortcodes\Conditional_Booking_Shortcodes() )->register();

		// Timeline view — registers via constructor (add_shortcode in __construct)
		new \DependentMedia\ClientSync\Shortcodes\Timeline_Shortcode();

		// Membership Plans pricing cards.
		// NOTE: Always register the shortcode — the handler itself checks for
		// plans at render time. Gating on post_type_exists() caused a load-order
		// bug when Pro registers the CPT after the free plugin's register_all().
		( new \DependentMedia\ClientSync\Shortcodes\Membership_Plans_Shortcode() )->register();

		// Dimension Grid — auto-lists dimension items with booking links.
		( new \DependentMedia\ClientSync\Shortcodes\Dimension_Grid_Shortcode() )->register();
	}

	/**
	 * Renders the new faceted search booking form.
	 *
	 * UPDATED: Added Litepicker dependency for date_range booking mode and
	 * passes registry data to JavaScript for booking mode detection.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output for the React app root.
	 */
	public function render_faceted_booking_form( $atts ) {
		$asset_file_path = clisyc_PLUGIN_DIR . 'assets/dist/faceted-booking/index.asset.php';

		if ( ! file_exists( $asset_file_path ) ) {
			return '<!-- Client Sync Faceted Form: Build assets not found. -->';
		}

		$asset_file   = include $asset_file_path;
		$dependencies = $asset_file['dependencies'] ?? [];

		// Ensure required WordPress dependencies are loaded
		if ( ! in_array( 'wp-api-fetch', $dependencies, true ) ) {
			$dependencies[] = 'wp-api-fetch';
		}
		if ( ! in_array( 'wp-element', $dependencies, true ) ) {
			$dependencies[] = 'wp-element';
		}

		// Enqueue the base frontend style for consistency
		wp_enqueue_style( 'clisyc-frontend-style' );

		// --- NEW: Enqueue Litepicker for date_range booking mode ---
		// Register Litepicker if not already registered
		if ( ! wp_script_is( 'clisyc-litepicker-script', 'registered' ) ) {
			wp_register_script(
				'clisyc-litepicker-script',
				clisyc_PLUGIN_URL . 'assets/vendor/litepicker/litepicker.js',
				[],
				'2.0.12',
				true
			);
		}
		if ( ! wp_style_is( 'clisyc-litepicker', 'registered' ) ) {
			wp_register_style(
				'clisyc-litepicker',
				clisyc_PLUGIN_URL . 'assets/vendor/litepicker/litepicker.css',
				[],
				'2.0.12'
			);
		}

		// Enqueue Litepicker - needed for date_range booking mode
		wp_enqueue_script( 'clisyc-litepicker-script' );
		wp_enqueue_style( 'clisyc-litepicker' );

		// Add Litepicker as dependency for faceted booking app
		$dependencies[] = 'clisyc-litepicker-script';
		// --- END NEW ---

		wp_enqueue_script(
			'clisyc-faceted-booking-app',
			clisyc_PLUGIN_URL . 'assets/dist/faceted-booking/index.js',
			$dependencies,
			$asset_file['version'],
			true
		);
		wp_enqueue_style(
			'clisyc-faceted-booking-app-style',
			clisyc_PLUGIN_URL . 'assets/dist/faceted-booking/style-index.css',
			[ 'clisyc-litepicker' ],
			$asset_file['version']
		);
		// Entry-level styles extracted by webpack.
		wp_enqueue_style(
			'clisyc-faceted-booking-app-entry-style',
			clisyc_PLUGIN_URL . 'assets/dist/faceted-booking/style-index.css',
			[ 'clisyc-faceted-booking-app-style' ],
			$asset_file['version']
		);

		$data_to_localize = $this->get_appointment_calendar_data();
		$data_to_localize['formNonce']     = wp_create_nonce( Constants::POST_TYPE_APPOINTMENT );
		// Distinct action name for the waitlist POST so a nonce captured from
		// a booking form can't be replayed against the waitlist endpoint
		// (defense-in-depth; both endpoints require the same privilege level,
		// so this isn't an escalation vector, just hygiene).
		$data_to_localize['waitlistNonce'] = wp_create_nonce( 'clisyc_join_waitlist' );

		// --- NEW: Add registry data for booking mode detection ---
		$data_to_localize['registry'] = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		// --- END NEW ---

		wp_localize_script(
			'clisyc-faceted-booking-app',
			'clisycFacetedBookingData',
			$data_to_localize
		);

		$color_settings = get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] );
		$inline_style   = '';
		if ( ! empty( $color_settings['available_bg'] ) ) {
			$inline_style .= '--clisyc-available-bg: ' . esc_attr( $color_settings['available_bg'] ) . '; ';
		}
		if ( ! empty( $color_settings['available_text'] ) ) {
			$inline_style .= '--clisyc-available-text: ' . esc_attr( $color_settings['available_text'] ) . '; ';
		}
		if ( ! empty( $color_settings['booked_bg'] ) ) {
			$inline_style .= '--clisyc-unavailable-bg: ' . esc_attr( $color_settings['booked_bg'] ) . '; ';
			$inline_style .= '--clisyc-unavailable-text: ' . esc_attr( $color_settings['booked_text'] ) . '; ';
		}

		return '<div id="clisyc-faceted-booking-react-root" class="clisyc-faceted-booking-app" style="' . esc_attr( $inline_style ) . '"><p>Loading Booking Interface...</p></div>';
	}

	/**
	 * The new "smart" shortcode that decides which booking interface to show.
	 * Now accepts a 'mode' attribute for explicit control.
	 */
	public function render_universal_booking_form( $atts ) {
		// Enqueue the base style here as it's always needed.
		wp_enqueue_style( 'clisyc-frontend-style' );

		$atts = shortcode_atts(
			[
				'mode'                => 'auto', // New attribute: 'auto', 'calendar', or 'search'
				'show_booked_toggle'  => 'false', // Show the "Show booked slots" toggle
				'show_booked_default' => 'false', // Default state of the toggle (if shown)
			],
			$atts,
			'clisyc_booking_form'
		);

		$registry         = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_dim_slug = null;
		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) ) {
				$primary_dim_slug = $slug;
				break;
			}
		}

		// 1. HIGHEST PRIORITY: On a single page of a primary dimension item, always show the single item booking form.
		if ( $primary_dim_slug && is_singular( $primary_dim_slug ) ) {
			return $this->render_single_item_booking( [ 'id' => get_the_ID() ] );
		}

		$display_mode = $atts['mode'];

		// 2. If mode is 'auto', use the global setting.
		if ( 'auto' === $display_mode ) {
			$default_mode = get_option( Constants::OPTION_UNIVERSAL_SHORTCODE_MODE, 'slot' );
			$display_mode = ( 'date_range' === $default_mode ) ? 'search' : 'calendar';
		}

		// 3. Render the explicitly requested or determined mode.
		if ( 'search' === $display_mode ) {
			$results_page_id = (int) get_option( Constants::OPTION_SEARCH_RESULTS_PAGE, 0 );
			$form_action_url = ( $results_page_id > 0 )
				? get_permalink( $results_page_id )
				: get_post_type_archive_link( $primary_dim_slug );

			return $this->render_availability_search( $atts, $form_action_url );
		}

		// 4. Fallback to the standard time-slot appointment form.
		return $this->render_appointment_form( $atts );
	}

	// --- Core Rendering Functions ---

	private function add_drag_conflict_fixer_script() {
		// This inline script is a targeted fix for conflicts with plugins like Beaver Builder
		// that use the HoverIntent library, which aggressively stops mousemove event propagation.
		$script = "jQuery(document).ready(function($) { $(document).off('mousemove.hoverIntent'); });";
		wp_add_inline_script( 'clisyc-frontend-date-range-booking', $script );
	}

	public function render_appointment_form( $atts ) {
		// 1. Parse the new attributes
		$atts = shortcode_atts(
			[
				'show_booked_toggle'  => 'false',
				'show_booked_default' => 'false',
			],
			$atts,
			'clisyc_booking_form'
		);

		Debug_Logger::log( 'PHP: render_appointment_form shortcode initiated.', 'Shortcodes' );
		// --- START: REACT ASSET ENQUEUEING ---
		$asset_file_path = clisyc_PLUGIN_DIR . 'assets/dist/booking-form/index.asset.php';
		Debug_Logger::log( 'PHP: Checking for asset file at path: ' . $asset_file_path, 'Shortcodes' );
		if ( file_exists( $asset_file_path ) ) {
			Debug_Logger::log( 'PHP: SUCCESS! Asset file found. Enqueuing scripts and styles.', 'Shortcodes' );
			// Register the custom dropdown script if it's not already.
			if ( ! wp_script_is( 'clisyc-custom-dropdown', 'registered' ) ) {
				wp_register_script( 'clisyc-custom-dropdown', clisyc_PLUGIN_URL . 'assets/js/clisyc-custom-dropdown.js', [ 'jquery' ], $this->version, true );
			}
			// Enqueue it now.
			wp_enqueue_script( 'clisyc-custom-dropdown' );
			$asset_file = include $asset_file_path;
			// FIX: Ensure wp-element is loaded for the React app
			$dependencies = $asset_file['dependencies'] ?? [];
			if ( ! in_array( 'wp-element', $dependencies, true ) ) {
				$dependencies[] = 'wp-element';
			}
			// FIX: Ensure wp-api-fetch is loaded
			if ( ! in_array( 'wp-api-fetch', $dependencies, true ) ) {
				$dependencies[] = 'wp-api-fetch';
			}

			// Register the shared FullCalendar vendor chunk (webpack code-split).
			$vendor_fc_path = clisyc_PLUGIN_DIR . 'assets/dist/vendor-fullcalendar/index.asset.php';
			if ( file_exists( $vendor_fc_path ) ) {
				$vendor_fc = include $vendor_fc_path;
				wp_register_script(
					'clisyc-vendor-fullcalendar',
					clisyc_PLUGIN_URL . 'assets/dist/vendor-fullcalendar/index.js',
					$vendor_fc['dependencies'] ?? [],
					$vendor_fc['version'] ?? null,
					true
				);
				$dependencies[] = 'clisyc-vendor-fullcalendar';
			}

			wp_enqueue_script(
				'clisyc-frontend-booking-app',
				clisyc_PLUGIN_URL . 'assets/dist/booking-form/index.js',
				$dependencies,
				$asset_file['version'],
				true
			);
			wp_enqueue_style(
				'clisyc-frontend-booking-app-style',
				clisyc_PLUGIN_URL . 'assets/dist/booking-form/style-index.css',
				[],
				$asset_file['version']
			);
			// Entry-level styles (sidebar, modal, calendar, filters) extracted by webpack.
			wp_enqueue_style(
				'clisyc-frontend-booking-app-entry-style',
				clisyc_PLUGIN_URL . 'assets/dist/booking-form/style-index.css',
				[ 'clisyc-frontend-booking-app-style' ],
				$asset_file['version']
			);
			// Enqueue collapsible header styles (must load after booking-app-style)
			wp_enqueue_style( 'clisyc-collapsible-header' );
			$data_to_localize = $this->get_appointment_calendar_data();
			// --- END: THE FIX ---
			Debug_Logger::log( 'PHP: Data being localized for JavaScript: ' . print_r( $data_to_localize, true ), 'Shortcodes' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			wp_localize_script(
				'clisyc-frontend-booking-app',
				'clisycBookingFormData',
				// Add the form nonce and show booked settings to the localized data
				array_merge(
					$data_to_localize,
					[
						'formNonce'         => wp_create_nonce( Constants::POST_TYPE_APPOINTMENT ),
						'waitlistNonce'     => wp_create_nonce( 'clisyc_join_waitlist' ),
						'showBookedToggle'  => filter_var( $atts['show_booked_toggle'], FILTER_VALIDATE_BOOLEAN ),
						'showBookedDefault' => filter_var( $atts['show_booked_default'], FILTER_VALIDATE_BOOLEAN ),
						'isAdmin'           => current_user_can( 'manage_options' ),
						'waitlist_enabled'  => \DependentMedia\ClientSync\Core\Waitlist_Manager::is_enabled(),
						'proRecurringEnabled'    => function_exists( 'clisyc_pro_is_license_active' ) && clisyc_pro_is_license_active(),
						'restRootUrl'            => esc_url_raw( rest_url() ),
						'wc_integration_enabled'     => (bool) get_option( Constants::OPTION_WC_ENABLED, false ),
						'stripe_integration_enabled' => (bool) get_option( Constants::OPTION_STRIPE_ENABLED, false ),
					]
				)
			);
		} else {
			Debug_Logger::log( 'PHP: FATAL! Asset file NOT FOUND. The build process may have failed or the path is incorrect.', 'Shortcodes' );
			return '<!-- Client Sync: Booking form assets not found. Please check your build process and file paths. Expected path: ' . esc_html( $asset_file_path ) . ' -->';
		}
		// --- END: REACT ASSET ENQUEUEING ---
		// CRITICAL FIX: Inject CSS variables for color settings
		$color_settings = get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] );
		$inline_style   = '';
		if ( ! empty( $color_settings['available_bg'] ) ) {
			$inline_style .= '--clisyc-available-bg: ' . esc_attr( $color_settings['available_bg'] ) . '; ';
		}
		if ( ! empty( $color_settings['available_text'] ) ) {
			$inline_style .= '--clisyc-available-text: ' . esc_attr( $color_settings['available_text'] ) . '; ';
		}
		if ( ! empty( $color_settings['booked_bg'] ) ) {
			$inline_style .= '--clisyc-booked-bg: ' . esc_attr( $color_settings['booked_bg'] ) . '; ';
		}
		if ( ! empty( $color_settings['booked_text'] ) ) {
			$inline_style .= '--clisyc-booked-text: ' . esc_attr( $color_settings['booked_text'] ) . '; ';
		}
		if ( ! empty( $color_settings['unavailable_bg'] ) ) {
			$inline_style .= '--clisyc-unavailable-bg: ' . esc_attr( $color_settings['unavailable_bg'] ) . '; ';
		}
		if ( ! empty( $color_settings['unavailable_text'] ) ) {
			$inline_style .= '--clisyc-unavailable-text: ' . esc_attr( $color_settings['unavailable_text'] ) . '; ';
		}
		return '<div id="clisyc-booking-form-react-root" class="clisyc-booking-form-app" style="' . esc_attr( $inline_style ) . '"><p>Loading Booking Form...</p></div>';
	}

	public function render_single_item_booking( $atts ) {
		// --- START: NEW ASSET ENQUEUEING BLOCK ---
		wp_enqueue_style( 'clisyc-frontend-style' );
		wp_enqueue_style( 'clisyc-litepicker' );
		wp_enqueue_style( 'clisyc-image-marker' );
		wp_enqueue_script( 'clisyc-litepicker-script' );
		wp_enqueue_script( 'clisyc-frontend-date-range-booking' );
		wp_enqueue_script( 'clisyc-frontend-image-marker' );
		$this->add_drag_conflict_fixer_script();

		$atts             = shortcode_atts( [ 'id' => 0 ], $atts, 'clisyc_single_item_booking' );
		$item_id          = $atts['id'] ? absint( $atts['id'] ) : get_the_ID();
		$registry         = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_dim_slug = null;
		foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
			if ( ! empty( $settings['primary'] ) ) {
				$primary_dim_slug = $slug;
				break;
			}
		}

		if ( ! $item_id || ! $primary_dim_slug || get_post_type( $item_id ) !== $primary_dim_slug ) {
			// Do nothing here, the checks below will handle returning an error message.
		}

		$data_for_js = [
			'restUrl'                => esc_url_raw( rest_url( 'clisyc/v1/' ) ),
			'restNonce'              => wp_create_nonce( 'wp_rest' ),
			'primaryServiceDimKey'   => $primary_dim_slug,
			'filterOrder'            => [ $primary_dim_slug ],
			'preselected_id'         => $item_id,
			'wc_integration_enabled' => (bool) get_option( Constants::OPTION_WC_ENABLED, false ),
			'stripe_integration_enabled' => \DependentMedia\ClientSync\Integrations\Stripe_Integration::is_active(),
			'l10n'                   => [
				'price'       => __( 'Price', 'client-sync' ),
				'calculating' => __( 'Calculating...', 'client-sync' ),
				'priceError'  => __( 'Could not calculate price.', 'client-sync' ),
			],
		];
		wp_localize_script( 'clisyc-frontend-date-range-booking', 'clisycDateRangeData', $data_for_js );
		// --- END: NEW ASSET ENQUEUEING BLOCK ---

		if ( ! $item_id ) {
			return '<!-- Client Sync: No ID provided or found for single item booking. -->';
		}
		if ( ! $primary_dim_slug || get_post_type( $item_id ) !== $primary_dim_slug ) {
			return '<!-- Client Sync: The specified ID does not belong to a primary dimension. -->';
		}

		ob_start();
		$form_renderer             = new FormRenderer();
		$appointment_custom_fields = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
		$appointment_field_order   = get_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, [] );

		$view_file = clisyc_PLUGIN_DIR . 'includes/frontend/views/view-single-item-booking.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		}

		return ob_get_clean();
	}

	public function render_availability_search( $atts, $form_action_url = null ) {
		// --- START: NEW ASSET ENQUEUEING BLOCK ---
		wp_enqueue_style( 'clisyc-frontend-style' );
		wp_enqueue_style( 'clisyc-litepicker' );
		wp_enqueue_script( 'clisyc-litepicker-script' );
		// --- END: NEW ASSET ENQUEUEING BLOCK ---

		ob_start();

		// If the action URL wasn't passed in, calculate the fallback (for backward compatibility).
		if ( ! $form_action_url ) {
			$registry         = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
			$primary_dim_slug = null;
			foreach ( $registry['dimensions'] ?? [] as $slug => $settings ) {
				if ( ! empty( $settings['primary'] ) ) {
					$primary_dim_slug = $slug;
					break;
				}
			}
			$results_page_id = (int) get_option( Constants::OPTION_SEARCH_RESULTS_PAGE, 0 );
			$form_action_url = ( $results_page_id > 0 ) ? get_permalink( $results_page_id ) : get_post_type_archive_link( $primary_dim_slug );
		}

		if ( ! $form_action_url ) {
			return '<!-- Client Sync: Could not determine the results page URL. -->';
		}

		// Get currently active filters from the URL to re-populate the form.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This reads URL filter parameters for display purposes, not a form submission.
		$unslashed_get     = wp_unslash( $_GET );
		$checkin           = isset( $unslashed_get['checkin'] ) ? sanitize_text_field( $unslashed_get['checkin'] ) : '';
		$checkout          = isset( $unslashed_get['checkout'] ) ? sanitize_text_field( $unslashed_get['checkout'] ) : '';
		$attribute_filters = isset( $unslashed_get['clisyc_attr'] ) && is_array( $unslashed_get['clisyc_attr'] ) ? array_map( 'sanitize_text_field', $unslashed_get['clisyc_attr'] ) : [];

		$all_dimension_fields = get_option( Constants::OPTION_DIMENSION_FIELDS, [] );
		$filterable_fields    = array_filter( $all_dimension_fields, fn( $field ) => ! empty( $field['filterable'] ) );

		// We no longer process results here. The view file is now just a form.
		include clisyc_PLUGIN_DIR . 'includes/frontend/views/view-availability-search.php';

		return ob_get_clean();
	}

	private function _is_payment_required(): bool {
		return class_exists( 'WooCommerce' ) &&
			   get_option( Constants::OPTION_WC_ENABLED, false ) &&
			   absint( get_option( Constants::OPTION_WC_PRODUCT_ID, 0 ) ) > 0;
	}

	private function _get_repopulated_post_data(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This retrieves repopulation data from URL, not a form submission.
		if ( isset( $_GET['posted_data'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This retrieves repopulation data from URL, not a form submission.
			$posted_data_json = sanitize_text_field( wp_unslash( $_GET['posted_data'] ) );
			$decoded_data     = json_decode( urldecode( $posted_data_json ), true );
			return ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded_data ) ) ? $decoded_data : [];
		}

		if ( ! is_user_logged_in() && isset( $_COOKIE['clisyc_pending_appt_token'] ) ) {
			$token         = sanitize_key( $_COOKIE['clisyc_pending_appt_token'] );
			$transient_key = 'clisyc_pending_appt_' . $token;
			$pending_data  = get_transient( $transient_key );

			if ( is_array( $pending_data ) ) {
				$sanitized_data               = [];
				$sanitized_data['service_id'] = isset( $pending_data['service_id'] ) ? absint( $pending_data['service_id'] ) : 0;
				$sanitized_data['time_slot']  = isset( $pending_data['time_slot'] ) ? sanitize_text_field( $pending_data['time_slot'] ) : '';
				if ( isset( $pending_data['clisyc_custom_field'] ) && is_array( $pending_data['clisyc_custom_field'] ) ) {
					$sanitized_data['clisyc_custom_field'] = array_map( 'sanitize_text_field', $pending_data['clisyc_custom_field'] );
				}
				return $sanitized_data;
			}
		}

		return [];
	}

	public function get_appointment_calendar_data(): array {
		global $wpdb; // Add this to get access to the database object

		// --- START: THE FIX - Direct Database Query for Options ---
		$options_to_fetch = [
			'clisyc_calendar_start_time',
			'clisyc_calendar_end_time',
			'clisyc_calendar_week_starts_on',
			'clisyc_calendar_smart_start_date',
		];
		$placeholders = implode( ', ', array_fill( 0, count( $options_to_fetch ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders contains the %s placeholders dynamically generated.
		$query = $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ($placeholders)", $options_to_fetch );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above, caching not needed for one-time config fetch.
		$options_from_db_raw = $wpdb->get_results( $query, OBJECT_K );

		$options = [];
		foreach ( $options_to_fetch as $opt_name ) {
			$options[ $opt_name ] = $options_from_db_raw[ $opt_name ]->option_value ?? null;
		}

		$start_of_week_setting = $options['clisyc_calendar_week_starts_on'] ?? '-1';
		$start_of_week         = ( '-1' === $start_of_week_setting ) ? (int) get_option( 'start_of_week', 0 ) : (int) $start_of_week_setting;
		// --- END: THE FIX ---

		$registry           = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$custom_types       = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
		$enabled_dimensions = array_filter( $registry['dimensions'] ?? [], fn( $dim ) => ! empty( $dim['enabled'] ) );

		$availability_dimensions = [];
		foreach ( $enabled_dimensions as $slug => $settings ) {
			if ( ! empty( $settings['frontend_visible'] ) ) {
				// --- START: Distributed Pricing Injection ---
				$options_query = get_posts( [
					'post_type'      => $slug,
					'posts_per_page' => 200,
					'no_found_rows'  => true,
					'post_status'    => 'publish',
				] );

				$options_meta = [];
				foreach ( $options_query as $opt ) {
					$product_id      = get_post_meta( $opt->ID, '_clisyc_wc_product_id', true );
					$calc_type       = get_post_meta( $opt->ID, '_clisyc_addon_calc_type', true ) ?: 'per_night';
					$price           = 0;
					$formatted_price = '';

					if ( $product_id && function_exists( 'wc_get_product' ) ) {
						$product = wc_get_product( $product_id );
						if ( $product ) {
							$price           = (float) $product->get_price();
							$formatted_price = wp_strip_all_tags( wc_price( $price ) );
						}
					}

					// Get mandatory fees if this is a primary item
					$mandatory_fees = get_post_meta( $opt->ID, '_clisyc_additional_products', true ) ?: [];

					$options_meta[ $opt->ID ] = [
						'price'           => $price,
						'formatted_price' => $formatted_price,
						'calc_type'       => $calc_type,
						'has_fees'        => ! empty( $mandatory_fees ),
						'mandatory_fees'  => $mandatory_fees,
					];
				}
				// --- END: Distributed Pricing Injection ---

				$availability_dimensions[ $slug ] = [
					'label'        => $custom_types[ $slug ]['singular'] ?? ucfirst( str_replace( 'clisyc_', '', $slug ) ),
					'icon'         => $custom_types[ $slug ]['icon'] ?? 'dashicons-admin-generic', // ADD THIS LINE
					'options_meta' => $options_meta, // <--- NEW: Pass metadata to JS
				];
			}
		}

		$posted_data = get_transient( 'clisyc_booking_posted_data' ) ?: [];
		if ( $posted_data ) {
			delete_transient( 'clisyc_booking_posted_data' );
		}

		$wc_enabled       = (bool) get_option( Constants::OPTION_WC_ENABLED, false );
		$wc_is_active     = class_exists( 'WooCommerce' ) && $wc_enabled && absint( get_option( Constants::OPTION_WC_PRODUCT_ID, 0 ) ) > 0;
		$stripe_is_active = \DependentMedia\ClientSync\Integrations\Stripe_Integration::is_active();

		$data = [
			'restUrl'                  => esc_url_raw( rest_url( 'clisyc/v1/slots' ) ),
			'restNonce'                => wp_create_nonce( 'wp_rest' ),
			'availabilityDimensions'   => $availability_dimensions,
			'filterOrder'              => $registry['filter_order'] ?? array_keys( $availability_dimensions ),
			'isGuest'                  => ! is_user_logged_in(),
			'spinnerUrl'               => esc_url( admin_url( 'images/spinner-2x.gif' ) ),
			'timezoneMapUrl'           => esc_url( clisyc_PLUGIN_URL . 'assets/images/timezone-map.png' ),
			'pluginUrl'                => esc_url( clisyc_PLUGIN_URL ),
			'postedData'               => $posted_data,
			// START: NEW LOGIC
			'primaryItemName'          => null, // Default to null
			// END: NEW LOGIC
			'paymentRequired'          => $wc_is_active || $stripe_is_active,
			'currencySymbol'           => $wc_is_active ? get_woocommerce_currency_symbol() : '$',
			'wc_integration_enabled'   => $wc_enabled,
			'stripe_integration_enabled' => \DependentMedia\ClientSync\Integrations\Stripe_Integration::is_active(),
			'hideBooked'               => false,
			'customFieldDefinitions'   => get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] ),
			'successPageUrl'           => ( $success_page = get_option( Constants::OPTION_BOOKING_SUCCESS_PAGE ) ) ? get_permalink( $success_page ) : null,
			'contactPageUrl'           => ( $contact_page = get_option( Constants::OPTION_CONTACT_PAGE ) ) ? get_permalink( $contact_page ) : null,
			'primaryServiceDimKey'     => get_option( Constants::OPTION_PRIMARY_SERVICE_DIM, 'clisyc_service' ),
			'nextAvailableDate'        => $this->get_smart_start_date( $options ), // Pass the options we fetched
			'smartStartDateEnabled'    => rest_sanitize_boolean( $options['clisyc_calendar_smart_start_date'] ?? false ), // NEW: Flag for React to check
			'generationLookahead'      => (int) get_option( Constants::OPTION_AUTO_GEN_LOOKAHEAD, 14 ), // Days ahead for availability check
			'calendarOptions'          => [
				'timeZone'                   => wp_timezone_string(),
				'showOverviewAvailability'   => get_option( 'clisyc_calendar_show_overview_availability', 'none' ),
				'initialView'                => get_option( Constants::OPTION_FRONTEND_CALENDAR_STYLE, [ 'initial_view' => 'timeGridWeek' ] )['initial_view'],
				'slotMinTime'                => $options['clisyc_calendar_start_time'] ?? '08:00',
				'slotMaxTime'                => $options['clisyc_calendar_end_time'] ?? '18:00',
				'enabledViews'               => get_option( Constants::OPTION_CALENDAR_ENABLED_VIEWS, [ 'dayGridMonth', 'dayGridWeek', 'dayGridDay', 'timeGridWeek', 'listWeek' ] ),
				'startOfWeek'                => $start_of_week,
				// NEW: Slot height settings for week/day view event sizing
				'slotHeightPerHour'          => (int) get_option( Constants::OPTION_CALENDAR_SLOT_HEIGHT, 80 ),
				'slotDuration'               => get_option( Constants::OPTION_CALENDAR_SLOT_DURATION, '00:15:00' ),
			],
			'colorSettings'            => get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] ),
			'l10n'                     => [
				'none'                    => esc_html__( 'None', 'client-sync' ),
				'selectAvailability'      => esc_html__( 'Please make your selections to view the calendar.', 'client-sync' ),
				'selectedSlot'            => esc_html__( 'Selected Slot:', 'client-sync' ),
				'checkSeriesAvailability' => esc_html__( 'Check Series Availability', 'client-sync' ),
				'validating'              => esc_html__( 'Validating...', 'client-sync' ),

				// NEW: Calendar Legend strings
				'legendTitle'             => esc_html__( 'Legend:', 'client-sync' ),
				'legendAvailable'         => esc_html__( 'Available', 'client-sync' ),
				'legendBooked'            => esc_html__( 'Booked', 'client-sync' ),
				'legendBlocked'           => esc_html__( 'Blocked', 'client-sync' ),
				'legendToday'             => esc_html__( 'Today', 'client-sync' ),
				'legendCurrentTime'       => esc_html__( 'Current Time', 'client-sync' ),

				// NEW: Admin Notice strings
				'noticeOutOfRangeTitle'       => esc_html__( 'Slots Outside Calendar Hours', 'client-sync' ),
				'noticeOutOfRangeMessage'     => __( 'All available slots fall outside your visible calendar hours. Visitors won\'t see these slots. Adjust your calendar hours in Settings → Calendars, or regenerate schedules within business hours.', 'client-sync' ),
				'noticeSomeOutOfRangeTitle'   => esc_html__( 'Some Slots Outside Calendar Hours', 'client-sync' ),
				'noticeSomeOutOfRangeMessage' => __( 'Some available slots fall outside your visible calendar hours and won\'t be visible to visitors.', 'client-sync' ),
				'dismissNotice'               => esc_html__( 'Dismiss notice', 'client-sync' ),

				// NEW: No Availability Modal strings
				'noAvailabilityTitle'         => esc_html__( 'No Availability', 'client-sync' ),
				'noAvailabilityMessage'       => esc_html__( 'There are currently no available appointment times. Please check back later or contact us for assistance.', 'client-sync' ),
				'contactUs'                   => esc_html__( 'Contact Us', 'client-sync' ),
				'close'                       => esc_html__( 'Close', 'client-sync' ),

				// NEW: Smart Date Modal strings
				'headsUp'                     => esc_html__( 'Heads Up!', 'client-sync' ),
				'jumpedAheadMessage'          => __( "We've jumped ahead to the first available date for you.", 'client-sync' ),
				'gotIt'                       => esc_html__( 'Got it', 'client-sync' ),
			],
		];

		// START: NEW LOGIC - Check for pre-selected item in URL to pass its name + ID to JS
		// URL params use "select_" prefix to avoid conflict with CPT query vars.
		// e.g., ?select_clisyc_service=376 → preselects service ID 376
		if ( ! empty( $registry['filter_order'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$unslashed_get = wp_unslash( $_GET );
			foreach ( $registry['filter_order'] as $slug ) {
				$param_key = 'select_' . $slug;
				if ( ! empty( $unslashed_get[ $param_key ] ) ) {
					$item_id = absint( $unslashed_get[ $param_key ] );
					if ( $item_id > 0 ) {
						$data['primaryItemName']      = get_the_title( $item_id );
						$data['preselectedServiceId'] = $item_id;
						break; // Found the first pre-selected item, stop looking.
					}
				}
			}
		}
		// END: NEW LOGIC

		if ( is_user_logged_in() ) {
			$data['userCredits'] = get_user_meta( get_current_user_id(), '_clisyc_credits', true ) ?: [];
		}

		// No longer need the debug logs here as the direct query is more reliable
		return $data;
	}

	/**
	 * Gets the smart start date, calculating it on-the-fly if the cached version is missing.
	 *
	 * @param array $options Pre-fetched options to avoid redundant DB calls.
	 * @return string|null The 'Y-m-d' date string or null.
	 */
	private function get_smart_start_date( $options ) {
		// If the feature is disabled, return null.
		// Use rest_sanitize_boolean to properly convert the stored value to a boolean
		if ( ! rest_sanitize_boolean( $options['clisyc_calendar_smart_start_date'] ?? false ) ) {
			return null;
		}

		// First, try to get the cached value set by the cron job.
		$cached_date = get_option( Constants::OPTION_CALENDAR_SMART_START_NA, null );
		if ( $cached_date ) {
			return $cached_date;
		}

		// If the cache is empty, calculate it now. This is a fallback for reliability.
		$db_manager        = new \DependentMedia\ClientSync\Core\Database_Manager();
		$future_slot_dates = $db_manager->get_distinct_available_slot_dates();

		if ( ! empty( $future_slot_dates ) ) {
			// Cache the result for the next page load.
			update_option( Constants::OPTION_CALENDAR_SMART_START_NA, $future_slot_dates[0] );
			return $future_slot_dates[0];
		}

		return null;
	}

}