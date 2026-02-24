<?php
/**
 * File: src/shared/includes/admin/settings/trait-settings-appearance.php
 * Appearance settings registration and callbacks (calendar display, colors, text size).
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

trait Settings_Appearance {

	/**
	 * Register appearance-related settings, sections, and fields.
	 */
	protected function register_appearance_settings(): void {
		$group = 'clisyc_appearance_settings_group';
		$page  = 'clisyc-appearance-settings';

		register_setting( $group, Constants::OPTION_CALENDAR_START_TIME, 'sanitize_text_field' );
		register_setting( $group, Constants::OPTION_CALENDAR_END_TIME, 'sanitize_text_field' );
		register_setting( $group, Constants::OPTION_CALENDAR_ENABLED_VIEWS, [ $this, 'sanitize_enabled_views_setting' ] );
		register_setting( $group, Constants::OPTION_FRONTEND_CALENDAR_STYLE, [ $this, 'sanitize_frontend_calendar_style' ] );
		register_setting( $group, Constants::OPTION_CALENDAR_COLOR_SETTINGS, [ $this, 'sanitize_color_settings' ] );
		register_setting( $group, Constants::OPTION_CALENDAR_SLOT_HEIGHT, [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '80' ] );

		// --- Calendar Display ---
		add_settings_section( 'clisyc_appearance_calendar_display_section', __( 'Calendar Display', 'client-sync' ), [ $this, 'render_calendar_display_section_header' ], $page );
		add_settings_field( Constants::OPTION_CALENDAR_START_TIME, __( 'Calendar Start Time', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_appearance_calendar_display_section', ['id' => Constants::OPTION_CALENDAR_START_TIME, 'type' => 'time', 'default' => '08:00'] );
		add_settings_field( Constants::OPTION_CALENDAR_END_TIME, __( 'Calendar End Time', 'client-sync' ), [ $this, 'render_settings_field_callback' ], $page, 'clisyc_appearance_calendar_display_section', ['id' => Constants::OPTION_CALENDAR_END_TIME, 'type' => 'time', 'default' => '18:00'] );
		add_settings_field( 'clisyc_calendar_visibility_check', __( 'Visibility Check', 'client-sync' ), [ $this, 'render_visibility_check_button' ], $page, 'clisyc_appearance_calendar_display_section' );
		add_settings_field( Constants::OPTION_CALENDAR_ENABLED_VIEWS, __( 'Enabled Calendar Views', 'client-sync' ), [ $this, 'render_calendar_views_field' ], $page, 'clisyc_appearance_calendar_display_section' );
		add_settings_field( Constants::OPTION_FRONTEND_CALENDAR_STYLE, __( 'Default Initial View', 'client-sync' ), [ $this, 'render_initial_view_field' ], $page, 'clisyc_appearance_calendar_display_section' );
		add_settings_field(
			Constants::OPTION_CALENDAR_SLOT_HEIGHT,
			__( 'Calendar Slot Height', 'client-sync' ),
			[ $this, 'render_slot_height_field' ],
			$page,
			'clisyc_appearance_calendar_display_section'
		);

		// --- Calendar Event Colors ---
		add_settings_section( 'clisyc_appearance_colors_section', __( 'Calendar Event Colors', 'client-sync' ), [ $this, 'render_colors_section_header' ], $page );

		$slot_color_fields = [
			'available_bg'   => __( 'Available Slot Background', 'client-sync' ),
			'available_text' => __( 'Available Slot Text', 'client-sync' ),
			'booked_bg'      => __( 'Booked Slot Background', 'client-sync' ),
			'booked_text'    => __( 'Booked Slot Text', 'client-sync' ),
			'blocked_bg'     => __( 'Blocked Slot Background', 'client-sync' ),
			'blocked_text'   => __( 'Blocked Slot Text', 'client-sync' ),
		];
		foreach ( $slot_color_fields as $key => $label ) {
			add_settings_field( 'clisyc_calendar_color_settings[' . $key . ']', $label, [ $this, 'render_color_picker_field' ], $page, 'clisyc_appearance_colors_section', [ 'id' => 'clisyc_calendar_color_settings[' . $key . ']', 'option_name' => Constants::OPTION_CALENDAR_COLOR_SETTINGS, 'path' => [ $key ], ] );
		}

		// --- UI Accent Colors ---
		add_settings_section( 'clisyc_appearance_accent_section', __( 'UI Accent Colors', 'client-sync' ), [ $this, 'render_accent_section_header' ], $page );

		$accent_color_fields = [
			'accent_normal_bg'   => __( 'Button Background', 'client-sync' ),
			'accent_normal_text' => __( 'Button Text', 'client-sync' ),
			'accent_hover_bg'    => __( 'Button Hover Background', 'client-sync' ),
			'accent_hover_text'  => __( 'Button Hover Text', 'client-sync' ),
			'icon_bg'            => __( 'Icon Background', 'client-sync' ),
			'icon_text'          => __( 'Icon Color', 'client-sync' ),
		];
		foreach ( $accent_color_fields as $key => $label ) {
			add_settings_field( 'clisyc_calendar_color_settings[' . $key . ']', $label, [ $this, 'render_color_picker_field' ], $page, 'clisyc_appearance_accent_section', [ 'id' => 'clisyc_calendar_color_settings[' . $key . ']', 'option_name' => Constants::OPTION_CALENDAR_COLOR_SETTINGS, 'path' => [ $key ], ] );
		}

		// --- Text Size ---
		add_settings_section( 'clisyc_appearance_text_size_section', __( 'Text Size', 'client-sync' ), [ $this, 'render_text_size_section_header' ], $page );
		add_settings_field( 'clisyc_calendar_color_settings[text_size]', __( 'Calendar Text Size', 'client-sync' ), [ $this, 'render_text_size_field' ], $page, 'clisyc_appearance_text_size_section', [ 'id' => 'clisyc_calendar_color_settings[text_size]', 'option_name' => Constants::OPTION_CALENDAR_COLOR_SETTINGS, 'path' => [ 'text_size' ], ] );
	}

	// -----------------------------------------------------------------
	// Section Callbacks
	// -----------------------------------------------------------------

	public function render_calendar_display_section_header() {
		echo '<p>' . esc_html__( 'Configure how the booking calendar appears to your visitors.', 'client-sync' ) . '</p>';
	}

	public function render_colors_section_header() {
		echo '<p>' . esc_html__( 'Customize the colors used to display calendar events. Leave empty to use defaults.', 'client-sync' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Default colors provide a clean, accessible palette. Clear any field to restore its default.', 'client-sync' ) . '</p>';
	}

	public function render_accent_section_header() {
		echo '<p>' . esc_html__( 'Customize buttons, icons, and interactive elements throughout the booking interface.', 'client-sync' ) . '</p>';
	}

	public function render_text_size_section_header() {
		echo '<p>' . esc_html__( 'Adjust the text size throughout the booking interface.', 'client-sync' ) . '</p>';
	}

	// -----------------------------------------------------------------
	// Field Callbacks
	// -----------------------------------------------------------------

	public function render_color_picker_field( $args ) {
		$id          = $args['id'] ?? '';
		$option_name = $args['option_name'] ?? '';
		$path        = $args['path'] ?? [];
		$defaults    = self::get_default_color_settings();

		$option_value = get_option( $option_name, [] );
		$value = $option_value;
		foreach ( $path as $key ) {
			$value = $value[ $key ] ?? '';
		}

		$default_for_key = $defaults[ $path[0] ] ?? '#000000';
		$placeholder = $default_for_key;

		if ( empty( $value ) ) {
			$value = $default_for_key;
		}

		printf(
			'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="clisyc-color-picker" data-default-color="%4$s" placeholder="%5$s" />',
			esc_attr( $id ),
			esc_attr( $option_name . '[' . implode( '][', $path ) . ']' ),
			esc_attr( $value ),
			esc_attr( $default_for_key ),
			esc_attr( $placeholder )
		);
		echo '<p class="description">' . esc_html__( 'Leave empty to use default.', 'client-sync' ) . '</p>';
	}

	public function render_text_size_field( $args ) {
		$id          = $args['id'] ?? '';
		$option_name = $args['option_name'] ?? '';
		$path        = $args['path'] ?? [];

		$option_value = get_option( $option_name, [] );
		$value = 'medium';
		foreach ( $path as $key ) {
			if ( isset( $option_value[ $key ] ) ) {
				$value = $option_value[ $key ];
			}
		}

		$sizes = [
			'small'   => __( 'Small', 'client-sync' ),
			'medium'  => __( 'Medium (Default)', 'client-sync' ),
			'large'   => __( 'Large', 'client-sync' ),
			'x-large' => __( 'Extra Large', 'client-sync' ),
		];

		printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $option_name . '[' . implode( '][', $path ) . ']' ) );
		foreach ( $sizes as $size_value => $size_label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $size_value ), selected( $value, $size_value, false ), esc_html( $size_label ) );
		}
		echo '</select>';
	}

	public function render_slot_height_field() {
		$current_value = get_option( Constants::OPTION_CALENDAR_SLOT_HEIGHT, '80' );

		$height_options = [
			'48'  => __( 'Compact (48px/hour)', 'client-sync' ),
			'60'  => __( 'Small (60px/hour)', 'client-sync' ),
			'80'  => __( 'Medium (80px/hour) - Default', 'client-sync' ),
			'100' => __( 'Large (100px/hour)', 'client-sync' ),
			'120' => __( 'Extra Large (120px/hour)', 'client-sync' ),
			'150' => __( 'Maximum (150px/hour)', 'client-sync' ),
		];

		echo '<select id="clisyc_calendar_slot_height" name="clisyc_calendar_slot_height">';
		foreach ( $height_options as $height_value => $height_label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $height_value ),
				selected( $current_value, $height_value, false ),
				esc_html( $height_label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Controls the vertical height of time slots in week/day views. Larger values make short appointments easier to see and click.', 'client-sync' ) . '</p>';
	}

	public function render_calendar_views_field() {
		$saved_views = get_option( Constants::OPTION_CALENDAR_ENABLED_VIEWS, [] );
		if ( empty( $saved_views ) ) {
			$saved_views = [ 'dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek' ];
		}

		$all_views = [
			'dayGridMonth' => __( 'Month Grid', 'client-sync' ),
			'dayGridWeek'  => __( 'Week Grid', 'client-sync' ),
			'dayGridDay'   => __( 'Day Grid', 'client-sync' ),
			'timeGridWeek' => __( 'Week Time Grid', 'client-sync' ),
			'timeGridDay'  => __( 'Day Time Grid', 'client-sync' ),
			'listWeek'     => __( 'Week List', 'client-sync' ),
			'listDay'      => __( 'Day List', 'client-sync' ),
			'listMonth'    => __( 'Month List', 'client-sync' ),
		];

		echo '<fieldset>';
		foreach ( $all_views as $view_value => $view_label ) {
			$checked = in_array( $view_value, $saved_views, true ) ? 'checked' : '';
			printf(
				'<label style="display: block; margin-bottom: 5px;"><input type="checkbox" name="clisyc_calendar_enabled_views[]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $view_value ),
				esc_attr( $checked ),
				esc_html( $view_label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Select which calendar views are available to users.', 'client-sync' ) . '</p>';
	}

	public function render_initial_view_field() {
		$style = get_option( Constants::OPTION_FRONTEND_CALENDAR_STYLE, [] );
		$current = $style[ Constants::OPTION_INITIAL_VIEW ] ?? 'timeGridWeek';

		$views = [
			'dayGridMonth' => __( 'Month Grid', 'client-sync' ),
			'dayGridWeek'  => __( 'Week Grid', 'client-sync' ),
			'dayGridDay'   => __( 'Day Grid', 'client-sync' ),
			'timeGridWeek' => __( 'Week Time Grid', 'client-sync' ),
			'timeGridDay'  => __( 'Day Time Grid', 'client-sync' ),
			'listWeek'     => __( 'Week List', 'client-sync' ),
			'listDay'      => __( 'Day List', 'client-sync' ),
			'listMonth'    => __( 'Month List', 'client-sync' ),
		];

		printf( '<select id="%1$s" name="clisyc_frontend_calendar_style[%1$s]">', esc_attr( Constants::OPTION_INITIAL_VIEW ) );
		foreach ( $views as $view_value => $view_label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $view_value ), selected( $current, $view_value, false ), esc_html( $view_label ) );
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The default view when the calendar first loads.', 'client-sync' ) . '</p>';
	}

	public function render_visibility_check_button() {
		echo '<button type="button" id="clisyc-check-slot-visibility" class="button button-secondary">';
		echo '<span class="dashicons dashicons-search" style="vertical-align: middle; margin-right: 4px;"></span>';
		esc_html_e( 'Check for Hidden Slots', 'client-sync' );
		echo '</button>';
		echo '<p class="description">' . esc_html__( 'Scan for time slots that fall outside the visible calendar hours.', 'client-sync' ) . '</p>';
		echo '<div id="clisyc-visibility-results" style="margin-top: 10px;"></div>';
	}

	// -----------------------------------------------------------------
	// Sanitization
	// -----------------------------------------------------------------

	public function sanitize_color_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$sanitized_output = [];
		$allowed_sizes = [ 'small', 'medium', 'large', 'x-large' ];

		foreach ( $input as $key => $value_to_sanitize ) {
			if ( 'text_size' === $key ) {
				$sanitized_output[ $key ] = in_array( $value_to_sanitize, $allowed_sizes, true ) ? $value_to_sanitize : 'medium';
			} else {
				$sanitized = sanitize_hex_color( $value_to_sanitize );
				$sanitized_output[ $key ] = $sanitized ?: '';
			}
		}

		return $sanitized_output;
	}

	public function sanitize_frontend_calendar_style( $input ) {
		$output = [];
		$allowed_views = [
			'dayGridMonth', 'dayGridWeek', 'dayGridDay',
			'timeGridWeek', 'timeGridDay',
			'listWeek', 'listDay', 'listMonth'
		];
		if ( isset( $input[ Constants::OPTION_INITIAL_VIEW ] ) && in_array( $input[ Constants::OPTION_INITIAL_VIEW ], $allowed_views, true ) ) {
			$output[ Constants::OPTION_INITIAL_VIEW ] = $input[ Constants::OPTION_INITIAL_VIEW ];
		} else {
			$output[ Constants::OPTION_INITIAL_VIEW ] = 'timeGridWeek';
		}
		return $output;
	}

	public function sanitize_enabled_views_setting( $input ) {
		if ( ! is_array( $input ) ) {
			return [];
		}
		$allowed_views = [
			'dayGridMonth', 'dayGridWeek', 'dayGridDay',
			'timeGridWeek', 'timeGridDay',
			'listWeek', 'listDay', 'listMonth'
		];
		return array_values( array_filter( $input, function( $view ) use ( $allowed_views ) {
			return in_array( $view, $allowed_views, true );
		} ) );
	}
}
