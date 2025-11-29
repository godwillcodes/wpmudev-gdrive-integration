import { createRoot, render, StrictMode, useState, useEffect, useCallback, createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl, Spinner, Notice } from '@wordpress/components';

import "./scss/style.scss"

// Icon Components
const RefreshIcon = () => (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor" className="size-6" style={{ width: '16px', height: '16px', marginRight: '6px' }}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
    </svg>
);

const DisconnectIcon = () => (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor" style={{ width: '16px', height: '16px', marginRight: '6px' }}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
    </svg>
);

const DownloadIcon = () => (
    <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style={{ marginRight: '6px' }}>
        <path d="M3 15L3 16C3 17.1046 3.89543 18 5 18L15 18C16.1046 18 17 17.1046 17 16L17 15M13 10L10 13M10 13L7 10M10 13L10 3" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
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

const domElement = document.getElementById(window.wpmudevDriveTest.dom_element_id);

/**
 * Format file size in bytes to human-readable format.
 *
 * @param {number} bytes File size in bytes.
 * @return {string} Formatted file size.
 */
const formatFileSize = (bytes) => {
    if (!bytes || bytes === 0) {
        return '-';
    }
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
};

/**
 * Get file type label from MIME type.
 *
 * @param {string} mimeType MIME type string.
 * @return {string} Human-readable file type.
 */
const getFileTypeLabel = (mimeType) => {
    if (!mimeType) {
        return __('File', 'wpmudev-plugin-test');
    }

    // Google Drive special types
    if (mimeType === 'application/vnd.google-apps.folder') {
        return __('Folder', 'wpmudev-plugin-test');
    }
    if (mimeType === 'application/vnd.google-apps.document') {
        return __('Google Doc', 'wpmudev-plugin-test');
    }
    if (mimeType === 'application/vnd.google-apps.spreadsheet') {
        return __('Google Sheet', 'wpmudev-plugin-test');
    }
    if (mimeType === 'application/vnd.google-apps.presentation') {
        return __('Google Slides', 'wpmudev-plugin-test');
    }

    // Common file types
    const typeMap = {
        'image/jpeg': __('Image', 'wpmudev-plugin-test'),
        'image/png': __('Image', 'wpmudev-plugin-test'),
        'image/gif': __('Image', 'wpmudev-plugin-test'),
        'image/webp': __('Image', 'wpmudev-plugin-test'),
        'application/pdf': __('PDF', 'wpmudev-plugin-test'),
        'text/plain': __('Text', 'wpmudev-plugin-test'),
        'application/zip': __('ZIP', 'wpmudev-plugin-test'),
        'application/json': __('JSON', 'wpmudev-plugin-test'),
    };

    // Check for partial matches (e.g., "image/" for all images)
    for (const [key, value] of Object.entries(typeMap)) {
        if (mimeType.startsWith(key.split('/')[0] + '/')) {
            return value;
        }
    }

    // Return generic type based on main type
    const mainType = mimeType.split('/')[0];
    if (mainType === 'image') {
        return __('Image', 'wpmudev-plugin-test');
    }
    if (mainType === 'video') {
        return __('Video', 'wpmudev-plugin-test');
    }
    if (mainType === 'audio') {
        return __('Audio', 'wpmudev-plugin-test');
    }

    return __('File', 'wpmudev-plugin-test');
};

const WPMUDEV_DriveTest = () => {
    const [isAuthenticated, setIsAuthenticated] = useState(window.wpmudevDriveTest.authStatus || false);
    const [hasCredentials, setHasCredentials] = useState(window.wpmudevDriveTest.hasCredentials || false);
    const [showCredentials, setShowCredentials] = useState(!window.wpmudevDriveTest.hasCredentials);
    const [isLoading, setIsLoading] = useState(false);
    const [uploadProgress, setUploadProgress] = useState(0);
    const [files, setFiles] = useState([]);
    const [uploadFile, setUploadFile] = useState(null);
    const [folderName, setFolderName] = useState('');
    const [notice, setNotice] = useState({ message: '', type: '' });
    const [isDragging, setIsDragging] = useState(false);
    const [credentials, setCredentials] = useState({
        clientId: '',
        clientSecret: ''
    });
    // Pagination state
    const [nextPageToken, setNextPageToken] = useState(null);
    const [pageTokenHistory, setPageTokenHistory] = useState([]); // Array of tokens: [null, token1, token2, ...] where index = page number
    const [currentPage, setCurrentPage] = useState(1);
    const [hasMore, setHasMore] = useState(false);

    // Memoize showNotice to prevent stale closures in useEffect.
    const showNotice = useCallback((message, type = 'success') => {
        setNotice({ message, type });
        setTimeout(() => setNotice({ message: '', type: '' }), 5000);
    }, []); // setNotice is stable, so empty deps array is safe.

    useEffect(() => {
        // Keep local auth state in sync with server-provided value.
        setIsAuthenticated(window.wpmudevDriveTest.authStatus || false);
        setHasCredentials(window.wpmudevDriveTest.hasCredentials || false);

        // Handle OAuth callback redirects.
        const urlParams = new URLSearchParams(window.location.search);
        const authStatus = urlParams.get('auth');
        const errorMessage = urlParams.get('error_message');

        if (authStatus === 'success') {
            setIsAuthenticated(true);
            showNotice(
                __('Successfully authenticated with Google Drive!', 'wpmudev-plugin-test'),
                'success'
            );
            // Clean up URL parameters.
            window.history.replaceState({}, document.title, window.location.pathname);
        } else if (authStatus === 'error') {
            const message = errorMessage
                ? decodeURIComponent(errorMessage)
                : __('Authentication failed. Please try again.', 'wpmudev-plugin-test');
            showNotice(message, 'error');
            // Clean up URL parameters.
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }, [showNotice]); // Include showNotice in dependencies to prevent stale closures.

    const overviewCards = [
        {
            id: 'credentials',
            label: __('Credentials', 'wpmudev-plugin-test'),
            value: hasCredentials
                ? __('Configured', 'wpmudev-plugin-test')
                : __('Missing', 'wpmudev-plugin-test'),
            description: hasCredentials
                ? __('Client ID and Secret stored securely.', 'wpmudev-plugin-test')
                : __('Add your Google Cloud keys to continue.', 'wpmudev-plugin-test'),
            state: hasCredentials ? 'success' : 'warning',
        },
        {
            id: 'auth',
            label: __('Authentication', 'wpmudev-plugin-test'),
            value: isAuthenticated
                ? __('Connected', 'wpmudev-plugin-test')
                : __('Not connected', 'wpmudev-plugin-test'),
            description: isAuthenticated
                ? __('Ready to manage Drive content.', 'wpmudev-plugin-test')
                : __('Authenticate to unlock Drive actions.', 'wpmudev-plugin-test'),
            state: isAuthenticated ? 'success' : 'neutral',
        },
        {
            id: 'files',
            label: __('Files loaded', 'wpmudev-plugin-test'),
            value: files.length,
            description: files.length
                ? __('Most recent file list is ready.', 'wpmudev-plugin-test')
                : __('Load files to preview Drive items.', 'wpmudev-plugin-test'),
            state: files.length ? 'neutral' : 'muted',
        },
    ];

    const handleSaveCredentials = async () => {
        if (!credentials.clientId.trim() || !credentials.clientSecret.trim()) {
            showNotice(
                __('Both Client ID and Client Secret are required.', 'wpmudev-plugin-test'),
                'error'
            );
            return;
        }

        try {
            setIsLoading(true);

            const response = await fetch(
                `${window.location.origin}/wp-json/${window.wpmudevDriveTest.restEndpointSave}`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': window.wpmudevDriveTest.nonce,
                    },
                    body: JSON.stringify({
                        client_id: credentials.clientId.trim(),
                        client_secret: credentials.clientSecret.trim(),
                    }),
                }
            );

            const data = await response.json();

            if (!response.ok || !data?.success) {
                const errorMessage =
                    data?.message ||
                    __('Failed to save credentials. Please try again.', 'wpmudev-plugin-test');
                showNotice(errorMessage, 'error');
                return;
            }

            setHasCredentials(true);
            setShowCredentials(false);
            showNotice(
                __('Credentials saved successfully.', 'wpmudev-plugin-test'),
                'success'
            );
        } catch (error) {
            showNotice(
                __('An unexpected error occurred while saving credentials.', 'wpmudev-plugin-test'),
                'error'
            );
        } finally {
            setIsLoading(false);
        }
    };

    const handleAuth = async () => {
        if (!hasCredentials) {
            showNotice(
                __('Please save your Google Drive credentials first.', 'wpmudev-plugin-test'),
                'error'
            );
            return;
        }

        try {
            setIsLoading(true);

            const response = await fetch(
                `${window.location.origin}/wp-json/${window.wpmudevDriveTest.restEndpointAuth}`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': window.wpmudevDriveTest.nonce,
                    },
                }
            );

            const data = await response.json();

            if (!response.ok || !data?.success) {
                const errorMessage =
                    data?.message ||
                    data?.code ||
                    __('Failed to start authentication. Please try again.', 'wpmudev-plugin-test');
                showNotice(errorMessage, 'error');
                setIsLoading(false);
                return;
            }

            // Redirect to Google OAuth consent screen.
            if (data.auth_url) {
                window.location.href = data.auth_url;
            } else {
                showNotice(
                    __('Invalid response from server. Please try again.', 'wpmudev-plugin-test'),
                    'error'
                );
                setIsLoading(false);
            }
        } catch (error) {
            showNotice(
                __('An unexpected error occurred while starting authentication.', 'wpmudev-plugin-test'),
                'error'
            );
            setIsLoading(false);
        }
    };

    const handleDisconnect = async () => {
        if (!confirm(__('Are you sure you want to disconnect from Google Drive? You will need to authenticate again to use Drive features.', 'wpmudev-plugin-test'))) {
            return;
        }

        try {
            setIsLoading(true);

            const response = await fetch(
                `${window.location.origin}/wp-json/wpmudev/v1/drive/disconnect`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': window.wpmudevDriveTest.nonce,
                    },
                }
            );

            const data = await response.json();

            if (!response.ok || !data?.success) {
                const errorMessage =
                    data?.message ||
                    __('Failed to disconnect from Google Drive. Please try again.', 'wpmudev-plugin-test');
                showNotice(errorMessage, 'error');
                setIsLoading(false);
                return;
            }

            // Update authentication status
            setIsAuthenticated(false);
            setFiles([]);
            setNextPageToken(null);
            setHasMore(false);
            setCurrentPage(1);
            setPageTokenHistory([]);

            showNotice(
                data?.message || __('Successfully disconnected from Google Drive.', 'wpmudev-plugin-test'),
                'success'
            );
            setIsLoading(false);
        } catch (error) {
            showNotice(
                __('An unexpected error occurred while disconnecting.', 'wpmudev-plugin-test'),
                'error'
            );
            setIsLoading(false);
        }
    };

    const loadFiles = async (pageToken = null, isNextPage = false) => {
        try {
            setIsLoading(true);
            
            // Build URL with pagination parameters
            const url = new URL(`${window.location.origin}/wp-json/${window.wpmudevDriveTest.restEndpointFiles}`);
            url.searchParams.set('page_size', '10'); // Set page size to 10
            if (pageToken) {
                url.searchParams.set('page_token', pageToken);
            }

            const response = await fetch(url.toString(), {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': window.wpmudevDriveTest.nonce,
                },
            });

            const data = await response.json();

            if (!response.ok) {
                const errorMessage =
                    data?.message ||
                    data?.code ||
                    __('Failed to load files. Please try again.', 'wpmudev-plugin-test');
                showNotice(errorMessage, 'error');
                return;
            }

            // Handle response format (new format with pagination or legacy array format)
            const fileList = data?.files || (Array.isArray(data) ? data : (data?.data || []));
            setFiles(fileList);
            
            // Update pagination state
            const newNextToken = data?.nextPageToken || null;
            setNextPageToken(newNextToken);
            setHasMore(data?.hasMore || !!newNextToken);
            
            // Update page tracking
            if (isNextPage && pageToken) {
                // Going forward - store the token we just used for the page we're now on
                setPageTokenHistory(prev => {
                    const newHistory = [...prev];
                    // Ensure array is long enough (index 0 = page 1, index 1 = page 2, etc.)
                    while (newHistory.length < currentPage + 1) {
                        newHistory.push(null);
                    }
                    newHistory[currentPage] = pageToken; // Store token that loads this page
                    return newHistory;
                });
                setCurrentPage(prev => prev + 1);
            } else if (!pageToken) {
                // Resetting to first page
                setPageTokenHistory([null]); // First page (index 0) has no token
                setCurrentPage(1);
            }
        } catch (error) {
            showNotice(
                __('An unexpected error occurred while loading files.', 'wpmudev-plugin-test'),
                'error'
            );
        } finally {
            setIsLoading(false);
        }
    };

    const loadNextPage = () => {
        if (nextPageToken && hasMore) {
            loadFiles(nextPageToken, true);
        }
    };

    const loadPreviousPage = () => {
        if (currentPage > 1) {
            const prevPage = currentPage - 1;
            const prevToken = pageTokenHistory[prevPage] || null;
            setCurrentPage(prevPage);
            loadFiles(prevToken, false);
        } else {
            // Already on first page
            setCurrentPage(1);
            loadFiles(null, false);
        }
    };

    const handleUpload = async () => {
        if (!uploadFile) {
            showNotice(
                __('Please select a file to upload.', 'wpmudev-plugin-test'),
                'error'
            );
            return;
        }

        setIsLoading(true);
        setUploadProgress(0);

        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('file', uploadFile);

            const xhr = new XMLHttpRequest();

            // Track upload progress
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percentComplete = Math.round((e.loaded / e.total) * 100);
                    setUploadProgress(percentComplete);
                }
            });

            // Handle completion
            xhr.addEventListener('load', async () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        const data = JSON.parse(xhr.responseText);

                        if (!data?.success) {
                            const errorMessage =
                                data?.message ||
                                data?.code ||
                                __('Failed to upload file. Please try again.', 'wpmudev-plugin-test');
                            showNotice(errorMessage, 'error');
                            setIsLoading(false);
                            setUploadProgress(0);
                            reject(new Error(errorMessage));
                            return;
                        }

                        // Clear file input
                        setUploadFile(null);
                        // Reset file input element
                        const fileInput = document.querySelector('.drive-file-input');
                        if (fileInput) {
                            fileInput.value = '';
                        }

                        showNotice(
                            __('File uploaded successfully!', 'wpmudev-plugin-test'),
                            'success'
                        );

                        // Automatically refresh file list (reset to first page)
                        await loadFiles(null, false);
                        setIsLoading(false);
                        setUploadProgress(0);
                        resolve(data);
                    } catch (error) {
                        showNotice(
                            __('Failed to parse server response.', 'wpmudev-plugin-test'),
                            'error'
                        );
                        setIsLoading(false);
                        setUploadProgress(0);
                        reject(error);
                    }
                } else {
                    let errorMessage = __('Failed to upload file. Please try again.', 'wpmudev-plugin-test');
                    try {
                        const data = JSON.parse(xhr.responseText);
                        errorMessage = data?.message || data?.code || errorMessage;
                    } catch (e) {
                        // Use default error message
                    }
                    showNotice(errorMessage, 'error');
                    setIsLoading(false);
                    setUploadProgress(0);
                    reject(new Error(errorMessage));
                }
            });

            // Handle errors
            xhr.addEventListener('error', () => {
                showNotice(
                    __('An unexpected error occurred while uploading the file.', 'wpmudev-plugin-test'),
                    'error'
                );
                setIsLoading(false);
                setUploadProgress(0);
                reject(new Error('Network error'));
            });

            xhr.addEventListener('abort', () => {
                showNotice(
                    __('Upload was cancelled.', 'wpmudev-plugin-test'),
                    'error'
                );
                setIsLoading(false);
                setUploadProgress(0);
                reject(new Error('Upload cancelled'));
            });

            // Open and send request
            xhr.open('POST', `${window.location.origin}/wp-json/${window.wpmudevDriveTest.restEndpointUpload}`);
            xhr.setRequestHeader('X-WP-Nonce', window.wpmudevDriveTest.nonce);
            xhr.send(formData);
        });
    };

    const handleDownload = async (fileId, fileName) => {
        if (!fileId) {
            showNotice(
                __('File ID is missing. Cannot download file.', 'wpmudev-plugin-test'),
                'error'
            );
            return;
        }

        try {
            setIsLoading(true);
            const response = await fetch(
                `${window.location.origin}/wp-json/${window.wpmudevDriveTest.restEndpointDownload}?file_id=${encodeURIComponent(fileId)}`,
                {
                    method: 'GET',
                    headers: {
                        'X-WP-Nonce': window.wpmudevDriveTest.nonce,
                    },
                }
            );

            const data = await response.json();

            if (!response.ok || !data?.success) {
                const errorMessage =
                    data?.message ||
                    data?.code ||
                    __('Failed to download file. Please try again.', 'wpmudev-plugin-test');
                showNotice(errorMessage, 'error');
                return;
            }

            // Decode base64 content and create download
            const content = atob(data.content);
            const blob = new Blob([content], { type: data.mimeType || 'application/octet-stream' });
            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = fileName || data.filename || 'download';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(url);

            showNotice(
                __('File downloaded successfully!', 'wpmudev-plugin-test'),
                'success'
            );
        } catch (error) {
            showNotice(
                __('An unexpected error occurred while downloading the file.', 'wpmudev-plugin-test'),
                'error'
            );
        } finally {
            setIsLoading(false);
        }
    };

    const handleCreateFolder = async () => {
        const trimmedName = folderName.trim();
        if (!trimmedName) {
            showNotice(
                __('Please enter a folder name.', 'wpmudev-plugin-test'),
                'error'
            );
            return;
        }

        try {
            setIsLoading(true);
            const response = await fetch(
                `${window.location.origin}/wp-json/${window.wpmudevDriveTest.restEndpointCreate}`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': window.wpmudevDriveTest.nonce,
                    },
                    body: JSON.stringify({
                        name: trimmedName,
                    }),
                }
            );

            const data = await response.json();

            if (!response.ok || !data?.success) {
                const errorMessage =
                    data?.message ||
                    data?.code ||
                    __('Failed to create folder. Please try again.', 'wpmudev-plugin-test');
                showNotice(errorMessage, 'error');
                return;
            }

            // Clear folder name input
            setFolderName('');
            showNotice(
                __('Folder created successfully!', 'wpmudev-plugin-test'),
                'success'
            );

            // Automatically refresh file list
            await loadFiles(null, false); // Reset to first page after folder creation
        } catch (error) {
            showNotice(
                __('An unexpected error occurred while creating the folder.', 'wpmudev-plugin-test'),
                'error'
            );
        } finally {
            setIsLoading(false);
        }
    };

    // Load files when authenticated
    useEffect(() => {
        if (isAuthenticated) {
            loadFiles(null, false); // Load first page
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isAuthenticated]); // Only run when authentication status changes

    return (
        <div className="sui-wrap wpmudev-drive-app">
            <div className="sui-header wpmudev-drive-header">
                <h1 className="sui-header-title">
                    {__('Google Drive Test', 'wpmudev-plugin-test')}
                </h1>
                <p className="sui-description">
                    {__('Test Google Drive API integration for applicant assessment.', 'wpmudev-plugin-test')}
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

            <div className="wpmudev-drive-overview">
                {overviewCards.map((card) => (
                    <div key={card.id} className={`wpmudev-overview-card state-${card.state}`}>
                        <span className="wpmudev-overview-label">{card.label}</span>
                        <strong className="wpmudev-overview-value">{card.value}</strong>
                        <p className="wpmudev-overview-description">{card.description}</p>
                    </div>
                ))}
            </div>

            <div className="wpmudev-drive-content">
            {showCredentials ? (
                <div className="wpmudev-drive-grid wpmudev-drive-grid--two">
                    <div className="sui-box wpmudev-drive-panel">
                    <div className="sui-box-header">
                        <h2 className="sui-box-title">
                            {__('Set Google Drive Credentials', 'wpmudev-plugin-test')}
                        </h2>
                    </div>
                    <div className="sui-box-body">
                        <div className="sui-box-settings-row wpmudev-drive-scopes-row">
                            <TextControl
                                help={createInterpolateElement(
                                    __(
                                        'You can get Client ID from <a>Google Cloud Console</a>. Make sure to enable Google Drive API.',
                                        'wpmudev-plugin-test'
                                    ),
                                    {
                                        a: <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer" />,
                                    }
                                )}
                                label={__('Client ID', 'wpmudev-plugin-test')}
                                value={credentials.clientId}
                                onChange={(value) => setCredentials({ ...credentials, clientId: value })}
                            />
                        </div>

                        <div className="sui-box-settings-row wpmudev-drive-secret-row">
                            <TextControl
                                help={createInterpolateElement(
                                    __(
                                        'You can get Client Secret from <a>Google Cloud Console</a>.',
                                        'wpmudev-plugin-test'
                                    ),
                                    {
                                        a: <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer" />,
                                    }
                                )}
                                label={__('Client Secret', 'wpmudev-plugin-test')}
                                value={credentials.clientSecret}
                                onChange={(value) => setCredentials({ ...credentials, clientSecret: value })}
                                type="password"
                            />
                        </div>

                        <div className="sui-box-settings-row">
                            <span>
                                {__('Please use this URL', 'wpmudev-plugin-test')}{' '}
                                <em>{window.wpmudevDriveTest.redirectUri}</em>{' '}
                                {__(
                                    "in your Google API's Authorized redirect URIs field.",
                                    'wpmudev-plugin-test'
                                )}
                            </span>
                        </div>
                        <div className="sui-box-settings-row wpmudev-drive-required-scopes">
                            <strong>
                                {__('Required scopes for Google Drive API:', 'wpmudev-plugin-test')}
                            </strong>

                            <ul>
                                <li>https://www.googleapis.com/auth/drive.file</li>
                                <li>https://www.googleapis.com/auth/drive.readonly</li>
                            </ul>
                        </div>

                    </div>
                    <div className="sui-box-footer">
                        <div className="sui-actions-right">
                            <Button
                                className="wpmudev-drive-primary"
                                variant="primary"
                                onClick={handleSaveCredentials}
                                disabled={isLoading}
                            >
                                {isLoading ? <Spinner /> : __('Save Credentials', 'wpmudev-plugin-test')}
                            </Button>
                        </div>
                    </div>
                    </div>
                    <div className="sui-box wpmudev-drive-panel wpmudev-drive-guidance">
                        <div className="wpmudev-drive-guidance__body">
                            <h3>{__('Google Cloud checklist', 'wpmudev-plugin-test')}</h3>
                            <ol>
                                <li>{__('Enable the Google Drive API for your project.', 'wpmudev-plugin-test')}</li>
                                <li>{__('Create an OAuth client (Web Application).', 'wpmudev-plugin-test')}</li>
                                <li>{__('Add the redirect URI shown on this page.', 'wpmudev-plugin-test')}</li>
                                <li>{__('Paste the Client ID and Secret into this form.', 'wpmudev-plugin-test')}</li>
                            </ol>
                            <p>
                                {__(
                                    'We encrypt secrets at rest. Rotate them any time from this screen.',
                                    'wpmudev-plugin-test'
                                )}
                            </p>
                        </div>
                    </div>
                </div>
            ) : !isAuthenticated ? (
                <div className="wpmudev-drive-grid wpmudev-drive-grid--auth">
                    {/* Single Authentication Pane */}
                    <div className="sui-box wpmudev-drive-panel wpmudev-drive-panel--auth">
                    <div className="sui-box-header">
                        <h2 className="sui-box-title">
                            {__('Authenticate with Google Drive', 'wpmudev-plugin-test')}
                        </h2>
                            <p className="sui-description">
                                {__('Connect your Google Drive account to enable file uploads, folder creation, and file browsing. We never store your files.', 'wpmudev-plugin-test')}
                        </p>
                    </div>
                    <div className="sui-box-body">
                            <div className="wpmudev-drive-auth-content">
                            <div className="wpmudev-drive-auth-scopes">
                                <p className="wpmudev-drive-auth-scopes-title">
                                        {__('This connection will be able to:', 'wpmudev-plugin-test')}
                                    </p>
                                    <ul className="wpmudev-drive-scopes-list">
                                        <li>{__('View and manage Google Drive files', 'wpmudev-plugin-test')}</li>
                                        <li>{__('Upload new files to Drive', 'wpmudev-plugin-test')}</li>
                                        <li>{__('Create folders in Drive', 'wpmudev-plugin-test')}</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div className="sui-box-footer">
                            <div className="wpmudev-drive-auth-actions">
                            <Button
                                variant="secondary"
                                className="wpmudev-drive-auth-change"
                                onClick={() => setShowCredentials(true)}
                            >
                                    {__('Change credentials', 'wpmudev-plugin-test')}
                            </Button>
                            <Button
                                className="wpmudev-drive-auth-primary"
                                variant="primary"
                                onClick={handleAuth}
                                disabled={isLoading}
                            >
                                {isLoading ? (
                                    <>
                                        <Spinner />
                                        {__('Connecting...', 'wpmudev-plugin-test')}
                                    </>
                                ) : (
                                    __('Authenticate with Google Drive', 'wpmudev-plugin-test')
                                )}
                            </Button>
                        </div>
                    </div>
                </div>
                </div>
            ) : (
                <>
                    <div className="wpmudev-drive-grid wpmudev-drive-grid--actions">
                        {/* File Upload Section */}
                        <div className="sui-box wpmudev-drive-panel wpmudev-drive-panel--upload">
                            <div className="sui-box-header">
                                <h2 className="sui-box-title">
                                    {__('Upload file to drive', 'wpmudev-plugin-test')}
                                </h2>
                                <p className="sui-description">
                                    {__('Drag and drop files here or click to browse.', 'wpmudev-plugin-test')}
                                </p>
                            </div>
                            <div className="sui-box-body">
                                <div className="sui-box-settings-row wpmudev-drive-upload-row">
                                    <div 
                                        className={`wpmudev-drive-dropzone ${isDragging ? 'is-dragging' : ''} ${uploadFile ? 'has-file' : ''}`}
                                        onDragOver={(e) => {
                                            e.preventDefault();
                                            e.stopPropagation();
                                            setIsDragging(true);
                                        }}
                                        onDragLeave={(e) => {
                                            e.preventDefault();
                                            e.stopPropagation();
                                            setIsDragging(false);
                                        }}
                                        onDrop={(e) => {
                                            e.preventDefault();
                                            e.stopPropagation();
                                            setIsDragging(false);
                                            const droppedFile = e.dataTransfer.files?.[0];
                                            if (droppedFile) {
                                                setUploadFile(droppedFile);
                                            }
                                        }}
                                    >
                                    <div className="wpmudev-drive-file-input-wrapper">
                                        <label className="wpmudev-drive-file-label">
                                            <input
                                                type="file"
                                                onChange={(e) => setUploadFile(e.target.files?.[0] || null)}
                                                className="drive-file-input"
                                                disabled={isLoading}
                                            />
                                            <span className="wpmudev-drive-file-label-text">
                                                {uploadFile
                                                        ? __('Change file', 'wpmudev-plugin-test')
                                                        : __('Choose file', 'wpmudev-plugin-test')}
                                            </span>
                                        </label>
                                        {uploadFile && (
                                            <div className="wpmudev-drive-file-info">
                                                <strong>{__('Selected:', 'wpmudev-plugin-test')}</strong>{' '}
                                                <span className="wpmudev-drive-file-name">{uploadFile.name}</span>
                                                <span className="wpmudev-drive-file-size">
                                                    ({formatFileSize(uploadFile.size)})
                                                </span>
                                            </div>
                                        )}
                                        {isLoading && uploadProgress > 0 && (
                                            <div className="wpmudev-drive-upload-progress">
                                                <div className="wpmudev-drive-progress-bar-wrapper">
                                                    <div
                                                        className="wpmudev-drive-progress-bar"
                                                        style={{ width: `${uploadProgress}%` }}
                                                    />
                                                </div>
                                                <div className="wpmudev-drive-progress-text">
                                                    {__('Uploading:', 'wpmudev-plugin-test')} {uploadProgress}%
                                                </div>
                                            </div>
                                        )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="sui-box-footer">
                                <div className="sui-actions-right">
                                    <Button
                                        variant="primary"
                                        onClick={handleUpload}
                                        disabled={isLoading || !uploadFile}
                                    >
                                        {isLoading ? (
                                            <>
                                                <Spinner />
                                                {uploadProgress > 0 ? `${uploadProgress}%` : __('Uploading...', 'wpmudev-plugin-test')}
                                            </>
                                        ) : (
                                            __('Upload to drive', 'wpmudev-plugin-test')
                                        )}
                                    </Button>
                                </div>
                            </div>
                        </div>

                        {/* Create Folder Section */}
                        <div className="sui-box wpmudev-drive-panel wpmudev-drive-panel--folder">
                            <div className="sui-box-header">
                                <h2 className="sui-box-title">
                                    {__('Create new folder', 'wpmudev-plugin-test')}
                                </h2>
                                <p className="sui-description">
                                    {__('Organize your files with custom folders.', 'wpmudev-plugin-test')}
                                </p>
                            </div>
                            <div className="sui-box-body">
                                <div className="sui-box-settings-row">
                                    <TextControl
                                        label={__('Folder name', 'wpmudev-plugin-test')}
                                        value={folderName}
                                        onChange={setFolderName}
                                        placeholder={__('Enter folder name', 'wpmudev-plugin-test')}
                                    />
                                </div>
                            </div>
                            <div className="sui-box-footer">
                                <div className="sui-actions-right">
                                    <Button
                                        variant="secondary"
                                        onClick={handleCreateFolder}
                                        disabled={isLoading || !folderName.trim()}
                                    >
                                        {isLoading ? <Spinner /> : __('Create folder', 'wpmudev-plugin-test')}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Files List Section */}
                    <div className="sui-box wpmudev-drive-panel wpmudev-drive-panel--full wpmudev-drive-panel--files">
                        <div className="sui-box-header">
                            <h2 className="sui-box-title">
                                {__('Your drive files', 'wpmudev-plugin-test')}
                            </h2>
                            <div className="sui-actions-right wpmudev-drive-files-actions">
                                <div className="wpmudev-drive-actions-left">
                                    <Button
                                        variant="secondary"
                                        className="wpmudev-drive-connection-btn"
                                        onClick={() => setShowCredentials(true)}
                                        disabled={isLoading}
                                    >
                                        {__('Change credentials', 'wpmudev-plugin-test')}
                                    </Button>
                                    <Button
                                        variant="secondary"
                                        className="wpmudev-drive-disconnect-btn"
                                        onClick={handleDisconnect}
                                        disabled={isLoading}
                                    >
                                        {isLoading ? <Spinner /> : (
                                            <>
                                                <DisconnectIcon />
                                                {__('Disconnect', 'wpmudev-plugin-test')}
                                            </>
                                        )}
                                    </Button>
                                </div>
                                <div className="wpmudev-drive-actions-right">
                                    <Button
                                        variant="secondary"
                                        onClick={loadFiles}
                                        disabled={isLoading}
                                    >
                                        {isLoading ? <Spinner /> : (
                                            <>
                                                <RefreshIcon />
                                                {__('Refresh files', 'wpmudev-plugin-test')}
                                            </>
                                        )}
                                    </Button>
                                </div>
                            </div>
                        </div>
                        <div className="sui-box-body">
                            {isLoading ? (
                                <div className="drive-loading">
                                    <Spinner />
                                    <p>{__('Loading files…', 'wpmudev-plugin-test')}</p>
                                </div>
                            ) : files.length > 0 ? (
                                <div className="wpmudev-drive-files-table">
                                    <table className="sui-table">
                                        <thead>
                                            <tr>
                                                <th>{__('Name', 'wpmudev-plugin-test')}</th>
                                                <th>{__('Type', 'wpmudev-plugin-test')}</th>
                                                <th>{__('Size', 'wpmudev-plugin-test')}</th>
                                                <th>{__('Modified', 'wpmudev-plugin-test')}</th>
                                                <th>{__('Actions', 'wpmudev-plugin-test')}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {files.map((file) => {
                                                const isFolder = file.mimeType === 'application/vnd.google-apps.folder';
                                                const fileSize = file.size ? formatFileSize(file.size) : '-';
                                                const modifiedDate = file.modifiedTime
                                                    ? new Date(file.modifiedTime).toLocaleString()
                                                    : __('Unknown', 'wpmudev-plugin-test');
                                                const fileType = isFolder
                                                    ? __('Folder', 'wpmudev-plugin-test')
                                                    : getFileTypeLabel(file.mimeType);

                                                return (
                                                    <tr key={file.id} className="wpmudev-drive-file-row">
                                                        <td className="wpmudev-drive-file-name">
                                                            <strong>{file.name}</strong>
                                                        </td>
                                                        <td className="wpmudev-drive-file-type">
                                                            <span className={`wpmudev-drive-type-badge ${isFolder ? 'folder' : 'file'}`}>
                                                                {fileType}
                                                            </span>
                                                        </td>
                                                        <td className="wpmudev-drive-file-size">
                                                            {fileSize}
                                                        </td>
                                                        <td className="wpmudev-drive-file-date">
                                                            {modifiedDate}
                                                        </td>
                                                        <td className="wpmudev-drive-file-actions">
                                                            <div className="wpmudev-drive-actions-group">
                                                                {!isFolder && (
                                                                    <Button
                                                                        variant="link"
                                                                        size="small"
                                                                        onClick={() => handleDownload(file.id, file.name)}
                                                                        disabled={isLoading}
                                                                        className="wpmudev-drive-action-btn"
                                                                    >
                                                                        <DownloadIcon />
                                                                        {__('Download', 'wpmudev-plugin-test')}
                                                                    </Button>
                                                                )}
                                                                {file.webViewLink && (
                                                                    <Button
                                                                        variant="link"
                                                                        size="small"
                                                                        href={file.webViewLink}
                                                                        target="_blank"
                                                                        rel="noopener noreferrer"
                                                                        className="wpmudev-drive-action-btn"
                                                                    >
                                                                        {__('View in drive', 'wpmudev-plugin-test')}
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                    
                                    {/* Pagination Controls */}
                                    {(hasMore || currentPage > 1) && (
                                        <div className="wpmudev-drive-pagination" style={{ 
                                            display: 'flex', 
                                            justifyContent: 'space-between', 
                                            alignItems: 'center',
                                            marginTop: '20px',
                                            padding: '16px',
                                            borderTop: '1px solid #e5e7eb'
                                        }}>
                                            <div className="wpmudev-drive-pagination-info" style={{
                                                fontFamily: "'DM Sans', sans-serif",
                                                fontSize: '14px',
                                                color: '#6b7280'
                                            }}>
                                                {__('Page', 'wpmudev-plugin-test')} {currentPage} • {files.length} {__('items', 'wpmudev-plugin-test')}
                                            </div>
                                            <div className="wpmudev-drive-pagination-buttons" style={{
                                                display: 'flex',
                                                gap: '12px',
                                                alignItems: 'center'
                                            }}>
                                                <Button
                                                    variant="secondary"
                                                    onClick={loadPreviousPage}
                                                    disabled={isLoading || currentPage === 1}
                                                    style={{
                                                        backgroundColor: currentPage === 1 ? '#f3f4f6' : 'transparent',
                                                        border: '2px solid #000000',
                                                        color: currentPage === 1 ? '#9ca3af' : '#000000',
                                                        fontFamily: "'DM Sans', sans-serif",
                                                        fontWeight: 600,
                                                        padding: '8px 16px',
                                                        borderRadius: '6px',
                                                        cursor: currentPage === 1 ? 'not-allowed' : 'pointer',
                                                        display: 'flex',
                                                        alignItems: 'center'
                                                    }}
                                                >
                                                    <PreviousIcon />
                                                    {__('Previous', 'wpmudev-plugin-test')}
                                                </Button>
                                                <Button
                                                    variant="primary"
                                                    onClick={loadNextPage}
                                                    disabled={isLoading || !hasMore}
                                                    style={{
                                                        backgroundColor: !hasMore ? '#666666' : '#000000',
                                                        border: '2px solid ' + (!hasMore ? '#666666' : '#000000'),
                                                        color: '#ffffff',
                                                        fontFamily: "'DM Sans', sans-serif",
                                                        fontWeight: 600,
                                                        padding: '8px 16px',
                                                        borderRadius: '6px',
                                                        cursor: !hasMore ? 'not-allowed' : 'pointer',
                                                        display: 'flex',
                                                        alignItems: 'center'
                                                    }}
                                                >
                                                    {__('Next', 'wpmudev-plugin-test')}
                                                    <NextIcon />
                                                </Button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="sui-box-settings-row">
                                    <p>
                                        {__(
                                            'No files found in your Drive. Upload a file or create a folder to get started.',
                                            'wpmudev-plugin-test'
                                        )}
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>
                </>
            )}
            </div>
        </div>
    );
}

if (createRoot) {
    createRoot(domElement).render(<StrictMode><WPMUDEV_DriveTest /></StrictMode>);
} else {
    render(<StrictMode><WPMUDEV_DriveTest /></StrictMode>, domElement);
}