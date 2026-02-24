<?php
/**
 * Handles the [clisyc_registration] shortcode.
 *
 * Renders the user registration form with custom fields.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Services\FormRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Registration_Shortcode {

	/**
	 * Register the shortcode.
	 */
	public function register() {
		add_shortcode( 'clisyc_registration', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Render the registration form shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		// --- START: NEW ASSET ENQUEUEING BLOCK ---
		wp_enqueue_style( 'clisyc-frontend-style' );
		wp_enqueue_style( 'clisyc-image-marker' );
		wp_enqueue_script( 'clisyc-frontend-image-marker' );
		// --- END: NEW ASSET ENQUEUEING BLOCK ---

		ob_start();

		if ( is_user_logged_in() ) {
			echo '<p>' . esc_html__( 'You are already registered and logged in.', 'client-sync' ) . '</p>';
			return ob_get_clean();
		}
		if ( ! get_option( 'users_can_register' ) ) {
			echo '<p>' . esc_html__( 'User registration is currently not allowed.', 'client-sync' ) . '</p>';
			return ob_get_clean();
		}

		if ( $errors = get_transient( 'clisyc_registration_errors' ) ) {
			echo '<div class="clisyc-registration-message clisyc-error-message">';
			foreach ( $errors as $error_message ) {
				echo '<p>' . wp_kses_post( $error_message ) . '</p>';
			}
			echo '</div>';
			delete_transient( 'clisyc_registration_errors' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['registration'] ) && 'success' === $_GET['registration'] ) {
			echo '<div class="clisyc-registration-message clisyc-success-message"><p>' . esc_html__( 'Registration successful! You can now log in.', 'client-sync' ) . '</p></div>';
		}

		$form_renderer = new FormRenderer();
		$custom_fields = get_option( Constants::OPTION_CUSTOM_FIELDS, [] );
		$field_order   = get_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, array_keys( $custom_fields ) );
		$field_order   = is_array( $field_order ) ? array_intersect( $field_order, array_keys( $custom_fields ) ) : [];
		foreach ( array_keys( $custom_fields ) as $key ) {
			if ( ! in_array( $key, $field_order ) ) {
				$field_order[] = $key;
			}
		}
		$posted_data = get_transient( 'clisyc_registration_posted_data' ) ?: [];
		delete_transient( 'clisyc_registration_posted_data' );

		$view_file = clisyc_PLUGIN_DIR . 'includes/frontend/views/view-registration-form.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			echo '<p>' . esc_html__( 'Error: Registration form view file not found.', 'client-sync' ) . '</p>';
		}

		return ob_get_clean();
	}
}
