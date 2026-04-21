/**
 * Messaging Admin JavaScript
 * Client Sync Pro — Encrypted Messaging Module
 *
 * Handles: Client Profile page interactions, timeline loading,
 * thread management, message composer, quick reply in meta box.
 */
(function ($) {
	'use strict';

	if (typeof clisycMessaging === 'undefined') return;

	const API = clisycMessaging.restBase;
	const strings = clisycMessaging.strings;
	let currentClientId = 0;
	let currentThreadId = 0;
	let currentPage = 1;
	let currentFilter = '';
	let pollTimer = null;

	// ── Initialization ────────────────────────────────────────

	$(document).ready(function () {
		initClientSearch();
		initTabs();
		initThreadList();
		initComposer();
		initModal();
		initQuickReply();

		// Auto-load if client_id or thread_id provided.
		const $timeline = $('#clisyc-tab-timeline');
		const $threadList = $('#clisyc-thread-list');

		currentClientId = parseInt($timeline.data('client-id')) || 0;
		currentThreadId = parseInt($('#clisyc-message-thread').data('thread-id')) || 0;

		if (currentClientId > 0) {
			loadTimeline(currentClientId);
			loadThreads(currentClientId);
		}

		if (currentThreadId > 0) {
			$('.clisyc-tab[data-tab="messages"]').click();
			loadThread(currentThreadId);
		}

		startPolling();
	});

	// ── Client Search ─────────────────────────────────────────

	function initClientSearch() {
		let searchTimeout;

		$('#clisyc-client-search-input').on('input', function () {
			clearTimeout(searchTimeout);
			const query = $(this).val().trim();
			if (query.length < 2) {
				$('#clisyc-client-search-results').empty();
				return;
			}
			searchTimeout = setTimeout(() => searchClients(query), 300);
		});
	}

	function searchClients(query) {
		wp.apiFetch({
			path: '/wp/v2/users?search=' + encodeURIComponent(query) + '&per_page=10&context=edit',
			method: 'GET',
		}).then(function (users) {
			const $results = $('#clisyc-client-search-results').empty();
			users.forEach(function (user) {
				const $item = $('<div class="clisyc-search-item"></div>')
					.append('<img src="' + user.avatar_urls['24'] + '" width="24" height="24" />')
					.append('<span>' + escHtml(user.name) + '</span>')
					.on('click', function () {
						selectClient(user.id, user.name);
					});
				$results.append($item);
			});
		});
	}

	function selectClient(clientId, clientName) {
		currentClientId = clientId;
		$('#clisyc-client-search-results').empty();
		$('#clisyc-client-search-input').val('');

		// Reload the page with client_id param for full header render.
		window.location.href = window.location.pathname + '?page=clisyc-clients&client_id=' + clientId;
	}

	// ── Tabs ──────────────────────────────────────────────────

	function initTabs() {
		$('.clisyc-tab').on('click', function () {
			const tab = $(this).data('tab');
			$('.clisyc-tab').removeClass('active');
			$(this).addClass('active');
			$('.clisyc-tab-content').hide();
			$('#clisyc-tab-' + tab).show();
		});
	}

	// ── Timeline ──────────────────────────────────────────────

	function loadTimeline(clientId, page, filter) {
		page = page || 1;
		filter = filter || '';
		currentPage = page;
		currentFilter = filter;

		const $content = $('#clisyc-timeline-content');
		$content.html('<div class="clisyc-loading">' + strings.loading + '</div>');

		let url = API + 'timeline?client_id=' + clientId + '&page=' + page + '&per_page=20';
		if (filter) url += '&filter=' + filter;

		$.ajax({
			url: url,
			headers: { 'X-WP-Nonce': clisycMessaging.nonce },
			success: function (data, status, xhr) {
				renderTimeline(data);
				renderTimelinePagination(
					parseInt(xhr.getResponseHeader('X-WP-Total')),
					parseInt(xhr.getResponseHeader('X-WP-TotalPages')),
					page
				);
				loadEventTypeFilters(clientId);
			},
			error: function () {
				$content.html('<div class="clisyc-empty-state"><p>Failed to load timeline.</p></div>');
			}
		});
	}

	function renderTimeline(events) {
		const $content = $('#clisyc-timeline-content');

		if (!events.length) {
			$content.html('<div class="clisyc-empty-state"><span class="dashicons dashicons-list-view"></span><p>' + strings.noEvents + '</p></div>');
			return;
		}

		let html = '<ul class="clisyc-timeline-list">';
		events.forEach(function (event) {
			html += '<li class="clisyc-timeline-item clisyc-timeline-type-' + escHtml(event.event_type) + '">';
			html += '<span class="clisyc-timeline-icon dashicons ' + escHtml(event.icon) + '"></span>';
			html += '<span class="clisyc-timeline-summary">' + escHtml(event.summary) + '</span>';
			html += '<time class="clisyc-timeline-date">' + formatRelativeTime(event.event_date) + '</time>';
			html += '</li>';
		});
		html += '</ul>';

		$content.html(html);
	}

	function renderTimelinePagination(total, totalPages, currentPage) {
		const $pagination = $('#clisyc-timeline-pagination');

		if (totalPages <= 1) {
			$pagination.hide();
			return;
		}

		let html = '';
		html += '<button ' + (currentPage <= 1 ? 'disabled' : '') + ' data-page="' + (currentPage - 1) + '">&laquo; Prev</button>';
		html += '<span>Page ' + currentPage + ' of ' + totalPages + '</span>';
		html += '<button ' + (currentPage >= totalPages ? 'disabled' : '') + ' data-page="' + (currentPage + 1) + '">Next &raquo;</button>';

		$pagination.html(html).show();

		$pagination.find('button').off('click').on('click', function () {
			const page = parseInt($(this).data('page'));
			if (page > 0) loadTimeline(currentClientId, page, currentFilter);
		});
	}

	function loadEventTypeFilters(clientId) {
		$.ajax({
			url: API + 'timeline/event-types?client_id=' + clientId,
			headers: { 'X-WP-Nonce': clisycMessaging.nonce },
			success: function (types) {
				const $filters = $('#clisyc-timeline-filters');
				$filters.empty();

				$filters.append(
					$('<button class="clisyc-timeline-filter-btn active" data-filter="">' + strings.allTypes + '</button>')
				);

				types.forEach(function (type) {
					$filters.append(
						$('<button class="clisyc-timeline-filter-btn" data-filter="' + escHtml(type.slug) + '">' + escHtml(type.label) + '</button>')
					);
				});

				$filters.find('.clisyc-timeline-filter-btn').on('click', function () {
					$filters.find('.clisyc-timeline-filter-btn').removeClass('active');
					$(this).addClass('active');
					loadTimeline(currentClientId, 1, $(this).data('filter'));
				});
			}
		});
	}

	// ── Thread List ───────────────────────────────────────────

	function initThreadList() {
		// Thread list click delegation.
		$('#clisyc-thread-list').on('click', '.clisyc-thread-item', function () {
			const threadId = $(this).data('thread-id');
			$('.clisyc-thread-item').removeClass('active');
			$(this).addClass('active').removeClass('unread');
			loadThread(threadId);
			$('.clisyc-tab[data-tab="messages"]').click();
		});
	}

	function loadThreads(clientId) {
		const $list = $('#clisyc-thread-list');
		$list.html('<div class="clisyc-loading">' + strings.loading + '</div>');

		let url = API + 'threads?per_page=50';
		if (clientId) url += '&client_id=' + clientId;

		$.ajax({
			url: url,
			headers: { 'X-WP-Nonce': clisycMessaging.nonce },
			success: function (threads) {
				renderThreadList(threads);
			},
			error: function () {
				$list.html('<div class="clisyc-loading">Failed to load threads.</div>');
			}
		});
	}

	function renderThreadList(threads) {
		const $list = $('#clisyc-thread-list');

		if (!threads.length) {
			$list.html('<div class="clisyc-empty-state"><p>' + strings.noMessages + '</p></div>');
			return;
		}

		let html = '';
		threads.forEach(function (thread) {
			const isActive = thread.thread_id === currentThreadId;
			const isUnread = thread.unread_count > 0;

			html += '<div class="clisyc-thread-item' + (isActive ? ' active' : '') + (isUnread ? ' unread' : '') + '" data-thread-id="' + thread.thread_id + '">';
			html += '<img src="' + escHtml(thread.client_avatar) + '" width="32" height="32" />';
			html += '<div class="clisyc-thread-info">';
			html += '<div class="clisyc-thread-subject">' + escHtml(thread.subject || thread.client_name) + '</div>';
			html += '<div class="clisyc-thread-meta">' + escHtml(thread.client_name) + ' · ' + formatRelativeTime(thread.last_message_at || thread.created_at) + '</div>';
			html += '</div>';
			if (isUnread) {
				html += '<span class="clisyc-thread-badge">' + thread.unread_count + '</span>';
			}
			html += '</div>';
		});

		$list.html(html);
	}

	// ── Thread / Messages ─────────────────────────────────────

	function loadThread(threadId) {
		currentThreadId = threadId;
		const $thread = $('#clisyc-message-thread');
		$thread.html('<div class="clisyc-loading">' + strings.loading + '</div>');

		$.ajax({
			url: API + 'threads/' + threadId,
			headers: { 'X-WP-Nonce': clisycMessaging.nonce },
			success: function (data) {
				renderMessages(data.messages);
				$('#clisyc-message-composer').show().data('thread-id', threadId);
				$('#clisyc-send-btn').prop('disabled', true);
			},
			error: function () {
				$thread.html('<div class="clisyc-empty-state"><p>Failed to load messages.</p></div>');
			}
		});
	}

	function renderMessages(messages) {
		const $thread = $('#clisyc-message-thread');

		if (!messages.length) {
			$thread.html('<div class="clisyc-empty-state"><p>' + strings.noMessages + '</p></div>');
			return;
		}

		let html = '<div class="clisyc-message-list clisyc-message-compact">';
		messages.forEach(function (msg) {
			const isMine = msg.sender_id === clisycMessaging.currentUserId;
			html += '<div class="clisyc-message-item ' + (isMine ? 'clisyc-message-admin' : 'clisyc-message-client') + '">';
			html += '<div class="clisyc-message-header">';
			html += '<img src="' + escHtml(msg.sender_avatar) + '" width="24" height="24" />';
			html += '<strong>' + escHtml(msg.sender_name) + '</strong>';
			html += '<time>' + formatRelativeTime(msg.created_at) + '</time>';
			html += '</div>';
			html += '<div class="clisyc-message-body">' + nl2br(escHtml(msg.body)) + '</div>';

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

		$thread.html(html);

		// Scroll to bottom.
		$thread.scrollTop($thread[0].scrollHeight);
	}

	// ── Composer ──────────────────────────────────────────────

	function initComposer() {
		const $body = $('#clisyc-compose-body');
		const $sendBtn = $('#clisyc-send-btn');

		$body.on('input', function () {
			$sendBtn.prop('disabled', !$.trim($(this).val()));
		});

		$sendBtn.on('click', function () {
			const body = $.trim($body.val());
			if (!body) return;

			const threadId = $('#clisyc-message-composer').data('thread-id');
			sendMessage(threadId, body);
		});

		// File attachment.
		$('#clisyc-compose-file').on('change', function () {
			const file = this.files[0];
			if (file) {
				$('#clisyc-attached-file-name').text(file.name);
			} else {
				$('#clisyc-attached-file-name').text('');
			}
		});
	}

	function sendMessage(threadId, body) {
		const $sendBtn = $('#clisyc-send-btn');
		$sendBtn.prop('disabled', true).text(strings.loading);

		$.ajax({
			url: API + 'messages',
			method: 'POST',
			headers: { 'X-WP-Nonce': clisycMessaging.nonce },
			contentType: 'application/json',
			data: JSON.stringify({
				thread_id: threadId,
				body: body
			}),
			success: function (data) {
				$('#clisyc-compose-body').val('');
				$('#clisyc-compose-file').val('');
				$('#clisyc-attached-file-name').text('');
				$sendBtn.text(strings.sendMessage);

				// Handle file attachment upload.
				const file = $('#clisyc-compose-file')[0].files[0];
				if (file && data.message_id) {
					uploadAttachment(data.message_id, file);
				}

				loadThread(threadId);
				if (currentClientId) loadThreads(currentClientId);
			},
			error: function () {
				alert(strings.sendFailed);
				$sendBtn.prop('disabled', false).text(strings.sendMessage);
			}
		});
	}

	function uploadAttachment(messageId, file) {
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
			success: function () {
				loadThread(currentThreadId);
			}
		});
	}

	// ── Modal (New Thread) ────────────────────────────────────

	function initModal() {
		$('#clisyc-new-thread-btn').on('click', function () {
			$('#clisyc-new-thread-modal').show();
		});

		$('.clisyc-modal-close, .clisyc-modal-cancel, .clisyc-modal-overlay').on('click', function () {
			$(this).closest('.clisyc-modal').hide();
		});

		$('#clisyc-create-thread-btn').on('click', function () {
			const clientId = parseInt($('#clisyc-new-thread-btn').data('client-id')) || currentClientId;
			const subject = $.trim($('#clisyc-thread-subject').val());
			const body = $.trim($('#clisyc-thread-body').val());

			if (!clientId) return;

			$(this).prop('disabled', true).text(strings.loading);

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
					$('#clisyc-new-thread-modal').hide();
					$('#clisyc-thread-subject').val('');
					$('#clisyc-thread-body').val('');
					$('#clisyc-create-thread-btn').prop('disabled', false).text(strings.sendMessage);

					loadThreads(currentClientId);
					loadThread(data.thread_id);
					$('.clisyc-tab[data-tab="messages"]').click();
				},
				error: function () {
					alert(strings.sendFailed);
					$('#clisyc-create-thread-btn').prop('disabled', false).text(strings.sendMessage);
				}
			});
		});
	}

	// ── Quick Reply (Meta Box) ────────────────────────────────

	function initQuickReply() {
		$('.clisyc-quick-reply-send').on('click', function () {
			const $container = $(this).closest('.clisyc-quick-reply');
			const $input = $container.find('.clisyc-quick-reply-input');
			const body = $.trim($input.val());
			if (!body) return;

			let threadId = parseInt($container.data('thread-id'));
			const $metabox = $(this).closest('.clisyc-metabox-timeline');
			const clientId = parseInt($metabox.data('client-id'));
			const appointmentId = parseInt($metabox.data('appointment-id'));
			const $btn = $(this);

			$btn.prop('disabled', true).text(strings.loading);

			// If no thread, create one first.
			if (!threadId) {
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
						$container.data('thread-id', data.thread_id);
						$input.val('');
						$btn.prop('disabled', false).text(strings.sendMessage);
						// Reload page to show the message.
						location.reload();
					},
					error: function () {
						alert(strings.sendFailed);
						$btn.prop('disabled', false).text(strings.sendMessage);
					}
				});
			} else {
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
						$btn.prop('disabled', false).text(strings.sendMessage);
						location.reload();
					},
					error: function () {
						alert(strings.sendFailed);
						$btn.prop('disabled', false).text(strings.sendMessage);
					}
				});
			}
		});
	}

	// ── Polling ───────────────────────────────────────────────

	function startPolling() {
		if (clisycMessaging.pollInterval <= 0) return;

		pollTimer = setInterval(function () {
			$.ajax({
				url: API + 'timeline/unread-count',
				headers: { 'X-WP-Nonce': clisycMessaging.nonce },
				success: function (data) {
					const $badge = $('#clisyc-messages-badge');
					if (data.unread_count > 0) {
						$badge.text(data.unread_count).show();
					} else {
						$badge.hide();
					}
				}
			});
		}, clisycMessaging.pollInterval * 1000);
	}

	// ── Utility ───────────────────────────────────────────────

	function escHtml(str) {
		if (!str) return '';
		const div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	function nl2br(str) {
		return str.replace(/\n/g, '<br>');
	}

	function formatRelativeTime(dateStr) {
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

	function formatFileSize(bytes) {
		if (bytes < 1024) return bytes + ' B';
		if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB';
		return (bytes / 1048576).toFixed(1) + ' MB';
	}

})(jQuery);
