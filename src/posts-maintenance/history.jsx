import { createRoot, StrictMode, useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Spinner, Notice, Modal } from '@wordpress/components';

import "./scss/style.scss";

const domElement = document.getElementById('wpmudev-posts-maintenance-history-app');

// Icon Components
const CloseIcon = () => (
    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M15 5L5 15M5 5L15 15" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
    </svg>
);

const PreviousIcon = () => (
    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style={{ marginRight: '6px' }}>
        <path d="M12 15L7 10L12 5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
    </svg>
);

const NextIcon = () => (
    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style={{ marginLeft: '6px' }}>
        <path d="M8 5L13 10L8 15" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
    </svg>
);

const WPMUDEV_Posts_Maintenance_History = () => {
    const config = window.wpmudevPostsMaintenanceHistory;
    const [history, setHistory] = useState([]);
    const [selectedScan, setSelectedScan] = useState(null);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [notice, setNotice] = useState({ message: '', type: '' });
    const [currentPage, setCurrentPage] = useState(1);
    const itemsPerPage = 3;

    const showNotice = useCallback((message, type = 'success') => {
        setNotice({ message, type });
        setTimeout(() => setNotice({ message: '', type: '' }), 5000);
    }, []);

    const loadHistory = useCallback(async () => {
        // History is passed from PHP via localized script
        // For now, we'll fetch it from the status endpoint or pass it via config
        // This would need to be added to the PHP enqueue
    }, []);

    const loadScanDetails = useCallback(async (scanId) => {
        setIsLoading(true);
        try {
            const url = `${config.restBase}${config.endpoints.get}${scanId}`;
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': config.nonce,
                    'Content-Type': 'application/json',
                },
            });

            const data = await response.json();

            // Check if response is an error
            if (!response.ok || data.code || data.message) {
                const errorMessage = data.message || config.strings.loadError || __('Failed to load scan details.', 'wpmudev-plugin-test');
                showNotice(errorMessage, 'error');
                setIsLoading(false);
                return;
            }

            // Check if we have valid scan data
            if (data && (data.scan_id || data.timestamp)) {
                setSelectedScan(data);
                setIsModalOpen(true);
            } else {
                showNotice(config.strings.loadError || __('Failed to load scan details.', 'wpmudev-plugin-test'), 'error');
            }
        } catch (error) {
            console.error('Load scan details error:', error);
            showNotice(config.strings.loadError || __('Failed to load scan details.', 'wpmudev-plugin-test'), 'error');
        } finally {
            setIsLoading(false);
        }
    }, [config, showNotice]);

    const deleteScan = useCallback(async (scanId) => {
        if (!window.confirm(config.strings.deleteConfirm)) {
            return;
        }

        setIsDeleting(true);
        try {
            const response = await fetch(`${config.restBase}${config.endpoints.delete}${scanId}`, {
                method: 'DELETE',
                headers: {
                    'X-WP-Nonce': config.nonce,
                },
            });

            const data = await response.json();

            if (data.success) {
                setHistory(prev => {
                    const updated = prev.filter(record => record.scan_id !== scanId);
                    // Reset to first page if current page becomes empty
                    const totalPages = Math.ceil(updated.length / itemsPerPage);
                    if (currentPage > totalPages && totalPages > 0) {
                        setCurrentPage(totalPages);
                    } else if (totalPages === 0) {
                        setCurrentPage(1);
                    }
                    return updated;
                });
                showNotice(config.strings.deleteSuccess, 'success');
            } else {
                showNotice(data.message || config.strings.deleteError, 'error');
            }
        } catch (error) {
            console.error('Delete scan error:', error);
            showNotice(config.strings.deleteError, 'error');
        } finally {
            setIsDeleting(false);
        }
    }, [config, showNotice]);

    // Initialize history from config (passed from PHP)
    useEffect(() => {
        if (config.history && Array.isArray(config.history)) {
            setHistory(config.history);
            // Reset to first page when history changes
            setCurrentPage(1);
        }
    }, [config]);

    // Calculate pagination
    const totalPages = Math.ceil(history.length / itemsPerPage);
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;
    const paginatedHistory = history.slice(startIndex, endIndex);

    const handlePreviousPage = () => {
        setCurrentPage(prev => Math.max(1, prev - 1));
    };

    const handleNextPage = () => {
        setCurrentPage(prev => Math.min(totalPages, prev + 1));
    };

    return (
        <div className="sui-wrap wpmudev-posts-maintenance-wrap">
            <div className="sui-header wpmudev-posts-maintenance-header">
                <h1 className="sui-header-title">
                    {__('Scan History', 'wpmudev-plugin-test')}
                </h1>
                <p className="sui-description">
                    {__('View and manage all previous scan records.', 'wpmudev-plugin-test')}
                </p>
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

            <div className="wpmudev-posts-maintenance-grid">
                {history.length === 0 ? (
                    <div className="sui-box wpmudev-posts-panel">
                        <div className="sui-box-header">
                            <h2 className="sui-box-title">
                                {__('Scan History', 'wpmudev-plugin-test')}
                            </h2>
                            <p className="sui-description">
                                {__('View and manage all previous scan records.', 'wpmudev-plugin-test')}
                            </p>
                        </div>
                        <div className="sui-box-body">
                            <p className="sui-description">
                                {__('No scan history available. Run a scan to create records.', 'wpmudev-plugin-test')}
                            </p>
                        </div>
                    </div>
                ) : (
                    <>
                        {paginatedHistory.map((record) => {
                            const timestamp = record.timestamp ? new Date(record.timestamp * 1000) : null;
                            const date = timestamp ? timestamp.toLocaleDateString() : '';
                            const time = timestamp ? timestamp.toLocaleTimeString() : '';
                            const status = record.status ? record.status.charAt(0).toUpperCase() + record.status.slice(1) : '';
                            const health = record.health_score !== undefined ? record.health_score.toFixed(1) : '0.0';
                            const postTypes = record.post_types || [];
                            const context = record.context ? record.context.charAt(0).toUpperCase() + record.context.slice(1) : 'Manual';
                            const metrics = record.metrics || {};
                            const blankContent = metrics.posts_with_blank_content || 0;
                            const missingImages = metrics.posts_missing_featured_image || 0;

                            return (
                                <div key={record.scan_id} className="sui-box wpmudev-posts-panel wpmudev-scan-record">
                                    <div className="sui-box-header">
                                        <div className="wpmudev-scan-record-header">
                                            <div>
                                                <h2 className="sui-box-title" style={{ margin: 0, marginBottom: '4px' }}>
                                                    {date}
                                                </h2>
                                                <p className="sui-description" style={{ margin: 0, fontSize: '13px' }}>
                                                    {time} • {context}
                                                </p>
                                            </div>
                                            <div className="wpmudev-scan-record-health">
                                                <div className="wpmudev-scan-health-value">{health}%</div>
                                                <div className="wpmudev-scan-health-label">{__('Health Score', 'wpmudev-plugin-test')}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="sui-box-body">
                                        <div className="wpmudev-scan-record-content">
                                            <div className="wpmudev-scan-record-meta">
                                                <div className="wpmudev-scan-meta-item">
                                                    <span className="wpmudev-scan-meta-label">{__('Status', 'wpmudev-plugin-test')}</span>
                                                    <span className={`wpmudev-scan-badge wpmudev-scan-badge-${status.toLowerCase()}`}>
                                                        {status}
                                                    </span>
                                                </div>
                                                <div className="wpmudev-scan-meta-item">
                                                    <span className="wpmudev-scan-meta-label">{__('Processed', 'wpmudev-plugin-test')}</span>
                                                    <span className="wpmudev-scan-meta-value">
                                                        {record.processed || 0} / {record.total || 0}
                                                    </span>
                                                </div>
                                            </div>
                                            {postTypes.length > 0 && (
                                                <div className="wpmudev-scan-record-post-types">
                                                    <span className="wpmudev-scan-post-types-label">{__('Post Types', 'wpmudev-plugin-test')}</span>
                                                    <div className="wpmudev-scan-post-types-list">
                                                        {postTypes.map((type) => (
                                                            <span key={type} className="wpmudev-scan-post-type-tag">
                                                                {type}
                                                            </span>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                            {(blankContent > 0 || missingImages > 0) && (
                                                <div className="wpmudev-scan-record-issues">
                                                    {blankContent > 0 && (
                                                        <div className="wpmudev-scan-issue-item">
                                                            <span className="wpmudev-scan-issue-icon">□</span>
                                                            <span className="wpmudev-scan-issue-text">
                                                                {blankContent} {__('blank content', 'wpmudev-plugin-test')}
                                                            </span>
                                                        </div>
                                                    )}
                                                    {missingImages > 0 && (
                                                        <div className="wpmudev-scan-issue-item">
                                                            <span className="wpmudev-scan-issue-icon">🖼</span>
                                                            <span className="wpmudev-scan-issue-text">
                                                                {missingImages} {__('missing images', 'wpmudev-plugin-test')}
                                                            </span>
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                    <div className="sui-box-footer">
                                        <div className="wpmudev-scan-record-actions">
                                            <Button
                                                variant="secondary"
                                                onClick={() => loadScanDetails(record.scan_id)}
                                                disabled={isLoading}
                                            >
                                                {__('View Details', 'wpmudev-plugin-test')}
                                            </Button>
                                            <Button
                                                variant="secondary"
                                                onClick={() => deleteScan(record.scan_id)}
                                                disabled={isDeleting}
                                                isBusy={isDeleting}
                                            >
                                                {__('Delete', 'wpmudev-plugin-test')}
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}

                        {/* Pagination */}
                        {history.length > itemsPerPage && (
                            <div className="wpmudev-scan-history-pagination">
                                <div className="wpmudev-pagination-info">
                                    {__('Showing', 'wpmudev-plugin-test')} {startIndex + 1}-{Math.min(endIndex, history.length)} {__('of', 'wpmudev-plugin-test')} {history.length}
                                </div>
                                <div className="wpmudev-pagination-controls">
                                    <Button
                                        variant="secondary"
                                        onClick={handlePreviousPage}
                                        disabled={currentPage === 1}
                                        className="wpmudev-pagination-btn"
                                    >
                                        <PreviousIcon />
                                        {__('Previous', 'wpmudev-plugin-test')}
                                    </Button>
                                    <div className="wpmudev-pagination-pages">
                                        {Array.from({ length: totalPages }, (_, i) => i + 1).map((page) => (
                                            <button
                                                key={page}
                                                className={`wpmudev-pagination-page ${currentPage === page ? 'active' : ''}`}
                                                onClick={() => setCurrentPage(page)}
                                            >
                                                {page}
                                            </button>
                                        ))}
                                    </div>
                                    <Button
                                        variant="secondary"
                                        onClick={handleNextPage}
                                        disabled={currentPage === totalPages}
                                        className="wpmudev-pagination-btn"
                                    >
                                        {__('Next', 'wpmudev-plugin-test')}
                                        <NextIcon />
                                    </Button>
                                </div>
                            </div>
                        )}
                    </>
                )}
            </div>

            {isModalOpen && (
                <Modal
                    title={__('Scan Details', 'wpmudev-plugin-test')}
                    onRequestClose={() => {
                        setIsModalOpen(false);
                        setSelectedScan(null);
                    }}
                    className="wpmudev-scan-details-modal"
                    isDismissible={true}
                >
                    {isLoading ? (
                        <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', padding: '40px', minHeight: '200px' }}>
                            <Spinner />
                        </div>
                    ) : selectedScan ? (
                        <div className="wpmudev-scan-details">
                            <div className="wpmudev-scan-details-section">
                                <h4>{__('Basic Information', 'wpmudev-plugin-test')}</h4>
                                <table className="wpmudev-scan-details-table">
                                    <tbody>
                                        <tr>
                                            <td><strong>{__('Date:', 'wpmudev-plugin-test')}</strong></td>
                                            <td>{selectedScan.timestamp ? new Date(selectedScan.timestamp * 1000).toLocaleString() : 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <td><strong>{__('Status:', 'wpmudev-plugin-test')}</strong></td>
                                            <td>{selectedScan.status ? selectedScan.status.charAt(0).toUpperCase() + selectedScan.status.slice(1) : 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <td><strong>{__('Context:', 'wpmudev-plugin-test')}</strong></td>
                                            <td>{selectedScan.context ? selectedScan.context.charAt(0).toUpperCase() + selectedScan.context.slice(1) : 'Manual'}</td>
                                        </tr>
                                        <tr>
                                            <td><strong>{__('Post Types:', 'wpmudev-plugin-test')}</strong></td>
                                            <td>{selectedScan.post_types && selectedScan.post_types.length > 0 ? selectedScan.post_types.join(', ') : 'N/A'}</td>
                                        </tr>
                                        <tr>
                                            <td><strong>{__('Total Posts:', 'wpmudev-plugin-test')}</strong></td>
                                            <td>{selectedScan.total || 0}</td>
                                        </tr>
                                        <tr>
                                            <td><strong>{__('Processed:', 'wpmudev-plugin-test')}</strong></td>
                                            <td>{selectedScan.processed || 0}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            {selectedScan.health_score !== undefined && selectedScan.health_score !== null && (
                                <div className="wpmudev-scan-details-section">
                                    <h4>{__('Site Health Score', 'wpmudev-plugin-test')}</h4>
                                    <div className="wpmudev-scan-details-health">
                                        <div className="wpmudev-scan-details-health-value">
                                            {typeof selectedScan.health_score === 'number' ? selectedScan.health_score.toFixed(1) : selectedScan.health_score}%
                                        </div>
                                    </div>
                                </div>
                            )}

                            {selectedScan.metrics && (
                                <div className="wpmudev-scan-details-section">
                                    <h4>{__('Metrics', 'wpmudev-plugin-test')}</h4>
                                    <table className="wpmudev-scan-details-table">
                                        <tbody>
                                            <tr>
                                                <td><strong>{__('Published Posts:', 'wpmudev-plugin-test')}</strong></td>
                                                <td>{selectedScan.metrics.published_posts || 0}</td>
                                            </tr>
                                            <tr>
                                                <td><strong>{__('Drafts/Private:', 'wpmudev-plugin-test')}</strong></td>
                                                <td>{selectedScan.metrics.draft_private_posts || 0}</td>
                                            </tr>
                                            <tr>
                                                <td><strong>{__('Blank Content:', 'wpmudev-plugin-test')}</strong></td>
                                                <td>{selectedScan.metrics.posts_with_blank_content || 0}</td>
                                            </tr>
                                            <tr>
                                                <td><strong>{__('Missing Featured Images:', 'wpmudev-plugin-test')}</strong></td>
                                                <td>{selectedScan.metrics.posts_missing_featured_image || 0}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    ) : (
                        <div style={{ padding: '40px', textAlign: 'center' }}>
                            <p>{__('No scan details available.', 'wpmudev-plugin-test')}</p>
                        </div>
                    )}
                </Modal>
            )}
        </div>
    );
};

if (domElement) {
    const root = createRoot(domElement);
    root.render(
        <StrictMode>
            <WPMUDEV_Posts_Maintenance_History />
        </StrictMode>
    );
}

