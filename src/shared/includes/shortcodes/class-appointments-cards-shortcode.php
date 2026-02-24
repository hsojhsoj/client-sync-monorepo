<?php
/**
 * Handles the [clisyc_appointments_cards] shortcode.
 *
 * Renders a card-based view of user appointments.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Appointments_Cards_Shortcode {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Constructor.
	 *
	 * @param string $version Plugin version.
	 */
	public function __construct( string $version ) {
		$this->version = $version;
	}

	/**
	 * Register the shortcode.
	 */
	public function register() {
		add_shortcode( 'clisyc_appointments_cards', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Render the appointments cards shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="clisyc-login-required"><p>' . esc_html__( 'Please log in to view your appointments.', 'client-sync' ) . '</p></div>';
		}

		$atts = shortcode_atts(
			[
				'title'       => __( 'Your Appointments', 'client-sync' ),
				'show_search' => 'true',
				'show_filter' => 'true',
			],
			$atts,
			'clisyc_appointments_cards'
		);

		// Enqueue the required assets for this shortcode.
		wp_enqueue_style(
			'clisyc-appointments-cards',
			clisyc_PLUGIN_URL . 'assets/css/clisyc-appointments-cards.css',
			[ 'dashicons' ],
			$this->version
		);

		$color_settings = get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] );
		$css_variables  = [];
		if ( ! empty( $color_settings['accent_normal_bg'] ) ) {
			$css_variables[] = '--clisyc-accent-normal-bg: ' . esc_attr( $color_settings['accent_normal_bg'] );
			$css_variables[] = '--clisyc-accent-normal-border: ' . esc_attr( $color_settings['accent_normal_bg'] );
		}
		if ( ! empty( $color_settings['accent_normal_text'] ) ) {
			$css_variables[] = '--clisyc-accent-normal-text: ' . esc_attr( $color_settings['accent_normal_text'] );
		}
		if ( ! empty( $color_settings['accent_hover_bg'] ) ) {
			$css_variables[] = '--clisyc-accent-hover-bg: ' . esc_attr( $color_settings['accent_hover_bg'] );
		}
		if ( ! empty( $color_settings['accent_hover_text'] ) ) {
			$css_variables[] = '--clisyc-accent-hover-text: ' . esc_attr( $color_settings['accent_hover_text'] );
		}
		if ( ! empty( $color_settings['icon_bg'] ) ) {
			$css_variables[] = '--clisyc-icon-bg: ' . esc_attr( $color_settings['icon_bg'] );
		}
		if ( ! empty( $color_settings['icon_text'] ) ) {
			$css_variables[] = '--clisyc-icon-text: ' . esc_attr( $color_settings['icon_text'] );
		}

		if ( ! empty( $css_variables ) ) {
			$inline_style = '#clisyc-appointments-cards-dashboard {' . implode( ';', $css_variables ) . ';}';
			wp_add_inline_style( 'clisyc-appointments-cards', $inline_style );
		}

		wp_enqueue_script(
			'clisyc-appointments-cards',
			clisyc_PLUGIN_URL . 'assets/js/clisyc-appointments-cards.js',
			[ 'jquery' ],
			$this->version,
			true
		);

		wp_localize_script(
			'clisyc-appointments-cards',
			'clisycAppointmentsCardsData',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'clisyc_appointments_cards_nonce' ),
				'userId'  => get_current_user_id(),
			]
		);

		ob_start();
		$template_path = clisyc_PLUGIN_DIR . 'includes/frontend/views/view-appointments-cards.php';
		if ( file_exists( $template_path ) ) {
			// Pass attributes to the template file.
			include $template_path;
		}
		return ob_get_clean();
	}
}
