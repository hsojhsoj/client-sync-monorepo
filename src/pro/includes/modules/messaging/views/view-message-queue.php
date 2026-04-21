<?php
/**
 * View: Message Queue — Admin shared inbox.
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Messaging
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap clisyc-queue-page">
	<h1 class="wp-heading-inline">
		<?php esc_html_e( 'Message Queue', 'client-sync-pro' ); ?>
		<span id="clisyc-queue-badge" class="clisyc-queue-title-badge" style="display:none;"></span>
	</h1>

	<!-- Filter Bar -->
	<div class="clisyc-queue-filters">
		<div class="clisyc-queue-status-filters" id="clisyc-queue-status-filters">
			<button type="button" class="clisyc-queue-filter-btn active" data-status="">
				<?php esc_html_e( 'All Active', 'client-sync-pro' ); ?>
				<span class="clisyc-filter-count" data-count="total_active"></span>
			</button>
			<button type="button" class="clisyc-queue-filter-btn" data-status="awaiting_reply">
				<?php esc_html_e( 'Awaiting Reply', 'client-sync-pro' ); ?>
				<span class="clisyc-filter-count" data-count="awaiting_reply"></span>
			</button>
			<button type="button" class="clisyc-queue-filter-btn" data-status="open">
				<?php esc_html_e( 'Open', 'client-sync-pro' ); ?>
				<span class="clisyc-filter-count" data-count="open"></span>
			</button>
			<button type="button" class="clisyc-queue-filter-btn" data-status="resolved">
				<?php esc_html_e( 'Resolved', 'client-sync-pro' ); ?>
				<span class="clisyc-filter-count" data-count="resolved"></span>
			</button>
		</div>
		<div class="clisyc-queue-sort">
			<select id="clisyc-queue-sort">
				<option value="oldest_unanswered"><?php esc_html_e( 'Oldest Unanswered', 'client-sync-pro' ); ?></option>
				<option value="newest"><?php esc_html_e( 'Newest First', 'client-sync-pro' ); ?></option>
			</select>
		</div>
	</div>

	<!-- Two Column Layout -->
	<div class="clisyc-queue-layout">

		<!-- Queue List (left) -->
		<div class="clisyc-queue-list-panel">
			<div id="clisyc-queue-list" class="clisyc-queue-list">
				<div class="clisyc-loading"><?php esc_html_e( 'Loading...', 'client-sync-pro' ); ?></div>
			</div>
			<div id="clisyc-queue-pagination" class="clisyc-pagination" style="display:none;"></div>
		</div>

		<!-- Thread Detail (right) -->
		<div class="clisyc-queue-detail-panel">
			<div id="clisyc-queue-thread-header" class="clisyc-queue-thread-header" style="display:none;">
				<!-- Populated by JS: client info, subject, status actions -->
			</div>

			<div id="clisyc-queue-messages" class="clisyc-message-thread">
				<div class="clisyc-empty-state">
					<span class="dashicons dashicons-email-alt"></span>
					<p><?php esc_html_e( 'Select a conversation from the queue.', 'client-sync-pro' ); ?></p>
				</div>
			</div>

			<!-- Composer -->
			<div id="clisyc-queue-composer" class="clisyc-message-composer" style="display:none;">
				<textarea id="clisyc-queue-compose-body"
				          class="clisyc-compose-body"
				          placeholder="<?php esc_attr_e( 'Type your reply...', 'client-sync-pro' ); ?>"
				          rows="3"></textarea>
				<div class="clisyc-compose-actions">
					<label class="clisyc-attach-btn button" title="<?php esc_attr_e( 'Attach File', 'client-sync-pro' ); ?>">
						<span class="dashicons dashicons-paperclip"></span>
						<input type="file" id="clisyc-queue-compose-file" style="display:none;" />
					</label>
					<span id="clisyc-queue-attached-file-name" class="clisyc-attached-file-name"></span>
					<button type="button" id="clisyc-queue-send-btn" class="button button-primary" disabled>
						<?php esc_html_e( 'Send', 'client-sync-pro' ); ?>
					</button>
				</div>
			</div>
		</div>
	</div>
</div>
