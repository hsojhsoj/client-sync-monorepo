<?php
/**
 * File: src/pro/includes/modules/forms/class-forms-module.php -> client-sync-pro/includes/modules/forms/class-forms-module.php
 * Main controller for the Form Builder module. Initializes all related components.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Forms
 */

namespace ClientSyncPro\Modules\Forms;

use ClientSyncPro\Module_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Forms_Module implements Module_Interface {

	/**
	 * Initializes the module, loading components and registering hooks.
	 */
	public function initialize() {
		// Register the one-time setup actions upon plugin activation.
		register_activation_hook( WP_PLUGIN_DIR . '/client-sync-pro/client-sync-pro.php', [ __CLASS__, 'activate' ] );

		// Load all components of the Form Builder module.
		require_once __DIR__ . '/class-form-cpt.php';
		require_once __DIR__ . '/class-form-builder-metabox.php';
		require_once __DIR__ . '/class-leads-list-table.php';
		require_once __DIR__ . '/class-form-submission-handler.php';
		require_once __DIR__ . '/class-form-shortcode.php';

		// Instantiate and register hooks for each component.
		$form_cpt = new Form_CPT();
		$form_cpt->register_hooks();

		$form_metabox = new Form_Builder_Metabox();
		$form_metabox->register_hooks();

		$form_handler = new Form_Submission_Handler();
		$form_handler->register_hooks();

		$form_shortcode = new Form_Shortcode();
		$form_shortcode->register_hooks();

		// Register submenu items under the main Client Sync menu.
		add_action( 'clisyc_register_extra_submenu_items', [ $this, 'add_menu_items' ] );

		add_action( 'admin_menu', [ $this, 'add_leads_page' ] );
	}

	/**
	 * Register the Forms CPT in the main Client Sync menu.
	 *
	 * @param string $main_page_slug The slug of the main menu page.
	 */
	public function add_menu_items( $main_page_slug ) {
		add_submenu_page(
			$main_page_slug,
			__( 'Forms', 'client-sync-pro' ),
			__( 'Forms', 'client-sync-pro' ),
			'manage_options',
			'edit.php?post_type=clisyc_form'
		);
	}

	public function add_leads_page() {
		add_submenu_page(
			'edit.php?post_type=clisyc_form',
			__( 'Leads', 'client-sync-pro' ),
			__( 'Leads', 'client-sync-pro' ),
			'manage_options',
			'clisyc-form-leads',
			[ $this, 'render_leads_page' ]
		);
	}

	public function render_leads_page() {
		$list_table = new Leads_List_Table();
		$list_table->prepare_items();
		echo '<div class="wrap"><h1>' . esc_html__( 'Form Leads', 'client-sync-pro' ) . '</h1>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="clisyc-form-leads" />';
		$list_table->search_box( __( 'Search Leads', 'client-sync-pro' ), 'clisyc-leads-search' );
		$list_table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * One-time activation tasks for this module.
	 * This is hooked to the main Pro plugin's activation.
	 */
	public static function activate() {
		// Create the 'Lead' user role if it doesn't exist.
		// Leads have no backend capabilities by default.
		if ( ! get_role( 'clisyc_lead' ) ) {
			add_role( 'clisyc_lead', 'Lead', [ 'read' => false ] );
		}
	}
}