<?php
/**
 * File: src/shared/includes/admin/class-settings-manager.php
 * Manages all WordPress Settings API registration and callbacks.
 *
 * This class coordinates settings registration by delegating to focused traits:
 * - Settings_Notifications  — Email/SMS notification templates and reminders
 * - Settings_Appearance     — Calendar display, colors, text size
 * - Settings_Behavior       — Booking rules, links, self-service, HIPAA, spam, waitlist
 * - Settings_Automation     — Slot auto-generation
 * - Settings_Payments       — WooCommerce and Stripe integration
 * - Settings_Integrations   — Google Calendar, Twilio, Zoom, Webhooks
 *
 * Each trait registers its own settings group, sections, fields, and sanitization
 * callbacks. This class provides the shared field renderers used across all groups.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Admin\Settings\Settings_Notifications;
use DependentMedia\ClientSync\Admin\Settings\Settings_Appearance;
use DependentMedia\ClientSync\Admin\Settings\Settings_Behavior;
use DependentMedia\ClientSync\Admin\Settings\Settings_Automation;
use DependentMedia\ClientSync\Admin\Settings\Settings_Payments;
use DependentMedia\ClientSync\Admin\Settings\Settings_Integrations;
use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings_Manager {

	use Settings_Notifications;
	use Settings_Appearance;
	use Settings_Behavior;
	use Settings_Automation;
	use Settings_Payments;
	use Settings_Integrations;

	// =====================================================================
	// Color Settings (static API — used by Frontend and Views)
	// =====================================================================

	/**
	 * Default color palette for calendar styling.
	 * Modern, accessible colors with good contrast ratios.
	 *
	 * @return array
	 */
	public static function get_default_color_settings(): array {
		return [
			// Available slots - Emerald green (success/positive)
			'available_bg'      => '#d1fae5', // emerald-100
			'available_text'    => '#065f46', // emerald-800

			// Booked slots - Red (unavailable/negative)
			'booked_bg'         => '#fee2e2', // red-100
			'booked_text'       => '#991b1b', // red-800

			// Blocked slots - Gray (neutral/inactive)
			'blocked_bg'        => '#e5e7eb', // gray-200
			'blocked_text'      => '#374151', // gray-700

			// Accent colors - Blue (primary action)
			'accent_normal_bg'  => '#3b82f6', // blue-500
			'accent_normal_text'=> '#ffffff', // white
			'accent_hover_bg'   => '#2563eb', // blue-600
			'accent_hover_text' => '#ffffff', // white

			// Icon colors - Light gray background
			'icon_bg'           => '#f3f4f6', // gray-100
			'icon_text'         => '#4b5563', // gray-600

			// Text size scale (small, medium, large, x-large)
			'text_size'         => 'medium',
		];
	}

	/**
	 * Get merged color settings (saved values merged with defaults).
	 *
	 * @return array
	 */
	public static function get_color_settings(): array {
		$defaults = self::get_default_color_settings();
		$saved = get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] );

		$merged = [];
		foreach ( $defaults as $key => $default_value ) {
			$saved_value = $saved[ $key ] ?? '';
			$merged[ $key ] = ! empty( $saved_value ) ? $saved_value : $default_value;
		}

		return $merged;
	}

	// =====================================================================
	// Hook Registration
	// =====================================================================

	public function register_hooks() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Register all settings groups by delegating to focused traits.
	 */
	public function register_settings() {
		if ( ! is_admin() ) {
			return;
		}

		$this->register_notification_settings();
		$this->register_appearance_settings();
		$this->register_behavior_settings();
		$this->register_automation_settings();
		$this->register_payment_settings();
		$this->register_integration_settings();
	}

	// =====================================================================
	// Shared Field Renderers
	// =====================================================================

	/**
	 * Generic settings field renderer.
	 *
	 * Handles text, number, email, url, password, checkbox, select,
	 * textarea, time, and wp_editor field types.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_settings_field_callback( $args ) {
		$id          = $args['id'] ?? '';
		$type        = $args['type'] ?? 'text';
		$class       = $args['class'] ?? '';
		$desc        = $args['desc'] ?? '';
		$default     = $args['default'] ?? '';
		$option_name = $args['option_name'] ?? $id;
		$path        = $args['path'] ?? [];
		$options     = $args['options'] ?? [];

		// Get value.
		if ( ! empty( $path ) ) {
			$option_value = get_option( $option_name, [] );
			$value = $option_value;
			foreach ( $path as $key ) {
				$value = $value[ $key ] ?? $default;
			}
		} else {
			$value = get_option( $id, $default );
		}

		switch ( $type ) {
			case 'checkbox':
				printf(
					'<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s />',
					esc_attr( $id ),
					esc_attr( $option_name . ( ! empty( $path ) ? '[' . implode( '][', $path ) . ']' : '' ) ),
					checked( 1, $value, false )
				);
				break;

			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $option_name . ( ! empty( $path ) ? '[' . implode( '][', $path ) . ']' : '' ) ) );
				foreach ( $options as $opt_value => $opt_label ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $opt_value ), selected( $value, $opt_value, false ), esc_html( $opt_label ) );
				}
				echo '</select>';
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" class="large-text" rows="3">%3$s</textarea>',
					esc_attr( $id ),
					esc_attr( $option_name . ( ! empty( $path ) ? '[' . implode( '][', $path ) . ']' : '' ) ),
					esc_textarea( $value )
				);
				break;

			case 'wp_editor':
				$editor_id = str_replace( [ '[', ']' ], '_', $id );
				wp_editor( $value, $editor_id, [
					'textarea_name' => $option_name . ( ! empty( $path ) ? '[' . implode( '][', $path ) . ']' : '' ),
					'textarea_rows' => 10,
					'media_buttons' => false,
					'teeny'         => true,
				] );
				break;

			default: // text, number, email, url, password, time
				printf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="%5$s" />',
					esc_attr( $type ),
					esc_attr( $id ),
					esc_attr( $option_name . ( ! empty( $path ) ? '[' . implode( '][', $path ) . ']' : '' ) ),
					esc_attr( $value ),
					esc_attr( $class )
				);
				break;
		}

		if ( $desc ) {
			printf( '<p class="description">%s</p>', wp_kses( $desc, [ 'code' => [], 'strong' => [], 'em' => [], 'a' => [ 'href' => [], 'target' => [] ], 'br' => [], 'span' => [ 'id' => [], 'class' => [] ] ] ) );
		}
	}

	/**
	 * Render a WordPress pages dropdown with optional "Create Page" button.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_page_dropdown_field( $args ) {
		$id    = $args['id'] ?? '';
		$desc  = $args['desc'] ?? '';
		$value = get_option( $id, 0 );

		$dropdown = wp_dropdown_pages( [
			'name'              => esc_attr( $id ),
			'id'                => esc_attr( $id ),
			'selected'          => absint( $value ),
			'show_option_none'  => esc_html__( '— Select —', 'client-sync' ),
			'option_none_value' => '0',
			'echo'              => 0,
		] );

		echo '<div class="clisyc-page-dropdown-wrap" style="display: flex; align-items: center; gap: 8px;">';

		echo wp_kses(
			$dropdown,
			[
				'select' => [
					'name' => [],
					'id'   => [],
				],
				'option' => [
					'value'    => [],
					'selected' => [],
					'class'    => [],
				],
			]
		);

		// Show "Create Page" button for settings that have a page definition.
		$page_definitions = \DependentMedia\ClientSync\Admin\Onboarding_Wizard::get_page_definitions();
		$has_definition   = false;
		foreach ( $page_definitions as $def ) {
			if ( ( $def['option_key'] ?? '' ) === $id ) {
				$has_definition = true;
				break;
			}
		}

		if ( $has_definition ) {
			printf(
				'<button type="button" class="button button-secondary clisyc-create-page-btn" data-option-key="%s" data-dropdown-id="%s" title="%s" style="%s">
					<span class="dashicons dashicons-plus-alt2" style="vertical-align: middle; margin-top: -2px;"></span> %s
				</button>',
				esc_attr( $id ),
				esc_attr( $id ),
				esc_attr__( 'Create a new page with the correct shortcode and select it automatically', 'client-sync' ),
				absint( $value ) > 0 ? 'display:none;' : '',
				esc_html__( 'Create Page', 'client-sync' )
			);
		}

		echo '</div>';

		if ( $desc ) {
			printf( '<p class="description">%s</p>', wp_kses( $desc, [ 'code' => [], 'strong' => [], 'em' => [], 'a' => [ 'href' => [], 'target' => [] ] ] ) );
		}
	}
}
