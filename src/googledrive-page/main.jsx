import { createRoot, render, StrictMode, useState, useEffect, createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TextControl, Spinner, Notice } from '@wordpress/components';

import "./scss/style.scss"

const domElement = document.getElementById(window.wpmudevDriveTest.dom_element_id);

const WPMUDEV_DriveTest = () => {
    const [isAuthenticated, setIsAuthenticated] = useState(window.wpmudevDriveTest.authStatus || false);
    const [hasCredentials, setHasCredentials] = useState(window.wpmudevDriveTest.hasCredentials || false);
    const [showCredentials, setShowCredentials] = useState(!window.wpmudevDriveTest.hasCredentials);
    const [isLoading, setIsLoading] = useState(false);
    const [files, setFiles] = useState([]);
    const [uploadFile, setUploadFile] = useState(null);
    const [folderName, setFolderName] = useState('');
    const [notice, setNotice] = useState({ message: '', type: '' });
    const [credentials, setCredentials] = useState({
        clientId: '',
        clientSecret: ''
    });

    useEffect(() => {
        // Keep local auth state in sync with server-provided value.
        setIsAuthenticated(window.wpmudevDriveTest.authStatus || false);
        setHasCredentials(window.wpmudevDriveTest.hasCredentials || false);
    }, []);

    const showNotice = (message, type = 'success') => {
        setNotice({ message, type });
        setTimeout(() => setNotice({ message: '', type: '' }), 5000);
    };

    const handleSaveCredentials = async () => {
    };

    const handleAuth = async () => {
    };

    const loadFiles = async () => {

    };

    const handleUpload = async () => {
    };

    const handleDownload = async (fileId, fileName) => {
    };

    const handleCreateFolder = async () => {
    };

    return (
        <>
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

            {showCredentials ? (
                <div className="sui-box">
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
                        <div className="sui-box-settings-row" style={{ display: 'flex', flexDirection: 'column' }}>
                            <strong style={{ marginBottom: '6px' }}>
                                {__('Required scopes for Google Drive API:', 'wpmudev-plugin-test')}
                            </strong>

                            <ul style={{ margin: 0, paddingLeft: '20px' }}>
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
            ) : !isAuthenticated ? (
                <div className="sui-box">
                    <div className="sui-box-header">
                        <h2 className="sui-box-title">
                            {__('Authenticate with Google Drive', 'wpmudev-plugin-test')}
                        </h2>
                    </div>
                    <div className="sui-box-body">
                        <div className="sui-box-settings-row">
                            <p>
                                {__(
                                    'Please authenticate with Google Drive to proceed with the test.',
                                    'wpmudev-plugin-test'
                                )}
                            </p>
                            <p>
                                <strong>
                                    {__(
                                        'This test will require the following permissions:',
                                        'wpmudev-plugin-test'
                                    )}
                                </strong>
                            </p>
                            <ul>
                                <li>
                                    {__('View and manage Google Drive files', 'wpmudev-plugin-test')}
                                </li>
                                <li>
                                    {__('Upload new files to Drive', 'wpmudev-plugin-test')}
                                </li>
                                <li>
                                    {__('Create folders in Drive', 'wpmudev-plugin-test')}
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div className="sui-box-footer">
                        <div className="sui-actions-left">
                            <Button
                                variant="secondary"
                                onClick={() => setShowCredentials(true)}
                            >
                                {__('Change Credentials', 'wpmudev-plugin-test')}
                            </Button>
                        </div>
                        <div className="sui-actions-right">
                            <Button
                                variant="primary"
                                onClick={handleAuth}
                                disabled={isLoading}
                            >
                                {isLoading ? <Spinner /> : __('Authenticate with Google Drive', 'wpmudev-plugin-test')}
                            </Button>
                        </div>
                    </div>
                </div>
            ) : (
                <>
                    {/* File Upload Section */}
                    <div className="sui-box">
                        <div className="sui-box-header">
                            <h2 className="sui-box-title">
                                {__('Upload File to Drive', 'wpmudev-plugin-test')}
                            </h2>
                        </div>
                        <div className="sui-box-body">
                            <div className="sui-box-settings-row">
                                <input
                                    type="file"
                                    onChange={(e) => setUploadFile(e.target.files[0])}
                                    className="drive-file-input"
                                />
                                {uploadFile && (
                                    <p>
                                        <strong>{__('Selected:', 'wpmudev-plugin-test')}</strong>{' '}
                                        {uploadFile.name} ({Math.round(uploadFile.size / 1024)} KB)
                                    </p>
                                )}
                            </div>
                        </div>
                        <div className="sui-box-footer">
                            <div className="sui-actions-right">
                                <Button
                                    variant="primary"
                                    onClick={handleUpload}
                                    disabled={isLoading || !uploadFile}
                                >
                                    {isLoading ? <Spinner /> : __('Upload to Drive', 'wpmudev-plugin-test')}
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* Create Folder Section */}
                    <div className="sui-box">
                        <div className="sui-box-header">
                            <h2 className="sui-box-title">
                                {__('Create New Folder', 'wpmudev-plugin-test')}
                            </h2>
                        </div>
                        <div className="sui-box-body">
                            <div className="sui-box-settings-row">
                                <TextControl
                                    label={__('Folder Name', 'wpmudev-plugin-test')}
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
                                    {isLoading ? <Spinner /> : __('Create Folder', 'wpmudev-plugin-test')}
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* Files List Section */}
                    <div className="sui-box">
                        <div className="sui-box-header">
                            <h2 className="sui-box-title">
                                {__('Your Drive Files', 'wpmudev-plugin-test')}
                            </h2>
                            <div className="sui-actions-right">
                                <Button
                                    variant="secondary"
                                    onClick={loadFiles}
                                    disabled={isLoading}
                                >
                                    {isLoading ? <Spinner /> : __('Refresh Files', 'wpmudev-plugin-test')}
                                </Button>
                            </div>
                        </div>
                        <div className="sui-box-body">
                            {isLoading ? (
                                <div className="drive-loading">
                                    <Spinner />
                                    <p>{__('Loading files…', 'wpmudev-plugin-test')}</p>
                                </div>
                            ) : files.length > 0 ? (
                                <div className="drive-files-grid">
                                    {files.map((file) => (
                                        <div key={file.id} className="drive-file-item">
                                            <div className="file-info">
                                                <strong>{file.name}</strong>
                                                <small>
                                                    {file.modifiedTime
                                                        ? new Date(file.modifiedTime).toLocaleDateString()
                                                        : __('Unknown date', 'wpmudev-plugin-test')}
                                                </small>
                                            </div>
                                            <div className="file-actions">
                                                {file.webViewLink && (
                                                    <Button
                                                        variant="link"
                                                        size="small"
                                                        href=''
                                                        target="_blank"
                                                    >
                                                        {__('View in Drive', 'wpmudev-plugin-test')}
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    ))}
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
        </>
    );
}

if (createRoot) {
    createRoot(domElement).render(<StrictMode><WPMUDEV_DriveTest /></StrictMode>);
} else {
    render(<StrictMode><WPMUDEV_DriveTest /></StrictMode>, domElement);
}