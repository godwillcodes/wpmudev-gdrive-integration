/**
 * Posts Maintenance History JavaScript
 * Handles view details and delete functionality
 */

(function () {
	'use strict';

	// Check if localization data exists
	if (typeof wpmudevPostsMaintenanceHistory === 'undefined') {
		console.error('wpmudevPostsMaintenanceHistory localization data not found');
		return;
	}

	const config = wpmudevPostsMaintenanceHistory;

	/**
	 * Initialize
	 */
	function init() {
		bindEvents();
	}

	/**
	 * Bind event listeners
	 */
	function bindEvents() {
		// View details buttons
		const viewButtons = document.querySelectorAll('.wpmudev-view-scan-details');
		viewButtons.forEach(function (button) {
			button.addEventListener('click', handleViewDetails);
		});

		// Delete buttons
		const deleteButtons = document.querySelectorAll('.wpmudev-delete-scan');
		deleteButtons.forEach(function (button) {
			button.addEventListener('click', handleDelete);
		});

		// Modal close buttons
		const closeButtons = document.querySelectorAll('[data-modal-close]');
		closeButtons.forEach(function (button) {
			button.addEventListener('click', closeModal);
		});

		// Close modal on backdrop click
		const modal = document.getElementById('wpmudev-scan-details-modal');
		if (modal) {
			modal.addEventListener('click', function (e) {
				if (e.target === modal) {
					closeModal();
				}
			});
		}
	}

	/**
	 * Handle view details click
	 */
	function handleViewDetails(e) {
		const button = e.currentTarget;
		const scanId = button.getAttribute('data-scan-id');

		if (!scanId) {
			return;
		}

		loadScanDetails(scanId);
	}

	/**
	 * Load scan details
	 */
	function loadScanDetails(scanId) {
		const modal = document.getElementById('wpmudev-scan-details-modal');
		const content = document.getElementById('wpmudev-scan-details-content');

		if (!modal || !content) {
			return;
		}

		// Show modal
		modal.setAttribute('aria-hidden', 'false');
		modal.classList.add('sui-modal-open');
		content.innerHTML = '<p class="sui-description">' + escapeHtml('Loading...') + '</p>';

		// Fetch scan details
		fetch(config.restBase + config.endpoints.get + scanId, {
			method: 'GET',
			headers: {
				'X-WP-Nonce': config.nonce,
			},
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (data) {
				if (data.scan_id) {
					renderScanDetails(data, content);
				} else {
					content.innerHTML = '<p class="sui-description sui-notice-error">' + escapeHtml(config.strings.loadError) + '</p>';
				}
			})
			.catch(function (error) {
				console.error('Load scan details error:', error);
				content.innerHTML = '<p class="sui-description sui-notice-error">' + escapeHtml(config.strings.loadError) + '</p>';
			});
	}

	/**
	 * Render scan details
	 */
	function renderScanDetails(record, container) {
		const timestamp = record.timestamp ? new Date(record.timestamp * 1000).toLocaleString() : 'N/A';
		const status = record.status ? record.status.charAt(0).toUpperCase() + record.status.slice(1) : 'N/A';
		const total = record.total || 0;
		const processed = record.processed || 0;
		const health = record.health_score !== undefined ? record.health_score.toFixed(1) : '0.0';
		const postTypes = record.post_types && Array.isArray(record.post_types) ? record.post_types.join(', ') : 'N/A';
		const context = record.context ? record.context.charAt(0).toUpperCase() + record.context.slice(1) : 'Manual';

		const metrics = record.metrics || {};
		const published = metrics.published_posts || 0;
		const drafts = metrics.draft_private_posts || 0;
		const brokenLinks = metrics.posts_with_broken_links || 0;
		const blankContent = metrics.posts_with_blank_content || 0;
		const missingImages = metrics.posts_missing_featured_image || 0;

		let html = '<div class="wpmudev-scan-details">';

		// Basic Info
		html += '<div class="wpmudev-scan-details-section">';
		html += '<h4>' + escapeHtml('Basic Information') + '</h4>';
		html += '<table class="wpmudev-scan-details-table">';
		html += '<tr><td><strong>' + escapeHtml('Date:') + '</strong></td><td>' + escapeHtml(timestamp) + '</td></tr>';
		html += '<tr><td><strong>' + escapeHtml('Status:') + '</strong></td><td>' + escapeHtml(status) + '</td></tr>';
		html += '<tr><td><strong>' + escapeHtml('Context:') + '</strong></td><td>' + escapeHtml(context) + '</td></tr>';
		html += '<tr><td><strong>' + escapeHtml('Post Types:') + '</strong></td><td>' + escapeHtml(postTypes) + '</td></tr>';
		html += '<tr><td><strong>' + escapeHtml('Total Posts:') + '</strong></td><td>' + escapeHtml(total) + '</td></tr>';
		html += '<tr><td><strong>' + escapeHtml('Processed:') + '</strong></td><td>' + escapeHtml(processed) + '</td></tr>';
		html += '</table>';
		html += '</div>';

		// Health Score
		html += '<div class="wpmudev-scan-details-section">';
		html += '<h4>' + escapeHtml('Site Health Score') + '</h4>';
		html += '<div class="wpmudev-scan-details-health">';
		html += '<div class="wpmudev-scan-details-health-value">' + escapeHtml(health) + '%</div>';
		html += '</div>';
		html += '</div>';

		// Metrics
		html += '<div class="wpmudev-scan-details-section">';
		html += '<h4>' + escapeHtml('Metrics') + '</h4>';
		html += '<table class="wpmudev-scan-details-table">';
		html += '<tr><td><strong>' + escapeHtml('Published Posts:') + '</strong></td><td>' + escapeHtml(published) + '</td></tr>';
		html += '<tr><td><strong>' + escapeHtml('Drafts/Private:') + '</strong></td><td>' + escapeHtml(drafts) + '</td></tr>';
		html += '<tr><td><strong>' + escapeHtml('Broken Internal Links:') + '</strong></td><td>' + escapeHtml(brokenLinks) + '</td></tr>';
		html += '<tr><td><strong>' + escapeHtml('Blank Content:') + '</strong></td><td>' + escapeHtml(blankContent) + '</td></tr>';
		html += '<tr><td><strong>' + escapeHtml('Missing Featured Images:') + '</strong></td><td>' + escapeHtml(missingImages) + '</td></tr>';
		html += '</table>';
		html += '</div>';

		html += '</div>';

		container.innerHTML = html;
	}

	/**
	 * Handle delete click
	 */
	function handleDelete(e) {
		const button = e.currentTarget;
		const scanId = button.getAttribute('data-scan-id');

		if (!scanId) {
			return;
		}

		if (!confirm(config.strings.deleteConfirm)) {
			return;
		}

		deleteScan(scanId, button);
	}

	/**
	 * Delete scan record
	 */
	function deleteScan(scanId, button) {
		const recordElement = button.closest('.wpmudev-scan-record');

		if (!recordElement) {
			return;
		}

		// Disable button
		button.disabled = true;
		button.textContent = 'Deleting...';

		fetch(config.restBase + config.endpoints.delete + scanId, {
			method: 'DELETE',
			headers: {
				'X-WP-Nonce': config.nonce,
			},
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (data) {
				if (data.success) {
					// Remove record from DOM
					recordElement.style.transition = 'opacity 0.3s ease';
					recordElement.style.opacity = '0';
					setTimeout(function () {
						recordElement.remove();
						showNotice('success', config.strings.deleteSuccess);

						// If no records left, show empty state
						const container = document.querySelector('.wpmudev-scan-history-list');
						if (container && container.children.length === 0) {
							location.reload();
						}
					}, 300);
				} else {
					button.disabled = false;
					button.textContent = 'Delete';
					showNotice('error', data.message || config.strings.deleteError);
				}
			})
			.catch(function (error) {
				console.error('Delete scan error:', error);
				button.disabled = false;
				button.textContent = 'Delete';
				showNotice('error', config.strings.deleteError);
			});
	}

	/**
	 * Close modal
	 */
	function closeModal() {
		const modal = document.getElementById('wpmudev-scan-details-modal');
		if (modal) {
			modal.setAttribute('aria-hidden', 'true');
			modal.classList.remove('sui-modal-open');
		}
	}

	/**
	 * Show notice
	 */
	function showNotice(type, message) {
		const notice = document.getElementById('wpmudev-scan-history-notice');
		if (!notice) {
			return;
		}

		notice.className = 'sui-notice sui-notice-' + type;
		notice.style.display = 'block';
		notice.innerHTML = '<p>' + escapeHtml(message) + '</p>';

		// Auto-hide after 5 seconds
		setTimeout(function () {
			notice.style.display = 'none';
		}, 5000);
	}

	/**
	 * Escape HTML
	 */
	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	// Initialize when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

