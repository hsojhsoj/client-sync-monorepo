/**
 * File: clisyc-admin-client-search.js
 * Client Search Autocomplete for Appointment Edit Screen
 * Location: src/assets/js/clisyc-admin-client-search.js
 *
 * Replaces the dropdown select with a searchable autocomplete input.
 * Searches users by name and email via REST API.
 *
 * @package ClientSync
 */

(function() {
    'use strict';

    // Configuration
    const CONFIG = {
        minChars: 2,           // Minimum characters before searching
        debounceMs: 300,       // Debounce delay for API calls
        maxResults: 10,        // Maximum results to show
        inputSelector: '#clisyc-client-search-input',
        hiddenSelector: '#clisyc-client-id',
        resultsSelector: '#clisyc-client-search-results',
        containerSelector: '.clisyc-client-search-container',
        selectedSelector: '.clisyc-client-selected',
    };

    // State
    let debounceTimer = null;
    let currentResults = [];
    let selectedIndex = -1;
    let isOpen = false;

    /**
     * Initialize the client search when DOM is ready
     */
    document.addEventListener('DOMContentLoaded', function() {
        const container = document.querySelector(CONFIG.containerSelector);
        if (!container) return;

        const input = document.querySelector(CONFIG.inputSelector);
        const hidden = document.querySelector(CONFIG.hiddenSelector);
        const results = document.querySelector(CONFIG.resultsSelector);
        const selected = document.querySelector(CONFIG.selectedSelector);

        if (!input || !hidden || !results) return;

        // If there's an existing client ID, load their info
        if (hidden.value && parseInt(hidden.value) > 0) {
            loadExistingUser(hidden.value, selected, input);
        }

        // Input event handlers
        input.addEventListener('input', function(e) {
            handleInput(e.target.value, results, hidden, selected);
        });

        input.addEventListener('keydown', function(e) {
            handleKeydown(e, input, results, hidden, selected);
        });

        input.addEventListener('focus', function() {
            if (input.value.length >= CONFIG.minChars && currentResults.length > 0) {
                showResults(results);
            }
        });

        // Close results when clicking outside
        document.addEventListener('click', function(e) {
            if (!container.contains(e.target)) {
                hideResults(results);
            }
        });

        // Clear button handler
        const clearBtn = container.querySelector('.clisyc-client-clear-btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', function(e) {
                e.preventDefault();
                clearSelection(input, hidden, selected, results);
            });
        }
    });

    /**
     * Handle input changes with debouncing
     */
    function handleInput(query, resultsEl, hiddenEl, selectedEl) {
        clearTimeout(debounceTimer);
        
        // Clear selection if user is typing
        if (hiddenEl.value && query !== '') {
            // Don't clear if they just selected something
        }

        if (query.length < CONFIG.minChars) {
            hideResults(resultsEl);
            currentResults = [];
            return;
        }

        debounceTimer = setTimeout(function() {
            searchUsers(query, resultsEl, hiddenEl, selectedEl);
        }, CONFIG.debounceMs);
    }

    /**
     * Handle keyboard navigation
     */
    function handleKeydown(e, inputEl, resultsEl, hiddenEl, selectedEl) {
        if (!isOpen) {
            if (e.key === 'ArrowDown' && inputEl.value.length >= CONFIG.minChars) {
                searchUsers(inputEl.value, resultsEl, hiddenEl, selectedEl);
            }
            return;
        }

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, currentResults.length - 1);
                highlightResult(resultsEl);
                break;

            case 'ArrowUp':
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, 0);
                highlightResult(resultsEl);
                break;

            case 'Enter':
                e.preventDefault();
                if (selectedIndex >= 0 && currentResults[selectedIndex]) {
                    selectUser(currentResults[selectedIndex], inputEl, hiddenEl, selectedEl, resultsEl);
                }
                break;

            case 'Escape':
                hideResults(resultsEl);
                break;

            case 'Tab':
                hideResults(resultsEl);
                break;
        }
    }

    /**
     * Search users via REST API
     */
    function searchUsers(query, resultsEl, hiddenEl, selectedEl) {
        const url = new URL(window.clisycClientSearch.restUrl + '/users/search', window.location.origin);
        url.searchParams.set('q', query);
        url.searchParams.set('limit', CONFIG.maxResults);

        resultsEl.innerHTML = '<div class="clisyc-client-search-loading"><span class="spinner is-active"></span> Searching...</div>';
        showResults(resultsEl);

        fetch(url.toString(), {
            method: 'GET',
            headers: {
                'X-WP-Nonce': window.clisycClientSearch.nonce,
                'Content-Type': 'application/json',
            },
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Search failed');
            }
            return response.json();
        })
        .then(data => {
            currentResults = data.results || [];
            selectedIndex = -1;
            renderResults(currentResults, resultsEl, hiddenEl, selectedEl, query);
        })
        .catch(error => {
            console.error('[ClientSearch] Error:', error);
            resultsEl.innerHTML = '<div class="clisyc-client-search-error">Search failed. Please try again.</div>';
        });
    }

    /**
     * Render search results
     */
    function renderResults(users, resultsEl, hiddenEl, selectedEl, query) {
        if (users.length === 0) {
            resultsEl.innerHTML = `
                <div class="clisyc-client-search-empty">
                    <span class="dashicons dashicons-info-outline"></span>
                    No clients found for "${escapeHtml(query)}"
                </div>
            `;
            return;
        }

        const html = users.map((user, index) => {
            const roleLabels = user.roles.map(r => formatRole(r)).join(', ');
            
            return `
                <div class="clisyc-client-search-item" data-index="${index}" data-user-id="${user.id}">
                    <img class="clisyc-client-avatar" src="${escapeHtml(user.avatar_url)}" alt="" />
                    <div class="clisyc-client-info">
                        <div class="clisyc-client-name">${highlightMatch(user.display_name, query)}</div>
                        <div class="clisyc-client-email">${highlightMatch(user.email, query)}</div>
                    </div>
                    <div class="clisyc-client-role">${escapeHtml(roleLabels)}</div>
                </div>
            `;
        }).join('');

        resultsEl.innerHTML = html;

        // Add click handlers to results
        resultsEl.querySelectorAll('.clisyc-client-search-item').forEach(item => {
            item.addEventListener('click', function() {
                const index = parseInt(this.dataset.index);
                if (currentResults[index]) {
                    selectUser(currentResults[index], 
                        document.querySelector(CONFIG.inputSelector),
                        hiddenEl, 
                        selectedEl, 
                        resultsEl
                    );
                }
            });

            item.addEventListener('mouseenter', function() {
                selectedIndex = parseInt(this.dataset.index);
                highlightResult(resultsEl);
            });
        });

        showResults(resultsEl);
    }

    /**
     * Highlight the currently selected result
     */
    function highlightResult(resultsEl) {
        resultsEl.querySelectorAll('.clisyc-client-search-item').forEach((item, index) => {
            item.classList.toggle('is-highlighted', index === selectedIndex);
        });

        // Scroll highlighted item into view
        const highlighted = resultsEl.querySelector('.is-highlighted');
        if (highlighted) {
            highlighted.scrollIntoView({ block: 'nearest' });
        }
    }

    /**
     * Select a user
     */
    function selectUser(user, inputEl, hiddenEl, selectedEl, resultsEl) {
        // Update hidden field with user ID
        hiddenEl.value = user.id;

        // Update the input to show selected user
        inputEl.value = '';
        inputEl.placeholder = 'Search for a different client...';

        // Show selected user display
        if (selectedEl) {
            selectedEl.innerHTML = `
                <img class="clisyc-client-avatar" src="${escapeHtml(user.avatar_url)}" alt="" />
                <div class="clisyc-client-info">
                    <strong>${escapeHtml(user.display_name)}</strong>
                    <span class="clisyc-client-email">${escapeHtml(user.email)}</span>
                </div>
                <button type="button" class="clisyc-client-clear-btn" title="Clear selection">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            `;
            selectedEl.classList.add('has-selection');

            // Re-attach clear button handler
            const clearBtn = selectedEl.querySelector('.clisyc-client-clear-btn');
            if (clearBtn) {
                clearBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    clearSelection(inputEl, hiddenEl, selectedEl, resultsEl);
                });
            }
        }

        hideResults(resultsEl);
        currentResults = [];
        
        // Trigger change event for any listeners
        hiddenEl.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /**
     * Clear the current selection
     */
    function clearSelection(inputEl, hiddenEl, selectedEl, resultsEl) {
        hiddenEl.value = '';
        inputEl.value = '';
        inputEl.placeholder = 'Type to search clients by name or email...';
        inputEl.focus();

        if (selectedEl) {
            selectedEl.innerHTML = '';
            selectedEl.classList.remove('has-selection');
        }

        hideResults(resultsEl);
        currentResults = [];

        // Trigger change event
        hiddenEl.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /**
     * Load existing user data for pre-selected client
     */
    function loadExistingUser(userId, selectedEl, inputEl) {
        const url = window.clisycClientSearch.restUrl + '/users/' + userId;

        fetch(url, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': window.clisycClientSearch.nonce,
                'Content-Type': 'application/json',
            },
        })
        .then(response => response.json())
        .then(user => {
            if (user && user.id) {
                if (selectedEl) {
                    selectedEl.innerHTML = `
                        <img class="clisyc-client-avatar" src="${escapeHtml(user.avatar_url)}" alt="" />
                        <div class="clisyc-client-info">
                            <strong>${escapeHtml(user.display_name)}</strong>
                            <span class="clisyc-client-email">${escapeHtml(user.email)}</span>
                        </div>
                        <button type="button" class="clisyc-client-clear-btn" title="Clear selection">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    `;
                    selectedEl.classList.add('has-selection');

                    // Attach clear button handler
                    const clearBtn = selectedEl.querySelector('.clisyc-client-clear-btn');
                    if (clearBtn) {
                        clearBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            clearSelection(
                                inputEl,
                                document.querySelector(CONFIG.hiddenSelector),
                                selectedEl,
                                document.querySelector(CONFIG.resultsSelector)
                            );
                        });
                    }
                }
                inputEl.placeholder = 'Search for a different client...';
            }
        })
        .catch(error => {
            console.error('[ClientSearch] Error loading existing user:', error);
        });
    }

    /**
     * Show results dropdown
     */
    function showResults(resultsEl) {
        resultsEl.classList.add('is-open');
        isOpen = true;
    }

    /**
     * Hide results dropdown
     */
    function hideResults(resultsEl) {
        resultsEl.classList.remove('is-open');
        isOpen = false;
        selectedIndex = -1;
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Highlight matching text in search results
     */
    function highlightMatch(text, query) {
        if (!text || !query) return escapeHtml(text);
        
        const escaped = escapeHtml(text);
        const regex = new RegExp('(' + escapeRegex(query) + ')', 'gi');
        return escaped.replace(regex, '<mark>$1</mark>');
    }

    /**
     * Escape regex special characters
     */
    function escapeRegex(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * Format role slug to readable label
     */
    function formatRole(role) {
        const roleMap = {
            'administrator': 'Admin',
            'editor': 'Editor',
            'author': 'Author',
            'contributor': 'Contributor',
            'subscriber': 'Subscriber',
            'customer': 'Customer',
            'shop_manager': 'Shop Manager',
        };
        return roleMap[role] || role.charAt(0).toUpperCase() + role.slice(1).replace(/_/g, ' ');
    }

})();