<?php
/**
 * File: src/shared/includes/admin/class-dashboard-widget.php
 * Registers a compact "Client Sync — Today" widget on the main WordPress Dashboard.
 *
 * Shows at-a-glance stats (today's bookings, upcoming appointments, total clients)
 * and a short list of the next few appointments — all without leaving wp-admin/index.php.
 *
 * @package    ClientSync
 * @subpackage ClientSync/Admin
 */

namespace DependentMedia\ClientSync\Admin;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dashboard_Widget {

	/**
	 * Registers the WordPress Dashboard widget via the wp_dashboard_setup hook.
	 */
	public function register_hooks(): void {
		add_action( 'wp_dashboard_setup', [ $this, 'add_widget' ] );

		// Invalidate the stats transient whenever an appointment is created, updated, or deleted.
		add_action( 'save_post_' . Constants::POST_TYPE_APPOINTMENT, [ $this, 'clear_stats_transient' ] );
		add_action( 'delete_post', [ $this, 'clear_stats_transient_on_delete' ] );
		add_action( 'transition_post_status', [ $this, 'clear_stats_transient_on_status_change' ], 10, 3 );
	}

	/**
	 * Clear stats transient when an appointment is saved.
	 */
	public function clear_stats_transient(): void {
		delete_transient( 'clisyc_dashboard_widget_stats' );
	}

	/**
	 * Clear stats transient when a post is deleted, if it's an appointment.
	 */
	public function clear_stats_transient_on_delete( $post_id ): void {
		if ( get_post_type( $post_id ) === Constants::POST_TYPE_APPOINTMENT ) {
			delete_transient( 'clisyc_dashboard_widget_stats' );
		}
	}

	/**
	 * Clear stats transient when an appointment's status changes.
	 */
	public function clear_stats_transient_on_status_change( $new_status, $old_status, $post ): void {
		if ( $post && $post->post_type === Constants::POST_TYPE_APPOINTMENT && $new_status !== $old_status ) {
			delete_transient( 'clisyc_dashboard_widget_stats' );
		}
	}

