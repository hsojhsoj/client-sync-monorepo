<?php
/**
 * File: src/shared/includes/admin/settings/trait-settings-automation.php
 * Automation settings registration and callbacks (slot auto-generation).
 *
 * Extracted from Settings_Manager to keep each settings group focused.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Settings
 */

namespace DependentMedia\ClientSync\Admin\Settings;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Settings_Automation {

	/**
	 * Register automation-related settings, sections, and fields.
	 */
	protected function register_automation_settings(): void {
		$group = 'clisyc_automation_settings_group';
		$page  = 'clisyc-automation';

		register_setting( $group, Constants::OPTION_AUTO_GEN_ENABLED, [ 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ] );
		register_setting( $group, Constants::OPTION_AUTO_GEN_LOOKAHEAD, [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 14 ] );

		add_settings_section( 'clisyc_automation_slot_generation_section', '', [ $this, 'render_automation_section_header' ], $page );
		add_settings_field( Constants::OPTION_AUTO_GEN_ENABLED, __( 'Enable Auto-Generation', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_automation_slot_generation_section', [ 'id' => Constants::OPTION_AUTO_GEN_ENABLED, 'type' => 'checkbox', 'desc' => __( 'Automatically generate future available time slots based on the schedule of your primary dimension.', 'client-sync' ) ] );
		add_settings_field( Constants::OPTION_AUTO_GEN_LOOKAHEAD, __( 'Generation Lookahead', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_automation_slot_generation_section', [ 'id' => Constants::OPTION_AUTO_GEN_LOOKAHEAD, 'type' => 'number', 'class' => 'small-text', 'desc' => __( 'days. How many days in advance should slots be generated?', 'client-sync' ), 'default' => 14 ] );
	}

	// -----------------------------------------------------------------
	// Section Callbacks
	// -----------------------------------------------------------------

	public function render_automation_section_header() {
		echo '<p>' . esc_html__( 'Configure automatic slot generation based on your defined schedules.', 'client-sync' ) . '</p>';
	}
}
