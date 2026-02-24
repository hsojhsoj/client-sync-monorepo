<?php
/**
 * File: src/shared/includes/frontend/views/view-staff-dashboard.php
 * View template for the [clisyc_staff_dashboard] shortcode.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Frontend/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Variables passed from Staff_Dashboard_Shortcode::render():
 * @var string $staff_name    Display name of the staff member.
 * @var int    $staff_post_id Personnel dimension post ID.
 * @var array  $today_stats   { total: int, checked_in: int, remaining: int }
 */
?>

<div class="clisyc-staff-dashboard" id="clisyc-staff-dashboard">

	<!-- ── Header & Summary ────────────────────────────────────────── -->
	<div class="clisyc-staff-header">
		<h2 class="clisyc-staff-welcome">
			<?php
			printf(
				/* translators: %s: staff member name */
				esc_html__( 'Welcome, %s', 'client-sync' ),
				'<span>' . esc_html( $staff_name ) . '</span>'
			);
			?>
		</h2>

		<div class="clisyc-today-summary">
			<div class="clisyc-summary-card">
				<span class="clisyc-summary-number" id="clisyc-summary-total"><?php echo esc_html( $today_stats['total'] ); ?></span>
				<span class="clisyc-summary-label"><?php esc_html_e( "Today's Appointments", 'client-sync' ); ?></span>
			</div>
			<div class="clisyc-summary-card clisyc-summary-card--success">
				<span class="clisyc-summary-number" id="clisyc-summary-checked-in"><?php echo esc_html( $today_stats['checked_in'] ); ?></span>
				<span class="clisyc-summary-label"><?php esc_html_e( 'Checked In', 'client-sync' ); ?></span>
			</div>
			<div class="clisyc-summary-card clisyc-summary-card--pending">
				<span class="clisyc-summary-number" id="clisyc-summary-remaining"><?php echo esc_html( $today_stats['remaining'] ); ?></span>
				<span class="clisyc-summary-label"><?php esc_html_e( 'Remaining', 'client-sync' ); ?></span>
			</div>
		</div>
	</div>

	<!-- ── Tabs & Search ───────────────────────────────────────────── -->
	<div class="clisyc-staff-controls">
		<div class="clisyc-staff-tabs">
			<button class="clisyc-staff-tab active" data-status="today">
				<span class="dashicons dashicons-calendar-alt"></span>
				<?php esc_html_e( 'Today', 'client-sync' ); ?>
			</button>
			<button class="clisyc-staff-tab" data-status="upcoming">
				<span class="dashicons dashicons-arrow-right-alt"></span>
				<?php esc_html_e( 'Upcoming', 'client-sync' ); ?>
			</button>
			<button class="clisyc-staff-tab" data-status="past">
				<span class="dashicons dashicons-backup"></span>
				<?php esc_html_e( 'Past', 'client-sync' ); ?>
			</button>
		</div>

		<div class="clisyc-staff-search-wrap">
			<span class="dashicons dashicons-search"></span>
			<input type="text"
				   class="clisyc-staff-search"
				   id="clisyc-staff-search"
				   placeholder="<?php esc_attr_e( 'Search clients…', 'client-sync' ); ?>"
				   autocomplete="off">
		</div>
	</div>

	<!-- ── Appointments Table ──────────────────────────────────────── -->
	<div class="clisyc-staff-table-wrap">
		<div class="clisyc-loading-overlay" style="display:none;">
			<span class="spinner is-active"></span>
		</div>
		<table class="clisyc-staff-appointments-table" id="clisyc-staff-appointments-table">
			<thead><!-- JS-rendered headers --></thead>
			<tbody>
				<tr><td colspan="6"><?php esc_html_e( 'Loading…', 'client-sync' ); ?></td></tr>
			</tbody>
		</table>
		<div class="clisyc-staff-pagination"></div>
	</div>

	<!-- ── Notes Modal ─────────────────────────────────────────────── -->
	<div class="clisyc-staff-notes-modal" id="clisyc-staff-notes-modal" style="display:none;">
		<div class="clisyc-notes-modal-overlay"></div>
		<div class="clisyc-notes-modal-content">
			<div class="clisyc-notes-modal-header">
				<h3><?php esc_html_e( 'Appointment Notes', 'client-sync' ); ?></h3>
				<button class="clisyc-close-notes" type="button" aria-label="<?php esc_attr_e( 'Close', 'client-sync' ); ?>">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</div>
			<div class="clisyc-notes-modal-body">
				<textarea class="clisyc-staff-notes-editor" id="clisyc-staff-notes-editor" rows="8"></textarea>
			</div>
			<div class="clisyc-notes-modal-footer">
				<button class="button clisyc-close-notes" type="button"><?php esc_html_e( 'Cancel', 'client-sync' ); ?></button>
				<button class="button button-primary clisyc-save-notes" type="button" id="clisyc-save-notes">
					<span class="dashicons dashicons-yes"></span>
					<?php esc_html_e( 'Save Notes', 'client-sync' ); ?>
				</button>
			</div>
			<input type="hidden" id="clisyc-notes-appointment-id" value="">
		</div>
	</div>

</div>
