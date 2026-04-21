<?php
/**
 * File: src/shared/includes/admin/class-menu-manager.php -> client-sync/includes/admin/class-menu-manager.php
 * Centralizes all admin menu registration for the Client Sync plugin.
 * Refactored to use hooks for extensibility (Pro modules).
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu_Manager {

	/**
	 * A reference to the main Admin class instance, which holds the render callbacks.
	 * @var \DependentMedia\ClientSync\Admin\Admin
	 */
	private $admin_handler;

	/**
	 * Constructor.
	 * @param \DependentMedia\ClientSync\Admin\Admin $admin_handler An instance of the main Admin class.
	 */
	public function __construct( \DependentMedia\ClientSync\Admin\Admin $admin_handler ) {
		$this->admin_handler = $admin_handler;
	}

	/**
	 * Registers all menus and submenus for the base plugin.
	 * This is the single source of truth for the admin menu structure.
	 */
	public function register_menus() {
		$main_page_slug = 'client-sync';

		add_menu_page(
			__( 'Client Sync', 'client-sync' ),
			__( 'Client Sync', 'client-sync' ),
			'manage_options',
			$main_page_slug,
			[ $this->admin_handler, 'render_dashboard_page' ],
			'dashicons-businessperson',
			80
		);

		// --- Core Menu Items ---
		add_submenu_page( $main_page_slug, __( 'Dashboard', 'client-sync' ), __( 'Dashboard', 'client-sync' ), 'manage_options', $main_page_slug, [ $this->admin_handler, 'render_dashboard_page' ] );
		add_submenu_page( $main_page_slug, __( 'Appointments', 'client-sync' ), __( 'Appointments', 'client-sync' ), 'edit_posts', 'edit.php?post_type=clisyc_appointment' );
		add_submenu_page( $main_page_slug, __( 'Calendars & Schedules', 'client-sync' ), __( 'Calendars', 'client-sync' ), 'manage_options', 'clisyc-calendars', [ $this->admin_handler, 'render_calendars_page' ] );
		add_submenu_page( $main_page_slug, __( 'All Time Slots', 'client-sync' ), __( 'Slots List', 'client-sync' ), 'manage_options', 'clisyc-available-slots-list', [ $this->admin_handler, 'render_available_slots_list_page' ] );
		
		add_submenu_page( $main_page_slug, __( 'Dimensions', 'client-sync' ), __( 'Dimensions', 'client-sync' ), 'manage_options', 'clisyc-dimensions', [ $this->admin_handler, 'render_dimensions_page' ] );
		
		// --- Dynamic Dimensions (Services, Staff, etc.) ---
		$registry = get_option(Constants::OPTION_DIMENSION_REGISTRY, []);
		$enabled_dimensions = $registry['dimensions'] ?? [];
		foreach ($enabled_dimensions as $slug => $settings) {
			if (!empty($settings['enabled']) && post_type_exists($slug)) {
				$cpt = get_post_type_object($slug);
				if ($cpt) {
					add_submenu_page($main_page_slug, $cpt->labels->name, $cpt->labels->menu_name, $cpt->cap->edit_posts, 'edit.php?post_type=' . $slug);
				}
			}
		}

		// --- Hook for Pro Modules to register their own menus ---
		// This replaces hardcoded checks for clisyc_form, clisyc_package, license pages, etc.
		do_action( 'clisyc_register_extra_submenu_items', $main_page_slug );

		// --- Core Settings ---
		add_submenu_page( $main_page_slug, __( 'Booking Fields', 'client-sync' ), __( 'Custom Fields', 'client-sync' ), 'manage_options', 'clisyc-custom-fields', [ $this->admin_handler, 'render_custom_fields_page' ] );
		add_submenu_page( $main_page_slug, __( 'Client Sync Reports', 'client-sync' ), __( 'Reports', 'client-sync' ), 'manage_options', 'clisyc-reports', [ $this->admin_handler, 'render_reports_page' ] );
		add_submenu_page( $main_page_slug, __( 'Guide', 'client-sync' ), __( 'Guide', 'client-sync' ), 'manage_options', 'clisyc-guide', [ $this->admin_handler, 'render_guide_page' ] );
		add_submenu_page( $main_page_slug, __( 'Settings', 'client-sync' ), __( 'Settings', 'client-sync' ), 'manage_options', 'clisyc-settings', [ $this->admin_handler, 'render_settings_page' ] );
		
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			add_submenu_page( $main_page_slug, __( 'Testing Tools', 'client-sync' ), __( 'Testing', 'client-sync' ), 'manage_options', 'clisyc-testing', [ $this->admin_handler, 'render_testing_page' ] );
		}
	}

	/**
	 * Reorders the Client Sync submenu items and injects icons for Dimension CPTs.
	 * This runs late to ensure all CPTs have been added to the menu.
	 */
	public function modify_and_reorder_submenu() {
		global $submenu;
		$main_page_slug = 'client-sync';

		if ( ! isset( $submenu[ $main_page_slug ] ) ) {
			return;
		}

		$desired_order = [
			'client-sync',
			'edit.php?post_type=clisyc_appointment',
			'clisyc-checkin',
			'clisyc-calendars',
			'clisyc-available-slots-list',
			'---SEPARATOR-1---',
			'clisyc-dimensions',
			'---CPT-PLACEHOLDER---', // Dynamic dimensions (Staff/Services) go here
			'---SEPARATOR-2---',
			// Pro Items will naturally fall here if registered
			'clisyc-clients',
			'clisyc-message-queue',
			'edit.php?post_type=clisyc_form',
			'edit.php?post_type=clisyc_member_plan',
			'clisyc-subscribers',
			'edit.php?post_type=clisyc_package',
			'edit.php?post_type=clisyc_output_tmpl',
			'clisyc-custom-fields',
			'clisyc-reports',
			'clisyc-guide',
			'clisyc-settings',
			'client-sync-pro-license',
			'clisyc-testing',
		];
		
		$current_submenu = $submenu[ $main_page_slug ];
		$reordered_submenu = [];
		$cpt_submenus = [];
		$known_submenus_by_slug = [];
		$hidden_pages = []; // Pages not in desired_order that must stay registered for access control.

		foreach ( $current_submenu as $item ) {
			$slug = $item[2];
			$is_valid_cpt_link = ( strpos( $slug, 'post_type=clisyc_' ) !== false && $slug !== 'edit.php?post_type=clisyc_appointment' );

			if ( in_array( $slug, $desired_order, true ) ) {
				if ( ! isset( $known_submenus_by_slug[ $slug ] ) ) {
					$known_submenus_by_slug[ $slug ] = $item;
				}
			} elseif ( $is_valid_cpt_link ) {
				// Captures dynamic dimensions not explicitly listed in desired_order
				if ( ! isset( $cpt_submenus[ $slug ] ) ) {
					$cpt_submenus[ $slug ] = $item;
				}
			} elseif ( strpos( $slug, 'clisyc-' ) === 0 ) {
				// Preserve plugin pages (e.g. hidden tab pages) so WordPress
				// keeps them registered for capability checks and menu state.
				$hidden_pages[ $slug ] = $item;
			}
		}
		
		ksort( $cpt_submenus );

		$custom_dimensions = get_option( Constants::OPTION_CUSTOM_DIMENSION_TYPES, [] );
		$dimension_icons = [];
		if ( is_array( $custom_dimensions ) ) {
			foreach ( $custom_dimensions as $slug => $data ) {
				$dimension_icons[ $slug ] = $data['icon'] ?? 'dashicons-admin-generic';
			}
		}

		foreach ( $desired_order as $slug ) {
			if ( strpos( $slug, '---SEPARATOR' ) === 0 ) {
				$reordered_submenu[] = [ '', 'read', $slug, '', 'wp-menu-separator' ];
			} elseif ( $slug === '---CPT-PLACEHOLDER---' ) {
				foreach ( $cpt_submenus as $cpt_slug => $cpt_item ) {
					parse_str( wp_parse_url( $cpt_item[2], PHP_URL_QUERY ), $query_args );
					$post_type = $query_args['post_type'] ?? '';

					$cpt_object = get_post_type_object( $post_type );
					if ( ! $cpt_object ) continue;

					$menu_label = $cpt_object->labels->menu_name ?? $cpt_item[0];
					$icon_class = $dimension_icons[ $post_type ] ?? 'dashicons-admin-generic';
					
					if (strpos($icon_class, 'fa-') !== false) {
						$icon_html = '<span class="' . esc_attr($icon_class) . '" style="font-size: 1.1em; vertical-align: text-top; margin-right: 5px; width: 20px; text-align: center;"></span>';
					} else {
						$icon_html = '<span class="dashicons ' . esc_attr($icon_class) . '" style="font-size: 1.1em; vertical-align: text-top; margin-right: 5px;"></span>';
					}
					
					$reordered_submenu[] = [ $icon_html . $menu_label, $cpt_item[1], $cpt_item[2] ];
				}
			} elseif ( isset( $known_submenus_by_slug[ $slug ] ) ) {
				$reordered_submenu[] = $known_submenus_by_slug[ $slug ];
			}
		}

		// Append hidden pages so they remain registered (for capability checks
		// and parent-menu highlighting) without appearing in the visible menu.
		if ( ! empty( $hidden_pages ) ) {
			foreach ( $hidden_pages as $hidden_item ) {
				$hidden_item[4] = 'clisyc-hidden-submenu-item';
				$reordered_submenu[] = $hidden_item;
			}
			// Inject inline CSS to hide these items from the sidebar on all admin pages.
			add_action( 'admin_head', static function () {
				echo '<style>li.clisyc-hidden-submenu-item{display:none!important}</style>';
			} );
		}

		$submenu[ $main_page_slug ] = $reordered_submenu;
	}
}