	/**
	 * Registers the widget with WordPress.
	 */
	public function add_widget(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'clisyc_dashboard_widget',
			__( 'Client Sync — Today', 'client-sync' ),
			[ $this, 'render' ]
		);
	}

	/**
	 * Renders the widget content.
	 */
	public function render(): void {
		$stats    = $this->get_stats();
		$upcoming = $this->get_upcoming_appointments( 5 );

		// Accent color from calendar settings (consistent with email template).
		$color_settings = get_option( Constants::OPTION_CALENDAR_COLOR_SETTINGS, [] );
		$accent         = $color_settings['available_bg'] ?? '#4f46e5';
		?>
		<style>
			#clisyc_dashboard_widget .inside { padding: 0; margin: 0; }
			.clisyc-dw-stats { display: flex; border-bottom: 1px solid #e5e7eb; }
			.clisyc-dw-stat {
				flex: 1; text-align: center; padding: 14px 8px;
				border-right: 1px solid #e5e7eb;
			}
			.clisyc-dw-stat:last-child { border-right: none; }
			.clisyc-dw-stat .clisyc-dw-num {
				font-size: 26px; font-weight: 700; line-height: 1.2;
				color: <?php echo esc_attr( $accent ); ?>;
			}
			.clisyc-dw-stat .clisyc-dw-label {
				font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em;
				color: #6b7280; margin-top: 2px;
			}
			.clisyc-dw-list { margin: 0; padding: 0; list-style: none; }
			.clisyc-dw-list li {
				padding: 10px 12px; border-bottom: 1px solid #f3f4f6;
				display: flex; justify-content: space-between; align-items: center;
			}
			.clisyc-dw-list li:last-child { border-bottom: none; }
			.clisyc-dw-list .clisyc-dw-appt-title {
				font-weight: 500; color: #111827;
			}
			.clisyc-dw-list .clisyc-dw-appt-meta {
				font-size: 12px; color: #6b7280;
			}
			.clisyc-dw-list .clisyc-dw-appt-time {
				font-size: 12px; color: #6b7280; white-space: nowrap; margin-left: 12px;
			}
			.clisyc-dw-empty {
				padding: 20px 12px; text-align: center; color: #9ca3af; font-style: italic;
			}
			.clisyc-dw-footer {
				padding: 10px 12px; text-align: right; border-top: 1px solid #e5e7eb;
			}
			.clisyc-dw-footer a {
				font-size: 12px; text-decoration: none; font-weight: 500;
			}
		</style>

		<div class="clisyc-dw-stats">
			<div class="clisyc-dw-stat">
				<div class="clisyc-dw-num"><?php echo esc_html( $stats['today'] ); ?></div>
				<div class="clisyc-dw-label"><?php esc_html_e( 'Today', 'client-sync' ); ?></div>
			</div>
			<div class="clisyc-dw-stat">
				<div class="clisyc-dw-num"><?php echo esc_html( $stats['this_week'] ); ?></div>
				<div class="clisyc-dw-label"><?php esc_html_e( 'This Week', 'client-sync' ); ?></div>
			</div>
			<div class="clisyc-dw-stat">
				<div class="clisyc-dw-num"><?php echo esc_html( $stats['upcoming'] ); ?></div>
				<div class="clisyc-dw-label"><?php esc_html_e( 'Upcoming', 'client-sync' ); ?></div>
			</div>
			<div class="clisyc-dw-stat">
				<div class="clisyc-dw-num"><?php echo esc_html( $stats['clients'] ); ?></div>
				<div class="clisyc-dw-label"><?php esc_html_e( 'Clients', 'client-sync' ); ?></div>
			</div>
		</div>

		<?php if ( ! empty( $upcoming ) ) : ?>
			<ul class="clisyc-dw-list">
				<?php foreach ( $upcoming as $appt ) : ?>
					<li>
						<div>
							<div class="clisyc-dw-appt-title">
								<a href="<?php echo esc_url( $appt['edit_link'] ); ?>">
									<?php echo esc_html( $appt['title'] ); ?>
								</a>
							</div>
							<div class="clisyc-dw-appt-meta">
								<?php echo esc_html( $appt['client_name'] ); ?>
							</div>
						</div>
						<div class="clisyc-dw-appt-time">
							<?php echo esc_html( $appt['date_time'] ); ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<div class="clisyc-dw-empty">
				<?php esc_html_e( 'No upcoming appointments.', 'client-sync' ); ?>
			</div>
		<?php endif; ?>

		<div class="clisyc-dw-footer">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-dashboard' ) ); ?>">
				<?php esc_html_e( 'View Full Dashboard', 'client-sync' ); ?> &rarr;
			</a>
		</div>
		<?php
	}

	/**
	 * Gathers quick stats: today, this week, upcoming, total clients.
	 * Cached in a 5-minute transient to avoid running 3+ WP_Query on every admin dashboard load.
	 *
	 * @return array{ today: int, this_week: int, upcoming: int, clients: int }
	 */
	private function get_stats(): array {
		$cached = get_transient( 'clisyc_dashboard_widget_stats' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$today       = wp_date( 'Y-m-d' );
		$week_start  = wp_date( 'Y-m-d', strtotime( 'monday this week' ) );
		$week_end    = wp_date( 'Y-m-d', strtotime( 'sunday this week' ) );
		$statuses    = [ 'publish', Constants::STATUS_CONFIRMED, 'clisyc_paid_on_day', 'wc-completed', 'wc-processing' ];

		$base_args = [
			'post_type'              => Constants::POST_TYPE_APPOINTMENT,
			'post_status'            => $statuses,
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		// Today.
		$today_q = new \WP_Query( array_merge( $base_args, [
			'meta_query' => [ [ 'key' => 'clisyc_appointment_date', 'value' => $today, 'compare' => '=', 'type' => 'DATE' ] ],
		] ) );

		// This week.
		$week_q = new \WP_Query( array_merge( $base_args, [
			'meta_query' => [ [ 'key' => 'clisyc_appointment_date', 'value' => [ $week_start, $week_end ], 'compare' => 'BETWEEN', 'type' => 'DATE' ] ],
		] ) );

		// All upcoming.
		$upcoming_q = new \WP_Query( array_merge( $base_args, [
			'meta_query' => [ [ 'key' => 'clisyc_appointment_date', 'value' => $today, 'compare' => '>=', 'type' => 'DATE' ] ],
		] ) );

		// Total clients.
		$client_roles = apply_filters( 'clisyc_client_user_roles', [ 'subscriber', 'customer' ] );
		$user_count   = count_users();
		$clients      = 0;
		foreach ( $client_roles as $role ) {
			$clients += $user_count['avail_roles'][ $role ] ?? 0;
		}

		$stats = [
			'today'     => $today_q->post_count,
			'this_week' => $week_q->post_count,
			'upcoming'  => $upcoming_q->post_count,
			'clients'   => $clients,
		];

		set_transient( 'clisyc_dashboard_widget_stats', $stats, 5 * MINUTE_IN_SECONDS );

		return $stats;
	}

	/**
	 * Retrieves upcoming appointments (sorted chronologically).
	 *
	 * @param int $limit Max number to return.
	 * @return array<int, array{ title: string, client_name: string, date_time: string, edit_link: string }>
	 */
	private function get_upcoming_appointments( int $limit = 5 ): array {
		$now_utc = current_time( 'mysql', 1 );

		$query = new \WP_Query( [
			'post_type'      => Constants::POST_TYPE_APPOINTMENT,
			'posts_per_page' => $limit,
			'post_status'    => [ 'publish', Constants::STATUS_CONFIRMED, 'clisyc_paid_on_day', 'wc-processing', 'wc-completed' ],
			'meta_key'       => Constants::META_TIME_SLOT,
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [
				[
					'key'     => Constants::META_TIME_SLOT,
					'value'   => $now_utc,
					'compare' => '>=',
					'type'    => 'DATETIME',
				],
			],
		] );

		$appointments = [];
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id   = get_the_ID();
				$client    = get_user_by( 'id', get_post_field( 'post_author' ) );
				$time_slot = get_post_meta( $post_id, Constants::META_TIME_SLOT, true );

				$appointments[] = [
					'title'       => get_the_title(),
					'client_name' => $client ? $client->display_name : __( 'Guest', 'client-sync' ),
					'date_time'   => wp_date( get_option( 'date_format' ) . ' @ ' . get_option( 'time_format' ), strtotime( $time_slot ) ),
					'edit_link'   => get_edit_post_link( $post_id, 'raw' ),
				];
			}
		}
		wp_reset_postdata();

		return $appointments;
	}
}
