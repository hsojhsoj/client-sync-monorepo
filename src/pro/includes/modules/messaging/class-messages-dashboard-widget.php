<?php
/**
 * File: src/pro/includes/modules/messaging/class-messages-dashboard-widget.php
 * WordPress admin dashboard widget showing recent messages and unread count.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Messages_Dashboard_Widget {

	/** @var Message_Repository */
	private $repo;

	public function __construct( Message_Repository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'wp_dashboard_setup', [ $this, 'register_widget' ] );
	}

	/**
	 * Register the dashboard widget.
	 */
	public function register_widget(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$unread_count = $this->repo->get_unread_count( get_current_user_id() );
		$title        = __( 'Client Messages', 'client-sync-pro' );

		if ( $unread_count > 0 ) {
			$title .= sprintf(
				' <span class="clisyc-unread-badge">%d</span>',
				$unread_count
			);
		}

		wp_add_dashboard_widget(
			'clisyc_messages_widget',
			$title,
			[ $this, 'render_widget' ]
		);
	}

	/**
	 * Render the widget content.
	 */
	public function render_widget(): void {
		$messages = $this->repo->get_recent_messages( 5 );

		if ( empty( $messages ) ) {
			echo '<p>' . esc_html__( 'No messages yet.', 'client-sync-pro' ) . '</p>';
			return;
		}

		echo '<div class="clisyc-dashboard-messages">';

		foreach ( $messages as $msg ) {
			$sender    = get_userdata( (int) $msg->sender_id );
			$client    = get_userdata( (int) $msg->client_id );
			$is_unread = ! (bool) $msg->is_read && (int) $msg->recipient_id === get_current_user_id();

			$preview = ! empty( $msg->body ) ? wp_trim_words( $msg->body, 12, '…' ) : '';

			$thread_url = admin_url( 'admin.php?page=clisyc-clients&thread_id=' . $msg->thread_id );
			if ( $msg->client_id ) {
				$thread_url .= '&client_id=' . $msg->client_id;
			}
			?>
			<div class="clisyc-dashboard-message <?php echo $is_unread ? 'clisyc-unread' : ''; ?>">
				<div class="clisyc-dashboard-message-header">
					<?php echo get_avatar( (int) $msg->sender_id, 28 ); ?>
					<div class="clisyc-dashboard-message-meta">
						<strong><?php echo esc_html( $sender ? $sender->display_name : __( 'Unknown', 'client-sync-pro' ) ); ?></strong>
						<?php if ( $client && (int) $msg->sender_id !== (int) $msg->client_id ) : ?>
							<span class="clisyc-dashboard-message-client">
								→ <?php echo esc_html( $client->display_name ); ?>
							</span>
						<?php endif; ?>
						<time datetime="<?php echo esc_attr( $msg->created_at ); ?>" class="clisyc-dashboard-message-time">
							<?php echo esc_html( $this->format_relative_time( $msg->created_at ) ); ?>
						</time>
					</div>
					<?php if ( $is_unread ) : ?>
						<span class="clisyc-unread-dot" title="<?php esc_attr_e( 'Unread', 'client-sync-pro' ); ?>"></span>
					<?php endif; ?>
				</div>
				<?php if ( $preview ) : ?>
					<p class="clisyc-dashboard-message-preview">
						<a href="<?php echo esc_url( $thread_url ); ?>"><?php echo esc_html( $preview ); ?></a>
					</p>
				<?php endif; ?>
			</div>
			<?php
		}

		echo '</div>';

		// Queue summary — show awaiting reply count.
		$awaiting = $this->repo->get_awaiting_reply_count();
		if ( $awaiting > 0 ) {
			$queue_url = admin_url( 'admin.php?page=clisyc-message-queue' );
			printf(
				'<div class="clisyc-dashboard-queue-summary">'
				. '<span class="dashicons dashicons-warning"></span> '
				. '<span>%s</span> '
				. '<a href="%s">%s &rarr;</a>'
				. '</div>',
				sprintf(
					/* translators: %d: number of threads awaiting reply */
					esc_html( _n(
						'%d thread awaiting reply',
						'%d threads awaiting reply',
						$awaiting,
						'client-sync-pro'
					) ),
					$awaiting
				),
				esc_url( $queue_url ),
				esc_html__( 'Message Queue', 'client-sync-pro' )
			);
		}

		echo '<p class="clisyc-dashboard-messages-footer">';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-clients' ) ) . '" class="button button-secondary">';
		echo esc_html__( 'View All Messages', 'client-sync-pro' );
		echo '</a> ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=clisyc-message-queue' ) ) . '" class="button button-secondary">';
		echo esc_html__( 'Message Queue', 'client-sync-pro' );
		echo '</a>';
		echo '</p>';
	}

	// ── Private Helpers ───────────────────────────────────────────

	/**
	 * Format a datetime as a relative time string.
	 *
	 * @param string $datetime MySQL datetime (UTC).
	 * @return string Formatted time.
	 */
	private function format_relative_time( string $datetime ): string {
		$timestamp = strtotime( $datetime );
		$now       = current_time( 'timestamp', true );
		$diff      = $now - $timestamp;

		if ( $diff < 60 ) {
			return __( 'Just now', 'client-sync-pro' );
		}

		if ( $diff < 3600 ) {
			$minutes = (int) floor( $diff / 60 );
			/* translators: %d: number of minutes */
			return sprintf( _n( '%d min ago', '%d mins ago', $minutes, 'client-sync-pro' ), $minutes );
		}

		if ( $diff < 86400 ) {
			$hours = (int) floor( $diff / 3600 );
			/* translators: %d: number of hours */
			return sprintf( _n( '%d hour ago', '%d hours ago', $hours, 'client-sync-pro' ), $hours );
		}

		if ( $diff < 604800 ) {
			$days = (int) floor( $diff / 86400 );
			/* translators: %d: number of days */
			return sprintf( _n( '%d day ago', '%d days ago', $days, 'client-sync-pro' ), $days );
		}

		return wp_date( get_option( 'date_format' ), $timestamp );
	}
}
