<?php
/**
 * File: src/pro/includes/modules/memberships/class-memberships-module.php -> client-sync-pro/includes/modules/memberships/class-memberships-module.php
 * Main controller for the Memberships module. Initializes all related components.
 * REFACTORED: Now implements the Module_Interface for automatic loading.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Memberships
 */

namespace ClientSyncPro\Modules\Memberships;

// ADDED: Use statement for the new interface.
use ClientSyncPro\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) exit;

// MODIFIED: Class now implements the interface.
class Memberships_Module implements Module_Interface {

	public function __construct() {
		// The constructor is now solely responsible for this module's own hooks.
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
	}

	public function enqueue_assets( $hook_suffix ) {
		$screen = get_current_screen();

		$is_membership_cpt = is_object( $screen )
			&& 'clisyc_member_plan' === $screen->post_type
			&& in_array( $hook_suffix, [ 'post.php', 'post-new.php', 'edit.php' ], true );

		$is_subscribers_page = is_object( $screen )
			&& 'client-sync_page_clisyc-subscribers' === $screen->id;

		if ( ! $is_membership_cpt && ! $is_subscribers_page ) {
			return;
		}

		wp_enqueue_style(
			'client-sync-pro-admin-memberships',
			plugin_dir_url( __FILE__ ) . 'css/admin-memberships.css',
			[],
			'1.2.0'
		);

		if ( $is_membership_cpt ) {
			wp_enqueue_script(
				'client-sync-pro-admin-memberships',
				plugin_dir_url( __FILE__ ) . 'js/admin-memberships.js',
				['jquery'],
				'1.2.0',
				true
			);
		}
	}

    /**
     * Initializes the module by loading its components and registering their hooks.
     * This method is called by the Module_Manager.
     * {@inheritdoc}
     */
    public function initialize() {
        // Load all components of the Memberships module.
        require_once __DIR__ . '/class-membership-cpt.php';
        require_once __DIR__ . '/class-user-profile-integration.php';
        require_once __DIR__ . '/class-membership-help-tabs.php';

        // Instantiate and register hooks for each component.
        $membership_cpt = new Membership_CPT();
        $membership_cpt->register_hooks();
        
        $user_profile = new User_Profile_Integration();
        $user_profile->register_hooks();

        // Register Help tabs for the Membership Plans screens.
        $help_tabs = new Membership_Help_Tabs();
        $help_tabs->register_hooks();

        // Register starter plan templates (empty-state banner + AJAX).
        require_once __DIR__ . '/class-membership-plan-templates.php';
        $plan_templates = new Membership_Plan_Templates();
        $plan_templates->register_hooks();

        // Load the Subscribers list table class.
        require_once __DIR__ . '/class-subscribers-list-table.php';

        // Only load the subscription integration if the plugin is active
        if ( class_exists( 'WC_Subscriptions' ) ) {
            require_once __DIR__ . '/class-wc-subscriptions-integration.php';
            $wc_integration = new WC_Subscriptions_Integration();
            $wc_integration->register_hooks();
        }

        // Register the submenu item for the main plugin menu
        add_action( 'clisyc_register_extra_submenu_items', [ $this, 'add_menu_items' ] );
    }

    /**
     * Adds the Memberships CPT list as a submenu item under the main plugin menu.
     *
     * @param string $main_page_slug The parent slug for the submenu.
     */
    public function add_menu_items( $main_page_slug ) {
        add_submenu_page(
            $main_page_slug,
            __( 'Memberships', 'client-sync-pro' ),
            __( 'Memberships', 'client-sync-pro' ),
            'manage_options',
            'edit.php?post_type=clisyc_member_plan'
        );

        add_submenu_page(
            $main_page_slug,
            __( 'Subscribers', 'client-sync-pro' ),
            __( 'Subscribers', 'client-sync-pro' ),
            'manage_options',
            'clisyc-subscribers',
            [ $this, 'render_subscribers_page' ]
        );
    }

    /**
     * Renders the Subscribers admin list page.
     */
    public function render_subscribers_page() {
        require_once __DIR__ . '/views/view-subscribers-page.php';
    }
}