import { createRoot, StrictMode, useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Spinner, Notice } from '@wordpress/components';

import "./scss/style.scss";

// Icon Components
const RefreshIcon = () => (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" style={{ width: '16px', height: '16px', marginRight: '6px' }}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
    </svg>
);

const PlayIcon = () => (
    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style={{ marginRight: '6px' }}>
        <path d="M6 4L16 10L6 16V4Z" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
    </svg>
);

const WPMUDEV_Posts_Maintenance = () => {
    const config = window.wpmudevPostsMaintenance || {};
    
    const [selectedPostTypes, setSelectedPostTypes] = useState(
        config.postTypes?.filter(pt => ['post', 'page'].includes(pt.slug)).map(pt => pt.slug) || []
    );
    // Only set job if it's actually active, otherwise null
    const initialJob = config.job && ['pending', 'running'].includes(config.job.status) ? config.job : null;
    const [job, setJob] = useState(initialJob);
    const [lastRun, setLastRun] = useState(config.lastRun || null);
    const [isLoading, setIsLoading] = useState(false);
    const [notice, setNotice] = useState({ message: '', type: '' });
    const pollingIntervalRef = useRef(null);
    const [activeTab, setActiveTab] = useState('scan'); // 'scan' or 'settings'
    const [settings, setSettings] = useState(config.settings || {
        auto_scan_enabled: true,
        scheduled_time: '00:00',
        scheduled_post_types: ['post', 'page'],
    });
    const [nextScan, setNextScan] = useState(config.nextScan || null);
    const [countdown, setCountdown] = useState('');

    const showNotice = useCallback((message, type = 'success') => {
        setNotice({ message, type });
        setTimeout(() => setNotice({ message: '', type: '' }), 5000);
    }, []);

    const fetchStatus = useCallback(async () => {
        try {
            const response = await fetch(`${config.restBase}${config.endpoints.status}`, {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': config.nonce,
                },
            });

            const data = await response.json();

            if (data.job) {
                setJob(data.job);
            }
            if (data.lastRun) {
                setLastRun(data.lastRun);
            }
            if (data.nextScan !== undefined) {
                setNextScan(data.nextScan);
            }

            // Stop polling if job is complete
            if (data.job && !['pending', 'running'].includes(data.job.status)) {
                if (pollingIntervalRef.current) {
                    clearInterval(pollingIntervalRef.current);
                    pollingIntervalRef.current = null;
                }
            }
        } catch (error) {
            console.error('Fetch status error:', error);
        }
    }, [config]);

    const startPolling = useCallback(() => {
        if (pollingIntervalRef.current) {
            return;
        }

        pollingIntervalRef.current = setInterval(() => {
            fetchStatus();
        }, 5000);

        fetchStatus();
    }, [fetchStatus]);

    const stopPolling = useCallback(() => {
        if (pollingIntervalRef.current) {
            clearInterval(pollingIntervalRef.current);
            pollingIntervalRef.current = null;
        }
    }, []);

    const handleStartScan = useCallback(async () => {
        if (selectedPostTypes.length === 0) {
            showNotice(config.strings.selectPostTypes, 'error');
            return;
        }

        if (isLoading) {
            return;
        }

        setIsLoading(true);
        showNotice('', '');

        try {
            const url = `${config.restBase}${config.endpoints.start}`;
            console.log('Starting scan:', { url, post_types: selectedPostTypes, nonce: config.nonce ? 'present' : 'missing' });
            
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                body: JSON.stringify({
                    post_types: selectedPostTypes,
                }),
            });
            
            console.log('Scan response:', { status: response.status, ok: response.ok });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                const message = errorData.message || errorData.data?.message || `HTTP ${response.status}: ${response.statusText}`;
                showNotice(message, 'error');
                setIsLoading(false);
                return;
            }

            const data = await response.json();

            if (data.success !== false && data.job) {
                setJob(data.job);
                if (data.lastRun) {
                    setLastRun(data.lastRun);
                }
                showNotice(data.message || config.strings.scanStarted, 'success');
                startPolling();
            } else {
                const message = data.message || data.data?.message || config.strings.scanFailed;
                showNotice(message, 'error');
            }
        } catch (error) {
            console.error('Scan start error:', error);
            showNotice(config.strings.scanFailed + ': ' + (error.message || 'Unknown error'), 'error');
        } finally {
            setIsLoading(false);
        }
    }, [selectedPostTypes, isLoading, config, showNotice, startPolling]);

    const handleRefresh = useCallback(async () => {
        if (isLoading) {
            return;
        }

        setIsLoading(true);
        await fetchStatus();
        setIsLoading(false);
    }, [isLoading, fetchStatus]);

    const togglePostType = useCallback((slug) => {
        setSelectedPostTypes(prev => {
            if (prev.includes(slug)) {
                return prev.filter(s => s !== slug);
            } else {
                return [...prev, slug];
            }
        });
    }, []);

    const toggleScheduledPostType = useCallback((slug) => {
        setSettings(prev => {
            const current = prev.scheduled_post_types || [];
            const updated = current.includes(slug)
                ? current.filter(s => s !== slug)
                : [...current, slug];
            return { ...prev, scheduled_post_types: updated };
        });
    }, []);

    const handleSaveSettings = useCallback(async () => {
        setIsLoading(true);
        try {
            const response = await fetch(`${config.restBase}${config.endpoints.settings}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                },
                body: JSON.stringify(settings),
            });

            const data = await response.json();

            if (data.success) {
                setSettings(data.settings);
                setNextScan(data.settings.next_scan);
                showNotice(data.message || config.strings.settingsSaved, 'success');
            } else {
                showNotice(data.message || config.strings.settingsError, 'error');
            }
        } catch (error) {
            console.error('Save settings error:', error);
            showNotice(config.strings.settingsError + ': ' + (error.message || 'Unknown error'), 'error');
        } finally {
            setIsLoading(false);
        }
    }, [settings, config, showNotice]);

    // Countdown timer
    useEffect(() => {
        const updateCountdown = () => {
            if (!nextScan) {
                setCountdown('');
                return;
            }

            const now = Math.floor(Date.now() / 1000);
            const diff = nextScan - now;

            if (diff <= 0) {
                setCountdown(__('Scan scheduled to run now', 'wpmudev-plugin-test'));
                return;
            }

            const days = Math.floor(diff / 86400);
            const hours = Math.floor((diff % 86400) / 3600);
            const minutes = Math.floor((diff % 3600) / 60);
            const seconds = diff % 60;

            if (days > 0) {
                setCountdown(`${days}d ${hours}h ${minutes}m`);
            } else if (hours > 0) {
                setCountdown(`${hours}h ${minutes}m ${seconds}s`);
            } else if (minutes > 0) {
                setCountdown(`${minutes}m ${seconds}s`);
            } else {
                setCountdown(`${seconds}s`);
            }
        };

        updateCountdown();
        const interval = setInterval(updateCountdown, 1000);

        return () => clearInterval(interval);
    }, [nextScan]);

    // Initialize polling if job is active
    useEffect(() => {
        if (job && ['pending', 'running'].includes(job.status)) {
            startPolling();
        } else {
            // Stop polling if job is not active
            stopPolling();
        }

        return () => {
            stopPolling();
        };
    }, [job, startPolling, stopPolling]);
    
    // Clear stale jobs - only keep active jobs
    useEffect(() => {
        if (job && !['pending', 'running'].includes(job.status)) {
            // Job is completed or failed, don't keep it in state to block new scans
            console.log('Clearing completed job:', job.status);
            setJob(null);
        }
    }, [job]);

    // Generate pie chart SVG
    const generatePieChart = (publishedPercent, draftsPercent) => {
        const size = 120;
        const radius = 50;
        const center = size / 2;
        const circumference = 2 * Math.PI * radius;
        
        const publishedDash = (publishedPercent / 100) * circumference;
        const draftsDash = (draftsPercent / 100) * circumference;
        const publishedOffset = circumference - publishedDash;

        return (
            <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
                {publishedPercent > 0 && (
                    <circle
                        cx={center}
                        cy={center}
                        r={radius}
                        fill="none"
                        stroke="#000000"
                        strokeWidth="20"
                        strokeDasharray={`${publishedDash} ${circumference}`}
                        strokeDashoffset={publishedOffset}
                        transform={`rotate(-90 ${center} ${center})`}
                    />
                )}
                {draftsPercent > 0 && (
                    <circle
                        cx={center}
                        cy={center}
                        r={radius}
                        fill="none"
                        stroke="#e5e7eb"
                        strokeWidth="20"
                        strokeDasharray={`${draftsDash} ${circumference}`}
                        strokeDashoffset={circumference - publishedDash - draftsDash}
                        transform={`rotate(-90 ${center} ${center})`}
                    />
                )}
                <text
                    x={center}
                    y={center + 5}
                    textAnchor="middle"
                    fontSize="14"
                    fontWeight="600"
                    fill="#000000"
                    fontFamily="'DM Sans', sans-serif"
                >
                    {Math.round(publishedPercent)}%
                </text>
            </svg>
        );
    };

    // Calculate dashboard metrics
    const metrics = job?.metrics || lastRun?.metrics || null;
    const healthScore = job?.health_score !== undefined ? job.health_score : (lastRun?.health_score !== undefined ? lastRun.health_score : null);

    const total = metrics ? (parseInt(metrics.total_posts) || 0) : 0;
    const published = metrics ? (parseInt(metrics.published_posts) || 0) : 0;
    const drafts = metrics ? (parseInt(metrics.draft_private_posts) || 0) : 0;
    const blankContent = metrics ? (parseInt(metrics.posts_with_blank_content) || 0) : 0;
    const missingImages = metrics ? (parseInt(metrics.posts_missing_featured_image) || 0) : 0;

    const publishedPercent = total > 0 ? (published / total) * 100 : 0;
    const draftsPercent = total > 0 ? (drafts / total) * 100 : 0;

    const progress = job?.progress || 0;
    const isJobActive = job && job.status && ['pending', 'running'].includes(job.status);
    
    // Determine why button is disabled
    const getDisabledReason = () => {
        if (isLoading) {
            return __('Please wait, operation in progress...', 'wpmudev-plugin-test');
        }
        if (isJobActive) {
            return __('A scan is currently running. Please wait for it to complete.', 'wpmudev-plugin-test');
        }
        if (selectedPostTypes.length === 0) {
            return __('Please select at least one post type to scan.', 'wpmudev-plugin-test');
        }
        return null;
    };
    
    const disabledReason = getDisabledReason();
    const isButtonDisabled = isLoading || isJobActive || selectedPostTypes.length === 0;
    
    // Debug logging
    useEffect(() => {
        console.log('Button state:', {
            selectedPostTypes,
            selectedCount: selectedPostTypes.length,
            isLoading,
            isJobActive,
            jobStatus: job?.status,
            hasJob: !!job,
            disabled: isButtonDisabled,
            disabledReason: disabledReason
        });
    }, [selectedPostTypes, isLoading, isJobActive, job, isButtonDisabled, disabledReason]);

    return (
        <div className="sui-wrap wpmudev-posts-maintenance-wrap">
            <div className="sui-header wpmudev-posts-maintenance-header">
                <h1 className="sui-header-title">
                    {__('Posts Maintenance', 'wpmudev-plugin-test')}
                </h1>
                <p className="sui-description">
                    {__('Scan and maintain your WordPress posts and pages.', 'wpmudev-plugin-test')}
                </p>
            </div>

            {/* Tabs */}
            <div className="wpmudev-tabs" style={{ marginBottom: '24px', borderBottom: '1px solid rgba(100, 116, 139, 0.5)' }}>
                <button
                    className={`wpmudev-tab ${activeTab === 'scan' ? 'active' : ''}`}
                    onClick={() => setActiveTab('scan')}
                    style={{
                        padding: '12px 24px',
                        background: 'none',
                        border: 'none',
                        borderBottom: activeTab === 'scan' ? '2px solid #000' : '2px solid transparent',
                        cursor: 'pointer',
                        fontFamily: "'DM Sans', sans-serif",
                        fontSize: '14px',
                        fontWeight: activeTab === 'scan' ? '600' : '400',
                        color: activeTab === 'scan' ? '#000' : '#64748b',
                    }}
                >
                    {__('Scan', 'wpmudev-plugin-test')}
                </button>
                <button
                    className={`wpmudev-tab ${activeTab === 'settings' ? 'active' : ''}`}
                    onClick={() => setActiveTab('settings')}
                    style={{
                        padding: '12px 24px',
                        background: 'none',
                        border: 'none',
                        borderBottom: activeTab === 'settings' ? '2px solid #000' : '2px solid transparent',
                        cursor: 'pointer',
                        fontFamily: "'DM Sans', sans-serif",
                        fontSize: '14px',
                        fontWeight: activeTab === 'settings' ? '600' : '400',
                        color: activeTab === 'settings' ? '#000' : '#64748b',
                    }}
                >
                    {__('Settings', 'wpmudev-plugin-test')}
                </button>
            </div>

            {notice.message && (
                <Notice
                    status={notice.type}
                    isDismissible
                    onRemove={() => setNotice({ message: '', type: '' })}
                >
                    {notice.message}
                </Notice>
            )}

            {activeTab === 'settings' ? (
                <div className="wpmudev-posts-maintenance-grid">
                    {/* Settings Pane */}
                    <div className="sui-box wpmudev-posts-panel wpmudev-posts-panel--settings">
                        <div className="sui-box-header">
                            <h2 className="sui-box-title">
                                {__('Automatic Scan Settings', 'wpmudev-plugin-test')}
                            </h2>
                            <p className="sui-description">
                                {__('Configure when and what to scan automatically.', 'wpmudev-plugin-test')}
                            </p>
                        </div>
                        <div className="sui-box-body wpmudev-settings-body">
                            <div className="wpmudev-settings-section">
                                <div className="wpmudev-settings-field">
                                    <label className="wpmudev-settings-label wpmudev-settings-label--checkbox">
                                        <input
                                            type="checkbox"
                                            className="wpmudev-settings-checkbox"
                                            checked={settings.auto_scan_enabled}
                                            onChange={(e) => setSettings(prev => ({ ...prev, auto_scan_enabled: e.target.checked }))}
                                        />
                                        <span className="wpmudev-settings-label-text">
                                            {__('Enable automatic daily scans', 'wpmudev-plugin-test')}
                                        </span>
                                    </label>
                                    <p className="wpmudev-settings-description">
                                        {__('Automatically scan your posts at the scheduled time each day.', 'wpmudev-plugin-test')}
                                    </p>
                                </div>
                            </div>

                            {settings.auto_scan_enabled && (
                                <>
                                    <div className="wpmudev-settings-section">
                                        <div className="wpmudev-settings-field">
                                            <label className="wpmudev-settings-label">
                                                {__('Scheduled Time', 'wpmudev-plugin-test')}
                                            </label>
                                            <p className="wpmudev-settings-description">
                                                {__('Select the time when automatic scans should run each day.', 'wpmudev-plugin-test')}
                                            </p>
                                            <div className="wpmudev-settings-input-wrapper">
                                                <input
                                                    type="time"
                                                    className="wpmudev-settings-time-input"
                                                    value={settings.scheduled_time}
                                                    onChange={(e) => setSettings(prev => ({ ...prev, scheduled_time: e.target.value }))}
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <div className="wpmudev-settings-section">
                                        <div className="wpmudev-settings-field">
                                            <label className="wpmudev-settings-label">
                                                {__('Post Types to Scan', 'wpmudev-plugin-test')}
                                            </label>
                                            <p className="wpmudev-settings-description">
                                                {__('Choose which post types should be included in automatic scans.', 'wpmudev-plugin-test')}
                                            </p>
                                            <div className="wpmudev-settings-post-types">
                                                {(config.postTypes || []).map((postType) => (
                                                    <label
                                                        key={postType.slug}
                                                        className={`wpmudev-settings-post-type ${(settings.scheduled_post_types || []).includes(postType.slug) ? 'selected' : ''}`}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            value={postType.slug}
                                                            checked={(settings.scheduled_post_types || []).includes(postType.slug)}
                                                            onChange={() => toggleScheduledPostType(postType.slug)}
                                                        />
                                                        <span>{postType.label}</span>
                                                    </label>
                                                ))}
                                            </div>
                                        </div>
                                    </div>

                                    {nextScan && countdown && (
                                        <div className="wpmudev-settings-section">
                                            <div className="wpmudev-settings-field">
                                                <label className="wpmudev-settings-label">
                                                    {__('Next Scheduled Scan', 'wpmudev-plugin-test')}
                                                </label>
                                                <div className="wpmudev-settings-countdown">
                                                    <div className="wpmudev-settings-countdown-item">
                                                        <span className="wpmudev-settings-countdown-label">{__('Date & Time:', 'wpmudev-plugin-test')}</span>
                                                        <span className="wpmudev-settings-countdown-value">{new Date(nextScan * 1000).toLocaleString()}</span>
                                                    </div>
                                                    <div className="wpmudev-settings-countdown-item">
                                                        <span className="wpmudev-settings-countdown-label">{__('Time Remaining:', 'wpmudev-plugin-test')}</span>
                                                        <span className="wpmudev-settings-countdown-value wpmudev-settings-countdown-value--highlight">{countdown}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </>
                            )}
                        </div>
                        <div className="sui-box-footer">
                            <div className="sui-actions-right">
                                <Button
                                    variant="primary"
                                    onClick={handleSaveSettings}
                                    disabled={isLoading}
                                    isBusy={isLoading}
                                >
                                    {isLoading ? (
                                        <>
                                            <Spinner />
                                            {__('Saving...', 'wpmudev-plugin-test')}
                                        </>
                                    ) : (
                                        __('Save Settings', 'wpmudev-plugin-test')
                                    )}
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            ) : (
                <div className="wpmudev-posts-maintenance-grid">
                {/* Summary Dashboard Pane */}
                <div className="sui-box wpmudev-posts-panel wpmudev-posts-panel--dashboard">
                    <div className="sui-box-header">
                        <h2 className="sui-box-title">
                            {__('Summary Dashboard', 'wpmudev-plugin-test')}
                        </h2>
                        <p className="sui-description">
                            {__('Site health overview and post statistics.', 'wpmudev-plugin-test')}
                        </p>
                    </div>
                    {nextScan && countdown && (
                        <div style={{
                            padding: '12px 24px',
                            background: '#f8f9fa',
                            borderBottom: '1px solid rgba(100, 116, 139, 0.5)',
                            fontFamily: "'DM Sans', sans-serif",
                            fontSize: '13px',
                        }}>
                            <strong>{__('Next Automatic Scan:', 'wpmudev-plugin-test')}</strong>{' '}
                            {new Date(nextScan * 1000).toLocaleString()} ({countdown})
                        </div>
                    )}
                    <div className="sui-box-body">
                        {metrics && healthScore !== null ? (
                            <div className="wpmudev-dashboard-grid">
                                <div className="wpmudev-dashboard-main">
                                    <div className="wpmudev-health-score">
                                        <div className="wpmudev-health-score-value">{healthScore.toFixed(1)}%</div>
                                        <div className="wpmudev-health-score-label">{__('Site Health Score', 'wpmudev-plugin-test')}</div>
                                    </div>
                                    <div className="wpmudev-pie-chart">
                                        {generatePieChart(publishedPercent, draftsPercent)}
                                    </div>
                                </div>
                                <div className="wpmudev-dashboard-metrics">
                                    <div className="wpmudev-metric-item">
                                        <div className="wpmudev-metric-icon wpmudev-icon-published"></div>
                                        <div className="wpmudev-metric-content">
                                            <div className="wpmudev-metric-label">{__('Published Posts', 'wpmudev-plugin-test')}</div>
                                            <div className="wpmudev-metric-value">{published}</div>
                                        </div>
                                    </div>
                                    <div className="wpmudev-metric-item">
                                        <div className="wpmudev-metric-icon wpmudev-icon-draft"></div>
                                        <div className="wpmudev-metric-content">
                                            <div className="wpmudev-metric-label">{__('Drafts/Private', 'wpmudev-plugin-test')}</div>
                                            <div className="wpmudev-metric-value">{drafts}</div>
                                        </div>
                                    </div>
                                    <div className="wpmudev-metric-item wpmudev-metric-issue">
                                        <div className="wpmudev-metric-icon wpmudev-icon-blank"></div>
                                        <div className="wpmudev-metric-content">
                                            <div className="wpmudev-metric-label">{__('Blank Content', 'wpmudev-plugin-test')}</div>
                                            <div className="wpmudev-metric-value">{blankContent}</div>
                                        </div>
                                    </div>
                                    <div className="wpmudev-metric-item wpmudev-metric-issue">
                                        <div className="wpmudev-metric-icon wpmudev-icon-image"></div>
                                        <div className="wpmudev-metric-content">
                                            <div className="wpmudev-metric-label">{__('Missing Featured Images', 'wpmudev-plugin-test')}</div>
                                            <div className="wpmudev-metric-value">{missingImages}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <p className="sui-description">{__('Run a scan to see dashboard metrics.', 'wpmudev-plugin-test')}</p>
                        )}
                    </div>
                </div>

                {/* Scan Configuration Pane */}
                <div className="sui-box wpmudev-posts-panel wpmudev-posts-panel--config">
                    <div className="sui-box-header">
                        <h2 className="sui-box-title">
                            {__('Scan Configuration', 'wpmudev-plugin-test')}
                        </h2>
                        <p className="sui-description">
                            {__('Select post types to scan and update maintenance timestamps.', 'wpmudev-plugin-test')}
                        </p>
                    </div>
                    <div className="sui-box-body">
                        <div className="sui-box-settings-row">
                            <label className="sui-label">{__('Post Types', 'wpmudev-plugin-test')}</label>
                            <div className="wpmudev-post-types-grid">
                                {(config.postTypes || []).map((postType) => (
                                    <label
                                        key={postType.slug}
                                        className={selectedPostTypes.includes(postType.slug) ? 'selected' : ''}
                                    >
                                        <input
                                            type="checkbox"
                                            value={postType.slug}
                                            checked={selectedPostTypes.includes(postType.slug)}
                                            onChange={() => togglePostType(postType.slug)}
                                        />
                                        {postType.label}
                                    </label>
                                ))}
                            </div>
                        </div>
                    </div>
                    <div className="sui-box-footer">
                        <div className="sui-actions-right" style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: '8px' }}>
                            {disabledReason && (
                                <p className="sui-description" style={{ margin: 0, fontSize: '12px', color: '#64748b', fontStyle: 'italic' }}>
                                    {disabledReason}
                                </p>
                            )}
                            <Button
                                variant="primary"
                                onClick={handleStartScan}
                                disabled={isButtonDisabled}
                                isBusy={isLoading && !isJobActive}
                                title={disabledReason || ''}
                            >
                                {isLoading && !isJobActive ? (
                                    <>
                                        <Spinner />
                                        {__('Starting...', 'wpmudev-plugin-test')}
                                    </>
                                ) : (
                                    <>
                                        <PlayIcon />
                                        {__('Start Scan', 'wpmudev-plugin-test')}
                                    </>
                                )}
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Scan Progress Pane */}
                <div className="sui-box wpmudev-posts-panel wpmudev-posts-panel--progress">
                    <div className="sui-box-header">
                        <h2 className="sui-box-title">
                            {__('Scan Progress', 'wpmudev-plugin-test')}
                        </h2>
                        <p className="sui-description">
                            {__('Monitor the current scan operation in real-time.', 'wpmudev-plugin-test')}
                        </p>
                    </div>
                    <div className="sui-box-body">
                        <div className="wpmudev-progress-wrapper">
                            <div className="wpmudev-progress-bar">
                                <span style={{ width: `${progress}%` }}></span>
                            </div>
                            <div className="wpmudev-progress-meta">
                                <strong>
                                    {isJobActive
                                        ? (job?.status === 'pending' ? __('Pending...', 'wpmudev-plugin-test') : __('Running...', 'wpmudev-plugin-test'))
                                        : __('No active scan.', 'wpmudev-plugin-test')}
                                </strong>
                                {job && job.total > 0 && (
                                    <p className="sui-description">
                                        {__('Processed:', 'wpmudev-plugin-test')} {job.processed} / {job.total} ({progress}%)
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                    <div className="sui-box-footer">
                        <div className="sui-actions-right">
                            <Button
                                variant="secondary"
                                onClick={handleRefresh}
                                disabled={isLoading}
                                isBusy={isLoading}
                            >
                                {isLoading ? (
                                    <>
                                        <Spinner />
                                        {__('Refreshing...', 'wpmudev-plugin-test')}
                                    </>
                                ) : (
                                    <>
                                        <RefreshIcon />
                                        {__('Refresh Status', 'wpmudev-plugin-test')}
                                    </>
                                )}
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Last Run Summary Pane */}
                <div className="sui-box wpmudev-posts-panel wpmudev-posts-panel--summary">
                    <div className="sui-box-header">
                        <h2 className="sui-box-title">
                            {__('Last Run Summary', 'wpmudev-plugin-test')}
                        </h2>
                        <p className="sui-description">
                            {__('View details from the most recent scan operation.', 'wpmudev-plugin-test')}
                        </p>
                    </div>
                    <div className="sui-box-body">
                        {lastRun && lastRun.timestamp ? (
                            <div className="wpmudev-summary-content">
                                <p>
                                    <strong>{__('Date:', 'wpmudev-plugin-test')}</strong>{' '}
                                    {new Date(lastRun.timestamp * 1000).toLocaleString()}
                                </p>
                                <p>
                                    <strong>{__('Status:', 'wpmudev-plugin-test')}</strong>{' '}
                                    {lastRun.status ? lastRun.status.charAt(0).toUpperCase() + lastRun.status.slice(1) : __('Completed', 'wpmudev-plugin-test')}
                                </p>
                                <p>
                                    <strong>{__('Processed:', 'wpmudev-plugin-test')}</strong>{' '}
                                    {lastRun.processed || 0} {__('of', 'wpmudev-plugin-test')} {lastRun.total || 0} {__('posts', 'wpmudev-plugin-test')}
                                </p>
                                {lastRun.post_types && lastRun.post_types.length > 0 && (
                                    <p>
                                        <strong>{__('Post Types:', 'wpmudev-plugin-test')}</strong>{' '}
                                        {lastRun.post_types.join(', ')}
                                    </p>
                                )}
                            </div>
                        ) : (
                            <p className="sui-description">{__('No previous scan recorded.', 'wpmudev-plugin-test')}</p>
                        )}
                    </div>
                </div>
                </div>
            )}
        </div>
    );
};

// Initialize React app
const wrapperId = window.wpmudevPostsMaintenance?.wrapperId || 'wpmudev-posts-maintenance-app';
const domElement = document.getElementById(wrapperId);

if (domElement) {
    const root = createRoot(domElement);
    root.render(
        <StrictMode>
            <WPMUDEV_Posts_Maintenance />
        </StrictMode>
    );
}

