/**
 * Posts Maintenance JavaScript Controller
 * Handles UI interactions, REST API calls, and progress polling
 */

(function () {
	'use strict';

	// Check if localization data exists
	if (typeof wpmudevPostsMaintenance === 'undefined') {
		console.error('wpmudevPostsMaintenance localization data not found');
		return;
	}

	const config = wpmudevPostsMaintenance;
	const wrapperId = 'wpmudev-posts-maintenance-app';

	// DOM Elements
	const elements = {
		postTypesContainer: document.getElementById(wrapperId + '-post-types'),
		startButton: document.getElementById(wrapperId + '-start'),
		refreshButton: document.getElementById(wrapperId + '-refresh'),
		progressBar: document.getElementById(wrapperId + '-progress-bar'),
		progressText: document.getElementById(wrapperId + '-progress-text'),
		counts: document.getElementById(wrapperId + '-counts'),
		lastRun: document.getElementById(wrapperId + '-last-run'),
		dashboard: document.getElementById(wrapperId + '-dashboard'),
		notice: document.getElementById(wrapperId + '-notice'),
	};

	// State
	let pollingInterval = null;
	let selectedPostTypes = [];

	/**
	 * Initialize the application
	 */
	function init() {
		if (!elements.postTypesContainer || !elements.startButton) {
			console.error('Required DOM elements not found');
			return;
		}

		renderPostTypes();
		updateUI(config.job, config.lastRun);
		bindEvents();

		// Start polling if job is active
		if (config.job && ['pending', 'running'].includes(config.job.status)) {
			startPolling();
		}
	}

	/**
	 * Render post type checkboxes
	 */
	function renderPostTypes() {
		if (!elements.postTypesContainer || !config.postTypes || !config.postTypes.length) {
			return;
		}

		const grid = document.createElement('div');
		grid.className = 'wpmudev-post-types-grid';

		config.postTypes.forEach(function (postType) {
			const label = document.createElement('label');
			const checkbox = document.createElement('input');
			checkbox.type = 'checkbox';
			checkbox.value = postType.slug;
			checkbox.checked = ['post', 'page'].includes(postType.slug); // Default selection

			if (checkbox.checked) {
				selectedPostTypes.push(postType.slug);
			}

			checkbox.addEventListener('change', function () {
				if (this.checked) {
					if (!selectedPostTypes.includes(postType.slug)) {
						selectedPostTypes.push(postType.slug);
					}
				} else {
					selectedPostTypes = selectedPostTypes.filter(function (slug) {
						return slug !== postType.slug;
					});
				}
			});

			label.appendChild(checkbox);
			label.appendChild(document.createTextNode(postType.label));
			grid.appendChild(label);
		});

		elements.postTypesContainer.appendChild(grid);
	}

	/**
	 * Bind event listeners
	 */
	function bindEvents() {
		if (elements.startButton) {
			elements.startButton.addEventListener('click', handleStartScan);
		}

		if (elements.refreshButton) {
			elements.refreshButton.addEventListener('click', handleRefreshStatus);
		}
	}

	/**
	 * Handle scan start
	 */
	function handleStartScan() {
		if (!selectedPostTypes.length) {
			showNotice('error', config.strings.selectPostTypes);
			return;
		}

		if (elements.startButton.disabled) {
			return;
		}

		setButtonLoading(elements.startButton, true);
		hideNotice();

		fetch(config.restBase + config.endpoints.start, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify({
				post_types: selectedPostTypes,
			}),
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (data) {
				setButtonLoading(elements.startButton, false);

				if (data.success) {
					showNotice('success', config.strings.scanStarted);
					updateUI(data.job, config.lastRun);
					startPolling();
				} else {
					const message = data.message || data.data?.message || config.strings.scanFailed;
					showNotice('error', message);
				}
			})
			.catch(function (error) {
				setButtonLoading(elements.startButton, false);
				console.error('Scan start error:', error);
				showNotice('error', config.strings.scanFailed + ': ' + error.message);
			});
	}

	/**
	 * Handle refresh status
	 */
	function handleRefreshStatus() {
		if (elements.refreshButton.disabled) {
			return;
		}

		setButtonLoading(elements.refreshButton, true);
		fetchStatus();
	}

	/**
	 * Fetch current status from API
	 */
	function fetchStatus() {
		fetch(config.restBase + config.endpoints.status, {
			method: 'GET',
			headers: {
				'X-WP-Nonce': config.nonce,
			},
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (data) {
				setButtonLoading(elements.refreshButton, false);

				if (data.job && ['pending', 'running'].includes(data.job.status)) {
					updateUI(data.job, data.lastRun);
					if (!pollingInterval) {
						startPolling();
					}
				} else {
					updateUI(data.job, data.lastRun);
					stopPolling();
				}
			})
			.catch(function (error) {
				setButtonLoading(elements.refreshButton, false);
				console.error('Status fetch error:', error);
			});
	}

	/**
	 * Start polling for status updates
	 */
	function startPolling() {
		if (pollingInterval) {
			return;
		}

		// Poll every 5 seconds
		pollingInterval = setInterval(function () {
			fetchStatus();
		}, 5000);

		// Initial fetch
		fetchStatus();
	}

	/**
	 * Stop polling
	 */
	function stopPolling() {
		if (pollingInterval) {
			clearInterval(pollingInterval);
			pollingInterval = null;
		}
	}

	/**
	 * Update UI with job and last run data
	 */
	function updateUI(job, lastRun) {
		// Update progress bar
		if (elements.progressBar && job) {
			const progress = job.progress || 0;
			const span = elements.progressBar.querySelector('span');
			if (span) {
				span.style.width = progress + '%';
			}
		}

		// Update progress text
		if (elements.progressText) {
			if (!job || !['pending', 'running'].includes(job.status)) {
				elements.progressText.textContent = 'No active scan.';
			} else {
				const statusText = job.status === 'pending' ? 'Pending' : 'Running';
				elements.progressText.textContent = statusText + '...';
			}
		}

		// Update counts
		if (elements.counts && job) {
			if (job.total > 0) {
				elements.counts.textContent =
					'Processed: ' + job.processed + ' / ' + job.total + ' (' + (job.progress || 0) + '%)';
			} else {
				elements.counts.textContent = '';
			}
		}

		// Update last run
		if (elements.lastRun) {
			if (lastRun && lastRun.timestamp) {
				const date = new Date(lastRun.timestamp * 1000);
				const dateStr = date.toLocaleString();
				const processed = lastRun.processed || 0;
				const total = lastRun.total || 0;
				const postTypes = lastRun.post_types || [];
				const status = lastRun.status || 'completed';
				
				let html = '<p>';
				html += '<strong>Date:</strong> ' + escapeHtml(dateStr) + '<br>';
				html += '<strong>Status:</strong> ' + escapeHtml(status.charAt(0).toUpperCase() + status.slice(1)) + '<br>';
				html += '<strong>Processed:</strong> ' + processed + ' of ' + total + ' posts<br>';
				if (postTypes.length > 0) {
					html += '<strong>Post Types:</strong> ' + escapeHtml(postTypes.join(', '));
				}
				html += '</p>';
				elements.lastRun.innerHTML = html;
			} else {
				elements.lastRun.innerHTML = '<p class="sui-description">No previous scan recorded.</p>';
			}
		}

		// Update dashboard
		if (elements.dashboard) {
			updateDashboard(job, lastRun);
		}

		// Update button state
		if (elements.startButton) {
			if (job && ['pending', 'running'].includes(job.status)) {
				elements.startButton.disabled = true;
				elements.startButton.classList.add('sui-button-onload');
			} else {
				elements.startButton.disabled = false;
				elements.startButton.classList.remove('sui-button-onload');
			}
		}

		// Stop polling if job is complete
		if (job && !['pending', 'running'].includes(job.status)) {
			stopPolling();
		}
	}

	/**
	 * Show notice message
	 */
	function showNotice(type, message) {
		if (!elements.notice) {
			return;
		}

		elements.notice.className = 'sui-notice sui-notice-' + type;
		elements.notice.style.display = 'block';
		elements.notice.innerHTML = '<p>' + escapeHtml(message) + '</p>';

		// Auto-hide after 5 seconds
		setTimeout(function () {
			hideNotice();
		}, 5000);
	}

	/**
	 * Hide notice
	 */
	function hideNotice() {
		if (elements.notice) {
			elements.notice.style.display = 'none';
		}
	}

	/**
	 * Set button loading state
	 */
	function setButtonLoading(button, loading) {
		if (!button) {
			return;
		}

		if (loading) {
			button.disabled = true;
			button.classList.add('sui-button-onload');
		} else {
			button.disabled = false;
			button.classList.remove('sui-button-onload');
		}
	}

	/**
	 * Escape HTML to prevent XSS
	 */
	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	/**
	 * Update dashboard with metrics
	 */
	function updateDashboard(job, lastRun) {
		if (!elements.dashboard) {
			return;
		}

		// Use metrics from job if available, otherwise from lastRun
		const metrics = (job && job.metrics) ? job.metrics : (lastRun && lastRun.metrics ? lastRun.metrics : null);
		const healthScore = (job && job.health_score !== undefined) ? job.health_score : (lastRun && lastRun.health_score !== undefined ? lastRun.health_score : null);

		if (!metrics || !healthScore) {
			elements.dashboard.innerHTML = '<p class="sui-description">Run a scan to see dashboard metrics.</p>';
			return;
		}

		const total = parseInt(metrics.total_posts) || 0;
		const published = parseInt(metrics.published_posts) || 0;
		const drafts = parseInt(metrics.draft_private_posts) || 0;
		const brokenLinks = parseInt(metrics.posts_with_broken_links) || 0;
		const blankContent = parseInt(metrics.posts_with_blank_content) || 0;
		const missingImages = parseInt(metrics.posts_missing_featured_image) || 0;

		// Calculate pie chart percentages
		const publishedPercent = total > 0 ? (published / total) * 100 : 0;
		const draftsPercent = total > 0 ? (drafts / total) * 100 : 0;

		// Generate pie chart SVG
		const pieChart = generatePieChart(publishedPercent, draftsPercent);

		let html = '<div class="wpmudev-dashboard-grid">';
		
		// Health Score and Pie Chart Section
		html += '<div class="wpmudev-dashboard-main">';
		html += '<div class="wpmudev-health-score">';
		html += '<div class="wpmudev-health-score-value">' + healthScore.toFixed(1) + '%</div>';
		html += '<div class="wpmudev-health-score-label">Site Health Score</div>';
		html += '</div>';
		html += '<div class="wpmudev-pie-chart">' + pieChart + '</div>';
		html += '</div>';

		// Metrics Section
		html += '<div class="wpmudev-dashboard-metrics">';
		
		// Published vs Drafts
		html += '<div class="wpmudev-metric-item">';
		html += '<div class="wpmudev-metric-icon wpmudev-icon-published"></div>';
		html += '<div class="wpmudev-metric-content">';
		html += '<div class="wpmudev-metric-label">Published Posts</div>';
		html += '<div class="wpmudev-metric-value">' + published + '</div>';
		html += '</div></div>';

		html += '<div class="wpmudev-metric-item">';
		html += '<div class="wpmudev-metric-icon wpmudev-icon-draft"></div>';
		html += '<div class="wpmudev-metric-content">';
		html += '<div class="wpmudev-metric-label">Drafts/Private</div>';
		html += '<div class="wpmudev-metric-value">' + drafts + '</div>';
		html += '</div></div>';

		// Issues
		html += '<div class="wpmudev-metric-item wpmudev-metric-issue">';
		html += '<div class="wpmudev-metric-icon wpmudev-icon-broken-link"></div>';
		html += '<div class="wpmudev-metric-content">';
		html += '<div class="wpmudev-metric-label">Broken Internal Links</div>';
		html += '<div class="wpmudev-metric-value">' + brokenLinks + '</div>';
		html += '</div></div>';

		html += '<div class="wpmudev-metric-item wpmudev-metric-issue">';
		html += '<div class="wpmudev-metric-icon wpmudev-icon-blank"></div>';
		html += '<div class="wpmudev-metric-content">';
		html += '<div class="wpmudev-metric-label">Blank Content</div>';
		html += '<div class="wpmudev-metric-value">' + blankContent + '</div>';
		html += '</div></div>';

		html += '<div class="wpmudev-metric-item wpmudev-metric-issue">';
		html += '<div class="wpmudev-metric-icon wpmudev-icon-image"></div>';
		html += '<div class="wpmudev-metric-content">';
		html += '<div class="wpmudev-metric-label">Missing Featured Images</div>';
		html += '<div class="wpmudev-metric-value">' + missingImages + '</div>';
		html += '</div></div>';

		html += '</div>'; // .wpmudev-dashboard-metrics
		html += '</div>'; // .wpmudev-dashboard-grid

		elements.dashboard.innerHTML = html;
	}

	/**
	 * Generate pie chart SVG
	 */
	function generatePieChart(publishedPercent, draftsPercent) {
		const size = 120;
		const radius = 50;
		const center = size / 2;
		const circumference = 2 * Math.PI * radius;
		
		// Calculate dash offsets
		const publishedDash = (publishedPercent / 100) * circumference;
		const draftsDash = (draftsPercent / 100) * circumference;
		const publishedOffset = circumference - publishedDash;

		let svg = '<svg width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '">';
		
		// Published slice (green)
		if (publishedPercent > 0) {
			svg += '<circle cx="' + center + '" cy="' + center + '" r="' + radius + '" fill="none" stroke="#10b981" stroke-width="20" stroke-dasharray="' + publishedDash + ' ' + circumference + '" stroke-dashoffset="' + publishedOffset + '" transform="rotate(-90 ' + center + ' ' + center + ')"></circle>';
		}
		
		// Drafts slice (gray) - starts after published
		if (draftsPercent > 0) {
			const draftsOffset = circumference - publishedDash - draftsDash;
			svg += '<circle cx="' + center + '" cy="' + center + '" r="' + radius + '" fill="none" stroke="#e5e7eb" stroke-width="20" stroke-dasharray="' + draftsDash + ' ' + circumference + '" stroke-dashoffset="' + draftsOffset + '" transform="rotate(-90 ' + center + ' ' + center + ')"></circle>';
		}
		
		// Center text
		svg += '<text x="' + center + '" y="' + (center + 5) + '" text-anchor="middle" font-size="14" font-weight="600" fill="#1e293b">' + Math.round(publishedPercent) + '%</text>';
		
		svg += '</svg>';
		
		return svg;
	}

	// Initialize when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

