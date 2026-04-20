<?php
/**
 * File: src/pro/includes/modules/seat-selection/class-checkin-page.php
 * Dedicated admin check-in page for venue ticket scanning.
 *
 * Registers a submenu page under Client Sync that provides:
 * - Search by name, email, or appointment ID
 * - Date and venue/service filters
 * - QR code scanner (camera-based)
 * - One-click check-in for each appointment
 *
 * Designed for on-site tablet use at venues.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Seat_Selection
 */

namespace ClientSyncPro\Modules\Seat_Selection;

use DependentMedia\ClientSync\Constants;
use DependentMedia\ClientSync\Core\Table_Schema_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Checkin_Page {

	/**
	 * @var Table_Schema_Manager
	 */
	private $schema;

	public function __construct( Table_Schema_Manager $schema = null ) {
		$this->schema = $schema ?: new Table_Schema_Manager();
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'clisyc_register_extra_submenu_items', [ $this, 'add_menu_item' ] );
		add_action( 'wp_ajax_clisyc_checkin_search', [ $this, 'ajax_search' ] );
		add_action( 'wp_ajax_clisyc_checkin_confirm', [ $this, 'ajax_confirm' ] );
		add_action( 'wp_ajax_clisyc_checkin_by_token', [ $this, 'ajax_checkin_by_token' ] );
		add_action( 'wp_ajax_clisyc_checkin_stats', [ $this, 'ajax_stats' ] );
		add_action( 'wp_ajax_clisyc_checkin_bulk', [ $this, 'ajax_bulk_checkin' ] );
		add_action( 'wp_ajax_clisyc_checkin_undo', [ $this, 'ajax_undo_checkin' ] );
		add_action( 'wp_ajax_clisyc_checkin_events', [ $this, 'ajax_events_for_date' ] );
	}

	/**
	 * Add the Check-In submenu item.
	 *
	 * @param string $parent_slug Parent menu slug.
	 */
	public function add_menu_item( string $parent_slug ): void {
		$page_hook = add_submenu_page(
			$parent_slug,
			__( 'Check-In', 'client-sync-pro' ),
			__( 'Check-In', 'client-sync-pro' ),
			'edit_posts',
			'clisyc-checkin',
			[ $this, 'render_page' ]
		);

		add_action( 'admin_print_styles-' . $page_hook, [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue page-specific assets.
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style(
			'clisyc-checkin-page',
			clisyc_PLUGIN_URL . 'assets/css/clisyc-checkin-page.css',
			[ 'dashicons' ],
			defined( 'CLISYC_VERSION' ) ? CLISYC_VERSION : '1.0.0'
		);

		// Admin check-in scanner script (html5-qrcode).
		$asset_file = CLISYC_SHARED_DIR . '../assets/dist/admin-checkin/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : [ 'dependencies' => [], 'version' => '1.0.0' ];
		wp_enqueue_script(
			'clisyc-admin-checkin',
			clisyc_PLUGIN_URL . 'assets/dist/admin-checkin/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script( 'clisyc-admin-checkin', 'clisycCheckin', [
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'searchNonce'   => wp_create_nonce( 'clisyc_checkin_search' ),
			'confirmNonce'  => wp_create_nonce( 'clisyc_checkin_confirm' ),
			'tokenNonce'    => wp_create_nonce( 'clisyc_checkin_by_token' ),
			'statsNonce'    => wp_create_nonce( 'clisyc_checkin_stats' ),
			'bulkNonce'     => wp_create_nonce( 'clisyc_checkin_bulk' ),
			'undoNonce'     => wp_create_nonce( 'clisyc_checkin_undo' ),
			'eventsNonce'   => wp_create_nonce( 'clisyc_checkin_events' ),
			'strings'       => [
				'scanning'        => __( 'Scanning…', 'client-sync-pro' ),
				'checkedIn'       => __( 'Checked In', 'client-sync-pro' ),
				'checkIn'         => __( 'Check In', 'client-sync-pro' ),
				'checking'        => __( 'Checking…', 'client-sync-pro' ),
				'noResults'       => __( 'No appointments found.', 'client-sync-pro' ),
				'scanSuccess'     => __( 'Scan successful!', 'client-sync-pro' ),
				'scanError'       => __( 'Could not read QR code.', 'client-sync-pro' ),
				'invalidQr'       => __( 'This QR code is not a valid check-in ticket.', 'client-sync-pro' ),
				'alreadyChecked'  => __( 'Already checked in.', 'client-sync-pro' ),
				'error'           => __( 'An error occurred.', 'client-sync-pro' ),
				'startCamera'     => __( 'Start Camera', 'client-sync-pro' ),
				'stopCamera'      => __( 'Stop Camera', 'client-sync-pro' ),
				'checkInSelected' => __( 'Check In Selected', 'client-sync-pro' ),
				'exportCsv'       => __( 'Export CSV', 'client-sync-pro' ),
				'seats'           => __( 'seats', 'client-sync-pro' ),
				'seat'            => __( 'seat', 'client-sync-pro' ),
				'name'            => __( 'Name', 'client-sync-pro' ),
				'time'            => __( 'Time', 'client-sync-pro' ),
				'status'          => __( 'Status', 'client-sync-pro' ),
				'loadMore'        => __( 'Load More', 'client-sync-pro' ),
				'loading'         => __( 'Loading…', 'client-sync-pro' ),
				'undoCheckIn'     => __( 'Undo Check-In', 'client-sync-pro' ),
				'undoing'         => __( 'Undoing…', 'client-sync-pro' ),
				'undone'          => __( 'Check-in reversed.', 'client-sync-pro' ),
				'allEvents'       => __( 'All Events / Services', 'client-sync-pro' ),
				'startsIn'        => __( 'Starts in', 'client-sync-pro' ),
				'started'         => __( 'Started', 'client-sync-pro' ),
				'ago'             => __( 'ago', 'client-sync-pro' ),
				'now'             => __( 'Now', 'client-sync-pro' ),
			],
		] );
	}

	/**
	 * Render the check-in admin page.
	 */
	public function render_page(): void {
		$today = wp_date( 'Y-m-d' );
		?>
		<div class="wrap clisyc-checkin-wrap">
			<div class="clisyc-checkin-header">
				<h1 class="wp-heading-inline">
					<span class="dashicons dashicons-tickets-alt" style="font-size:28px;width:28px;height:28px;margin-right:8px;vertical-align:middle;"></span>
					<?php esc_html_e( 'Check-In', 'client-sync-pro' ); ?>
				</h1>
				<div id="clisyc-live-clock" class="clisyc-live-clock"></div>
			</div>

			<!-- Event Info Banner (populated by JS when an event is selected) -->
			<div id="clisyc-event-info" class="clisyc-event-info" style="display:none;"></div>

			<!-- Stats Bar -->
			<div id="clisyc-checkin-stats" class="clisyc-checkin-stats" style="display:none;">
				<div class="clisyc-stats-cards">
					<div class="clisyc-stats-card clisyc-stats-card--checked-in">
						<span class="clisyc-stats-card__value" id="clisyc-stat-checked-in">0</span>
						<span class="clisyc-stats-card__label"><?php esc_html_e( 'Checked In', 'client-sync-pro' ); ?></span>
					</div>
					<div class="clisyc-stats-card clisyc-stats-card--expected">
						<span class="clisyc-stats-card__value" id="clisyc-stat-expected">0</span>
						<span class="clisyc-stats-card__label"><?php esc_html_e( 'Expected', 'client-sync-pro' ); ?></span>
					</div>
					<div class="clisyc-stats-card clisyc-stats-card--remaining">
						<span class="clisyc-stats-card__value" id="clisyc-stat-remaining">0</span>
						<span class="clisyc-stats-card__label"><?php esc_html_e( 'Remaining', 'client-sync-pro' ); ?></span>
					</div>
					<div class="clisyc-stats-card clisyc-stats-card--rate">
						<span class="clisyc-stats-card__value" id="clisyc-stat-rate">0</span>
						<span class="clisyc-stats-card__label"><?php esc_html_e( 'Per Hour', 'client-sync-pro' ); ?></span>
					</div>
				</div>
				<div class="clisyc-stats-progress">
					<div class="clisyc-stats-progress__bar" id="clisyc-stat-progress" style="width:0%;"></div>
				</div>
			</div>

			<!-- Scanner Section -->
			<div class="clisyc-checkin-scanner-section">
				<button type="button" id="clisyc-scanner-toggle" class="clisyc-checkin-scanner-btn">
					<span class="dashicons dashicons-camera"></span>
					<?php esc_html_e( 'Scan QR Code', 'client-sync-pro' ); ?>
				</button>
				<div id="clisyc-scanner-container" class="clisyc-scanner-container" style="display:none;">
					<div id="clisyc-scanner-reader" class="clisyc-scanner-reader"></div>
					<div id="clisyc-scanner-result" class="clisyc-scanner-result" style="display:none;"></div>
				</div>
			</div>

			<!-- Search & Filters -->
			<div class="clisyc-checkin-filters">
				<div class="clisyc-checkin-search-row">
					<input type="text" id="clisyc-checkin-search" class="clisyc-checkin-search-input"
						   placeholder="<?php esc_attr_e( 'Search by name, email, or ID…', 'client-sync-pro' ); ?>"
						   autocomplete="off">
					<input type="date" id="clisyc-checkin-date" class="clisyc-checkin-date-input"
						   value="<?php echo esc_attr( $today ); ?>">
					<select id="clisyc-checkin-event-filter" class="clisyc-checkin-event-filter">
						<option value=""><?php esc_html_e( 'All Events / Services', 'client-sync-pro' ); ?></option>
					</select>
					<button type="button" id="clisyc-checkin-search-btn" class="button button-primary clisyc-checkin-search-btn">
						<span class="dashicons dashicons-search"></span>
						<?php esc_html_e( 'Search', 'client-sync-pro' ); ?>
					</button>
				</div>
			</div>

			<!-- View Toggle & Bulk Actions Toolbar -->
			<div id="clisyc-checkin-toolbar" class="clisyc-checkin-toolbar">
				<div class="clisyc-checkin-toolbar__views">
					<button type="button" class="clisyc-view-toggle clisyc-view-toggle--active" data-view="cards" title="<?php esc_attr_e( 'Card View', 'client-sync-pro' ); ?>">
						<span class="dashicons dashicons-grid-view"></span>
					</button>
					<button type="button" class="clisyc-view-toggle" data-view="list" title="<?php esc_attr_e( 'Door List', 'client-sync-pro' ); ?>">
						<span class="dashicons dashicons-list-view"></span>
					</button>
				</div>
				<div class="clisyc-checkin-toolbar__bulk" id="clisyc-bulk-actions" style="display:none;">
					<button type="button" id="clisyc-bulk-checkin-btn" class="button clisyc-bulk-btn clisyc-bulk-btn--checkin" disabled>
						<span class="dashicons dashicons-yes-alt"></span>
						<?php esc_html_e( 'Check In Selected', 'client-sync-pro' ); ?>
					</button>
					<button type="button" id="clisyc-export-csv-btn" class="button clisyc-bulk-btn clisyc-bulk-btn--csv">
						<span class="dashicons dashicons-download"></span>
						<?php esc_html_e( 'Export CSV', 'client-sync-pro' ); ?>
					</button>
					<button type="button" id="clisyc-print-btn" class="button clisyc-bulk-btn clisyc-bulk-btn--print">
						<span class="dashicons dashicons-printer"></span>
						<?php esc_html_e( 'Print', 'client-sync-pro' ); ?>
					</button>
				</div>
			</div>

			<!-- Results -->
			<div id="clisyc-checkin-results" class="clisyc-checkin-results">
				<p class="clisyc-checkin-placeholder">
					<?php esc_html_e( 'Search for appointments or scan a QR code to begin.', 'client-sync-pro' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Search appointments for check-in.
	 */
	public function ajax_search(): void {
		check_ajax_referer( 'clisyc_checkin_search', 'nonce' );

		// All check-in AJAX handlers in this file use the manager-view
		// capability (default `edit_others_posts`) rather than `edit_posts`,
		// because they list / mutate appointment data across every client in
		// the practice. `edit_posts` would also let Contributors through,
		// which would expose client identities site-wide.
		if ( ! current_user_can( apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' ) ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync-pro' ) ] );
		}

		$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$date     = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$page     = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$per_page = 50;

		// If an event filter is selected, restrict results to matching appointment IDs.
		$filtered_ids = null;
		if ( $event_id && ! empty( $date ) ) {
			$filtered_ids = $this->get_filtered_appointment_ids( $date, $event_id );
			if ( empty( $filtered_ids ) ) {
				wp_send_json_success( [
					'appointments' => [],
					'page'         => 1,
					'total_pages'  => 0,
					'total'        => 0,
					'has_more'     => false,
				] );
				return;
			}
		}

		global $wpdb;

		$query_args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => [
				'publish',
				Constants::STATUS_CONFIRMED,
				Constants::STATUS_PAID_ON_DAY,
				Constants::STATUS_PENDING_PAYMENT,
				Constants::STATUS_CHECKED_IN,
			],
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'meta_value',
			'meta_key'       => Constants::META_TIME_SLOT,
			'order'          => 'ASC',
		];

		$meta_query = [];

		// Date filter: match appointments on the given date.
		if ( ! empty( $date ) ) {
			try {
				$start_utc = ( new \DateTime( $date . ' 00:00:00', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
				$end_utc   = ( new \DateTime( $date . ' 23:59:59', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
				$meta_query[] = [
					'key'     => Constants::META_TIME_SLOT,
					'value'   => [ $start_utc->format( 'Y-m-d H:i:s' ), $end_utc->format( 'Y-m-d H:i:s' ) ],
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				];
			} catch ( \Exception $e ) {
				// Invalid date — skip filter.
			}
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		// Text search: search by name (author display_name), email, or appointment ID.
		if ( ! empty( $search ) ) {
			if ( is_numeric( $search ) ) {
				// Search by appointment ID directly.
				$query_args['post__in'] = [ absint( $search ) ];
			} else {
				// Search by author name or email.
				$users = get_users( [
					'search'         => '*' . $search . '*',
					'search_columns' => [ 'display_name', 'user_email', 'user_login' ],
					'number'         => 50,
					'fields'         => 'ID',
				] );

				if ( ! empty( $users ) ) {
					$query_args['author__in'] = $users;
				} else {
					// No matching users — return empty.
					wp_send_json_success( [ 'appointments' => [] ] );
				}
			}
		}

		// Apply event filter: intersect with already-determined post__in (from ID search).
		if ( is_array( $filtered_ids ) ) {
			if ( isset( $query_args['post__in'] ) ) {
				$query_args['post__in'] = array_intersect( $query_args['post__in'], $filtered_ids );
				if ( empty( $query_args['post__in'] ) ) {
					wp_send_json_success( [
						'appointments' => [],
						'page'         => 1,
						'total_pages'  => 0,
						'total'        => 0,
						'has_more'     => false,
					] );
					return;
				}
			} else {
				$query_args['post__in'] = $filtered_ids;
			}
		}

		$query = new \WP_Query( $query_args );
		$results = [];

		foreach ( $query->posts as $post ) {
			$results[] = $this->format_appointment_for_response( $post );
		}

		$total_pages = (int) $query->max_num_pages;

		wp_send_json_success( [
			'appointments' => $results,
			'page'         => $page,
			'total_pages'  => $total_pages,
			'total'        => (int) $query->found_posts,
			'has_more'     => $page < $total_pages,
		] );
	}

	/**
	 * AJAX: Confirm check-in for an appointment (from the check-in page).
	 */
	public function ajax_confirm(): void {
		check_ajax_referer( 'clisyc_checkin_confirm', 'nonce' );

		if ( ! current_user_can( apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' ) ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync-pro' ) ] );
		}

		$appointment_id = isset( $_POST['appointment_id'] ) ? absint( $_POST['appointment_id'] ) : 0;
		if ( ! $appointment_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid appointment.', 'client-sync-pro' ) ] );
		}

		$post = get_post( $appointment_id );
		if ( ! $post || Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
			wp_send_json_error( [ 'message' => __( 'Appointment not found.', 'client-sync-pro' ) ] );
		}

		if ( Constants::STATUS_CHECKED_IN === $post->post_status ) {
			$checked_in_at = get_post_meta( $appointment_id, Constants::META_CHECKED_IN_AT, true );
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: check-in time */
					__( 'Already checked in at %s.', 'client-sync-pro' ),
					$checked_in_at ? wp_date( get_option( 'time_format' ), strtotime( $checked_in_at ) ) : '—'
				),
			] );
		}

		$update_result = wp_update_post( [
			'ID'          => $appointment_id,
			'post_status' => Constants::STATUS_CHECKED_IN,
		] );

		if ( is_wp_error( $update_result ) ) {
			wp_send_json_error( [ 'message' => __( 'Failed to check in.', 'client-sync-pro' ) ] );
		}

		$now = current_time( 'mysql' );
		update_post_meta( $appointment_id, Constants::META_CHECKED_IN_AT, $now );

		wp_send_json_success( [
			'message'       => __( 'Checked in successfully!', 'client-sync-pro' ),
			'appointment'   => $this->format_appointment_for_response( get_post( $appointment_id ) ),
			'checked_in_at' => wp_date( get_option( 'time_format' ), strtotime( $now ) ),
		] );
	}

	/**
	 * AJAX: Check in via QR token (scanned from QR code).
	 */
	public function ajax_checkin_by_token(): void {
		check_ajax_referer( 'clisyc_checkin_by_token', 'nonce' );

		if ( ! current_user_can( apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' ) ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync-pro' ) ] );
		}

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( empty( $token ) ) {
			wp_send_json_error( [ 'message' => __( 'No check-in token provided.', 'client-sync-pro' ) ] );
		}

		// Find appointment by QR token.
		$query = new \WP_Query( [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_query'     => [
				[
					'key'   => Constants::META_QR_TOKEN,
					'value' => $token,
				],
			],
		] );

		if ( ! $query->have_posts() ) {
			wp_send_json_error( [ 'message' => __( 'No appointment found for this QR code.', 'client-sync-pro' ) ] );
		}

		$post = $query->posts[0];

		// Already checked in?
		if ( Constants::STATUS_CHECKED_IN === $post->post_status ) {
			$checked_in_at = get_post_meta( $post->ID, Constants::META_CHECKED_IN_AT, true );
			wp_send_json_error( [
				'message'     => sprintf(
					/* translators: %s: check-in time */
					__( 'Already checked in at %s.', 'client-sync-pro' ),
					$checked_in_at ? wp_date( get_option( 'time_format' ), strtotime( $checked_in_at ) ) : '—'
				),
				'appointment' => $this->format_appointment_for_response( $post ),
				'already_checked_in' => true,
			] );
		}

		// Check status is eligible.
		$eligible = [ 'publish', Constants::STATUS_CONFIRMED, Constants::STATUS_PAID_ON_DAY, Constants::STATUS_PENDING_PAYMENT ];
		if ( ! in_array( $post->post_status, $eligible, true ) ) {
			wp_send_json_error( [
				'message'     => sprintf(
					/* translators: %s: current status label */
					__( 'Cannot check in — appointment status is "%s".', 'client-sync-pro' ),
					get_post_status_object( $post->post_status )->label ?? $post->post_status
				),
				'appointment' => $this->format_appointment_for_response( $post ),
			] );
		}

		$update_result = wp_update_post( [
			'ID'          => $post->ID,
			'post_status' => Constants::STATUS_CHECKED_IN,
		] );

		if ( is_wp_error( $update_result ) ) {
			wp_send_json_error( [ 'message' => __( 'Failed to check in.', 'client-sync-pro' ) ] );
		}

		$now = current_time( 'mysql' );
		update_post_meta( $post->ID, Constants::META_CHECKED_IN_AT, $now );

		wp_send_json_success( [
			'message'       => __( 'Checked in successfully!', 'client-sync-pro' ),
			'appointment'   => $this->format_appointment_for_response( get_post( $post->ID ) ),
			'checked_in_at' => wp_date( get_option( 'time_format' ), strtotime( $now ) ),
		] );
	}

	/**
	 * AJAX: Return check-in stats for a given date.
	 *
	 * Returns { checked_in, total, remaining, percentage, rate_per_hour }.
	 */
	public function ajax_stats(): void {
		check_ajax_referer( 'clisyc_checkin_stats', 'nonce' );

		if ( ! current_user_can( apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' ) ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync-pro' ) ] );
		}

		$date     = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : wp_date( 'Y-m-d' );
		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;

		// Build date range in UTC for the query.
		try {
			$start_utc = ( new \DateTime( $date . ' 00:00:00', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
			$end_utc   = ( new \DateTime( $date . ' 23:59:59', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => __( 'Invalid date.', 'client-sync-pro' ) ] );
			return;
		}

		// If event filter is active, restrict to matching appointment IDs.
		$filtered_ids = null;
		if ( $event_id ) {
			$filtered_ids = $this->get_filtered_appointment_ids( $date, $event_id );
		}

		// All "expected" statuses — everyone who should be coming or has checked in.
		$expected_statuses = [
			'publish',
			Constants::STATUS_CONFIRMED,
			Constants::STATUS_PAID_ON_DAY,
			Constants::STATUS_PENDING_PAYMENT,
			Constants::STATUS_CHECKED_IN,
		];

		$stats_query_args = [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => $expected_statuses,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'     => Constants::META_TIME_SLOT,
					'value'   => [ $start_utc->format( 'Y-m-d H:i:s' ), $end_utc->format( 'Y-m-d H:i:s' ) ],
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				],
			],
		];

		if ( is_array( $filtered_ids ) ) {
			if ( empty( $filtered_ids ) ) {
				wp_send_json_success( [
					'checked_in'    => 0,
					'total'         => 0,
					'remaining'     => 0,
					'percentage'    => 0,
					'rate_per_hour' => 0,
				] );
				return;
			}
			$stats_query_args['post__in'] = $filtered_ids;
		}

		$query = new \WP_Query( $stats_query_args );

		$total      = $query->found_posts;
		$checked_in = 0;

		// Count checked-in and calculate rate per hour.
		$recent_count = 0;
		$one_hour_ago = time() - 3600;

		foreach ( $query->posts as $post_id ) {
			$status = get_post_status( $post_id );
			if ( Constants::STATUS_CHECKED_IN === $status ) {
				$checked_in++;

				// Count check-ins in the last hour for rate calculation.
				$ts = get_post_meta( $post_id, Constants::META_CHECKED_IN_AT, true );
				if ( ! empty( $ts ) && strtotime( $ts ) >= $one_hour_ago ) {
					$recent_count++;
				}
			}
		}

		$remaining  = max( 0, $total - $checked_in );
		$percentage = $total > 0 ? round( ( $checked_in / $total ) * 100 ) : 0;

		wp_send_json_success( [
			'checked_in'    => $checked_in,
			'total'         => $total,
			'remaining'     => $remaining,
			'percentage'    => $percentage,
			'rate_per_hour' => $recent_count,
		] );
	}

	/**
	 * AJAX: Bulk check-in multiple appointments.
	 *
	 * Accepts a JSON-encoded array of appointment IDs, checks in eligible ones.
	 */
	public function ajax_bulk_checkin(): void {
		check_ajax_referer( 'clisyc_checkin_bulk', 'nonce' );

		if ( ! current_user_can( apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' ) ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync-pro' ) ] );
		}

		$raw_ids = isset( $_POST['appointment_ids'] ) ? wp_unslash( $_POST['appointment_ids'] ) : '';
		$ids     = json_decode( $raw_ids, true );

		if ( ! is_array( $ids ) || empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'No appointments selected.', 'client-sync-pro' ) ] );
		}

		$eligible_statuses = [
			'publish',
			Constants::STATUS_CONFIRMED,
			Constants::STATUS_PAID_ON_DAY,
			Constants::STATUS_PENDING_PAYMENT,
		];

		$success_count = 0;
		$now           = current_time( 'mysql' );

		foreach ( $ids as $id ) {
			$id   = absint( $id );
			$post = get_post( $id );

			if ( ! $post || Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
				continue;
			}

			if ( ! in_array( $post->post_status, $eligible_statuses, true ) ) {
				continue;
			}

			$result = wp_update_post( [
				'ID'          => $id,
				'post_status' => Constants::STATUS_CHECKED_IN,
			] );

			if ( ! is_wp_error( $result ) ) {
				update_post_meta( $id, Constants::META_CHECKED_IN_AT, $now );
				$success_count++;
			}
		}

		wp_send_json_success( [
			'message' => sprintf(
				/* translators: %d: number of guests checked in */
				_n(
					'%d guest checked in successfully.',
					'%d guests checked in successfully.',
					$success_count,
					'client-sync-pro'
				),
				$success_count
			),
			'checked_in_count' => $success_count,
		] );
	}

	/**
	 * AJAX: Undo / reverse a check-in, restoring the appointment to Confirmed.
	 */
	public function ajax_undo_checkin(): void {
		check_ajax_referer( 'clisyc_checkin_undo', 'nonce' );

		if ( ! current_user_can( apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' ) ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync-pro' ) ] );
		}

		$appointment_id = isset( $_POST['appointment_id'] ) ? absint( $_POST['appointment_id'] ) : 0;
		if ( ! $appointment_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid appointment.', 'client-sync-pro' ) ] );
		}

		$post = get_post( $appointment_id );
		if ( ! $post || Constants::POST_TYPE_APPOINTMENT !== $post->post_type ) {
			wp_send_json_error( [ 'message' => __( 'Appointment not found.', 'client-sync-pro' ) ] );
		}

		if ( Constants::STATUS_CHECKED_IN !== $post->post_status ) {
			wp_send_json_error( [
				'message' => __( 'This appointment is not currently checked in.', 'client-sync-pro' ),
			] );
		}

		$update_result = wp_update_post( [
			'ID'          => $appointment_id,
			'post_status' => Constants::STATUS_CONFIRMED,
		] );

		if ( is_wp_error( $update_result ) ) {
			wp_send_json_error( [ 'message' => __( 'Failed to undo check-in.', 'client-sync-pro' ) ] );
		}

		delete_post_meta( $appointment_id, Constants::META_CHECKED_IN_AT );

		wp_send_json_success( [
			'message'     => __( 'Check-in reversed.', 'client-sync-pro' ),
			'appointment' => $this->format_appointment_for_response( get_post( $appointment_id ) ),
		] );
	}

	/**
	 * AJAX: Return events / primary dimension items that have appointments on a date.
	 *
	 * Used to populate the event filter dropdown and event info banner.
	 */
	public function ajax_events_for_date(): void {
		check_ajax_referer( 'clisyc_checkin_events', 'nonce' );

		if ( ! current_user_can( apply_filters( 'clisyc_manager_view_capability', 'edit_others_posts' ) ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'client-sync-pro' ) ] );
		}

		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : wp_date( 'Y-m-d' );
		$primary_slug = $this->get_primary_dimension_slug();

		if ( ! $primary_slug ) {
			wp_send_json_success( [ 'events' => [] ] );
			return;
		}

		// Get all appointment IDs for this date.
		try {
			$start_utc = ( new \DateTime( $date . ' 00:00:00', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
			$end_utc   = ( new \DateTime( $date . ' 23:59:59', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			wp_send_json_success( [ 'events' => [] ] );
			return;
		}

		$expected_statuses = [
			'publish',
			Constants::STATUS_CONFIRMED,
			Constants::STATUS_PAID_ON_DAY,
			Constants::STATUS_PENDING_PAYMENT,
			Constants::STATUS_CHECKED_IN,
		];

		$query = new \WP_Query( [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => $expected_statuses,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'     => Constants::META_TIME_SLOT,
					'value'   => [ $start_utc->format( 'Y-m-d H:i:s' ), $end_utc->format( 'Y-m-d H:i:s' ) ],
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				],
			],
		] );

		// Collect unique primary dimension item IDs with their earliest time slot.
		$event_map = []; // item_id => [ 'count' => N, 'earliest_utc' => '...' ]

		foreach ( $query->posts as $post_id ) {
			$dims = get_post_meta( $post_id, Constants::META_SLOT_DIMENSIONS, true );
			if ( ! is_array( $dims ) || empty( $dims[ $primary_slug ] ) ) {
				continue;
			}

			$item_id  = (int) $dims[ $primary_slug ];
			$time_utc = get_post_meta( $post_id, Constants::META_TIME_SLOT, true );

			if ( ! isset( $event_map[ $item_id ] ) ) {
				$event_map[ $item_id ] = [
					'count'        => 0,
					'earliest_utc' => $time_utc,
				];
			}

			$event_map[ $item_id ]['count']++;

			if ( $time_utc < $event_map[ $item_id ]['earliest_utc'] ) {
				$event_map[ $item_id ]['earliest_utc'] = $time_utc;
			}
		}

		$events = [];
		foreach ( $event_map as $item_id => $data ) {
			$title = get_the_title( $item_id );
			if ( ! $title ) {
				continue;
			}

			// Get venue name if linked.
			$venue_id   = (int) get_post_meta( $item_id, '_clisyc_linked_venue', true );
			$venue_name = $venue_id ? get_the_title( $venue_id ) : '';

			// Convert earliest time to local display.
			$time_display = '';
			$time_iso     = '';
			if ( ! empty( $data['earliest_utc'] ) ) {
				try {
					$dt = new \DateTime( $data['earliest_utc'], new \DateTimeZone( 'UTC' ) );
					$dt->setTimezone( wp_timezone() );
					$time_display = wp_date( get_option( 'time_format' ), $dt->getTimestamp() );
					$time_iso     = $dt->format( 'c' ); // ISO 8601 for JS countdown.
				} catch ( \Exception $e ) {
					// Skip.
				}
			}

			$events[] = [
				'id'         => $item_id,
				'title'      => $title,
				'venue'      => $venue_name,
				'count'      => $data['count'],
				'time'       => $time_display,
				'time_iso'   => $time_iso,
			];
		}

		// Sort by earliest time.
		usort( $events, function ( $a, $b ) {
			return strcmp( $a['time_iso'], $b['time_iso'] );
		} );

		wp_send_json_success( [ 'events' => $events ] );
	}

	// =========================================================================
	// Private Helpers
	// =========================================================================

	/**
	 * Get the primary dimension slug from the dimension registry.
	 *
	 * @return string|null
	 */
	private function get_primary_dimension_slug(): ?string {
		$registry = get_option( Constants::OPTION_DIMENSION_REGISTRY, [] );
		if ( ! empty( $registry['dimensions'] ) ) {
			foreach ( $registry['dimensions'] as $slug => $settings ) {
				if ( ! empty( $settings['enabled'] ) && ! empty( $settings['primary'] ) ) {
					return $slug;
				}
			}
		}
		return null;
	}

	/**
	 * Get the appointment IDs for a date + optional event filter.
	 * Returns an array of post IDs, or null if no event filter is applied.
	 *
	 * @param string $date      Date in Y-m-d format.
	 * @param int    $event_id  Primary dimension item ID (0 = no filter).
	 * @return int[]|null       Post IDs to restrict to, or null for no restriction.
	 */
	private function get_filtered_appointment_ids( string $date, int $event_id ): ?array {
		if ( ! $event_id ) {
			return null;
		}

		$primary_slug = $this->get_primary_dimension_slug();
		if ( ! $primary_slug ) {
			return null;
		}

		try {
			$start_utc = ( new \DateTime( $date . ' 00:00:00', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
			$end_utc   = ( new \DateTime( $date . ' 23:59:59', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return null;
		}

		$expected_statuses = [
			'publish',
			Constants::STATUS_CONFIRMED,
			Constants::STATUS_PAID_ON_DAY,
			Constants::STATUS_PENDING_PAYMENT,
			Constants::STATUS_CHECKED_IN,
		];

		$query = new \WP_Query( [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'post_status'    => $expected_statuses,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'     => Constants::META_TIME_SLOT,
					'value'   => [ $start_utc->format( 'Y-m-d H:i:s' ), $end_utc->format( 'Y-m-d H:i:s' ) ],
					'compare' => 'BETWEEN',
					'type'    => 'DATETIME',
				],
			],
		] );

		$filtered_ids = [];
		foreach ( $query->posts as $post_id ) {
			$dims = get_post_meta( $post_id, Constants::META_SLOT_DIMENSIONS, true );
			if ( is_array( $dims ) && isset( $dims[ $primary_slug ] ) && (int) $dims[ $primary_slug ] === $event_id ) {
				$filtered_ids[] = $post_id;
			}
		}

		return $filtered_ids;
	}

	/**
	 * Format an appointment post for the check-in page response.
	 *
	 * @param \WP_Post $post
	 * @return array
	 */
	private function format_appointment_for_response( \WP_Post $post ): array {
		$author = get_userdata( $post->post_author );

		// Time info.
		$time_slot = get_post_meta( $post->ID, Constants::META_TIME_SLOT, true );
		$time_display = '—';
		$date_display = '—';
		if ( ! empty( $time_slot ) ) {
			try {
				$dt = new \DateTime( $time_slot, new \DateTimeZone( 'UTC' ) );
				$dt->setTimezone( wp_timezone() );
				$ts = $dt->getTimestamp();
				$date_display = wp_date( get_option( 'date_format' ), $ts );
				$time_display = wp_date( get_option( 'time_format' ), $ts );
			} catch ( \Exception $e ) {
				// Invalid time slot.
			}
		}

		// Seats info.
		$seat_ids = get_post_meta( $post->ID, Constants::META_SELECTED_SEATS, true );
		$seat_count = is_array( $seat_ids ) ? count( $seat_ids ) : 0;
		$seat_details = apply_filters( 'clisyc_appointment_seat_details', [], $post->ID );

		// Status.
		$status_obj = get_post_status_object( $post->post_status );
		$checked_in_at = get_post_meta( $post->ID, Constants::META_CHECKED_IN_AT, true );

		// Event / service name from primary dimension.
		$event_name = '';
		$primary_slug = $this->get_primary_dimension_slug();
		if ( $primary_slug ) {
			$dims = get_post_meta( $post->ID, Constants::META_SLOT_DIMENSIONS, true );
			if ( is_array( $dims ) && ! empty( $dims[ $primary_slug ] ) ) {
				$event_name = get_the_title( (int) $dims[ $primary_slug ] );
			}
		}

		return [
			'id'             => $post->ID,
			'title'          => get_the_title( $post->ID ),
			'event_name'     => $event_name,
			'client_name'    => $author ? $author->display_name : __( 'Unknown', 'client-sync-pro' ),
			'client_email'   => $author ? $author->user_email : '',
			'date'           => $date_display,
			'time'           => $time_display,
			'status'         => $post->post_status,
			'status_label'   => $status_obj ? $status_obj->label : $post->post_status,
			'is_checked_in'  => Constants::STATUS_CHECKED_IN === $post->post_status,
			'checked_in_at'  => $checked_in_at ? wp_date( get_option( 'time_format' ), strtotime( $checked_in_at ) ) : '',
			'seat_count'     => $seat_count,
			'seat_details'   => $seat_details,
		];
	}
}
