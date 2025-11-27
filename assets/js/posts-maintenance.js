(function () {
	'use strict';

	const app = window.wpmudevPostsMaintenance || {};
	if (!app.restBase) {
		return;
	}

	const dom = {
		postTypesContainer: document.getElementById('wpmudev-posts-maintenance-app-post-types'),
		startButton: document.getElementById('wpmudev-posts-maintenance-app-start'),
		refreshButton: document.getElementById('wpmudev-posts-maintenance-app-refresh'),
		progressBar: document.getElementById('wpmudev-posts-maintenance-app-progress-bar'),
		progressText: document.getElementById('wpmudev-posts-maintenance-app-progress-text'),
		counts: document.getElementById('wpmudev-posts-maintenance-app-counts'),
		lastRun: document.getElementById('wpmudev-posts-maintenance-app-last-run'),
		notice: document.getElementById('wpmudev-posts-maintenance-app-notice')
	};

	let pollTimer = null;

	function renderPostTypes() {
		if (!dom.postTypesContainer || !Array.isArray(app.postTypes)) {
			return;
		}

		const fragment = document.createDocumentFragment();

		app.postTypes.forEach(function (type) {
			const wrapper = document.createElement('label');
			wrapper.className = 'sui-checkbox';

			const input = document.createElement('input');
			input.type = 'checkbox';
			input.name = 'wpmudev-post-types[]';
			input.value = type.slug;
			input.checked = app.job ? app.job.post_types.indexOf(type.slug) !== -1 : app.postTypes.length === 1;

			const label = document.createElement('span');
			label.className = 'sui-description';
			label.textContent = type.label;

			wrapper.appendChild(input);
			wrapper.appendChild(document.createElement('span'));
			wrapper.appendChild(label);
			fragment.appendChild(wrapper);
		});

		dom.postTypesContainer.innerHTML = '';
		dom.postTypesContainer.appendChild(fragment);
	}

	function setNotice(message, type) {
		if (!dom.notice) {
			return;
		}

		if (!message) {
			dom.notice.style.display = 'none';
			dom.notice.textContent = '';
			return;
		}

		dom.notice.className = 'sui-notice sui-notice-' + (type || 'success');
		dom.notice.textContent = message;
		dom.notice.style.display = 'block';
	}

	function toggleButton(disabled) {
		if (dom.startButton) {
			dom.startButton.disabled = disabled;
		}
	}

	function updateProgress(job) {
		if (!dom.progressBar || !dom.progressText) {
			return;
		}

		if (!job) {
			dom.progressBar.querySelector('span').style.width = '0%';
			dom.progressText.textContent = window.wp && window.wp.i18n ? window.wp.i18n.__('No active scan.', 'wpmudev-plugin-test') : 'No active scan.';
			dom.counts.textContent = '';
			return;
		}

		dom.progressBar.querySelector('span').style.width = job.progress + '%';

		const statusMap = {
			pending: 'Scan queued…',
			running: 'Scan in progress…',
			completed: 'Scan completed!',
			failed: 'Scan failed.'
		};

		dom.progressText.textContent = statusMap[job.status] || job.status;

		if (dom.counts) {
			dom.counts.textContent = job.total
				? job.processed + ' / ' + job.total + ' (' + job.progress + '%)'
				: '';
		}
	}

	function updateLastRun(summary) {
		if (!dom.lastRun) {
			return;
		}

		if (!summary || !summary.timestamp) {
			dom.lastRun.textContent = window.wp && window.wp.i18n ? window.wp.i18n.__('No scans have been completed yet.', 'wpmudev-plugin-test') : 'No scans have been completed yet.';
			return;
		}

		const date = new Date(summary.timestamp * 1000);
		const formatter = window.Intl && Intl.DateTimeFormat ? new Intl.DateTimeFormat(undefined, {
			dateStyle: 'medium',
			timeStyle: 'short'
		}) : null;

		const formatted = formatter ? formatter.format(date) : date.toLocaleString();

		dom.lastRun.textContent = window.wp && window.wp.i18n
			? window.wp.i18n.sprintf(window.wp.i18n.__('Last run on %s – processed %d items.', 'wpmudev-plugin-test'), formatted, summary.processed || 0)
			: 'Last run on ' + formatted + ' – processed ' + (summary.processed || 0) + ' items.';
	}

	function collectSelectedPostTypes() {
		const inputs = dom.postTypesContainer ? dom.postTypesContainer.querySelectorAll('input[type="checkbox"]:checked') : [];
		return Array.prototype.map.call(inputs, function (input) {
			return input.value;
		});
	}

	function request(endpoint, options) {
		const url = app.restBase.replace(/\/$/, '') + '/' + endpoint.replace(/^\//, '');

		const defaults = {
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': app.nonce || ''
			},
			credentials: 'same-origin'
		};

		const config = Object.assign({}, defaults, options || {});

		return fetch(url, config).then(function (response) {
			if (!response.ok) {
				return response.json().catch(function () {
					return {};
				}).then(function (data) {
					const error = data && data.message ? data.message : response.statusText;
					throw new Error(error);
				});
			}

			return response.json();
		});
	}

	function handleStart() {
		const selected = collectSelectedPostTypes();

		if (!selected.length) {
			setNotice(app.strings ? app.strings.selectPostTypes : 'Select at least one post type.', 'warning');
			return;
		}

		setNotice('');
		toggleButton(true);

		request(app.endpoints.start, {
			method: 'POST',
			body: JSON.stringify({
				post_types: selected
			})
		})
			.then(function (data) {
				setNotice(app.strings ? app.strings.scanStarted : 'Scan started.', 'success');
				updateProgress(data.job);
				pollStatus();
			})
			.catch(function (error) {
				setNotice(error.message || (app.strings ? app.strings.scanFailed : 'Failed to start scan.'), 'error');
				toggleButton(false);
			});
	}

	function fetchStatus() {
		return request(app.endpoints.status)
			.then(function (data) {
				updateProgress(data.job);
				updateLastRun(data.lastRun);

				if (!dom.postTypesContainer.innerHTML && Array.isArray(data.postTypes)) {
					app.postTypes = data.postTypes;
					renderPostTypes();
				}

				return data.job;
			})
			.catch(function (error) {
				setNotice(error.message, 'error');
				return null;
			});
	}

	function pollStatus() {
		clearTimeout(pollTimer);

		fetchStatus().then(function (job) {
			const running = job && (job.status === 'pending' || job.status === 'running');
			toggleButton(running);

			if (running) {
				pollTimer = setTimeout(pollStatus, 5000);
			} else {
				pollTimer = null;
			}
		});
	}

	function init() {
		renderPostTypes();
		updateProgress(app.job || null);
		updateLastRun(app.lastRun || null);

		if (dom.startButton) {
			dom.startButton.addEventListener('click', handleStart);
		}

		if (dom.refreshButton) {
			dom.refreshButton.addEventListener('click', function () {
				setNotice('');
				pollStatus();
			});
		}

		if (app.job && (app.job.status === 'pending' || app.job.status === 'running')) {
			toggleButton(true);
			pollStatus();
		}
	}

	document.addEventListener('DOMContentLoaded', init);
})();

