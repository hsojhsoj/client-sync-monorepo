<?php
/**
 * File: src/shared/includes/admin/class-asset-manager.php -> client-sync/includes/admin/class-asset-manager.php
 * Manages the enqueueing and localization of all admin scripts and styles.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Asset_Manager {

	private $plugin_name;
	private $version;
	private $db_manager;

	public function __construct( string $plugin_name, string $version, Database_Manager $db_manager ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->db_manager  = $db_manager;
	}

	public function register_hooks() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * Get a cache-busting version string for a plugin asset file.
	 *
	 * Uses the file's last-modified timestamp so browsers automatically
	 * fetch updated files after deployments without manual version bumps.
	 *
	 * @param string $relative_path Path relative to the plugin root (e.g. 'assets/js/foo.js').
	 * @return string Version string (filemtime or fallback to plugin version).
	 */
	private function asset_version( string $relative_path ): string {
		$file = defined( 'clisyc_PLUGIN_DIR' ) ? clisyc_PLUGIN_DIR . $relative_path : '';
		if ( $file && file_exists( $file ) ) {
			return (string) filemtime( $file );
		}
		return $this->version;
	}

	public function enqueue_scripts( $hook_suffix ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// --- Page & Context Definitions ---
		$main_page_slug          = 'client-sync';
		$dimensions_page_hook    = $main_page_slug . '_page_clisyc-dimensions';
		$custom_fields_page_hook = $main_page_slug . '_page_clisyc-custom-fields';
		$settings_page_hook      = $main_page_slug . '_page_clisyc-settings';
		$calendars_page_hook     = 'toplevel_page_client-sync' === $screen->base ? $main_page_slug . '_page_clisyc-calendars' : $screen->id;

		$post_type          = $screen->post_type ?? '';
		$registry           = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$enabled_dimensions = $registry['dimensions'] ?? [];
		$is_dimension_edit_page = ( $screen->base === 'post' && $post_type && isset( $enabled_dimensions[ $post_type ] ) && $enabled_dimensions[ $post_type ]['enabled'] );
		$is_clisyc_page = strpos( (string) $hook_suffix, 'client-sync' ) !== false || strpos( (string) $hook_suffix, 'clisyc-' ) !== false || strpos( $post_type, 'clisyc_' ) === 0 || $post_type === Constants::POST_TYPE_APPOINTMENT || $is_dimension_edit_page;

		// --- General Assets for ALL Plugin Pages ---
		if ( $is_clisyc_page ) {
			wp_enqueue_script( 'jquery-ui-dialog' );
			wp_enqueue_script( 'jquery-ui-sortable' );
			wp_enqueue_script( 'underscore' );
			wp_enqueue_style( 'wp-jquery-ui-dialog' );
			wp_enqueue_style( 'clisyc-admin-style', clisyc_PLUGIN_URL . 'assets/css/clisyc-admin-style.css', [ 'wp-jquery-ui-dialog' ], $this->asset_version( 'assets/css/clisyc-admin-style.css' ) );
			wp_enqueue_style( 'clisyc-fullscreen-layout', clisyc_PLUGIN_URL . 'assets/css/clisyc-fullscreen-layout.css', [ 'clisyc-admin-style' ], $this->asset_version( 'assets/css/clisyc-fullscreen-layout.css' ) );
			wp_enqueue_script( 'clisyc-fullscreen-layout', clisyc_PLUGIN_URL . 'assets/js/clisyc-fullscreen-layout.js', [ 'jquery' ], $this->asset_version( 'assets/js/clisyc-fullscreen-layout.js' ), true );
		}

		// --- FontAwesome & Icon Picker: only on pages that use the icon chooser ---
		$needs_icon_picker = ( $hook_suffix === $dimensions_page_hook || $is_dimension_edit_page );
		if ( $needs_icon_picker ) {
			wp_enqueue_style( 'clisyc-fontawesome', clisyc_PLUGIN_URL . 'assets/vendor/fontawesome/css/all.min.css', [], '6.5.1' );
			wp_enqueue_script( 'clisyc-admin-global-icon-picker', clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-global-icon-picker.js', [ 'jquery', 'jquery-ui-dialog', 'underscore' ], $this->asset_version( 'assets/js/clisyc-admin-global-icon-picker.js' ), true );
			wp_localize_script( 'clisyc-admin-global-icon-picker', 'clisycIconPickerData', [ 'icons' => $this->get_all_icon_choices() ] );
		}

		// --- Tooltips (where needed) ---
		$needs_tooltips = in_array( $hook_suffix, [ $dimensions_page_hook, $custom_fields_page_hook, $calendars_page_hook ], true ) || $is_dimension_edit_page;
		if ( $needs_tooltips ) {
			wp_enqueue_script( 'jquery-ui-tooltip' );
			wp_add_inline_script(
				'jquery-ui-tooltip',
				"jQuery(document).ready(function($){ $(document).tooltip({ items: '.clisyc-help-tip', content: function() { return $(this).attr('title').replace(/\\n/g, '<br>'); }, position: { my: 'center bottom-10', at: 'center top' } }); });"
			);
		}

		// --- Color Picker for Style & Behavior Settings ---
		if ( $hook_suffix === $settings_page_hook ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' ); // The main library script

			// Enqueue the shortcodes page script
			wp_enqueue_script( 'clisyc-admin-shortcodes-page', clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-shortcodes-page.js', [ 'jquery' ], $this->asset_version( 'assets/js/clisyc-admin-shortcodes-page.js' ), true );

			// Enqueue the settings page specific script
			wp_enqueue_script( 'clisyc-admin-styles-page', clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-styles-page.js', [ 'jquery', 'wp-color-picker' ], $this->asset_version( 'assets/js/clisyc-admin-styles-page.js' ), true );
			
			// Localize data for the settings page, including the required nonce.
			wp_localize_script(
				'clisyc-admin-styles-page',
				'clisycStylesPageData',
				[
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'clisyc_settings_nonce' ), // Standardized nonce for this page
					'l10n'     => [
						'saveSuccess' => __( 'Settings saved successfully.', 'client-sync' ),
						'saveError'   => __( 'An error occurred while saving.', 'client-sync' ),
					],
				]
			);

			// --- START: THE DEFINITIVE FIX V3 ---
			$color_picker_fix_script = "
				jQuery(document).ready(function($){
					// Initialize each picker individually.
					$('.clisyc-color-picker').each(function() {
						$(this).wpColorPicker();
					});

					// When a text input gains focus, the color picker tries to open.
					// We immediately force it closed so it only opens via the swatch button.
					$('input.clisyc-color-picker').on('focus', function(e) {
						var inputEl = $(this);
						setTimeout(function() {
							inputEl.iris('hide');
						}, 5);
					});
				});
			";
			wp_add_inline_script( 'wp-color-picker', $color_picker_fix_script );
			// --- END: THE DEFINITIVE FIX V3 ---

			// --- Create Page button handler for Frontend Links & Pages ---
			$create_page_script = "
				jQuery(document).ready(function($) {
					$('.clisyc-create-page-btn').on('click', function(e) {
						e.preventDefault();
						var btn       = $(this);
						var optionKey = btn.data('option-key');
						var selectEl  = $('#' + btn.data('dropdown-id'));

						btn.prop('disabled', true).text('" . esc_js( __( 'Creating…', 'client-sync' ) ) . "');

						$.post(clisycStylesPageData.ajax_url, {
							action:     'clisyc_create_page_for_setting',
							security:   clisycStylesPageData.nonce,
							option_key: optionKey
						}, function(response) {
							if (response.success) {
								var newId    = response.data.page_id;
								var newTitle = response.data.page_title;

								// Add the new option to the select if it doesn't exist, then select it.
								if (selectEl.find('option[value=\"' + newId + '\"]').length === 0) {
									selectEl.append($('<option>', { value: newId, text: newTitle }));
								}
								selectEl.val(newId);

								btn.replaceWith(
									'<span class=\"clisyc-page-created-notice\" style=\"color:#00a32a;font-style:italic;\">' +
									'<span class=\"dashicons dashicons-yes\" style=\"vertical-align:middle;\"></span> ' +
									'" . esc_js( __( 'Created', 'client-sync' ) ) . "' +
									'</span>'
								);
							} else {
								btn.prop('disabled', false)
									.html('<span class=\"dashicons dashicons-plus-alt2\" style=\"vertical-align:middle;margin-top:-2px;\"></span> " . esc_js( __( 'Create Page', 'client-sync' ) ) . "');
								alert(response.data && response.data.message ? response.data.message : '" . esc_js( __( 'An error occurred.', 'client-sync' ) ) . "');
							}
						}).fail(function() {
							btn.prop('disabled', false)
								.html('<span class=\"dashicons dashicons-plus-alt2\" style=\"vertical-align:middle;margin-top:-2px;\"></span> " . esc_js( __( 'Create Page', 'client-sync' ) ) . "');
							alert('" . esc_js( __( 'An error occurred. Please try again.', 'client-sync' ) ) . "');
						});
					});

					// Show/hide the Create button when the dropdown value changes.
					$('select[id^=\"clisyc_\"]').on('change', function() {
						var btn = $(this).closest('.clisyc-page-dropdown-wrap').find('.clisyc-create-page-btn');
						if (btn.length) {
							btn.toggle($(this).val() === '0');
						}
					});
				});
			";
			wp_add_inline_script( 'clisyc-admin-styles-page', $create_page_script );
		}

		// --- Appointment Edit Screen Assets (Slot Selector & Mode Toggle) ---
		if ( Constants::POST_TYPE_APPOINTMENT === $post_type && in_array( $screen->base, [ 'post', 'post-new' ], true ) ) {
			
			// 1. Enqueue Dependencies
			wp_enqueue_script( 'jquery-ui-datepicker' );
			// Use local jQuery UI CSS
			wp_enqueue_style( 'clisyc-jquery-ui', clisyc_PLUGIN_URL . 'assets/vendor/jquery-ui/jquery-ui.css', [], '1.13.2' );
			
			// 2. Enqueue the Slot Selector Script
			wp_enqueue_script(
				'clisyc-admin-appointment-slot-selector',
				clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-appointment-slot-selector.js',
				[ 'jquery', 'jquery-ui-datepicker' ],
				$this->asset_version( 'assets/js/clisyc-admin-appointment-slot-selector.js' ),
				true
			);

			// 3. Enqueue the Mode Toggle Script (shared logic)
			wp_enqueue_script(
				'clisyc-admin-booking-mode-toggle',
				clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-booking-mode-toggle.js',
				[ 'jquery', 'wp-data' ],
				$this->asset_version( 'assets/js/clisyc-admin-booking-mode-toggle.js' ),
				true
			);

			// 4. Localize Data (Pass PHP data to JS)
			global $post;
			$current_post_id = $post->ID ?? 0;
			
			// Get dimension configuration for slot display and auto-title
			$registry           = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
			$custom_types       = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
			$enabled_dimensions = $registry['dimensions'] ?? [];
			$filter_order       = $registry['filter_order'] ?? [];
			
			// Find primary dimension
			$primary_slug = '';
			foreach ( $enabled_dimensions as $slug => $settings ) {
				if ( ! empty( $settings['primary'] ) ) {
					$primary_slug = $slug;
					break;
				}
			}
			
			// Build dimension type labels
			$dimension_type_labels = [];
			foreach ( $enabled_dimensions as $slug => $settings ) {
				if ( ! empty( $settings['enabled'] ) ) {
					$dimension_type_labels[ $slug ] = $custom_types[ $slug ]['singular'] ?? ucfirst( str_replace( [ 'clisyc_', '_' ], [ '', ' ' ], $slug ) );
				}
			}
			
			// Pre-fetch dimension titles for URL-passed IDs (for initial title generation)
			$dimension_labels = [];
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL parameters for UI state, not processing sensitive data.
			$unslashed_get = wp_unslash( $_GET );
			$dim_ids_to_fetch = [];
			
			foreach ( $unslashed_get as $key => $value ) {
				if ( strpos( $key, 'clisyc_dim_' ) === 0 ) {
					$dim_id = absint( $value );
					if ( $dim_id > 0 ) {
						$dim_ids_to_fetch[] = $dim_id;
					}
				}
			}
			
			if ( ! empty( $dim_ids_to_fetch ) ) {
				$dim_posts = get_posts( [
					'post__in'       => $dim_ids_to_fetch,
					'post_type'      => 'any',
					'posts_per_page' => -1,
					'post_status'    => 'publish',
				] );
				foreach ( $dim_posts as $dim_post ) {
					$dimension_labels[ $dim_post->ID ] = $dim_post->post_title;
					// Also add with slug prefix for easier lookup
					$dimension_labels[ $dim_post->post_type . '_' . $dim_post->ID ] = $dim_post->post_title;
				}
			}
			
			wp_localize_script(
				'clisyc-admin-appointment-slot-selector',
				'clisycSlotSelectorData',
				[
					'ajax_url'            => admin_url( 'admin-ajax.php' ),
					'nonce'               => wp_create_nonce( 'clisyc_get_available_slots_nonce' ),
					'current_post_id'     => $current_post_id,
					'availableDateRange'  => $this->get_available_date_range_for_selector(),
					'time_format'         => get_option( 'time_format', 'g:i a' ),
					'text_loading_slots'  => __( 'Loading available slots...', 'client-sync' ),
					'text_none'           => __( 'None', 'client-sync' ),
					'text_no_slots'       => __( 'No available slots found.', 'client-sync' ),
					'text_error'          => __( 'Error loading slots.', 'client-sync' ),
					'text_select_date'    => __( 'Please select a date above.', 'client-sync' ),
					// NEW: Dimension configuration for slot display and auto-title
					'primaryDimension'    => $primary_slug,
					'dimensionOrder'      => $filter_order,
					'dimensionTypeLabels' => $dimension_type_labels,
					'dimensionLabels'     => $dimension_labels,
				]
			);

			// --- NEW: Enqueue Reference Calendar for Date Range Bookings ---
			wp_enqueue_script( 'clisyc-fullcalendar', clisyc_PLUGIN_URL . 'assets/vendor/fullcalendar/fullcalendar.global.min.js', [], '6.1.11', true );
			wp_enqueue_style( 'clisyc-calendar-styles', clisyc_PLUGIN_URL . 'assets/css/clisyc-calendar.css', [], $this->asset_version( 'assets/css/clisyc-calendar.css' ) );
			// Ensure admin coloring CSS is loaded
			wp_enqueue_style( 'clisyc-admin-calendar', clisyc_PLUGIN_URL . 'assets/css/clisyc-admin-calendar.css', [ 'clisyc-calendar-styles' ], $this->asset_version( 'assets/css/clisyc-admin-calendar.css' ) );

			// Enqueue the logic script (Config is injected via add_meta_box callback in class-post-type-manager.php)
			wp_enqueue_script( 'clisyc-admin-item-calendar', clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-item-calendar.js', [ 'clisyc-fullcalendar', 'jquery' ], $this->asset_version( 'assets/js/clisyc-admin-item-calendar.js' ), true );
			// --- END NEW ---

			// --- Client Search Autocomplete ---
			wp_enqueue_script(
				'clisyc-admin-client-search',
				clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-client-search.js',
				[], // No dependencies - uses vanilla JS
				$this->asset_version( 'assets/js/clisyc-admin-client-search.js' ),
				true // Load in footer
			);

			wp_enqueue_style(
				'clisyc-admin-client-search',
				clisyc_PLUGIN_URL . 'assets/css/clisyc-admin-client-search.css',
				[],
				$this->asset_version( 'assets/css/clisyc-admin-client-search.css' )
			);

			// Localize script with REST API info for client search
			wp_localize_script(
				'clisyc-admin-client-search',
				'clisycClientSearch',
				[
					'restUrl' => esc_url_raw( rest_url( 'clisyc/v1' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				]
			);
			// --- END Client Search ---
		}

		if ( 'client-sync_page_clisyc-calendars' === $screen->id ) {
			wp_enqueue_script( 'clisyc-fullcalendar', clisyc_PLUGIN_URL . 'assets/vendor/fullcalendar/fullcalendar.global.min.js', [], '6.1.11', true );
			wp_enqueue_style( 'clisyc-calendar-styles', clisyc_PLUGIN_URL . 'assets/css/clisyc-calendar.css', [], $this->asset_version( 'assets/css/clisyc-calendar.css' ) );

			// --- START: Admin Calendar Color Fix ---
			// Load CSS with proper text contrast for available/booked/blocked events
			wp_enqueue_style( 'clisyc-admin-calendar', clisyc_PLUGIN_URL . 'assets/css/clisyc-admin-calendar.css', [ 'clisyc-calendar-styles' ], $this->asset_version( 'assets/css/clisyc-admin-calendar.css' ) );

			// Inject CSS variables for calendar colors
			$color_settings = get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] );
			$colors = wp_parse_args( $color_settings, Constants::COLOR_DEFAULTS );

			$calendar_css_vars = sprintf(
				':root {
					--clisyc-available-bg: %s;
					--clisyc-available-text: %s;
					--clisyc-booked-bg: %s;
					--clisyc-booked-text: %s;
					--clisyc-blocked-bg: %s;
					--clisyc-blocked-text: %s;
				}',
				esc_attr( $colors['available_bg'] ),
				esc_attr( $colors['available_text'] ),
				esc_attr( $colors['booked_bg'] ),
				esc_attr( $colors['booked_text'] ),
				esc_attr( $colors['blocked_bg'] ),
				esc_attr( $colors['blocked_text'] )
			);
			wp_add_inline_style( 'clisyc-admin-calendar', $calendar_css_vars );
			// --- END: Admin Calendar Color Fix ---

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading tab parameter for navigation, not processing sensitive data.
			$active_tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'appointment-calendar';

			switch ( $active_tab ) {
				case 'appointment-calendar':
					wp_enqueue_script( 'clisyc-calendar-admin', clisyc_PLUGIN_URL . 'assets/js/clisyc-calendar-admin.js', [ 'jquery', 'clisyc-fullcalendar' ], $this->asset_version( 'assets/js/clisyc-calendar-admin.js' ), true );
					$default_views = ['dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek'];
					// Fix 1 applied here: ensuring action=edit is present
					wp_localize_script('clisyc-calendar-admin', 'clisycCalendarData', [ 'restUrl' => esc_url_raw(rest_url('clisyc/v1/slots')), 'nonce' => wp_create_nonce('wp_rest'), 'dimensionLabels' => $this->get_dimension_cpt_labels(), 'calendars' => [['options' => ['timeZone' => wp_timezone_string(), 'enabledViews' => get_option(Constants::OPTION_CALENDAR_ENABLED_VIEWS, $default_views)]]], 'config' => ['editUrlBase' => admin_url('post.php?action=edit&post='), 'addNewApptUrlBase' => admin_url('post-new.php?post_type=clisyc_appointment')]]);
					break;

				case 'master-schedule':
					wp_enqueue_script( 'clisyc-admin-master-schedule', clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-master-schedule.js', [ 'jquery', 'jquery-ui-tooltip', 'clisyc-fullcalendar' ], $this->asset_version( 'assets/js/clisyc-admin-master-schedule.js' ), true );
					$default_views = ['dayGridMonth', 'timeGridWeek', 'timeGridDay']; // listWeek not as useful here
					// Fix 2 applied here: ensuring action=edit is present
					wp_localize_script('clisyc-admin-master-schedule', 'clisycMasterScheduleData', ['ajaxurl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('clisyc_get_master_schedule_data_nonce'), 'editUrlBase' => admin_url('post.php?action=edit&post='), 'enabledViews' => get_option(Constants::OPTION_CALENDAR_ENABLED_VIEWS, $default_views), 'l10n' => ['clickToEdit' => __('Click to edit the source schedule', 'client-sync')]]);
					break;

				case 'time-slots':
					// Load the new React app for the Manage Slots tab.
					$asset_file_path = clisyc_PLUGIN_DIR . 'assets/dist/manage-slots/index.asset.php';
					if ( file_exists( $asset_file_path ) ) {
						$asset_file   = include $asset_file_path;
						$dependencies = $asset_file['dependencies'] ?? [];
						if ( ! in_array( 'wp-api-fetch', $dependencies, true ) ) {
							$dependencies[] = 'wp-api-fetch';
						}
						// FIX: Ensure wp-element is loaded for the React Manage Slots app
						if ( ! in_array( 'wp-element', $dependencies, true ) ) {
							$dependencies[] = 'wp-element';
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

						wp_enqueue_script( 'clisyc-manage-slots-app', clisyc_PLUGIN_URL . 'assets/dist/manage-slots/index.js', $dependencies, $asset_file['version'], true );
						wp_enqueue_style( 'clisyc-manage-slots-style', clisyc_PLUGIN_URL . 'assets/dist/manage-slots/style-index.css', [], $asset_file['version'] );
						// Entry-level styles extracted by webpack.
						wp_enqueue_style( 'clisyc-manage-slots-entry-style', clisyc_PLUGIN_URL . 'assets/dist/manage-slots/style-index.css', [ 'clisyc-manage-slots-style' ], $asset_file['version'] );

						// --- START: NEW LOCALIZATION BLOCK ---
						wp_localize_script(
							'clisyc-manage-slots-app', // Target the NEW React script handle
							'clisycManageSlotsData',   // The object name the JS expects
							[
								'restUrl'           => esc_url_raw( rest_url( 'clisyc/v1/' ) ),
								'get_nonce'         => wp_create_nonce( 'wp_rest' ),
								'save_nonce'        => wp_create_nonce( 'clisyc_save_available_slots_nonce' ),
								'ajax_url'          => admin_url( 'admin-ajax.php' ),
								'dimensionLabels'   => $this->get_dimension_cpt_labels( true ),
								'editUrlBase'       => admin_url( 'post.php?action=edit&post=' ), // Fix 3: Added
								'addNewApptUrlBase' => admin_url( 'post-new.php?post_type=clisyc_appointment' ), // Fix 3: Added
								'l10n'              => [
									'whatIsThis'        => __( 'What is this?', 'client-sync' ),
									'whatIsThisDesc'    => __( 'This is the primary editor for making direct, manual changes to your calendar for a specific date range.', 'client-sync' ),
									'whatCanIDo'        => __( 'What can I do here?', 'client-sync' ),
									'actionCreate'      => __( 'Create new slots by clicking and dragging on an empty area.', 'client-sync' ),
									'actionModify'      => __( 'Modify existing slots by dragging them to a new time or resizing their duration.', 'client-sync' ),
									'actionDelete'      => __( 'Delete a slot by clicking the (×) icon that appears on hover.', 'client-sync' ),
									'importantNote'     => __( 'Important:', 'client-sync' ),
									'importantNoteDesc' => __( 'Changes are not saved until you click the "Save Calendar Slots" button.', 'client-sync' ),
									'saveButton'        => __( 'Save Calendar Slots', 'client-sync' ),
									'createSlotTitle'   => __( 'Create New Slot', 'client-sync' ),
									'slotTypeLabel'     => __( 'Slot Type', 'client-sync' ),
									'none'              => __( '-- None --', 'client-sync' ),
									'blocked'           => __( 'Blocked', 'client-sync' ),
									'available'         => __( 'Available', 'client-sync' ),
									'saving'            => __( 'Saving...', 'client-sync' ),
									'saveSuccess'       => __( 'Slots saved successfully.', 'client-sync' ),
									'saveError'         => __( 'An error occurred while saving.', 'client-sync' ),
									'confirmDelete'     => __( 'Are you sure you want to delete this slot?', 'client-sync' ),
								],
							]
						);
						// --- END: NEW LOCALIZATION BLOCK ---
					}
					break;
			}
		}

		if ( $is_dimension_edit_page ) {
			global $post;
			$post_id = $post->ID ?? 0;

			// --- START: NEW FIX FOR WOOCOMMERCE DROPDOWN ---
			if ( class_exists( 'WooCommerce' ) ) {
				// This loads the Select2/SelectWoo library and the logic to make .wc-product-search work
				wp_enqueue_script( 'wc-enhanced-select' );
				// This makes it look correct
				wp_enqueue_style( 'woocommerce_admin_styles', WC()->plugin_url() . '/assets/css/admin.css' );
			}
			// --- END: NEW FIX FOR WOOCOMMERCE DROPDOWN ---

			// --- START: THE FIX ---
			// Enqueue the helper script for the "Create & Link Product" button.
			wp_enqueue_script( 'clisyc-admin-wc-product-helper', clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-wc-product-helper.js', [ 'jquery' ], $this->asset_version( 'assets/js/clisyc-admin-wc-product-helper.js' ), true );
			wp_localize_script(
				'clisyc-admin-wc-product-helper',
				'clisycWcProductHelper',
				[
					'ajax_url'    => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( 'clisyc_wc_product_helper_nonce' ),
					'admin_url'   => admin_url(),
				]
			);
			// --- END: THE FIX ---

			// Enqueue the WordPress color picker styles and scripts.
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' ); // The main library script

			// Add a small inline script to initialize our specific color picker field.
			wp_add_inline_script(
				'wp-color-picker',
				"jQuery(document).ready(function($){ $('.clisyc-color-picker').wpColorPicker(); });"
			);

			wp_enqueue_script( 'clisyc-fullcalendar', clisyc_PLUGIN_URL . 'assets/vendor/fullcalendar/fullcalendar.global.min.js', [], '6.1.11', true );
			wp_enqueue_style( 'clisyc-calendar-styles', clisyc_PLUGIN_URL . 'assets/css/clisyc-calendar.css', [], $this->asset_version( 'assets/css/clisyc-calendar.css' ) );

			wp_enqueue_script( 'clisyc-admin-booking-mode-toggle', clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-booking-mode-toggle.js', [ 'jquery', 'wp-data' ], $this->asset_version( 'assets/js/clisyc-admin-booking-mode-toggle.js' ), true );

			// --- NEW: Enqueue Availability Calendar for CPT Editor ---
			// Ensure admin coloring CSS is loaded
			wp_enqueue_style( 'clisyc-admin-calendar', clisyc_PLUGIN_URL . 'assets/css/clisyc-admin-calendar.css', [ 'clisyc-calendar-styles' ], $this->asset_version( 'assets/css/clisyc-admin-calendar.css' ) );

			// Inject CSS variables for calendar colors
			$color_settings = get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] );
			$colors = wp_parse_args( $color_settings, Constants::COLOR_DEFAULTS );
			$calendar_css_vars = sprintf(
				':root {
					--clisyc-available-bg: %s;
					--clisyc-available-text: %s;
					--clisyc-booked-bg: %s;
					--clisyc-booked-text: %s;
					--clisyc-blocked-bg: %s;
					--clisyc-blocked-text: %s;
				}',
				esc_attr( $colors['available_bg'] ),
				esc_attr( $colors['available_text'] ),
				esc_attr( $colors['booked_bg'] ),
				esc_attr( $colors['booked_text'] ),
				esc_attr( $colors['blocked_bg'] ),
				esc_attr( $colors['blocked_text'] )
			);
			wp_add_inline_style( 'clisyc-admin-calendar', $calendar_css_vars );

			wp_enqueue_script( 'clisyc-admin-item-calendar', clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-item-calendar.js', [ 'clisyc-fullcalendar', 'jquery' ], $this->asset_version( 'assets/js/clisyc-admin-item-calendar.js' ), true );
			wp_localize_script( 'clisyc-admin-item-calendar', 'clisycItemCalendarData', [
				'postId'      => $post_id,
				'postType'    => $post->post_type,
				'restUrl'     => esc_url_raw( rest_url( 'clisyc/v1/slots' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'editUrlBase' => admin_url( 'post.php?action=edit&post=' ),
				'slotMinTime' => get_option( Constants::OPTION_CALENDAR_START_TIME, '09:00' ) . ':00',
				'slotMaxTime' => get_option( Constants::OPTION_CALENDAR_END_TIME, '17:00' ) . ':00',
			]);
			// --- END NEW ---

			// --- NEW: Enqueue the React Schedule Editor ---
			$schedule_editor_asset_path = clisyc_PLUGIN_DIR . 'assets/dist/schedule-editor/index.asset.php';
			if ( file_exists( $schedule_editor_asset_path ) ) {
				$asset_file   = include $schedule_editor_asset_path;
				$dependencies = $asset_file['dependencies'] ?? [];
				// FIX: Ensure wp-element is loaded for the React Schedule Editor
				if ( ! in_array( 'wp-element', $dependencies, true ) ) {
					$dependencies[] = 'wp-element';
				}

				// Register the shared FullCalendar vendor chunk extracted by webpack.
				// The schedule-editor bundle imports @fullcalendar/* as npm modules
				// which webpack splits into vendor-fullcalendar/index.js — it must
				// load before the app bundle so the webpackChunk registry is populated.
				$vendor_fc_asset_path = clisyc_PLUGIN_DIR . 'assets/dist/vendor-fullcalendar/index.asset.php';
				if ( file_exists( $vendor_fc_asset_path ) ) {
					$vendor_fc_asset = include $vendor_fc_asset_path;
					wp_register_script(
						'clisyc-vendor-fullcalendar',
						clisyc_PLUGIN_URL . 'assets/dist/vendor-fullcalendar/index.js',
						$vendor_fc_asset['dependencies'] ?? [],
						$vendor_fc_asset['version'] ?? null,
						true
					);
					$dependencies[] = 'clisyc-vendor-fullcalendar';
				}

				wp_enqueue_script(
					'clisyc-schedule-editor-app',
					clisyc_PLUGIN_URL . 'assets/dist/schedule-editor/index.js',
					$dependencies,
					$asset_file['version'],
					true
				);
				wp_enqueue_style(
					'clisyc-schedule-editor-style',
					clisyc_PLUGIN_URL . 'assets/dist/schedule-editor/style-index.css',
					[],
					$asset_file['version']
				);
			}
			// --- END: React Schedule Editor ---

			wp_enqueue_script( 'clisyc-admin-schedule-overrides', clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-schedule-overrides.js', [ 'jquery', 'jquery-ui-datepicker', 'clisyc-fullcalendar' ], $this->asset_version( 'assets/js/clisyc-admin-schedule-overrides.js' ), true );
			$schedule_meta = get_post_meta( $post_id, Constants::META_SCHEDULE, true );
			$schedule_data = json_decode( $schedule_meta, true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $schedule_data ) ) {
				$schedule_data = [];
			}
			$start_time_setting = get_option( Constants::OPTION_CALENDAR_START_TIME, '09:00' );
			$end_time_setting   = get_option( Constants::OPTION_CALENDAR_END_TIME, '17:00' );
			$slot_max_time_fc   = ( '23:59' === $end_time_setting || '24:00' === $end_time_setting ) ? '24:00:00' : $end_time_setting . ':00';
			wp_localize_script(
				'clisyc-schedule-editor-app',
				'clisycScheduleEditorData',
				[
					'postType'        => $screen->post_type,
					'startOfWeek'     => (int) get_option( 'start_of_week', 0 ),
					'weekdays'        => array_values( $GLOBALS['wp_locale']->weekday ),
					'scheduleJson'    => ! empty( $schedule_meta ) ? $schedule_meta : '{"templates":{"A":{}}}', // <-- CRITICAL FIX: Pass the schedule data to React
					'calendarOptions' => [
						'initialView'   => 'timeGridDay',
						'initialDate'   => '1970-01-05',
						'headerToolbar' => false,
						'dayHeaders'    => false,
						'allDaySlot'    => false,
						'height'        => 'auto',
						'slotMinTime'   => $start_time_setting . ':00',
						'slotMaxTime'   => $slot_max_time_fc,
					],
					'l10n'            => [
						'confirmDelete' => __( 'Are you sure you want to delete this time slot?', 'client-sync' ),
					],
				]
			);

			wp_localize_script(
				'clisyc-admin-schedule-overrides',
				'clisycScheduleOverridesData',
				[
					'calendarOptions' => [
						'initialView'   => 'timeGridDay',
						'initialDate'   => '1970-01-05',
						'headerToolbar' => false,
						'dayHeaders'    => false,
						'allDaySlot'    => false,
						'height'        => 'auto',
						'slotMinTime'   => $start_time_setting . ':00',
						'slotMaxTime'   => $slot_max_time_fc,
					],
					'dummyDate'       => '1970-01-05',
					'l10n'            => [ 'confirmDelete' => __( 'Are you sure?', 'client-sync' ) ],
				]
			);

		}

		if ( $screen->id === 'toplevel_page_client-sync' ) {
			// Enqueue Chart.js from the vendor directory
			wp_enqueue_script( 'clisyc-chartjs', clisyc_PLUGIN_URL . 'assets/vendor/chartjs/chart.umd.min.js', [], '4.4.3', true );
			// Enqueue our new dashboard chart script
			wp_enqueue_script( 'clisyc-admin-dashboard-charts', clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-dashboard-charts.js', [ 'clisyc-chartjs' ], $this->asset_version( 'assets/js/clisyc-admin-dashboard-charts.js' ), true );
		}

		if ( $screen->id === 'client-sync_page_clisyc-reports' ) {
			wp_enqueue_script( 'clisyc-chartjs', clisyc_PLUGIN_URL . 'assets/vendor/chartjs/chart.umd.min.js', [], '4.4.3', true );
			wp_enqueue_script( 'clisyc-admin-reports', clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-reports.js', [ 'clisyc-chartjs' ], $this->asset_version( 'assets/js/clisyc-admin-reports.js' ), true );
		}

		if ( $hook_suffix === $dimensions_page_hook ) {
			wp_enqueue_script( 'clisyc-admin-dimensions-page', clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-dimensions-page.js', [ 'jquery', 'jquery-ui-sortable' ], $this->asset_version( 'assets/js/clisyc-admin-dimensions-page.js' ), true );
			wp_localize_script(
				'clisyc-admin-dimensions-page',
				'clisycDimensionPageData',
				[
					'ajax_url'      => admin_url( 'admin-ajax.php' ),
					'reorder_nonce' => wp_create_nonce( 'clisyc_reorder_dimensions_nonce' ),
					'l10n'          => [
						'addTitle'     => esc_js( __( 'Add New Dimension Type', 'client-sync' ) ),
						'editTitle'    => esc_js( __( 'Edit Dimension Type', 'client-sync' ) ),
						'addButton'    => esc_js( __( 'Add New Dimension Type', 'client-sync' ) ),
						'updateButton' => esc_js( __( 'Update Dimension Type', 'client-sync' ) ),
					],
				]
			);

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading tab parameter for navigation, not processing sensitive data.
			$active_dim_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'types';
			if ( 'graph' === $active_dim_tab ) {
				$asset_file_path = clisyc_PLUGIN_DIR . 'assets/dist/graph/index.asset.php';
				if ( file_exists( $asset_file_path ) ) {
					$asset_file   = include $asset_file_path;
					$dependencies = $asset_file['dependencies'];
					if ( ! in_array( 'wp-api-fetch', $dependencies, true ) ) {
						$dependencies[] = 'wp-api-fetch';
					}
					// FIX: Ensure wp-element is loaded for the React graph
					if ( ! in_array( 'wp-element', $dependencies, true ) ) {
						$dependencies[] = 'wp-element';
					}

					// Register the shared React Flow vendor chunk (webpack code-split).
					$vendor_rf_path = clisyc_PLUGIN_DIR . 'assets/dist/vendor-reactflow/index.asset.php';
					if ( file_exists( $vendor_rf_path ) ) {
						$vendor_rf = include $vendor_rf_path;
						wp_register_script(
							'clisyc-vendor-reactflow',
							clisyc_PLUGIN_URL . 'assets/dist/vendor-reactflow/index.js',
							$vendor_rf['dependencies'] ?? [],
							$vendor_rf['version'] ?? null,
							true
						);
						$dependencies[] = 'clisyc-vendor-reactflow';
						// Also enqueue the React Flow vendor styles.
						wp_enqueue_style( 'clisyc-vendor-reactflow-style', clisyc_PLUGIN_URL . 'assets/dist/vendor-reactflow/style-index.css', [], $vendor_rf['version'] ?? null );
					}

					wp_enqueue_script( 'clisyc-node-editor-app', clisyc_PLUGIN_URL . 'assets/dist/graph/index.js', $dependencies, $asset_file['version'], true );
					wp_enqueue_style( 'clisyc-node-editor-style', clisyc_PLUGIN_URL . 'assets/dist/graph/style-index.css', [], $asset_file['version'] );
				}
			}
		}

		if ( $hook_suffix === $custom_fields_page_hook ) {
			// FIX: Enqueue WordPress Media Library for the Image Map picker
			wp_enqueue_media();

			wp_enqueue_script( 'clisyc-admin-custom-fields', clisyc_PLUGIN_URL . 'assets/js/clisyc-admin-custom-fields.js', [ 'jquery', 'wp-i18n', 'wp-color-picker', 'jquery-ui-sortable' ], $this->asset_version( 'assets/js/clisyc-admin-custom-fields.js' ), true );
			$filterable_keys = get_option( Constants::OPTION_AVAILABILITY_DIM_FIELDS, [] );
			$all_fields      = get_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
			$filterable_defs = [];
			if ( is_array( $filterable_keys ) ) {
				foreach ( $filterable_keys as $key ) {
					if ( isset( $all_fields[ $key ] ) ) {
						$filterable_defs[ $key ] = [
							'label'   => $all_fields[ $key ]['label'],
							'options' => $all_fields[ $key ]['options'] ?? [],
						];
					}
				}
			}

			$conditional_rule_template_html = '
			<div class="clisyc-logic-rule-row" style="margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
				<select class="clisyc-rule-field">
					<option value="service_id" selected>' . esc_html__( 'Service', 'client-sync' ) . '</option>
				</select>
				<select class="clisyc-rule-operator">
					<option value="is">' . esc_html__( 'is', 'client-sync' ) . '</option>
					<option value="is_not">' . esc_html__( 'is not', 'client-sync' ) . '</option>
				</select>
				<select class="clisyc-rule-value">
					<option value="">' . esc_html__( 'Select a Service...', 'client-sync' ) . '</option>';

			$all_services = get_posts( [ 'post_type' => Constants::POST_TYPE_SERVICE, 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ] );
			foreach ( $all_services as $service ) {
				$conditional_rule_template_html .= '<option value="' . esc_attr( $service->ID ) . '">' . esc_html( $service->post_title ) . '</option>';
			}

			$conditional_rule_template_html .= '</select>
				<button type="button" class="button-link clisyc-remove-rule-button"><span class="dashicons dashicons-trash"></span></button>
			</div>';

			wp_localize_script(
				'clisyc-admin-custom-fields',
				'clisycAdminCustomFieldData',
				[
					'addText'                 => esc_js( __( 'Add Custom Field', 'client-sync' ) ),
					'updateText'              => esc_js( __( 'Update Custom Field', 'client-sync' ) ),
					'selectImage'             => esc_js( __( 'Select Image for Map', 'client-sync' ) ),
					'useImage'                => esc_js( __( 'Use this image', 'client-sync' ) ),
					'errorText'               => esc_js( __( 'An error occurred. Please try again.', 'client-sync' ) ),
					'reorderNonce'            => wp_create_nonce( 'clisyc_reorder_custom_fields_nonce' ),
					'ajax_url'                => admin_url( 'admin-ajax.php' ),
					'optionPlaceholder'       => esc_js( __( 'Option Value', 'client-sync' ) ),
					'optionColorLabel'        => esc_js( __( 'Option Color', 'client-sync' ) ),
					'filterableFields'        => $filterable_defs,
					'conditionalRuleTemplate' => $conditional_rule_template_html,
				]
			);
		}
	}

	/**
	 * Returns date-range parameters for the slot selector datepicker.
	 *
	 * Instead of building a 90-element array in PHP and inlining it as JSON,
	 * we pass a start date and day count so the JS client can generate the
	 * range itself. This shrinks the inline payload from ~2 KB to ~40 bytes.
	 */
	private function get_available_date_range_for_selector(): array {
		$start_date = new \DateTime( 'today', wp_timezone() );
		return [
			'startDate' => $start_date->format( 'Y-m-d' ),
			'dayCount'  => 90,
		];
	}

	private function get_dimension_cpt_labels( bool $use_singular = false ): array {
		$labels        = [];
		$registry      = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$custom_types  = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
		$enabled_slugs = array_keys( array_filter( $registry['dimensions'] ?? [], fn( $dim ) => ! empty( $dim['enabled'] ) ) );

		foreach ( $enabled_slugs as $slug ) {
			if ( $use_singular ) {
				$label = $custom_types[ $slug ]['singular'] ?? '';
				if ( ! $label ) {
					$cpt = get_post_type_object( $slug );
					$label = $cpt->labels->singular_name ?? $slug;
				}
			} else {
				$label = $custom_types[ $slug ]['plural'] ?? '';
				if ( ! $label ) {
					$cpt = get_post_type_object( $slug );
					$label = $cpt->labels->name ?? $slug;
				}
			}
			$labels[ $slug ] = $label;
		}
		return $labels;
	}

	private function get_primary_dimension_posts_for_js(): array {
		$registry         = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		$primary_dim_slug = null;
		if ( ! empty( $registry['dimensions'] ) ) {
			foreach ( $registry['dimensions'] as $slug => $settings ) {
				if ( ! empty( $settings['primary'] ) ) {
					$primary_dim_slug = $slug;
					break;
				}
			}
		}

		if ( ! $primary_dim_slug || ! post_type_exists( $primary_dim_slug ) ) {
			return [];
		}

		$posts = get_posts(
			[
				'post_type'      => $primary_dim_slug,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);

		return array_map(
			function( $post ) {
				return [
					'id'    => $post->ID,
					'title' => $post->post_title,
				];
			},
			$posts
		);
	}

	public function get_all_dashicon_classes() {
		return [ 'dashicons-menu', 'dashicons-admin-site', 'dashicons-dashboard', 'dashicons-admin-post', 'dashicons-admin-media', 'dashicons-admin-links', 'dashicons-admin-page', 'dashicons-admin-comments', 'dashicons-admin-appearance', 'dashicons-admin-plugins', 'dashicons-admin-users', 'dashicons-admin-tools', 'dashicons-admin-settings', 'dashicons-admin-network', 'dashicons-admin-home', 'dashicons-admin-generic', 'dashicons-admin-collapse', 'dashicons-filter', 'dashicons-admin-customizer', 'dashicons-admin-multisite', 'dashicons-welcome-write-blog', 'dashicons-welcome-add-page', 'dashicons-welcome-view-site', 'dashicons-welcome-widgets-menus', 'dashicons-welcome-comments', 'dashicons-welcome-learn-more', 'dashicons-format-asides', 'dashicons-format-audio', 'dashicons-format-chat', 'dashicons-format-gallery', 'dashicons-format-image', 'dashicons-format-links', 'dashicons-format-quote', 'dashicons-format-status', 'dashicons-format-video', 'dashicons-format-standard', 'dashicons-image-crop', 'dashicons-image-rotate', 'dashicons-image-rotate-left', 'dashicons-image-rotate-right', 'dashicons-image-flip-vertical', 'dashicons-image-flip-horizontal', 'dashicons-image-filter', 'dashicons-undo', 'dashicons-redo', 'dashicons-editor-bold', 'dashicons-editor-italic', 'dashicons-editor-ul', 'dashicons-editor-ol', 'dashicons-editor-quote', 'dashicons-editor-alignleft', 'dashicons-editor-aligncenter', 'dashicons-editor-alignright', 'dashicons-editor-insertmore', 'dashicons-editor-spellcheck', 'dashicons-editor-expand', 'dashicons-editor-contract', 'dashicons-editor-kitchensink', 'dashicons-editor-underline', 'dashicons-editor-justify', 'dashicons-editor-textcolor', 'dashicons-editor-paste-word', 'dashicons-editor-paste-text', 'dashicons-editor-removeformatting', 'dashicons-editor-video', 'dashicons-editor-customchar', 'dashicons-editor-outdent', 'dashicons-editor-indent', 'dashicons-editor-help', 'dashicons-editor-strikethrough', 'dashicons-editor-unlink', 'dashicons-editor-rtl', 'dashicons-editor-ltr', 'dashicons-editor-alignfull', 'dashicons-editor-table', 'dashicons-align-left', 'dashicons-align-right', 'dashicons-align-center', 'dashicons-align-none', 'dashicons-lock', 'dashicons-unlock', 'dashicons-calendar', 'dashicons-calendar-alt', 'dashicons-visibility', 'dashicons-hidden', 'dashicons-post-status', 'dashicons-edit', 'dashicons-trash', 'dashicons-sticky', 'dashicons-external', 'dashicons-arrow-up', 'dashicons-arrow-down', 'dashicons-arrow-right', 'dashicons-arrow-left', 'dashicons-arrow-up-alt', 'dashicons-arrow-down-alt', 'dashicons-arrow-right-alt', 'dashicons-arrow-left-alt', 'dashicons-arrow-up-alt2', 'dashicons-arrow-down-alt2', 'dashicons-arrow-right-alt2', 'dashicons-arrow-left-alt2', 'dashicons-sort', 'dashicons-leftright', 'dashicons-randomize', 'dashicons-list-view', 'dashicons-excerpt-view', 'dashicons-grid-view', 'dashicons-cloud-upload', 'dashicons-cloud-download', 'dashicons-cloud-saved', 'dashicons-cloud-off', 'dashicons-move', 'dashicons-controls-repeat', 'dashicons-controls-skipback', 'dashicons-controls-skipforward', 'dashicons-controls-play', 'dashicons-controls-pause', 'dashicons-controls-volumeon', 'dashicons-controls-volumeoff', 'dashicons-controls-back', 'dashicons-controls-forward', 'dashicons-plus', 'dashicons-minus', 'dashicons-dismiss', 'dashicons-marker', 'dashicons-star-filled', 'dashicons-star-half', 'dashicons-star-empty', 'dashicons-flag', 'dashicons-info', 'dashicons-info-outline', 'dashicons-warning', 'dashicons-share', 'dashicons-share-alt', 'dashicons-share-alt2', 'dashicons-twitter', 'dashicons-rss', 'dashicons-email', 'dashicons-email-alt', 'dashicons-email-alt2', 'dashicons-facebook', 'dashicons-facebook-alt', 'dashicons-google', 'dashicons-networking', 'dashicons-hammer', 'dashicons-art', 'dashicons-migrate', 'dashicons-performance', 'dashicons-universal-access', 'dashicons-universal-access-alt', 'dashicons-tickets', 'dashicons-nametag', 'dashicons-clipboard', 'dashicons-heart', 'dashicons-megaphone', 'dashicons-schedule', 'dashicons-wordpress', 'dashicons-wordpress-alt', 'pressthis', 'dashicons-update', 'dashicons-update-alt', 'dashicons-screenoptions', 'dashicons-layout', 'dashicons-cart', 'dashicons-feedback', 'dashicons-cloud', 'dashicons-translation', 'dashicons-tag', 'dashicons-category', 'dashicons-archive', 'dashicons-tagcloud', 'dashicons-text', 'dashicons-yes', 'dashicons-yes-alt', 'dashicons-no', 'dashicons-no-alt', 'dashicons-plus-alt', 'dashicons-minus-alt', 'dashicons-thumbs-up', 'dashicons-thumbs-down', 'dashicons-chart-pie', 'dashicons-chart-bar', 'dashicons-chart-line', 'dashicons-chart-area', 'dashicons-groups', 'dashicons-businessman', 'dashicons-id', 'dashicons-id-alt', 'dashicons-products', 'dashicons-awards', 'dashicons-forms', 'dashicons-testimonial', 'dashicons-portfolio', 'dashicons-book', 'dashicons-book-alt', 'dashicons-download', 'dashicons-upload', 'dashicons-backup', 'dashicons-clock', 'dashicons-lightbulb', 'dashicons-microphone', 'dashicons-desktop', 'dashicons-laptop', 'dashicons-tablet', 'dashicons-smartphone', 'dashicons-phone', 'dashicons-index-card', 'dashicons-carrot', 'dashicons-building', 'dashicons-store', 'dashicons-album', 'dashicons-palmtree', 'dashicons-tickets-alt', 'dashicons-money', 'dashicons-money-alt', 'dashicons-smiley', 'dashicons-paperclip', 'dashicons-slides', 'dashicons-analytics', 'dashicons-video-alt', 'dashicons-video-alt2', 'dashicons-video-alt3', 'dashicons-plugins-checked', 'dashicons-camera', 'dashicons-camera-alt', 'dashicons-playlist-audio', 'dashicons-playlist-video', 'dashicons-controls-play', 'dashicons-controls-pause', 'dashicons-controls-forward', 'dashicons-controls-skipforward', 'dashicons-controls-back', 'dashicons-controls-skipback', 'dashicons-controls-repeat', 'dashicons-controls-volumeon', 'dashicons-image-rotate-left', 'dashicons-image-rotate-right', 'dashicons-image-flip-vertical', 'dashicons-image-flip-horizontal', 'dashicons-database-add', 'dashicons-database', 'dashicons-database-export', 'dashicons-database-import', 'dashicons-database-remove', 'dashicons-database-view', 'dashicons-align-full-width', 'dashicons-align-pull-left', 'dashicons-align-pull-right', 'dashicons-align-wide', 'dashicons-block-default', 'dashicons-button', 'dashicons-columns', 'dashicons-cover-image', 'dashicons-ellipsis', 'dashicons-embed-audio', 'dashicons-embed-generic', 'dashicons-embed-photo', 'dashicons-embed-post', 'dashicons-embed-video', 'dashicons-exit', 'dashicons-heading', 'dashicons-html', 'dashicons-insert', 'dashicons-insert-after', 'dashicons-insert-before', 'dashicons-remove', 'dashicons-saved', 'dashicons-shortcode', 'dashicons-table-col-after', 'dashicons-table-col-before', 'dashicons-table-col-delete', 'dashicons-table-row-after', 'dashicons-table-row-before', 'dashicons-table-row-delete', 'dashicons-buddicons-activity', 'dashicons-buddicons-bbpress-logo', 'dashicons-buddicons-buddypress-logo', 'dashicons-buddicons-community', 'dashicons-buddicons-forums', 'dashicons-buddicons-friends', 'dashicons-buddicons-groups', 'dashicons-buddicons-pm', 'dashicons-buddicons-replies', 'dashicons-buddicons-topics', 'dashicons-buddicons-tracking', ];
	}

	public function get_all_icon_choices() {
		$dashicons = $this->get_all_dashicon_classes();
		$fontawesome_free = [ 'fas fa-hotel', 'fas fa-bed', 'fas fa-plane', 'fas fa-ship', 'fas fa-car', 'fas fa-taxi', 'fas fa-shuttle-van', 'fas fa-person-swimming', 'fas fa-umbrella-beach', 'fas fa-mountain-sun', 'fas fa-suitcase', 'fas fa-suitcase-rolling', 'fas fa-map-marked-alt', 'fas fa-compass', 'fas fa-globe', 'fas fa-ticket-alt', 'fas fa-luggage-cart', 'fas fa-campground', 'fas fa-hiking', 'fas fa-binoculars', 'fas fa-map', 'fas fa-road', 'fas fa-train', 'fas fa-subway', 'fas fa-bus', 'fas fa-bicycle', 'fas fa-motorcycle', 'fas fa-truck', 'fas fa-ambulance', 'fas fa-helicopter', 'fas fa-rocket', 'fas fa-space-shuttle', 'fas fa-user-doctor', 'fas fa-spa', 'fas fa-cut', 'fas fa-briefcase', 'fas fa-gavel', 'fas fa-chart-line', 'fas fa-bullhorn', 'fas fa-building', 'fas fa-handshake', 'fas fa-money-bill-wave', 'fas fa-chart-bar', 'fas fa-calculator', 'fas fa-phone-alt', 'fas fa-envelope', 'fas fa-fax', 'fas fa-file-invoice', 'fas fa-file-contract', 'fas fa-user-tie', 'fas fa-users', 'fas fa-user-friends', 'fas fa-chart-pie', 'fas fa-clipboard', 'fas fa-pen', 'fas fa-paperclip', 'fas fa-folder-open', 'fas fa-archive', 'fas fa-calendar-alt', 'fas fa-clock', 'fas fa-stopwatch', 'fas fa-alarm-clock', 'fas fa-utensils', 'fas fa-coffee', 'fas fa-wine-glass', 'fas fa-apple-alt', 'fas fa-bread-slice', 'fas fa-cheese', 'fas fa-egg', 'fas fa-fish', 'fas fa-hamburger', 'fas fa-pizza-slice', 'fas fa-hotdog', 'fas fa-ice-cream', 'fas fa-beer-mug-empty', 'fas fa-cocktail', 'fas fa-lemon', 'fas fa-carrot', 'fas fa-pepper-hot', 'fas fa-cookie', 'fas fa-drumstick-bite', 'fas fa-glass-cheers', 'fas fa-mug-hot', 'fas fa-bottle-water', 'fas fa-bowl-food', 'fas fa-blender', 'fas fa-shopping-basket', 'fas fa-info-circle', 'fas fa-question-circle', 'fas fa-check-circle', 'fas fa-times-circle', 'fas fa-star', 'fas fa-exclamation-triangle', 'fas fa-exclamation-circle', 'fas fa-ban', 'fas fa-heart', 'fas fa-thumbs-up', 'fas fa-thumbs-down', 'fas fa-arrow-left', 'fas fa-arrow-right', 'fas fa-arrow-up', 'fas fa-arrow-down', 'fas fa-search', 'fas fa-user', 'fas fa-home', 'fas fa-cog', 'fas fa-trash', 'fas fa-edit', 'fas fa-plus', 'fas fa-minus', 'fas fa-save', 'fas fa-print', 'fas fa-download', 'fas fa-upload', 'fas fa-lock', 'fas fa-unlock', 'fas fa-eye', 'fas fa-eye-slash', 'fas fa-bell', 'fas fa-comment', 'fas fa-envelope', 'fas fa-phone', 'fas fa-map-marker', 'fas fa-calendar', 'fas fa-clock', 'fas fa-folder', 'fas fa-file', 'fas fa-image', 'fas fa-video', 'fas fa-music', 'fas fa-microphone', 'fas fa-headphones', 'fas fa-gamepad', 'fas fa-keyboard', 'fas fa-mouse', 'fas fa-laptop', 'fas fa-mobile-alt', 'fas fa-tablet-alt', 'fas fa-tv', 'fas fa-desktop', 'fas fa-server', 'fas fa-database', 'fas fa-cloud', 'fas fa-wifi', 'fas fa-signal', 'fas fa-battery-full', 'fas fa-plug', 'fas fa-bolt', 'fas fa-fire', 'fas fa-snowflake', 'fas fa-sun', 'fas fa-moon', 'fas fa-cloud-rain', 'fas fa-umbrella', 'fas fa-wind', 'fas fa-thermometer', 'fas fa-tint', 'fas fa-lightbulb', 'fas fa-leaf', 'fas fa-tree', 'fas fa-flower', 'fas fa-paw', 'fas fa-bug', 'fas fa-frog', 'fas fa-dog', 'fas fa-cat', 'fas fa-horse', 'fas fa-fish', 'fas fa-dove', 'fas fa-spider', 'fas fa-seedling', 'fas fa-cloud-sun', 'fas fa-futbol', 'fas fa-basketball-ball', 'fas fa-volleyball-ball', 'fas fa-golf-ball', 'fas fa-table-tennis', 'fas fa-dumbbell', 'fas fa-biking', 'fas fa-running', 'fas fa-skating', 'fas fa-skiing', 'fas fa-snowboarding', 'fas fa-trophy', 'fas fa-medal', 'fas fa-robot', 'fas fa-code', 'fas fa-terminal', 'fas fa-microchip', 'fas fa-hdd', 'fas fa-memory', 'fas fa-plug', 'fas fa-battery-empty', 'fas fa-satellite-dish', 'fas fa-rss', 'fas fa-comments', 'fas fa-comment-dots', 'fas fa-comment-alt', 'fas fa-paper-plane', 'fas fa-rss', 'fas fa-share', 'fas fa-reply', 'fas fa-at', 'fas fa-hashtag', 'fas fa-hospital', 'fas fa-stethoscope', 'fas fa-pill', 'fas fa-syringe', 'fas fa-band-aid', 'fas fa-wheelchair', 'fas fa-dna', 'fas fa-microscope', 'fas fa-flask', 'fas fa-heartbeat', 'far fa-calendar', 'far fa-clock', 'far fa-user', 'far fa-heart', 'far fa-star', 'far fa-file', 'far fa-folder', 'far fa-image', 'far fa-comment', 'far fa-bell', 'far fa-envelope', 'far fa-trash-alt', 'far fa-edit', 'far fa-check-circle', 'far fa-times-circle', 'far fa-question-circle', 'far fa-info-circle', 'far fa-thumbs-up', 'far fa-thumbs-down', 'far fa-lightbulb', 'far fa-compass', 'far fa-map', 'far fa-paper-plane', 'far fa-gem', 'far fa-window-close', 'far fa-window-minimize', 'far fa-window-maximize', 'far fa-window-restore', 'far fa-address-book', 'far fa-address-card', 'far fa-id-badge', 'far fa-id-card', 'far fa-building', 'far fa-hospital', 'far fa-keyboard', 'far fa-moon', 'far fa-sun', 'far fa-smile', 'far fa-frown', 'far fa-meh', 'fab fa-facebook', 'fab fa-facebook-f', 'fab fa-twitter', 'fab fa-instagram', 'fab fa-youtube', 'fab fa-github', 'fab fa-gitlab', 'fab fa-linkedin', 'fab fa-linkedin-in', 'fab fa-pinterest', 'fab fa-reddit', 'fab fa-stack-overflow', 'fab fa-apple', 'fab fa-windows', 'fab fa-android', 'fab fa-linux', 'fab fa-wordpress', 'fab fa-drupal', 'fab fa-joomla', 'fab fa-html5', 'fab fa-css3', 'fab fa-js', 'fab fa-node', 'fab fa-react', 'fab fa-angular', 'fab fa-vuejs', 'fab fa-laravel', 'fab fa-bootstrap', 'fab fa-sass', 'fab fa-less', 'fab fa-gulp', 'fab fa-grunt', 'fab fa-npm', 'fab fa-yarn', 'fab fa-docker', 'fab fa-aws', 'fab fa-microsoft', 'fab fa-google', 'fab fa-google-drive', 'fab fa-dropbox', 'fab fa-slack', 'fab fa-discord', 'fab fa-telegram', 'fab fa-whatsapp', 'fab fa-viber', 'fab fa-skype', 'fab fa-zoom', 'fab fa-paypal', 'fab fa-stripe', 'fab fa-shopify', 'fab fa-amazon', 'fab fa-ebay', 'fab fa-bitcoin', 'fab fa-ethereum', 'fab fa-spotify', 'fab fa-soundcloud', 'fab fa-tiktok', 'fab fa-snapchat', 'fab fa-tumblr', 'fab fa-vimeo', 'fab fa-twitch', 'fab fa-stack-exchange', 'fab fa-medium', 'fab fa-dev', 'fab fa-hacker-news', 'fab fa-quora', 'fab fa-reddit-alien', 'fab fa-behance', 'fab fa-dribbble', 'fab fa-figma', 'fab fa-sketch', 'fab fa-adobe', 'fab fa-chrome', 'fab fa-firefox', 'fab fa-edge', 'fab fa-opera', 'fab fa-safari', 'fab fa-ubuntu', 'fab fa-steam', 'fab fa-playstation', 'fab fa-xbox', 'fab fa-nintendo-switch', 'fab fa-font-awesome', ];

		return array_merge( $dashicons, $fontawesome_free );
	}
}