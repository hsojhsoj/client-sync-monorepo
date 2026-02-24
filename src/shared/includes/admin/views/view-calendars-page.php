<?php
/**
 * File: src/shared/includes/admin/views/view-calendars-page.php -> client-sync/includes/admin/views/view-calendars-page.php
 * View for the main "Calendars & Schedules" admin page.
 *
 * This file renders the tabbed interface for the Appointment Calendar,
 * Manage Time Slots, and other schedule-related pages.
 * REFACTORED: Prefixed variables to comply with WordPress standards.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin/Views
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Prepare data for the view ---
// *** REFACTORED ***: Updated page slug to use the new prefix.
$clisyc_calendars_page_slug = 'clisyc-calendars';
$clisyc_default_tab         = 'appointment-calendar';

// Reading a navigational URL parameter is safe here, but it must be sanitized.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$clisyc_current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : $clisyc_default_tab;

$clisyc_tabs        = [
	'appointment-calendar' => __( 'Appointment Calendar', 'client-sync' ), // FIXED
	'time-slots'           => __( 'Manage Time Slots', 'client-sync' ), // FIXED
	'blocked-periods'      => __( 'Global Blocked Periods', 'client-sync' ), // FIXED
];

if ( ! array_key_exists( $clisyc_current_tab, $clisyc_tabs ) ) {
	$clisyc_current_tab = $clisyc_default_tab;
}

?>
<div class="wrap">
	<h1><?php esc_html_e( 'Calendars & Schedules', 'client-sync' ); ?></h1>
	<?php
	// *** REFACTORED ***: Updated transient names to use the new prefix.
	$clisyc_feedback_schedule_display = get_transient( 'clisyc_standard_schedule_feedback' );
	$clisyc_feedback_manage_slots     = get_transient( 'clisyc_manage_slots_feedback' );
	$clisyc_feedback_blocked_periods  = get_transient( 'clisyc_blocked_periods_feedback' );

	if ( $clisyc_feedback_schedule_display ) {
		$clisyc_notice_class = ! empty( $clisyc_feedback_schedule_display['success'] ) ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $clisyc_notice_class ) . ' is-dismissible"><p>' . wp_kses_post( $clisyc_feedback_schedule_display['message'] ) . '</p></div>';
		delete_transient( 'clisyc_standard_schedule_feedback' );
	}
	if ( $clisyc_feedback_manage_slots ) {
		$clisyc_notice_class   = ! empty( $clisyc_feedback_manage_slots['error'] ) ? 'notice-error' : 'notice-success';
		$clisyc_notice_message = $clisyc_feedback_manage_slots['error'] ?? ( $clisyc_feedback_manage_slots['message'] ?? '' );
		if ( $clisyc_notice_message ) {
			echo '<div class="notice ' . esc_attr( $clisyc_notice_class ) . ' is-dismissible"><p>' . wp_kses_post( $clisyc_notice_message ) . '</p></div>';
		}
		delete_transient( 'clisyc_manage_slots_feedback' );
	}
	if ( $clisyc_feedback_blocked_periods ) {
		$clisyc_notice_class   = ! empty( $clisyc_feedback_blocked_periods['error'] ) ? 'notice-error' : 'notice-success';
		$clisyc_notice_message = $clisyc_feedback_blocked_periods['error'] ?? ( $clisyc_feedback_blocked_periods['message'] ?? '' );
		if ( $clisyc_notice_message ) {
			echo '<div class="notice ' . esc_attr( $clisyc_notice_class ) . ' is-dismissible"><p>' . wp_kses_post( $clisyc_notice_message ) . '</p></div>';
		}
		delete_transient( 'clisyc_blocked_periods_feedback' );
	}
	settings_errors();
	?>

	<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Secondary menu for Calendars', 'client-sync' ); // FIXED ?>">
		<?php
		foreach ( $clisyc_tabs as $clisyc_tab_slug => $clisyc_tab_name ) :
			$clisyc_active_class = ( $clisyc_tab_slug === $clisyc_current_tab ) ? ' nav-tab-active' : '';
			$clisyc_tab_url      = add_query_arg(
				[
					'page' => $clisyc_calendars_page_slug, // Use refactored slug
					'tab'  => $clisyc_tab_slug,
				],
				admin_url( 'admin.php' )
			);
			?>
			<a href="<?php echo esc_url( $clisyc_tab_url ); ?>" class="nav-tab<?php echo esc_attr( $clisyc_active_class ); ?>"><?php echo esc_html( $clisyc_tab_name ); ?></a>
		<?php endforeach; ?>
	</nav>
	
	<div class="tab-content" style="margin-top: 1em;">
		<?php
		// Define the paths to the template parts. These filenames don't need to change.
		// *** REFACTORED ***: Use the new clisyc_PLUGIN_DIR constant.
		$clisyc_template_parts = [
			'appointment-calendar' => clisyc_PLUGIN_DIR . 'includes/admin/views/template-parts/part-appointment-calendar.php',
			'time-slots'           => clisyc_PLUGIN_DIR . 'includes/admin/views/template-parts/part-manage-time-slots.php',
			'blocked-periods'      => clisyc_PLUGIN_DIR . 'includes/admin/views/template-parts/part-blocked-periods.php',
		];

		$clisyc_template_to_load = $clisyc_template_parts[ $clisyc_current_tab ] ?? $clisyc_template_parts[ $clisyc_default_tab ];

		if ( file_exists( $clisyc_template_to_load ) ) {
			include $clisyc_template_to_load;
		} else {
			echo '<div class="notice notice-error"><p>';
			/* translators: 1: The name of the admin tab (e.g., 'appointment-calendar'), 2: The expected file path for the template. */
			$clisyc_error_string = esc_html__( 'Error: The view for the selected tab (%1$s) could not be found. Expected file at: %2$s', 'client-sync' ); // FIXED
			printf(
				wp_kses(
					$clisyc_error_string,
					[
						'strong' => [],
						'code'   => [],
					]
				),
				'<strong>' . esc_html( $clisyc_current_tab ) . '</strong>',
				'<code>' . esc_html( $clisyc_template_to_load ) . '</code>'
			);
			echo '</p></div>';
		}
		?>
	</div>
</div>