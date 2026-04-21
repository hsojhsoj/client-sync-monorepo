<?php
/**
 * File: src/pro/includes/modules/messaging/views/view-inbox.php
 * Frontend view for the [clisyc_inbox] shortcode.
 *
 * Variables available:
 * @var int  $user_id      Current user ID.
 * @var bool $is_admin     Whether the current user is an admin.
 * @var int  $unread_count Current unread message count.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="clisyc-inbox" id="clisyc-inbox" data-user-id="<?php echo esc_attr( $user_id ); ?>">

	<!-- Inbox Header -->
	<div class="clisyc-inbox-header">
		<h2>
			<?php esc_html_e( 'Messages', 'client-sync-pro' ); ?>
			<?php if ( $unread_count > 0 ) : ?>
				<span class="clisyc-unread-badge" id="clisyc-inbox-unread-badge"><?php echo esc_html( $unread_count ); ?></span>
			<?php endif; ?>
		</h2>
		<?php if ( \ClientSyncPro\Modules\Messaging\Messaging_Permissions::can_initiate( $user_id ) ) : ?>
			<button type="button" class="clisyc-btn clisyc-btn-primary" id="clisyc-compose-btn">
				<span class="dashicons dashicons-plus-alt2"></span>
				<?php esc_html_e( 'New Message', 'client-sync-pro' ); ?>
			</button>
		<?php endif; ?>
	</div>

	<div class="clisyc-inbox-layout">

		<!-- Thread List Panel -->
		<div class="clisyc-inbox-threads" id="clisyc-inbox-threads">
			<div class="clisyc-loading" id="clisyc-threads-loading">
				<span class="clisyc-spinner"></span>
				<?php esc_html_e( 'Loading…', 'client-sync-pro' ); ?>
			</div>
			<div id="clisyc-thread-list-container" style="display:none;"></div>
			<div id="clisyc-threads-empty" class="clisyc-empty-state" style="display:none;">
				<span class="dashicons dashicons-email-alt"></span>
				<p><?php esc_html_e( 'No conversations yet.', 'client-sync-pro' ); ?></p>
			</div>
		</div>

		<!-- Message View Panel -->
		<div class="clisyc-inbox-messages" id="clisyc-inbox-messages">
			<div id="clisyc-messages-placeholder" class="clisyc-empty-state">
				<span class="dashicons dashicons-email-alt"></span>
				<p><?php esc_html_e( 'Select a conversation to view messages.', 'client-sync-pro' ); ?></p>
			</div>

			<!-- Message List (populated by JS) -->
			<div id="clisyc-message-list-container" style="display:none;">
				<div class="clisyc-thread-header" id="clisyc-thread-header"></div>
				<div class="clisyc-message-list" id="clisyc-message-list"></div>
			</div>

			<!-- Reply Composer -->
			<div id="clisyc-reply-composer" class="clisyc-reply-composer" style="display:none;">
				<div class="clisyc-reply-input-wrap">
					<textarea id="clisyc-reply-body"
							  class="clisyc-reply-body"
							  placeholder="<?php esc_attr_e( 'Type your message…', 'client-sync-pro' ); ?>"
							  rows="2"></textarea>
				</div>
				<div class="clisyc-reply-actions">
					<label class="clisyc-attach-btn" title="<?php esc_attr_e( 'Attach File', 'client-sync-pro' ); ?>">
						<span class="dashicons dashicons-paperclip"></span>
						<input type="file" id="clisyc-reply-file" style="display:none;" />
					</label>
					<span id="clisyc-reply-attached-name" class="clisyc-attached-file-name"></span>
					<button type="button" id="clisyc-reply-send-btn" class="clisyc-btn clisyc-btn-primary" disabled>
						<?php esc_html_e( 'Send', 'client-sync-pro' ); ?>
					</button>
				</div>
			</div>
		</div>

	</div>

</div>

<!-- Compose Modal -->
<div id="clisyc-compose-modal" class="clisyc-modal" style="display:none;">
	<div class="clisyc-modal-overlay"></div>
	<div class="clisyc-modal-content">
		<div class="clisyc-modal-header">
			<h3><?php esc_html_e( 'New Message', 'client-sync-pro' ); ?></h3>
			<button type="button" class="clisyc-modal-close">&times;</button>
		</div>
		<div class="clisyc-modal-body">
			<?php if ( $is_admin ) : ?>
				<div class="clisyc-form-field">
					<label for="clisyc-compose-recipient"><?php esc_html_e( 'To', 'client-sync-pro' ); ?></label>
					<input type="search" id="clisyc-compose-recipient"
						   placeholder="<?php esc_attr_e( 'Search for a client…', 'client-sync-pro' ); ?>"
						   autocomplete="off" />
					<input type="hidden" id="clisyc-compose-recipient-id" />
					<div id="clisyc-compose-recipient-results" class="clisyc-search-results"></div>
				</div>
			<?php endif; ?>
			<div class="clisyc-form-field">
				<label for="clisyc-compose-subject"><?php esc_html_e( 'Subject', 'client-sync-pro' ); ?></label>
				<input type="text" id="clisyc-compose-subject"
					   placeholder="<?php esc_attr_e( 'Subject (optional)', 'client-sync-pro' ); ?>" />
			</div>
			<div class="clisyc-form-field">
				<label for="clisyc-compose-message"><?php esc_html_e( 'Message', 'client-sync-pro' ); ?></label>
				<textarea id="clisyc-compose-message" rows="5"
						  placeholder="<?php esc_attr_e( 'Type your message…', 'client-sync-pro' ); ?>"></textarea>
			</div>
		</div>
		<div class="clisyc-modal-footer">
			<button type="button" class="clisyc-btn clisyc-modal-cancel"><?php esc_html_e( 'Cancel', 'client-sync-pro' ); ?></button>
			<button type="button" class="clisyc-btn clisyc-btn-primary" id="clisyc-compose-send-btn">
				<?php esc_html_e( 'Send', 'client-sync-pro' ); ?>
			</button>
		</div>
	</div>
</div>
