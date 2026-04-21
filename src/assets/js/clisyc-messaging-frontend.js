/**
 * Messaging Frontend JavaScript
 * Client Sync Pro — Encrypted Messaging Module
 *
 * Handles: [clisyc_inbox] shortcode interactions, appointment detail
 * message thread, real-time SSE, and polling fallback.
 */
(function ($) {
	'use strict';

	if (typeof clisycMessaging === 'undefined') return;

	const API = clisycMessaging.restBase;
	const strings = clisycMessaging.strings;
	let currentThreadId = 0;
	let pollTimer = null;
	let sseSource = null;

	// ── Initialization ────────────────────────────────────────

	$(document).ready(function () {
		initInbox();
		initAppointmentMessages();
		initRealtime();
	});

	// ══════════════════════════════════════════════════════════
	//  INBOX ([clisyc_inbox] shortcode)
	// ══════════════════════════════════════════════════════════

	function initInbox() {
		if (!$('#clisyc-inbox').length) return;

		loadThreadList();
		initComposeModal();
		initReplyComposer();
	}

	// ── Thread List ───────────────────────────────────────────

	function loadThreadList() {
		const $container = $('#clisyc-thread-list-container');
		const $loading = $('#clisyc-threads-loading');
		const $empty = $('#clisyc-threads-empty');

		$.ajax({
			url: API + 'threads?per_page=50',
			headers: { 'X-WP-Nonce': clisycMessaging.nonce },
			success: function (threads) {
				$loading.hide();

				if (!threads.length) {
					$empty.show();
					return;
				}

				let html = '';
				threads.forEach(function (thread) {
					const isUnread = thread.unread_count > 0;
					const displayName = clisycMessaging.isAdmin ? thread.client_name : thread.subject || strings.noThreads;

					html += '<div class="clisyc-thread-list-item' + (isUnread ? ' unread' : '') + '" data-thread-id="' + thread.thread_id + '">';
					html += '<img src="' + esc(thread.client_avatar) + '" width="36" height="36" />';
					html += '<div class="clisyc-thread-list-info">';
					html += '<div class="clisyc-thread-list-name">' + esc(displayName) + '</div>';
					if (thread.subject) {
						html += '<div class="clisyc-thread-list-subject">' + esc(thread.subject) + '</div>';
					}
					html += '</div>';
					html += '<div style="text-align:right;">';
					html += '<div class="clisyc-thread-list-time">' + formatTime(thread.last_message_at || thread.created_at) + '</div>';
					if (isUnread) {
						html += '<span class="clisyc-thread-unread-badge">' + thread.unread_count + '</span>';
					}
					html += '</div>';
					html += '</div>';
				});

				$container.html(html).show();

				// Click handler.
				$container.find('.clisyc-thread-list-item').on('click', function () {
					$('.clisyc-thread-list-item').removeClass('active');
					$(this).addClass('active').removeClass('unread');
					$(this).find('.clisyc-thread-unread-badge').remove();
					loadMessages(parseInt($(this).data('thread-id')));
				});
			},
			error: function () {
				$loading.hide();
				$empty.show();
			}
		});
	}

	// ── Messages ──────────────────────────────────────────────

	function loadMessages(threadId) {
		currentThreadId = threadId;
		const $placeholder = $('#clisyc-messages-placeholder');
		const $container = $('#clisyc-message-list-container');
		const $list = $('#clisyc-message-list');
		const $header = $('#clisyc-thread-header');
		const $reply = $('#clisyc-reply-composer');

		$placeholder.hide();
		$container.show();
		$list.html('<div class="clisyc-loading"><span class="clisyc-spinner"></span> ' + strings.loading + '</div>');

		$.ajax({
			url: API + 'threads/' + threadId,
			headers: { 'X-WP-Nonce': clisycMessaging.nonce },
			success: function (data) {
				$header.html(esc(data.subject || data.client_name));

				let html = '';
				data.messages.forEach(function (msg) {
					const isMine = msg.sender_id === clisycMessaging.currentUserId;

					html += '<div class="clisyc-msg ' + (isMine ? 'clisyc-msg-mine' : 'clisyc-msg-other') + '">';
					html += '<div class="clisyc-msg-avatar"><img src="' + esc(msg.sender_avatar) + '" width="32" height="32" /></div>';
					html += '<div class="clisyc-msg-content">';
					html += '<div class="clisyc-msg-meta">';
					html += '<strong>' + esc(isMine ? strings.you : msg.sender_name) + '</strong>';
					html += '<span>' + formatTime(msg.created_at) + '</span>';
					html += '</div>';
					html += '<div class="clisyc-msg-bubble">' + nl2br(esc(msg.body)) + '</div>';

					if (msg.attachments && msg.attachments.length) {
						html += '<div class="clisyc-msg-attachments">';
						msg.attachments.forEach(function (att) {
							html += '<a href="' + API + 'attachments/' + att.attachment_id + '/download" class="clisyc-msg-attachment" target="_blank">';
							html += '<span class="dashicons dashicons-paperclip"></span> ';
							html += esc(att.file_name) + ' (' + formatSize(att.file_size) + ')';
							html += '</a>';
						});
						html += '</div>';
					}

					html += '</div></div>';
				});

				$list.html(html || '<div class="clisyc-empty-state"><p>' + strings.noMessages + '</p></div>');
				$list.scrollTop($list[0].scrollHeight);

				// Show reply composer.
				if (clisycMessaging.canReply) {
					$reply.show();
				}
			},
			error: function () {
				$list.html('<div class="clisyc-empty-state"><p>Failed to load messages.</p></div>');
			}
		});
	}

	// ── Reply Composer ────────────────────────────────────────

	function initReplyComposer() {
		const $body = $('#clisyc-reply-body');
		const $btn = $('#clisyc-reply-send-btn');
		const $fileInput = $('#clisyc-reply-file');

		$body.on('input', function () {
			$btn.prop('disabled', !$.trim($(this).val()));
		});

		$btn.on('click', function () {
			const body = $.trim($body.val());
			if (!body || !currentThreadId) return;

			$btn.prop('disabled', true).text(strings.loading);

			$.ajax({
				url: API + 'messages',
				method: 'POST',
				headers: { 'X-WP-Nonce': clisycMessaging.nonce },
				contentType: 'application/json',
				data: JSON.stringify({
					thread_id: currentThreadId,
					body: body
				}),
				success: function (data) {
					$body.val('');
					$btn.text(strings.send);

					// Upload attachment if present.
					const file = $fileInput[0].files[0];
					if (file && data.message_id) {
						uploadFile(data.message_id, file, function () {
							loadMessages(currentThreadId);
							loadThreadList();
						});
					} else {
						loadMessages(currentThreadId);
						loadThreadList();
					}

					$fileInput.val('');
					$('#clisyc-reply-attached-name').text('');
				},
				error: function () {
					alert(strings.sendFailed);
					$btn.prop('disabled', false).text(strings.send);
				}
			});
		});

		$fileInput.on('change', function () {
			const file = this.files[0];
			if (file) {
				// Validate size.
				if (file.size > clisycMessaging.maxFileSize) {
					alert(strings.fileTooLarge);
					$(this).val('');
					return;
				}
				$('#clisyc-reply-attached-name').text(file.name);
			} else {
				$('#clisyc-reply-attached-name').text('');
			}
		});
	}

	function uploadFile(messageId, file, callback) {
		const formData = new FormData();
		formData.append('file', file);
		formData.append('message_id', messageId);

		$.ajax({
			url: API + 'attachments',
			method: 'POST',
			headers: { 'X-WP-Nonce': clisycMessaging.nonce },
			data: formData,
			processData: false,
			contentType: false,
			success: callback,
			error: function () {
				alert(strings.uploadFailed);
				if (callback) callback();
			}
		});
	}

	// ── Compose Modal ─────────────────────────────────────────

	function initComposeModal() {
		$('#clisyc-compose-btn').on('click', function () {
			$('#clisyc-compose-modal').show();
		});

		$('.clisyc-modal-close, .clisyc-modal-cancel, .clisyc-modal-overlay').on('click', function () {
			$(this).closest('.clisyc-modal').hide();
		});

		// Client search for admin compose.
		initComposeRecipientSearch();

		$('#clisyc-compose-send-btn').on('click', function () {
			const clientId = clisycMessaging.isAdmin
				? parseInt($('#clisyc-compose-recipient-id').val())
				: clisycMessaging.currentUserId;
			const subject = $.trim($('#clisyc-compose-subject').val());
			const body = $.trim($('#clisyc-compose-message').val());

			if (!body) return;
			if (clisycMessaging.isAdmin && !clientId) {
				alert('Please select a recipient.');
				return;
			}

			const $btn = $(this);
			$btn.prop('disabled', true).text(strings.loading);

			$.ajax({
				url: API + 'threads',
				method: 'POST',
				headers: { 'X-WP-Nonce': clisycMessaging.nonce },
				contentType: 'application/json',
				data: JSON.stringify({
					client_id: clientId,
					subject: subject,
					body: body
				}),
				success: function (data) {
					$('#clisyc-compose-modal').hide();
					$('#clisyc-compose-recipient').val('');
					$('#clisyc-compose-recipient-id').val('');
					$('#clisyc-compose-subject').val('');
					$('#clisyc-compose-message').val('');
					$btn.prop('disabled', false).text(strings.send);

					loadThreadList();
					loadMessages(data.thread_id);
				},
				error: function () {
					alert(strings.sendFailed);
					$btn.prop('disabled', false).text(strings.send);
				}
			});
		});
	}

	function initComposeRecipientSearch() {
		let searchTimeout;

		$('#clisyc-compose-recipient').on('input', function () {
			clearTimeout(searchTimeout);
			const query = $(this).val().trim();
			if (query.length < 2) {
				$('#clisyc-compose-recipient-results').empty().hide();
				return;
			}
			searchTimeout = setTimeout(function () {
				wp.apiFetch({
					path: '/wp/v2/users?search=' + encodeURIComponent(query) + '&per_page=10',
					method: 'GET',
				}).then(function (users) {
					const $results = $('#clisyc-compose-recipient-results').empty().show();
					users.forEach(function (user) {
						$('<div class="clisyc-search-item"></div>')
							.text(user.name)
							.on('click', function () {
								$('#clisyc-compose-recipient').val(user.name);
								$('#clisyc-compose-recipient-id').val(user.id);
								$results.empty().hide();
							})
							.appendTo($results);
					});
				});
			}, 300);
		});
	}

	// ══════════════════════════════════════════════════════════
	//  APPOINTMENT MESSAGES (detail page injection)
	// ══════════════════════════════════════════════════════════

	function initAppointmentMessages() {
		$('.clisyc-appointment-messages').each(function () {
			const $section = $(this);
			const appointmentId = parseInt($section.data('appointment-id'));
			const clientId = parseInt($section.data('client-id'));
			let threadId = parseInt($section.data('thread-id')) || 0;

			$section.find('.clisyc-appt-reply-send').on('click', function () {
				const $input = $section.find('.clisyc-appt-reply-input');
				const body = $.trim($input.val());
				if (!body) return;

				const $btn = $(this);
				$btn.prop('disabled', true).text(strings.loading);

				if (!threadId) {
					// Create thread + message.
					$.ajax({
						url: API + 'threads',
						method: 'POST',
						headers: { 'X-WP-Nonce': clisycMessaging.nonce },
						contentType: 'application/json',
						data: JSON.stringify({
							client_id: clientId,
							appointment_id: appointmentId,
							body: body
						}),
						success: function (data) {
							threadId = data.thread_id;
							$section.data('thread-id', threadId);
							$input.val('');
							$btn.prop('disabled', false).text(strings.send);
							// Reload page to show the new message.
							location.reload();
						},
						error: function () {
							alert(strings.sendFailed);
							$btn.prop('disabled', false).text(strings.send);
						}
					});
				} else {
					// Send to existing thread.
					$.ajax({
						url: API + 'messages',
						method: 'POST',
						headers: { 'X-WP-Nonce': clisycMessaging.nonce },
						contentType: 'application/json',
						data: JSON.stringify({
							thread_id: threadId,
							body: body
						}),
						success: function () {
							$input.val('');
							$btn.prop('disabled', false).text(strings.send);
							location.reload();
						},
						error: function () {
							alert(strings.sendFailed);
							$btn.prop('disabled', false).text(strings.send);
						}
					});
				}
			});
		});
	}

	// ══════════════════════════════════════════════════════════
	//  REAL-TIME (SSE + Polling)
	// ══════════════════════════════════════════════════════════

	function initRealtime() {
		if (clisycMessaging.realtimeEnabled && typeof EventSource !== 'undefined') {
			initSSE();
		} else if (clisycMessaging.pollInterval > 0) {
			initPolling();
		}
	}

	function initSSE() {
		const url = window.location.origin + '/?clisyc_sse=1&token=' + encodeURIComponent(clisycMessaging.nonce);

		try {
			sseSource = new EventSource(url);

			sseSource.addEventListener('new_message', function (e) {
				const data = JSON.parse(e.data);
				updateUnreadBadge(data.unread_count);

				// Reload current thread if one is open.
				if (currentThreadId) {
					loadMessages(currentThreadId);
				}
			});

			sseSource.addEventListener('timeout', function () {
				// Reconnect after a brief delay.
				sseSource.close();
				setTimeout(initSSE, 1000);
			});

			sseSource.onerror = function () {
				// SSE failed — fall back to polling.
				sseSource.close();
				sseSource = null;
				initPolling();
			};
		} catch (e) {
			// SSE not supported — fall back to polling.
			initPolling();
		}
	}

	function initPolling() {
		if (pollTimer) return; // Already polling.
		if (clisycMessaging.pollInterval <= 0) return;

		pollTimer = setInterval(function () {
			$.ajax({
				url: API + 'timeline/unread-count',
				headers: { 'X-WP-Nonce': clisycMessaging.nonce },
				success: function (data) {
					updateUnreadBadge(data.unread_count);
				}
			});
		}, clisycMessaging.pollInterval * 1000);
	}

	function updateUnreadBadge(count) {
		const $badge = $('#clisyc-inbox-unread-badge');
		if (count > 0) {
			$badge.text(count).show();
		} else {
			$badge.hide();
		}
	}

	// ── Utility ───────────────────────────────────────────────

	function esc(str) {
		if (!str) return '';
		const el = document.createElement('span');
		el.textContent = str;
		return el.innerHTML;
	}

	function nl2br(str) {
		return str.replace(/\n/g, '<br>');
	}

	function formatTime(dateStr) {
		if (!dateStr) return '';
		const date = new Date(dateStr.replace(' ', 'T') + 'Z');
		const now = new Date();
		const diff = Math.floor((now - date) / 1000);

		if (diff < 60) return 'Just now';
		if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
		if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
		if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
		return date.toLocaleDateString();
	}

	function formatSize(bytes) {
		if (bytes < 1024) return bytes + ' B';
		if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
		return (bytes / 1048576).toFixed(1) + ' MB';
	}

})(jQuery);
