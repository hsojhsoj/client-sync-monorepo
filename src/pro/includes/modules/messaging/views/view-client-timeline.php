<?php
/**
 * File: src/pro/includes/modules/messaging/views/view-client-timeline.php
 * Admin view for the Clients page — client selector + unified timeline + messaging.
 *
 * Variables available:
 * @var int $client_id Selected client user ID (0 = none selected).
 * @var int $thread_id Selected thread ID (0 = none).
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap clisyc-clients-page">

	<h1 class="wp-heading-inline"><?php esc_html_e( 'Clients', 'client-sync-pro' ); ?></h1>

	<div class="clisyc-clients-layout">

		<!-- Client Selector / Search -->
		<div class="clisyc-clients-sidebar">
			<div class="clisyc-client-search">
				<label for="clisyc-client-search-input" class="screen-reader-text">
					<?php esc_html_e( 'Search clients', 'client-sync-pro' ); ?>
				</label>
				<input type="search"
					   id="clisyc-client-search-input"
					   class="clisyc-client-search-input"
					   placeholder="<?php esc_attr_e( 'Search clients…', 'client-sync-pro' ); ?>"
					   autocomplete="off" />
				<div id="clisyc-client-search-results" class="clisyc-client-search-results"></div>
			</div>

			<!-- Thread List -->
			<div id="clisyc-thread-list" class="clisyc-thread-list" data-client-id="<?php echo esc_attr( $client_id ); ?>">
				<div class="clisyc-loading"><?php esc_html_e( 'Loading…', 'client-sync-pro' ); ?></div>
			</div>
		</div>

		<!-- Main Content Area -->
		<div class="clisyc-clients-main">

			<?php if ( $client_id > 0 ) :
				$client = get_userdata( $client_id );
				if ( $client ) : ?>
					<div class="clisyc-client-header">
						<?php echo get_avatar( $client_id, 48 ); ?>
						<div class="clisyc-client-header-info">
							<h2><?php echo esc_html( $client->display_name ); ?></h2>
							<span class="clisyc-client-email"><?php echo esc_html( $client->user_email ); ?></span>
						</div>
						<div class="clisyc-client-header-actions">
							<button type="button" class="button button-primary" id="clisyc-new-thread-btn" data-client-id="<?php echo esc_attr( $client_id ); ?>">
								<span class="dashicons dashicons-plus-alt2"></span>
								<?php esc_html_e( 'New Conversation', 'client-sync-pro' ); ?>
							</button>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<!-- Tab Navigation -->
			<div class="clisyc-tabs" id="clisyc-client-tabs">
				<button type="button" class="clisyc-tab active" data-tab="timeline">
					<span class="dashicons dashicons-list-view"></span>
					<?php esc_html_e( 'Timeline', 'client-sync-pro' ); ?>
				</button>
				<button type="button" class="clisyc-tab" data-tab="messages">
					<span class="dashicons dashicons-email-alt"></span>
					<?php esc_html_e( 'Messages', 'client-sync-pro' ); ?>
					<span id="clisyc-messages-badge" class="clisyc-badge" style="display:none;"></span>
				</button>
			</div>

			<!-- Timeline Tab -->
			<div id="clisyc-tab-timeline" class="clisyc-tab-content active" data-client-id="<?php echo esc_attr( $client_id ); ?>">
				<div class="clisyc-timeline-filters" id="clisyc-timeline-filters">
					<!-- Populated by JS -->
				</div>
				<div id="clisyc-timeline-content" class="clisyc-timeline-content">
					<?php if ( $client_id > 0 ) : ?>
						<div class="clisyc-loading"><?php esc_html_e( 'Loading timeline…', 'client-sync-pro' ); ?></div>
					<?php else : ?>
						<div class="clisyc-empty-state">
							<span class="dashicons dashicons-admin-users"></span>
							<p><?php esc_html_e( 'Select a client to view their timeline.', 'client-sync-pro' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
				<div id="clisyc-timeline-pagination" class="clisyc-pagination" style="display:none;"></div>
			</div>

			<!-- Messages Tab -->
			<div id="clisyc-tab-messages" class="clisyc-tab-content" style="display:none;">
				<div id="clisyc-message-thread" class="clisyc-message-thread" data-thread-id="<?php echo esc_attr( $thread_id ); ?>">
					<?php if ( $thread_id > 0 ) : ?>
						<div class="clisyc-loading"><?php esc_html_e( 'Loading messages…', 'client-sync-pro' ); ?></div>
					<?php else : ?>
						<div class="clisyc-empty-state">
							<span class="dashicons dashicons-email-alt"></span>
							<p><?php esc_html_e( 'Select a conversation to view messages.', 'client-sync-pro' ); ?></p>
						</div>
					<?php endif; ?>
				</div>

				<!-- Composer -->
				<div id="clisyc-message-composer" class="clisyc-message-composer" style="display:none;">
					<textarea id="clisyc-compose-body"
							  class="clisyc-compose-body"
							  placeholder="<?php esc_attr_e( 'Type your message…', 'client-sync-pro' ); ?>"
							  rows="3"></textarea>
					<div class="clisyc-compose-actions">
						<label class="clisyc-attach-btn button" title="<?php esc_attr_e( 'Attach File', 'client-sync-pro' ); ?>">
							<span class="dashicons dashicons-paperclip"></span>
							<input type="file" id="clisyc-compose-file" style="display:none;" />
						</label>
						<span id="clisyc-attached-file-name" class="clisyc-attached-file-name"></span>
						<button type="button" id="clisyc-send-btn" class="button button-primary" disabled>
							<?php esc_html_e( 'Send', 'client-sync-pro' ); ?>
						</button>
					</div>
				</div>
			</div>

		</div>

	</div>

</div>

<!-- New Thread Modal -->
<div id="clisyc-new-thread-modal" class="clisyc-modal" style="display:none;">
	<div class="clisyc-modal-overlay"></div>
	<div class="clisyc-modal-content">
		<div class="clisyc-modal-header">
			<h3><?php esc_html_e( 'New Conversation', 'client-sync-pro' ); ?></h3>
			<button type="button" class="clisyc-modal-close">&times;</button>
		</div>
		<div class="clisyc-modal-body">
			<div class="clisyc-form-field">
				<label for="clisyc-thread-subject"><?php esc_html_e( 'Subject', 'client-sync-pro' ); ?></label>
				<input type="text" id="clisyc-thread-subject" placeholder="<?php esc_attr_e( 'Subject (optional)', 'client-sync-pro' ); ?>" />
			</div>
			<div class="clisyc-form-field">
				<label for="clisyc-thread-body"><?php esc_html_e( 'Message', 'client-sync-pro' ); ?></label>
				<textarea id="clisyc-thread-body" rows="4" placeholder="<?php esc_attr_e( 'Type your message…', 'client-sync-pro' ); ?>"></textarea>
			</div>
		</div>
		<div class="clisyc-modal-footer">
			<button type="button" class="button clisyc-modal-cancel"><?php esc_html_e( 'Cancel', 'client-sync-pro' ); ?></button>
			<button type="button" class="button button-primary" id="clisyc-create-thread-btn"><?php esc_html_e( 'Send', 'client-sync-pro' ); ?></button>
		</div>
	</div>
</div>
