<?php
/**
 * File: src/shared/includes/shortcodes/class-staff-dashboard-shortcode.php
 * Handles the [clisyc_staff_dashboard] shortcode.
 *
 * Renders a frontend dashboard for staff/practitioner members to view
 * their own appointments, check-in clients, and edit notes.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Shortcodes
 */

namespace DependentMedia\ClientSync\Shortcodes;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Services\Staff_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Staff_Dashboard_Shortcode {

	use Shortcode_Helpers;

	/**
	 * @var string Plugin name.
	 */
	private $plugin_name;

	/**
	 * @var string Plugin version.
	 */
	private $version;

	/**
	 * @param string $plugin_name Plugin name.
	 * @param string $version     Plugin version.
	 */
	public function __construct( string $plugin_name, string $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register the shortcode.
	 */
	public function register() {
		add_shortcode( 'clisyc_staff_dashboard', [ $this, 'render' ] );
	}

	/**
	 * Render the staff dashboard shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render( $atts ) {
		$atts = shortcode_atts( [], $atts, 'clisyc_staff_dashboard' );

		ob_start();

		// ── Auth check ──────────────────────────────────────────────────
		if ( ! is_user_logged_in() ) {
			$login_url = get_option( Constants::OPTION_LOGIN_PAGE_URL, '' );
			if ( empty( $login_url ) ) {
				$login_url = wp_login_url( get_permalink() );
			}
			echo '<div class="clisyc-staff-dashboard clisyc-staff-dashboard--login">';
			echo '<p>' . esc_html__( 'Please log in to access your staff dashboard.', 'client-sync' ) . '</p>';
			echo '<a href="' . esc_url( $login_url ) . '" class="button">' . esc_html__( 'Log In', 'client-sync' ) . '</a>';
			echo '</div>';
			return ob_get_clean();
		}

		// ── Staff resolution ────────────────────────────────────────────
		$staff_post_id = Staff_Resolver::get_staff_post_id();
		if ( ! $staff_post_id ) {
			echo '<div class="clisyc-staff-dashboard clisyc-staff-dashboard--not-staff">';
			echo '<p>' . esc_html__( 'Your account is not linked to a staff profile. Please contact an administrator.', 'client-sync' ) . '</p>';
			echo '</div>';
			return ob_get_clean();
		}

		$staff_name = Staff_Resolver::get_staff_display_name( $staff_post_id );

		// ── Today's summary (server-rendered) ───────────────────────────
		$today_stats = $this->get_today_stats( $staff_post_id );

		// ── Enqueue assets ──────────────────────────────────────────────
		wp_enqueue_style( 'clisyc-frontend-style' );
		wp_enqueue_style( 'clisyc-staff-dashboard' );
		wp_enqueue_script( 'clisyc-staff-dashboard' );

		wp_localize_script( 'clisyc-staff-dashboard', 'clisycStaffDashboardData', [
			'ajaxurl'             => admin_url( 'admin-ajax.php' ),
			'staff_id'            => $staff_post_id,
			'list_nonce'          => wp_create_nonce( 'clisyc_get_manager_appointments_list_nonce' ),
			'status_nonce'        => wp_create_nonce( 'clisyc_staff_update_status_nonce' ),
			'notes_nonce'         => wp_create_nonce( 'clisyc_staff_notes_nonce' ),
			'l10n'                => [
				'colClient'       => __( 'Client', 'client-sync' ),
				'colService'      => __( 'Service', 'client-sync' ),
				'colDate'         => __( 'Date', 'client-sync' ),
				'colTime'         => __( 'Time', 'client-sync' ),
				'colStatus'       => __( 'Status', 'client-sync' ),
				'colActions'      => __( 'Actions', 'client-sync' ),
				'noAppointments'  => __( 'No appointments found.', 'client-sync' ),
				'error'           => __( 'An error occurred.', 'client-sync' ),
				'serverError'     => __( 'An unexpected server error occurred.', 'client-sync' ),
				'checkIn'         => __( 'Check In', 'client-sync' ),
				'noShow'          => __( 'Mark No-Show', 'client-sync' ),
				'editNotes'       => __( 'Notes', 'client-sync' ),
				'confirmCheckIn'  => __( 'Mark this appointment as checked in?', 'client-sync' ),
				'confirmNoShow'   => __( 'Mark this appointment as no-show?', 'client-sync' ),
				'saveNotes'       => __( 'Save Notes', 'client-sync' ),
				'cancel'          => __( 'Cancel', 'client-sync' ),
				'notesSaved'      => __( 'Notes saved successfully.', 'client-sync' ),
				'notesSaveFailed' => __( 'Failed to save notes.', 'client-sync' ),
				'loading'         => __( 'Loading…', 'client-sync' ),
			],
		] );

		// ── Include view template ───────────────────────────────────────
		$template_file = CLISYC_SHARED_DIR . 'includes/frontend/views/view-staff-dashboard.php';
		if ( file_exists( $template_file ) ) {
			include $template_file;
		}

		return ob_get_clean();
	}

	/**
	 * Get today's appointment statistics for a staff member.
	 *
	 * @param int $staff_post_id Personnel dimension post ID.
	 * @return array { total: int, checked_in: int, remaining: int }
	 */
	private function get_today_stats( int $staff_post_id ): array {
		$personnel_slug = get_option( Constants::OPTION_PERSONNEL_DIM_SLUG, '' );

		if ( empty( $personnel_slug ) ) {
			return [ 'total' => 0, 'checked_in' => 0, 'remaining' => 0 ];
		}

		$site_tz     = wp_timezone();
		$today_start = ( new \DateTime( 'today', $site_tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$today_end   = ( new \DateTime( 'tomorrow', $site_tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		global $wpdb;
		$dim_like = $wpdb->esc_like( '"' . $personnel_slug . '";i:' . $staff_post_id );

		$query = new \WP_Query( [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => [ 'publish', 'confirmed', Constants::STATUS_PENDING_PAYMENT, 'draft' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'     => Constants::META_TIME_SLOT,
					'value'   => [ $today_start, $today_end ],
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				],
				[
					'key'     => 'clisyc_slot_dimensions',
					'value'   => $dim_like,
					'compare' => 'LIKE',
				],
			],
		] );

		$total      = count( $query->posts );
		$checked_in = 0;

		foreach ( $query->posts as $post_id ) {
			$post_status = get_post_status( $post_id );
			// 'publish' = checked in, 'draft' = completed
			if ( 'publish' === $post_status || 'draft' === $post_status ) {
				$checked_in++;
			}
		}

		return [
			'total'      => $total,
			'checked_in' => $checked_in,
			'remaining'  => max( 0, $total - $checked_in ),
		];
	}
}
