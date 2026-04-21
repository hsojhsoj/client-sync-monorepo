<?php
/**
 * File: src/pro/includes/modules/messaging/views/view-message-composer.php
 * Standalone message composer view — used by admin pages and shortcodes.
 *
 * This is a reusable template fragment for the message composition form.
 * It can be included in multiple contexts (admin meta box, client profile page).
 *
 * Variables available:
 * @var int         $client_id      Target client user ID.
 * @var int         $thread_id      Existing thread ID (0 for new thread).
 * @var int|null    $appointment_id Optional appointment ID.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$client_id      = $client_id ?? 0;
$thread_id      = $thread_id ?? 0;
$appointment_id = $appointment_id ?? 0;
?>

<div class="clisyc-composer"
	 data-client-id="<?php echo esc_attr( $client_id ); ?>"
	 data-thread-id="<?php echo esc_attr( $thread_id ); ?>"
	 data-appointment-id="<?php echo esc_attr( $appointment_id ); ?>">

	<?php if ( 0 === $thread_id ) : ?>
		<div class="clisyc-composer-field">
			<input type="text"
				   class="clisyc-composer-subject"
				   placeholder="<?php esc_attr_e( 'Subject (optional)', 'client-sync-pro' ); ?>"
				   maxlength="255" />
		</div>
	<?php endif; ?>

	<div class="clisyc-composer-field">
		<textarea class="clisyc-composer-body"
				  placeholder="<?php esc_attr_e( 'Type your message…', 'client-sync-pro' ); ?>"
				  rows="4"></textarea>
	</div>

	<div class="clisyc-composer-toolbar">
		<label class="clisyc-composer-attach" title="<?php esc_attr_e( 'Attach File', 'client-sync-pro' ); ?>">
			<span class="dashicons dashicons-paperclip"></span>
			<input type="file" class="clisyc-composer-file-input" style="display:none;" />
		</label>
		<span class="clisyc-composer-file-name"></span>
		<button type="button" class="button button-primary clisyc-composer-send">
			<?php esc_html_e( 'Send', 'client-sync-pro' ); ?>
		</button>
	</div>

	<div class="clisyc-composer-status" style="display:none;"></div>

</div>
