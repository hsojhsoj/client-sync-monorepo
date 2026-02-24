<?php
/**
 * Handles the [clisyc_user_account] shortcode.
 *
 * Renders the user account/profile form.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Integrations\Stripe_Integration;
use DependentMedia\ClientSync\Services\FormRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class User_Account_Shortcode {

	/**
	 * Register the shortcode.
	 */
	public function register() {
		add_shortcode( 'clisyc_user_account', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Render the user account form shortcode.
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

		if ( ! is_user_logged_in() ) {
			/* translators: %s: URL to the login page. */
			$login_text = sprintf( __( 'Please <a href="%s">log in</a> to view and update your account information.', 'client-sync' ), esc_url( wp_login_url( get_permalink() ) ) );
			echo '<p>' . wp_kses( $login_text, [ 'a' => [ 'href' => [] ] ] ) . '</p>';
			return ob_get_clean();
		}

		// START: Add this new block
		$membership_details = null;
		if ( class_exists( '\ClientSyncPro\Services\Membership_Rules_Engine' ) ) {
			$engine             = new \ClientSyncPro\Services\Membership_Rules_Engine();
			$membership_details = $engine->get_user_active_membership_details( get_current_user_id() );
		}
		// END: Add this new block
		$credits = get_user_meta( get_current_user_id(), '_clisyc_credits', true );

		// Generate Stripe Billing Portal URL if the customer has a Stripe Customer ID.
		$billing_portal_url = null;
		if ( Stripe_Integration::is_active() ) {
			$stripe             = new Stripe_Integration();
			$portal_result      = $stripe->create_billing_portal_session( get_current_user_id(), get_permalink() );
			if ( ! is_wp_error( $portal_result ) ) {
				$billing_portal_url = $portal_result['url'];
			}
		}

		if ( class_exists( '\ClientSyncPro\Modules\Interactions\Interactions_Module' ) ) {
			// Pro version logic here...
		} else {
			$form_renderer     = new FormRenderer();
			$user_id           = get_current_user_id();
			$custom_fields     = get_option( Constants::OPTION_CUSTOM_FIELDS, array() );
			if ( ! is_array( $custom_fields ) ) {
				$custom_fields = [];
			}
			$current_user_data = get_userdata( $user_id );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a display-only URL message parameter, not a form submission.
			if ( isset( $_GET['clisyc_message_type'], $_GET['clisyc_message_text'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a display-only URL message parameter, not a form submission.
				$unslashed_get = wp_unslash( $_GET );
				$type          = sanitize_key( $unslashed_get['clisyc_message_type'] );
				$text          = sanitize_textarea_field( urldecode( $unslashed_get['clisyc_message_text'] ) );
				$class         = ( 'error' === $type ) ? 'clisyc-error-message' : ( ( 'success' === $type || 'updated' === $type ) ? 'clisyc-success-message' : 'clisyc-info-message' );
				echo '<div class="clisyc-registration-message ' . esc_attr( $class ) . '"><p>' . wp_kses_post( $text ) . '</p></div>';
			}

			$view_file = clisyc_PLUGIN_DIR . 'includes/frontend/views/view-user-account-form.php';
			if ( file_exists( $view_file ) ) {
				include $view_file;
			} else {
				echo '<p>' . esc_html__( 'Error: User account form view file not found.', 'client-sync' ) . '</p>';
			}
		}

		return ob_get_clean();
	}
}
