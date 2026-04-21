/**
 * Message Queue — Admin Shared Inbox JavaScript
 * Client Sync Pro — Messaging Module
 *
 * Handles: queue list, filter/sort, thread detail panel,
 * reply composer, status actions, and background polling.
 */
(function ($) {
	'use strict';

	if (typeof clisycQueue === 'undefined') return;

	const API = clisycQueue.restBase;
	const S   = clisycQueue.strings;

	// ── State ─────────────────────────────────────────────────
	let currentStatus   = '';          // '' = all active
	let currentOrderby  = 'oldest_unanswered';
	let currentPage     = 1;
	let selectedThreadId = 0;
	let pollTimer       = null;

	// ── Initialization ────────────────────────────────────────

	$(document).ready(function () {
		initQueueFilters();
		initQueueSort();
		loadQueueCounts();
		loadQueue();
		startQueuePolling();
	});

	// ── Filters & Sort ────────────────────────────────────────

	function initQueueFilters() {
		$('#clisyc-queue-status-filters').on('click', '.clisyc-queue-filter-btn', function () {
			$('.clisyc-queue-filter-btn').removeClass('active');
			$(this).addClass('active');
			currentStatus = $(this).data('status') || '';
			currentPage = 1;
			selectedThreadId = 0;
			resetDetailPanel();
			loadQueue();
		});
	}

	function initQueueSort() {
		$('#clisyc-queue-sort').on('change', function () {
			currentOrderby = $(this).val();
			currentPage = 1;
			loadQueue();
		});
	}

	// ── Queue Counts (filter badges) ──────────────────────────

	function loadQueueCounts() {
		$.ajax({
			url: API + 'queue/counts',
			headers: { 'X-WP-Nonce': clisycQueue.nonce },
			success: function (data) {
				$('[data-count="total_active"]').text(data.total_active);
				$('[data-count="awaiting_reply"]').text(data.awaiting_reply);
				$('[data-count="open"]').text(data.open);
				$('[data-count="resolved"]').text(data.resolved);

				// Title badge.
				var $badge = $('#clisyc-queue-badge');
				if (data.awaiting_reply > 0) {
					$badge.text(data.awaiting_reply).show();
				} else {
					$badge.hide();
				}

				// WP admin menu bubble.
				updateMenuBadge(data.awaiting_reply);
			}
		});
	}

	/**
	 * Update the WordPress admin menu badge count.
	 */
	function updateMenuBadge(count) {
		var $menuBadge = $('#adminmenu .toplevel_page_clisyc-settings .awaiting-mod, #adminmenu a[href*="clisyc-message-queue"] .awaiting-mod');
		if ($menuBadge.length) {
			if (count > 0) {
				$menuBadge.text(count).show();
			} else {
				$menuBadge.hide();
			}
		}
	}

	// ── Queue List ────────────────────────────────────────────

	function loadQueue() {
		var $list = $('#clisyc-queue-list');
		$list.html('<div class="clisyc-loading">' + S.loading + '</div>');

		var url = API + 'queue?page=' + currentPage + '&per_page=20&orderby=' + encodeURIComponent(currentOrderby);
		if (currentStatus) {
			url += '&status=' + encodeURIComponent(currentStatus);
		}

		$.ajax({
			url: url,
			headers: { 'X-WP-Nonce': clisycQueue.nonce },
			success: function (data, status, xhr) {
				renderQueueList(data);
				renderQueuePagination(
					parseInt(xhr.getResponseHeader('X-WP-Total')) || 0,
					parseInt(xhr.getResponseHeader('X-WP-TotalPages')) || 0,
					currentPage
				);
			},
			error: function () {
				$list.html('<div class="clisyc-empty-state"><p>Failed to load queue.</p></div>');
			}
		});
	}

	function renderQueueList(threads) {
		var $list = $('#clisyc-queue-list');

		if (!threads.length) {
			$list.html(
				'<div class="clisyc-empty-state">' +
				'<span class="dashicons dashicons-email-alt"></span>' +
				'<p>' + S.noThreads + '</p>' +
				'</div>'
			);
			return;
		}

		var html = '';
		threads.forEach(function (thread) {
			var isActive    = thread.thread_id === selectedThreadId;
			var statusClass = thread.status ? ' status-' + thread.status : '';

			html += '<div class="clisyc-queue-item' + (isActive ? ' active' : '') + statusClass + '" data-thread-id="' + thread.thread_id + '">';
			html += '<img src="' + escHtml(thread.client_avatar) + '" width="40" height="40" alt="" />';
			html += '<div class="clisyc-queue-item-info">';

			// Header: name + status badge.
			html += '<div class="clisyc-queue-item-header">';
			html += '<strong>' + escHtml(thread.client_name) + '</strong>';
			html += statusBadge(thread.status);
			html += '</div>';

			// Subject.
			if (thread.subject) {
				html += '<div class="clisyc-queue-item-subject">' + escHtml(thread.subject) + '</div>';
			}

			// Preview.
			if (thread.last_message_preview) {
				var senderPrefix = thread.last_message_sender_name ? thread.last_message_sender_name + ': ' : '';
				html += '<div class="clisyc-queue-item-preview">' + escHtml(senderPrefix + thread.last_message_preview) + '</div>';
			}

			// Meta: time + message count.
			html += '<div class="clisyc-queue-item-meta">';
			html += formatRelativeTime(thread.last_message_at || thread.created_at);
			if (thread.message_count > 0) {
				html += ' &middot; ' + thread.message_count + ' ' + S.messages;
			}
			html += '</div>';

			html += '</div>'; // .clisyc-queue-item-info
			html += '</div>'; // .clisyc-queue-item
		});

		$list.html(html);

		// Click delegation.
		$list.find('.clisyc-queue-item').on('click', function () {
			var threadId = parseInt($(this).data('thread-id'));
			$('.clisyc-queue-item').removeClass('active');
			$(this).addClass('active');
			selectQueueThread(threadId);
		});
	}

	function statusBadge(status) {
		if (!status) return '';
		var labels = {
			'awaiting_reply': S.awaitingReply,
			'open': S.open,
			'resolved': S.resolved
		};
		var label = labels[status] || status;
		return '<span class="clisyc-queue-status-badge clisyc-status-' + escHtml(status) + '">' + escHtml(label) + '</span>';
	}

	// ── Queue Pagination ──────────────────────────────────────

	function renderQueuePagination(total, totalPages, page) {
		var $pagination = $('#clisyc-queue-pagination');

		if (totalPages <= 1) {
			$pagination.hide();
			return;
		}

		var html = '';
		html += '<button ' + (page <= 1 ? 'disabled' : '') + ' data-page="' + (page - 1) + '">&laquo; Prev</button>';
		html += '<span>Page ' + page + ' of ' + totalPages + '</span>';
		html += '<button ' + (page >= totalPages ? 'disabled' : '') + ' data-page="' + (page + 1) + '">Next &raquo;</button>';

		$pagination.html(html).show();

		$pagination.find('button').off('click').on('click', function () {
			var p = parseInt($(this).data('page'));
			if (p > 0) {
				currentPage = p;
				loadQueue();
			}
		});
	}

	// ── Thread Detail ─────────────────────────────────────────

	function selectQueueThread(threadId) {
		selectedThreadId = threadId;
		loadQueueThread(threadId);
	}

	function loadQueueThread(threadId) {
		var $messages = $('#clisyc-queue-messages');
		$messages.html('<div class="clisyc-loading">' + S.loading + '</div>');

		$.ajax({
			url: API + 'threads/' + threadId,
			headers: { 'X-WP-Nonce': clisycQueue.nonce },
			success: function (data) {
				renderThreadHeader(data.thread, threadId);
				renderQueueMessages(data.messages);
				initQueueComposer(threadId);
			},
			error: function () {
				$messages.html('<div class="clisyc-empty-state"><p>Failed to load thread.</p></div>');
			}
		});
	}

	function renderThreadHeader(thread, threadId) {
		var $header = $('#clisyc-queue-thread-header');

		var clientName = thread ? thread.client_name : '';
		var clientId   = thread ? thread.client_id : 0;
		var avatar     = thread ? thread.client_avatar : '';
		var subject    = thread ? (thread.subject || '') : '';
		var status     = thread ? (thread.status || 'open') : 'open';

		var html = '';
		if (avatar) {
			html += '<img src="' + escHtml(avatar) + '" width="36" height="36" alt="" />';
		}

		html += '<div class="clisyc-queue-thread-info">';
		html += '<h3>';
		if (clientId) {
			html += '<a href="' + escHtml(clisycQueue.clientsPageUrl) + '&client_id=' + clientId + '">' + escHtml(clientName) + '</a>';
		} else {
			html += escHtml(clientName);
		}
		html += '</h3>';
		if (subject) {
			html += '<div class="clisyc-queue-thread-subject">' + escHtml(subject) + '</div>';
		}
		html += '</div>';

		// Status action buttons.
		html += '<div class="clisyc-queue-thread-actions">';
		if (status !== 'resolved') {
			html += '<button type="button" class="button clisyc-queue-resolve-btn" data-thread-id="' + threadId + '">' + S.markResolved + '</button>';
		} else {
			html += '<button type="button" class="button clisyc-queue-reopen-btn" data-thread-id="' + threadId + '">' + S.reopen + '</button>';
		}
		if (clientId) {
			html += '<a href="' + escHtml(clisycQueue.clientsPageUrl) + '&client_id=' + clientId + '" class="button">' + S.viewClient + '</a>';
		}
		html += '</div>';

		$header.html(html).show();

		// Bind status action handlers.
		$header.find('.clisyc-queue-resolve-btn').off('click').on('click', function () {
			updateThreadStatus(parseInt($(this).data('thread-id')), 'resolved');
		});
		$header.find('.clisyc-queue-reopen-btn').off('click').on('click', function () {
			updateThreadStatus(parseInt($(this).data('thread-id')), 'open');
		});
	}

	function renderQueueMessages(messages) {
		var $container = $('#clisyc-queue-messages');

		if (!messages || !messages.length) {
			$container.html('<div class="clisyc-empty-state"><p>' + S.noMessages + '</p></div>');
			return;
		}

		var html = '<div class="clisyc-message-list clisyc-message-compact">';
		messages.forEach(function (msg) {
			var isMine = msg.sender_id === clisycQueue.currentUserId;
			html += '<div class="clisyc-message-item ' + (isMine ? 'clisyc-message-admin' : 'clisyc-message-client') + '">';
			html += '<div class="clisyc-message-header">';
			html += '<img src="' + escHtml(msg.sender_avatar) + '" width="24" height="24" alt="" />';
			html += '<strong>' + escHtml(msg.sender_name) + '</strong>';
			html += '<time>' + formatRelativeTime(msg.created_at) + '</time>';
			html += '</div>';
			html += '<div class="clisyc-message-body">' + nl2br(escHtml(msg.body)) + '</div>';

			// Attachments.
			if (msg.attachments && msg.attachments.length) {
				html += '<div class="clisyc-msg-attachments">';
				msg.attachments.forEach(function (att) {
					html += '<a href="' + API + 'attachments/' + att.attachment_id + '/download" class="clisyc-msg-attachment">';
					html += '<span class="dashicons dashicons-paperclip"></span>';
					html += escHtml(att.file_name) + ' (' + formatFileSize(att.file_size) + ')';
					html += '</a>';
				});
				html += '</div>';
			}

			html += '</div>';
		});
		html += '</div>';

		$container.html(html);

		// Scroll to bottom.
		$container.scrollTop($container[0].scrollHeight);
	}

	// ── Status Update ─────────────────────────────────────────

	function updateThreadStatus(threadId, newStatus) {
		$.ajax({
			url: API + 'queue/' + threadId + '/status',
			method: 'PUT',
			headers: { 'X-WP-Nonce': clisycQueue.nonce },
			contentType: 'application/json',
			data: JSON.stringify({ status: newStatus }),
			success: function () {
				// Refresh everything.
				loadQueueCounts();
				loadQueue();

				// If resolved and current filter is not resolved, reset detail panel.
				if (newStatus === 'resolved' && currentStatus !== 'resolved') {
					selectedThreadId = 0;
					resetDetailPanel();
				} else {
					// Reload the current thread to reflect new status.
					loadQueueThread(threadId);
				}
			},
			error: function () {
				alert('Failed to update status.');
			}
		});
	}

	// ── Composer ──────────────────────────────────────────────

	function initQueueComposer(threadId) {
		var $composer = $('#clisyc-queue-composer');
		var $body     = $('#clisyc-queue-compose-body');
		var $sendBtn  = $('#clisyc-queue-send-btn');
		var $fileInput = $('#clisyc-queue-compose-file');
		var $fileName  = $('#clisyc-queue-attached-file-name');

		$composer.show();
		$body.val('');
		$sendBtn.prop('disabled', true).text(S.sendMessage);
		$fileInput.val('');
		$fileName.text('');

		// Enable send when text entered.
		$body.off('input.queue').on('input.queue', function () {
			$sendBtn.prop('disabled', !$.trim($(this).val()));
		});

		// File attachment label.
		$fileInput.off('change.queue').on('change.queue', function () {
			var file = this.files[0];
			$fileName.text(file ? file.name : '');
		});

		// Send button.
		$sendBtn.off('click.queue').on('click.queue', function () {
			var body = $.trim($body.val());
			if (!body) return;

			$sendBtn.prop('disabled', true).text(S.loading);

			$.ajax({
				url: API + 'messages',
				method: 'POST',
				headers: { 'X-WP-Nonce': clisycQueue.nonce },
				contentType: 'application/json',
				data: JSON.stringify({
					thread_id: threadId,
					body: body
				}),
				success: function (data) {
					// Handle file attachment upload.
					var file = $fileInput[0].files[0];
					if (file && data.message_id) {
						uploadQueueAttachment(data.message_id, file, threadId);
					}

					$body.val('');
					$fileInput.val('');
					$fileName.text('');
					$sendBtn.prop('disabled', true).text(S.sendMessage);

					// Refresh thread + queue (status auto-updates via server).
					loadQueueThread(threadId);
					loadQueueCounts();
					loadQueue();
				},
				error: function () {
					alert(S.sendFailed);
					$sendBtn.prop('disabled', false).text(S.sendMessage);
				}
			});
		});
	}

	function uploadQueueAttachment(messageId, file, threadId) {
		var formData = new FormData();
		formData.append('file', file);
		formData.append('message_id', messageId);

		$.ajax({
			url: API + 'attachments',
			method: 'POST',
			headers: { 'X-WP-Nonce': clisycQueue.nonce },
			data: formData,
			processData: false,
			contentType: false,
			success: function () {
				loadQueueThread(threadId);
			}
		});
	}

	// ── Detail Panel Reset ────────────────────────────────────

	function resetDetailPanel() {
		$('#clisyc-queue-thread-header').hide().empty();
		$('#clisyc-queue-composer').hide();
		$('#clisyc-queue-messages').html(
			'<div class="clisyc-empty-state">' +
			'<span class="dashicons dashicons-email-alt"></span>' +
			'<p>' + S.selectThread + '</p>' +
			'</div>'
		);
	}

	// ── Polling ───────────────────────────────────────────────

	function startQueuePolling() {
		if (clisycQueue.pollInterval <= 0) return;

		pollTimer = setInterval(function () {
			loadQueueCounts();

			// If a thread is selected, silently refresh its messages.
			if (selectedThreadId > 0) {
				$.ajax({
					url: API + 'threads/' + selectedThreadId,
					headers: { 'X-WP-Nonce': clisycQueue.nonce },
					success: function (data) {
						renderQueueMessages(data.messages);
					}
				});
			}
		}, clisycQueue.pollInterval * 1000);
	}

	// ── Utility ───────────────────────────────────────────────

	function escHtml(str) {
		if (!str) return '';
		var div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	function nl2br(str) {
		return str.replace(/\n/g, '<br>');
	}

	function formatRelativeTime(dateStr) {
		if (!dateStr) return '';
		var date = new Date(dateStr.replace(' ', 'T') + 'Z');
		var now  = new Date();
		var diff = Math.floor((now - date) / 1000);

		if (diff < 60) return 'Just now';
		if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
		if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
		if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
		return date.toLocaleDateString();
	}

	function formatFileSize(bytes) {
		if (bytes < 1024) return bytes + ' B';
		if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
		return (bytes / 1048576).toFixed(1) + ' MB';
	}

})(jQuery);
