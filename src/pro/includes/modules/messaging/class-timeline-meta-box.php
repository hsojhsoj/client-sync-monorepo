<?php
/**
 * File: src/pro/includes/modules/messaging/class-timeline-meta-box.php
 * Adds a timeline + messaging meta box to the appointment edit screen.
 *
 * Shows contextual timeline filtered to the client, with the appointment-scoped
 * message thread and a quick-reply form inline.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

namespace ClientSyncPro\Modules\Messaging;

use DependentMedia\ClientSync\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Timeline_Meta_Box {

	/** @var Message_Repository */
	private $message_repo;

	/** @var Timeline_Repository */
	private $timeline_repo;

	public function __construct( Message_Repository $message_repo, Timeline_Repository $timeline_repo ) {
		$this->message_repo  = $message_repo;
		$this->timeline_repo = $timeline_repo;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
	}

	/**
	 * Add the meta box to the appointment edit screen.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'clisyc_messaging_timeline',
			__( 'Client Timeline & Messages', 'client-sync-pro' ),
			[ $this, 'render_meta_box' ],
			Constants::POST_TYPE_APPOINTMENT,
			'normal',
			'default'
		);
	}

	/**
	 * Render the meta box content.
	 *
	 * @param \WP_Post $post The appointment post object.
	 */
	public function render_meta_box( \WP_Post $post ): void {
		$client_id = (int) $post->post_author;
		$client    = get_userdata( $client_id );

		if ( ! $client ) {
			echo '<p>' . esc_html__( 'No client associated with this appointment.', 'client-sync-pro' ) . '</p>';
			return;
		}

		// Get the appointment-scoped thread (if it exists).
		$thread = $this->message_repo->get_thread_for_appointment( $post->ID );

		// Get recent timeline events for this client.
		$events = $this->timeline_repo->get_timeline( $client_id, 1, 10 );

		// Get messages if thread exists.
		$messages = [];
		if ( $thread ) {
			$messages = $this->message_repo->get_messages_for_thread( (int) $thread->thread_id, 1, 20 );
		}
		?>
		<div class="clisyc-metabox-timeline" data-client-id="<?php echo esc_attr( $client_id ); ?>" data-appointment-id="<?php echo esc_attr( $post->ID ); ?>">

			<div class="clisyc-metabox-client-info">
				<?php echo get_avatar( $client_id, 32 ); ?>
				<strong><?php echo esc_html( $client->display_name ); ?></strong>
				<span class="clisyc-metabox-client-email"><?php echo esc_html( $client->user_email ); ?></span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clisyc-clients&client_id=' . $client_id ) ); ?>" class="button button-small" style="margin-left:auto;">
					<?php esc_html_e( 'Full Profile', 'client-sync-pro' ); ?>
				</a>
			</div>

			<?php if ( ! empty( $events ) ) : ?>
				<div class="clisyc-metabox-section">
					<h4><?php esc_html_e( 'Recent Activity', 'client-sync-pro' ); ?></h4>
					<ul class="clisyc-timeline-list clisyc-timeline-compact">
						<?php foreach ( $events as $event ) : ?>
							<li class="clisyc-timeline-item clisyc-timeline-type-<?php echo esc_attr( $event->event_type ); ?>">
								<span class="clisyc-timeline-icon dashicons <?php echo esc_attr( $this->get_event_icon( $event->event_type ) ); ?>"></span>
								<span class="clisyc-timeline-summary"><?php echo esc_html( $event->summary ); ?></span>
								<time class="clisyc-timeline-date" datetime="<?php echo esc_attr( $event->event_date ); ?>">
									<?php echo esc_html( $this->format_relative_time( $event->event_date ) ); ?>
								</time>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div class="clisyc-metabox-section">
				<h4>
					<?php esc_html_e( 'Appointment Messages', 'client-sync-pro' ); ?>
					<?php if ( $thread ) : ?>
						<span class="clisyc-badge"><?php echo esc_html( count( $messages ) ); ?></span>
					<?php endif; ?>
				</h4>

				<?php if ( ! empty( $messages ) ) : ?>
					<div class="clisyc-message-list clisyc-message-compact">
						<?php foreach ( $messages as $msg ) : ?>
							<?php $sender = get_userdata( (int) $msg->sender_id ); ?>
							<div class="clisyc-message-item <?php echo Messaging_Permissions::is_admin( (int) $msg->sender_id ) ? 'clisyc-message-admin' : 'clisyc-message-client'; ?>">
								<div class="clisyc-message-header">
									<?php echo get_avatar( (int) $msg->sender_id, 24 ); ?>
									<strong><?php echo esc_html( $sender ? $sender->display_name : __( 'Unknown', 'client-sync-pro' ) ); ?></strong>
									<time datetime="<?php echo esc_attr( $msg->created_at ); ?>">
										<?php echo esc_html( $this->format_relative_time( $msg->created_at ) ); ?>
									</time>
								</div>
								<div class="clisyc-message-body">
									<?php echo wp_kses_post( wpautop( $msg->body ) ); ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="clisyc-no-messages"><?php esc_html_e( 'No messages for this appointment yet.', 'client-sync-pro' ); ?></p>
				<?php endif; ?>

				<?php if ( Messaging_Permissions::can_reply( get_current_user_id() ) ) : ?>
					<div class="clisyc-quick-reply" data-thread-id="<?php echo $thread ? esc_attr( $thread->thread_id ) : ''; ?>">
						<textarea class="clisyc-quick-reply-input" placeholder="<?php esc_attr_e( 'Type a quick reply…', 'client-sync-pro' ); ?>" rows="2"></textarea>
						<button type="button" class="button button-primary clisyc-quick-reply-send">
							<?php esc_html_e( 'Send', 'client-sync-pro' ); ?>
						</button>
					</div>
				<?php endif; ?>
			</div>

		</div>
		<?php
	}

	// ── Private Helpers ───────────────────────────────────────────

	/**
	 * Get dashicon class for an event type.
	 *
	 * @param string $event_type Event type slug.
	 * @return string Dashicons class.
	 */
	private function get_event_icon( string $event_type ): string {
		$icons = [
			'message_sent'          => 'dashicons-email-alt',
			'appointment_created'   => 'dashicons-calendar-alt',
			'appointment_cancelled' => 'dashicons-dismiss',
			'appointment_completed' => 'dashicons-yes-alt',
			'email_sent'            => 'dashicons-email',
			'sms_sent'              => 'dashicons-smartphone',
			'login'                 => 'dashicons-admin-users',
			'logout'                => 'dashicons-migrate',
			'note_updated'          => 'dashicons-edit',
		];

		return $icons[ $event_type ] ?? 'dashicons-marker';
	}

	/**
	 * Format a datetime as a human-readable relative time string.
	 *
	 * @param string $datetime MySQL datetime (UTC).
	 * @return string Formatted relative time.
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
