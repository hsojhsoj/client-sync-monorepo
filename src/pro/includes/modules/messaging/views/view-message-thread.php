<?php
/**
 * File: src/pro/includes/modules/messaging/views/view-message-thread.php
 * Compact message thread view injected into the appointment detail page.
 *
 * Variables available:
 * @var \WP_Post      $appointment_post The appointment post object.
 * @var object|null    $thread           Thread object (null if no thread yet).
 * @var array          $messages         Array of message objects.
 * @var int            $user_id          Current user ID.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

use ClientSyncPro\Modules\Messaging\Messaging_Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="clisyc-detail-section clisyc-appointment-messages"
     data-appointment-id="<?php echo esc_attr( $appointment_post->ID ); ?>"
     data-thread-id="<?php echo $thread ? esc_attr( $thread->thread_id ) : ''; ?>"
     data-client-id="<?php echo esc_attr( $appointment_post->post_author ); ?>">

	<h3>
		<span class="dashicons dashicons-email-alt"></span>
		<?php esc_html_e( 'Messages', 'client-sync-pro' ); ?>
		<?php if ( ! empty( $messages ) ) : ?>
			<span class="clisyc-badge"><?php echo esc_html( count( $messages ) ); ?></span>
		<?php endif; ?>
	</h3>

	<?php if ( ! empty( $messages ) ) : ?>
		<div class="clisyc-appointment-message-list">
			<?php foreach ( $messages as $msg ) :
				$sender    = get_userdata( (int) $msg->sender_id );
				$is_mine   = (int) $msg->sender_id === $user_id;
				$is_admin_sender = Messaging_Permissions::is_admin( (int) $msg->sender_id );
			?>
				<div class="clisyc-appt-msg <?php echo $is_mine ? 'clisyc-appt-msg-mine' : 'clisyc-appt-msg-other'; ?> <?php echo $is_admin_sender ? 'clisyc-appt-msg-admin' : 'clisyc-appt-msg-client'; ?>">
					<div class="clisyc-appt-msg-header">
						<?php echo get_avatar( (int) $msg->sender_id, 24 ); ?>
						<strong><?php echo esc_html( $sender ? $sender->display_name : __( 'Unknown', 'client-sync-pro' ) ); ?></strong>
						<time datetime="<?php echo esc_attr( $msg->created_at ); ?>">
							<?php echo esc_html( human_time_diff( strtotime( $msg->created_at ), current_time( 'timestamp', true ) ) ); ?>
							<?php esc_html_e( 'ago', 'client-sync-pro' ); ?>
						</time>
					</div>
					<div class="clisyc-appt-msg-body">
						<?php echo wp_kses_post( wpautop( $msg->body ) ); ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<p class="clisyc-no-messages"><?php esc_html_e( 'No messages for this appointment yet. Send a message below.', 'client-sync-pro' ); ?></p>
	<?php endif; ?>

	<?php if ( Messaging_Permissions::can_reply( $user_id ) ) : ?>
		<div class="clisyc-appt-reply-form">
			<textarea class="clisyc-appt-reply-input"
					  placeholder="<?php esc_attr_e( 'Type your message…', 'client-sync-pro' ); ?>"
					  rows="2"></textarea>
			<button type="button" class="clisyc-btn clisyc-btn-primary clisyc-appt-reply-send">
				<?php esc_html_e( 'Send', 'client-sync-pro' ); ?>
			</button>
		</div>
	<?php endif; ?>

</div>
