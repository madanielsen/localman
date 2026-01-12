<?php
/**
 * LocalMan - A Postman-like standalone PHP application
 * One file to send API requests and capture webhooks
 * 
 * @version 1.0.0
 * @license MIT
 */

// Set timezone for consistent timestamps
date_default_timezone_set('UTC');

// Define storage directories
define('STORAGE_DIR', __DIR__ . '/storage');
define('WEBHOOKS_DIR', STORAGE_DIR . '/webhooks');
define('REQUESTS_DIR', STORAGE_DIR . '/requests');
define('SETTINGS_FILE', STORAGE_DIR . '/localman.settings.json');
define('REQUEST_TIMEOUT', 30);

define('LOCALMAN_VERSION', '1.0.0');

// Create storage directories if they don't exist
if (!file_exists(WEBHOOKS_DIR)) {
    mkdir(WEBHOOKS_DIR, 0755, true);
}
if (!file_exists(REQUESTS_DIR)) {
    mkdir(REQUESTS_DIR, 0755, true);
}

/**
 * Load settings from file
 */
function loadSettings() {
    if (file_exists(SETTINGS_FILE)) {
        $content = @file_get_contents(SETTINGS_FILE);
        if ($content !== false) {
            $settings = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && $settings) {
                return $settings;
            }
        }
    }
    
    // Default settings
    return [
        'darkMode' => 'auto',
        'currentProject' => 'default',
        'lastVersionCheck' => 0,
        'projects' => [
            'default' => [
                'name' => 'Default Project',
                'webhookResponse' => [
                    'status' => 200,
                    'body' => '{"status": "success", "message": "Webhook received"}',
                    'headers' => ['Content-Type: application/json']
                ],
                'lastRequest' => [
                    'url' => 'https://api.localman.no/hello-world',
                    'method' => 'GET',
                    'params' => [],
                    'authorization' => ['type' => 'none', 'token' => '', 'username' => '', 'password' => ''],
                    'headers' => [],
                    'body' => '',
                    'bodyType' => 'none',
                    'formData' => [],
                    'showDefaultHeaders' => false
                ]
            ]
        ]
    ];
}

/**
 * Save settings to file
 */
function saveSettings($settings) {
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        error_log('LocalMan: Failed to encode settings to JSON');
        return false;
    }
    
    $fp = @fopen(SETTINGS_FILE, 'c');
    if ($fp === false) {
        error_log('LocalMan: Failed to open settings file');
        return false;
    }
    
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    
    fclose($fp);
    return true;
}

/**
 * Check if version check is needed (once every 24 hours)
 */
function shouldCheckVersion($settings) {
    $lastCheck = $settings['lastVersionCheck'] ?? 0;
    $now = time();
    return ($now - $lastCheck) >= 86400; // 24 hours in seconds
}

/**
 * Check for new version from GitHub
 */
function checkForNewVersion() {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/madanielsen/localman/releases/latest');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'LocalMan/' . LOCALMAN_VERSION);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if ($data && isset($data['tag_name'])) {
            $latestVersion = ltrim($data['tag_name'], 'v');
            if (version_compare($latestVersion, LOCALMAN_VERSION, '>')) {
                return [
                    'hasUpdate' => true,
                    'latestVersion' => $latestVersion,
                    'currentVersion' => LOCALMAN_VERSION,
                    'url' => $data['html_url'] ?? 'https://github.com/madanielsen/localman/releases/latest'
                ];
            }
        }
    }
    
    return ['hasUpdate' => false, 'currentVersion' => LOCALMAN_VERSION];
}

/**
 * Perform version check if needed
 */
function autoCheckVersion(&$settings) {
    if (shouldCheckVersion($settings)) {
        $versionInfo = checkForNewVersion();
        $settings['lastVersionCheck'] = time();
        
        if ($versionInfo['hasUpdate']) {
            $settings['availableUpdate'] = $versionInfo;
        } else {
            unset($settings['availableUpdate']);
        }
        
        saveSettings($settings);
    }
}

/**
 * Download and install update from GitHub
 */
function performAutoUpdate() {
    $currentFile = __FILE__;
    $backupFile = __DIR__ . '/index.php.backup';
    $settingsFile = SETTINGS_FILE;
    $settingsBackup = __DIR__ . '/localman.settings.json.backup';
    
    // Create backup of current file
    if (!copy($currentFile, $backupFile)) {
        return ['success' => false, 'message' => 'Failed to create backup file'];
    }
    
    // Create backup of settings file if it exists
    if (file_exists($settingsFile)) {
        copy($settingsFile, $settingsBackup);
    }
    
    // Download latest version from GitHub
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://raw.githubusercontent.com/madanielsen/localman/main/index.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'LocalMan/' . LOCALMAN_VERSION);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $newContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($newContent)) {
        return ['success' => false, 'message' => 'Failed to download update: ' . ($curlError ?: 'HTTP ' . $httpCode)];
    }
    
    // Validate the downloaded content (basic check)
    if (strpos($newContent, '<?php') !== 0 || strpos($newContent, 'LocalMan') === false) {
        return ['success' => false, 'message' => 'Downloaded file appears to be invalid'];
    }
    
    // Write new content to current file
    if (file_put_contents($currentFile, $newContent) === false) {
        return ['success' => false, 'message' => 'Failed to write new file. Backup available at: ' . basename($backupFile)];
    }
    
    // Delete settings file to force fresh start with new version
    if (file_exists($settingsFile)) {
        @unlink($settingsFile);
    }
    
    return [
        'success' => true, 
        'message' => 'Update installed successfully!\n\nBackups saved:\n- ' . basename($backupFile) . '\n- ' . basename($settingsBackup) . '\n\nSettings will be reset to defaults.',
        'backup' => $backupFile,
        'settingsBackup' => $settingsBackup
    ];
}

/**
 * Get current project settings
 */
function getCurrentProject($settings) {
    $projectName = $settings['currentProject'] ?? 'default';
    return $settings['projects'][$projectName] ?? $settings['projects']['default'];
}

/**
 * Load history from file storage
 */
function loadHistory($type) {
    $dir = $type === 'webhook' ? WEBHOOKS_DIR : REQUESTS_DIR;
    $files = glob($dir . '/*.json');
    
    if (empty($files)) {
        return [];
    }
    
    // Sort by filename (timestamp) descending
    rsort($files);
    
    // Load last 50 entries
    $history = [];
    foreach (array_slice($files, 0, 50) as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $history[] = $data;
        }
    }
    
    return $history;
}

/**
 * Save entry to file storage
 */
function saveEntry($type, $data) {
    $dir = $type === 'webhook' ? WEBHOOKS_DIR : REQUESTS_DIR;
    $timestamp = microtime(true);
    $filename = $dir . '/' . $timestamp . '_' . uniqid() . '.json';
    
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        error_log('LocalMan: Failed to encode entry to JSON');
        return false;
    }
    
    $fp = @fopen($filename, 'w');
    if ($fp !== false) {
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $json);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
    
    // Clean up old files (keep only last 50)
    $files = glob($dir . '/*.json');
    if (count($files) > 50) {
        rsort($files);
        foreach (array_slice($files, 50) as $oldFile) {
            @unlink($oldFile);
        }
    }
}

/**
 * Clear all entries of a specific type
 */
function clearHistory($type) {
    $dir = $type === 'webhook' ? WEBHOOKS_DIR : REQUESTS_DIR;
    $files = glob($dir . '/*.json');
    
    foreach ($files as $file) {
        unlink($file);
    }
}

/**
 * Count unread webhooks for current project
 */
function countUnreadWebhooks($currentProjectName) {
    $webhooks = loadHistory('webhook');
    $count = 0;
    foreach ($webhooks as $webhook) {
        if (!($webhook['read'] ?? false) && ($webhook['project'] ?? '') === $currentProjectName) {
            $count++;
        }
    }
    return $count;
}

/**
 * Mark all webhooks as read for current project
 */
function markWebhooksAsRead($currentProjectName) {
    $dir = WEBHOOKS_DIR;
    $files = glob($dir . '/*.json');
    
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && ($data['project'] ?? '') === $currentProjectName && !($data['read'] ?? false)) {
            $data['read'] = true;
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
        }
    }
}

// Handle actions
$action = $_GET['action'] ?? 'home';
$response_data = null;
$error = null;

// Load settings
$settings = loadSettings();
$currentProject = getCurrentProject($settings);

// Auto-check for new version (once every 24 hours)
autoCheckVersion($settings);

// Handle auto-update
if (isset($_POST['perform_auto_update'])) {
    $updateResult = performAutoUpdate();
    header('Content-Type: application/json');
    echo json_encode($updateResult);
    exit;
}

// Handle dark mode toggle
if (isset($_POST['toggle_dark_mode'])) {
    $settings['darkMode'] = $_POST['dark_mode'] ?? 'auto';
    saveSettings($settings);
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Handle project switching
if (isset($_POST['switch_project'])) {
    $projectName = $_POST['project_name'] ?? 'default';
    if (isset($settings['projects'][$projectName])) {
        $settings['currentProject'] = $projectName;
        saveSettings($settings);
        $currentProject = $settings['projects'][$projectName];
    }
}

// Handle new project creation
if (isset($_POST['create_project'])) {
    $projectName = $_POST['new_project_name'] ?? '';
    if (!empty($projectName) && !isset($settings['projects'][$projectName])) {
        $settings['projects'][$projectName] = [
            'name' => $projectName,
            'webhookResponse' => [
                'status' => 200,
                'body' => '{"status": "success", "message": "Webhook received"}',
                'headers' => ['Content-Type: application/json']
            ],
            'lastRequest' => [
                'url' => '',
                'method' => 'GET',
                'headers' => [],
                'body' => '',
                'showDefaultHeaders' => false
            ]
        ];
        $settings['currentProject'] = $projectName;
        saveSettings($settings);
        $currentProject = $settings['projects'][$projectName];
    }
}

// Handle reset webhook settings
if ($action === 'webhooks' && isset($_GET['reset'])) {
    $settings['projects'][$settings['currentProject']]['webhookResponse'] = [
        'status' => 200,
        'body' => '{"status": "success", "message": "Webhook received"}',
        'headers' => [['key' => 'Content-Type', 'value' => 'application/json', 'enabled' => true]]
    ];
    saveSettings($settings);
    header('Location: ?action=webhooks&success=webhook_reset');
    exit;
}

// Handle reset API request settings
if ($action === 'home' && isset($_GET['reset'])) {
    $settings['projects'][$settings['currentProject']]['lastRequest'] = [
        'url' => 'https://api.localman.com/test',
        'method' => 'GET',
        'params' => [],
        'authorization' => ['type' => 'none', 'token' => '', 'username' => '', 'password' => ''],
        'headers' => [],
        'body' => '',
        'bodyType' => 'none',
        'formData' => [],
        'showDefaultHeaders' => false
    ];
    saveSettings($settings);
    header('Location: ?action=home&success=request_reset');
    exit;
}

// Handle webhook response configuration
if (isset($_POST['save_webhook_config'])) {
    $status = intval($_POST['webhook_status'] ?? 200);
    // Validate status code (100-599)
    if ($status < 100 || $status > 599) {
        $status = 200;
    }
    $body = $_POST['webhook_body'] ?? '';
    
    // Process response headers as key-value pairs
    $headerKeys = $_POST['response_header_key'] ?? [];
    $headerValues = $_POST['response_header_value'] ?? [];
    $headerEnabled = $_POST['response_header_enabled'] ?? [];
    
    $headerArray = [];
    foreach ($headerKeys as $idx => $key) {
        $key = trim($key);
        $value = trim($headerValues[$idx] ?? '');
        
        if (!empty($key) && isset($headerEnabled[$idx])) {
            $headerArray[] = [
                'key' => $key,
                'value' => $value,
                'enabled' => true
            ];
        } elseif (!empty($key)) {
            $headerArray[] = [
                'key' => $key,
                'value' => $value,
                'enabled' => false
            ];
        }
    }
    
    $settings['projects'][$settings['currentProject']]['webhookResponse'] = [
        'status' => $status,
        'body' => $body,
        'headers' => $headerArray
    ];
    saveSettings($settings);
    $currentProject = getCurrentProject($settings);
    header('Location: ?action=webhooks');
    exit;
}

// Handle webhook capture
if ($action === 'webhook') {
    // Get project from URL parameter
    $webhookProject = $_GET['project'] ?? null;
    
    // Reject if no project specified or project doesn't exist
    if (!$webhookProject || !isset($settings['projects'][$webhookProject])) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Project not found',
            'message' => $webhookProject ? "Project '$webhookProject' does not exist" : 'No project specified in webhook URL'
        ]);
        exit;
    }
    
    // Use configured response from the specified project
    $webhookProjectConfig = $settings['projects'][$webhookProject];
    $webhookConfig = $webhookProjectConfig['webhookResponse'] ?? [
        'status' => 200,
        'body' => '{"status": "success", "message": "Webhook received"}',
        'headers' => [['key' => 'Content-Type', 'value' => 'application/json', 'enabled' => true]]
    ];
    
    // Prepare response headers array for storage
    $responseHeadersArray = [];
    foreach ($webhookConfig['headers'] as $header) {
        // Handle both old string format and new array format
        if (is_string($header)) {
            // Old format: "Key: Value"
            $responseHeadersArray[] = $header;
        } elseif (is_array($header) && ($header['enabled'] ?? true)) {
            // New format: array with key, value, enabled
            $responseHeadersArray[] = $header['key'] . ': ' . $header['value'];
        }
    }
    
    // Capture request body - handle different content types
    $rawBody = file_get_contents('php://input');
    $postData = $_POST;
    $filesData = $_FILES;
    
    // If raw body is empty but POST/FILES have data, it's likely multipart/form-data
    if (empty($rawBody) && (!empty($postData) || !empty($filesData))) {
        $bodyData = [];
        if (!empty($postData)) {
            $bodyData['post'] = $postData;
        }
        if (!empty($filesData)) {
            $bodyData['files'] = $filesData;
        }
        $body = json_encode($bodyData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } else {
        $body = $rawBody;
    }
    
    $webhook_data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'method' => $_SERVER['REQUEST_METHOD'],
        'headers' => getallheaders(),
        'query' => $_GET,
        'body' => $body,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'project' => $webhookProject,
        'read' => false,
        'response' => [
            'status' => $webhookConfig['status'],
            'headers' => $responseHeadersArray,
            'body' => $webhookConfig['body']
        ]
    ];
    
    saveEntry('webhook', $webhook_data);
    
    http_response_code($webhookConfig['status']);
    foreach ($webhookConfig['headers'] as $header) {
        // Handle both old string format and new array format
        if (is_string($header)) {
            // Old format: "Key: Value"
            header($header);
        } elseif (is_array($header) && ($header['enabled'] ?? true)) {
            // New format: array with key, value, enabled
            header($header['key'] . ': ' . $header['value']);
        }
    }
    echo $webhookConfig['body'];
    exit;
}

// Handle AJAX request to get webhook count
if ($action === 'webhook_count') {
    $unreadCount = countUnreadWebhooks($settings['currentProject']);
    header('Content-Type: application/json');
    echo json_encode(['count' => $unreadCount]);
    exit;
}

// Mark webhooks as read when viewing webhooks page
if ($action === 'webhooks') {
    markWebhooksAsRead($settings['currentProject']);
}

// Handle clear history
if ($action === 'clear_requests') {
    clearHistory('request');
    header('Location: ?');
    exit;
}

if ($action === 'clear_webhooks') {
    clearHistory('webhook');
    header('Location: ?action=webhooks');
    exit;
}

// Handle API request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_request'])) {
    $url = $_POST['url'] ?? '';
    $method = $_POST['method'] ?? 'GET';
    $body = $_POST['body'] ?? '';
    $bodyType = $_POST['body_type'] ?? 'none';
    
    // Parse query params (ignore empty keys)
    $paramRows = [];
    if (isset($_POST['param_key'])) {
        foreach ($_POST['param_key'] as $idx => $key) {
            $key = trim($key);
            if (!empty($key)) {
                $paramRows[] = [
                    'key' => $key,
                    'value' => $_POST['param_value'][$idx] ?? '',
                    'description' => $_POST['param_description'][$idx] ?? '',
                    'enabled' => isset($_POST['param_enabled'][$idx])
                ];
            }
        }
    }
    
    // Parse authorization
    $authType = $_POST['auth_type'] ?? 'none';
    $authorization = [
        'type' => $authType,
        'token' => $_POST['auth_token'] ?? '',
        'username' => $_POST['auth_username'] ?? '',
        'password' => $_POST['auth_password'] ?? ''
    ];
    
    // Save current request state (ignore empty keys)
    $headerRows = [];
    if (isset($_POST['header_key'])) {
        foreach ($_POST['header_key'] as $idx => $key) {
            $key = trim($key);
            if (!empty($key)) {
                $headerRows[] = [
                    'key' => $key,
                    'value' => $_POST['header_value'][$idx] ?? '',
                    'enabled' => isset($_POST['header_enabled'][$idx]),
                    'isDefault' => isset($_POST['header_is_default'][$idx]) ? true : false
                ];
            }
        }
    }
    
    // Parse form data
    $formDataRows = [];
    if (isset($_POST['formdata_key'])) {
        // Build a set of enabled checkbox indexes
        $enabledIndexes = [];
        if (isset($_POST['formdata_enabled'])) {
            $enabledIndexes = array_flip(array_keys($_POST['formdata_enabled']));
        }
        
        foreach ($_POST['formdata_key'] as $idx => $key) {
            $key = trim($key);
            if (!empty($key)) {
                $type = $_POST['formdata_type'][$idx] ?? 'text';
                $value = $_POST['formdata_value'][$idx] ?? '';
                $fileContent = null;
                $fileName = null;
                
                // Handle file uploads
                if ($type === 'file') {
                    // Check if new file was uploaded
                    if (isset($_FILES['formdata_file']['tmp_name'][$idx]) && !empty($_FILES['formdata_file']['tmp_name'][$idx])) {
                        $tmpName = $_FILES['formdata_file']['tmp_name'][$idx];
                        $fileName = $_FILES['formdata_file']['name'][$idx];
                        $fileContent = base64_encode(file_get_contents($tmpName));
                        $value = $fileName; // Store filename for display
                    } else {
                        // No new file uploaded, check if we have stored file content
                        $fileName = $value; // Keep existing filename
                        // Retrieve stored file content from hidden field
                        if (isset($_POST['formdata_file_content'][$idx])) {
                            $fileContent = $_POST['formdata_file_content'][$idx];
                        }
                    }
                }
                
                $formDataRows[] = [
                    'key' => $key,
                    'value' => $value,
                    'type' => $type,
                    'enabled' => isset($enabledIndexes[$idx]),
                    'fileContent' => $fileContent,
                    'fileName' => $fileName
                ];
            }
        }
    }
    
    $settings['projects'][$settings['currentProject']]['lastRequest'] = [
        'url' => $url,
        'method' => $method,
        'params' => $paramRows,
        'authorization' => $authorization,
        'headers' => $headerRows,
        'body' => $body,
        'bodyType' => $bodyType,
        'formData' => $formDataRows,
        'showDefaultHeaders' => isset($_POST['show_default_headers'])
    ];
    saveSettings($settings);
    
    // Reload current project to get updated settings for UI display
    $currentProject = getCurrentProject($settings);
    
    if (empty($url)) {
        $error = 'URL is required';
    } else {
        try {
            // Add query parameters to URL
            $finalUrl = $url;
            $enabledParams = array_filter($paramRows, function($p) { return $p['enabled'] ?? true; });
            if (!empty($enabledParams)) {
                // Parse existing URL to handle query strings properly
                $urlParts = parse_url($url);
                $existingParams = [];
                if (isset($urlParts['query'])) {
                    parse_str($urlParts['query'], $existingParams);
                }
                
                // Merge with new params
                $newParams = array_column($enabledParams, 'value', 'key');
                $allParams = array_merge($existingParams, $newParams);
                
                // Rebuild URL
                $finalUrl = $urlParts['scheme'] . '://' . $urlParts['host'];
                if (isset($urlParts['port'])) {
                    $finalUrl .= ':' . $urlParts['port'];
                }
                if (isset($urlParts['path'])) {
                    $finalUrl .= $urlParts['path'];
                }
                if (!empty($allParams)) {
                    $finalUrl .= '?' . http_build_query($allParams);
                }
                if (isset($urlParts['fragment'])) {
                    $finalUrl .= '#' . $urlParts['fragment'];
                }
            }
            
            // Build headers array
            $header_array = [];
            $added_headers = []; // Track added header keys (case-insensitive)
            
            // Add authorization header
            if ($authType === 'bearer' && !empty($authorization['token'])) {
                $header_array[] = 'Authorization: Bearer ' . $authorization['token'];
                $added_headers['authorization'] = true;
            } elseif ($authType === 'basic' && !empty($authorization['username'])) {
                $authHeader = base64_encode($authorization['username'] . ':' . $authorization['password']);
                $header_array[] = 'Authorization: Basic ' . $authHeader;
                $added_headers['authorization'] = true;
            }
            
            // Add custom headers (skip duplicates)
            foreach ($headerRows as $row) {
                if ($row['enabled']) {
                    $headerKeyLower = strtolower(trim($row['key']));
                    // Skip if this header was already added (e.g., Authorization from auth settings)
                    if (!isset($added_headers[$headerKeyLower])) {
                        $header_array[] = $row['key'] . ': ' . $row['value'];
                        $added_headers[$headerKeyLower] = true;
                    }
                }
            }
            
            // Make request using cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $finalUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, REQUEST_TIMEOUT);
            curl_setopt($ch, CURLOPT_ENCODING, ''); // Enable automatic decompression
            
            // Set method
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            
            // Set headers
            if (!empty($header_array)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $header_array);
            }
            
            // Handle body based on type
            if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                // Track temp files for cleanup
                $tempFiles = [];
                
                if ($bodyType === 'form-data' || $bodyType === 'x-www-form-urlencoded') {
                    $enabledFormData = array_filter($formDataRows, function($f) { return $f['enabled'] ?? true; });
                    
                    if ($bodyType === 'x-www-form-urlencoded') {
                        $postData = array_column($enabledFormData, 'value', 'key');
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
                    } else {
                        // For multipart/form-data, handle files properly
                        $postData = [];
                        foreach ($enabledFormData as $field) {
                            if (($field['type'] ?? 'text') === 'file' && !empty($field['fileContent'])) {
                                // Create CURLFile from base64 content
                                $tmpFile = tempnam(sys_get_temp_dir(), 'localman_');
                                file_put_contents($tmpFile, base64_decode($field['fileContent']));
                                $tempFiles[] = $tmpFile;
                                $postData[$field['key']] = new CURLFile($tmpFile, mime_content_type($tmpFile), $field['fileName'] ?? basename($tmpFile));
                            } else {
                                $postData[$field['key']] = $field['value'];
                            }
                        }
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                    }
                } elseif ($bodyType === 'raw' && !empty($body)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
            }
            
            $start_time = microtime(true);
            $response = curl_exec($ch);
            $duration = round((microtime(true) - $start_time) * 1000, 2);
            
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            $response_headers = '';
            $response_body = '';
            
            if ($response !== false) {
                $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $response_headers = substr($response, 0, $header_size);
                $response_body = substr($response, $header_size);
            }
            
            curl_close($ch);
            
            if ($curl_error) {
                $error = 'cURL Error: ' . $curl_error;
                $response_body = $curl_error;
            }
            
            // Always save to history, even on error
            $response_data = [
                'url' => $finalUrl, // Store full URL with query string
                'method' => $method,
                'status' => $http_code ?: 0,
                'duration' => $duration,
                'headers' => $response_headers,
                'body' => $response_body,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            saveEntry('request', [
                'url' => $finalUrl,
                'method' => $method,
                'status' => $http_code ?: 0,
                'duration' => $duration,
                'timestamp' => date('Y-m-d H:i:s'),
                'request' => [
                    'headers' => $header_array,
                    'body' => ($bodyType === 'form-data' || $bodyType === 'x-www-form-urlencoded') 
                        ? json_encode($postData ?? []) 
                        : ($bodyType === 'raw' ? $body : '')
                ],
                'response' => [
                    'headers' => $response_headers,
                    'body' => $response_body,
                    'error' => $curl_error ?: null
                ]
            ]);
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        } finally {
            // Clean up temporary files
            if (!empty($tempFiles)) {
                foreach ($tempFiles as $tmpFile) {
                    if (file_exists($tmpFile)) {
                        @unlink($tmpFile);
                    }
                }
            }
        }
    }
}

// Get webhook URL
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
if ($script_dir === '/' || $script_dir === '\\') {
    $script_dir = '';
} else {
    $script_dir = rtrim($script_dir, '/\\');
}
$webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") 
    . "://" . $_SERVER['HTTP_HOST'] . $script_dir . "/index.php?action=webhook&project=" . urlencode($settings['currentProject']);

// Load history for display
$request_history = loadHistory('request');
$webhook_history = loadHistory('webhook');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LocalMan - API Testing & Webhook Capture</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        * { box-sizing: border-box; }
        
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #f6f6f6;
            --bg-tertiary: #f0f0f0;
            --bg-sidebar: #f8f8f8;
            --bg-topbar: #ffffff;
            --text-primary: #212121;
            --text-secondary: #6b6b6b;
            --text-tertiary: #a0a0a0;
            --border-primary: #e0e0e0;
            --border-secondary: #e5e5e5;
            --input-bg: #ffffff;
        }
        
        [data-theme="dark"] {
            --bg-primary: #1e1e1e;
            --bg-secondary: #121212;
            --bg-tertiary: #2a2a2a;
            --bg-sidebar: #1a1a1a;
            --bg-topbar: #0d0d0d;
            --text-primary: #e0e0e0;
            --text-secondary: #a0a0a0;
            --text-tertiary: #6b6b6b;
            --border-primary: #3a3a3a;
            --border-secondary: #2a2a2a;
            --input-bg: #2a2a2a;
        }
        
        body { 
            margin: 0; 
            padding: 0; 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-secondary);
            color: var(--text-primary);
            transition: background-color 0.3s, color 0.3s;
        }
        .sidebar { 
            background: var(--bg-sidebar); 
            border-right: 1px solid var(--border-secondary);
            transition: background-color 0.3s;
        }
        .main-content { 
            background: var(--bg-secondary); 
        }
        .top-bar { 
            background: var(--bg-topbar); 
            border-bottom: 1px solid var(--border-secondary);
            transition: background-color 0.3s;
        }
        .method-badge { 
            font-family: 'Inter', sans-serif; 
            font-weight: 700; 
            font-size: 12px; 
            letter-spacing: 0px;
        }
        .tab-button { 
            position: relative; 
            color: var(--text-tertiary);
            transition: color 0.2s;
        }
        .tab-button:hover {
            color: var(--text-secondary);
        }
        .tab-button.active { 
            color: var(--text-primary);
        }
        .tab-button.active::after { 
            content: ''; 
            position: absolute; 
            bottom: 0; 
            left: 12px; 
            right: 12px; 
            height: 2px; 
            background: #ff6c37; 
        }
        .request-tabs {
            display: flex;
            border-bottom: 1px solid var(--border-primary);
            background: var(--bg-primary);
            gap: 0;
        }
        .request-tab {
            padding: 12px 20px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 500;
            transition: all 0.2s;
            position: relative;
        }
        .request-tab:hover {
            color: var(--text-primary);
        }
        .request-tab.active {
            border-bottom-color: #ff6c37;
            color: #ff6c37;
            font-weight: 600;
        }
        .request-tab .count {
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 11px;
            margin-left: 6px;
            font-weight: 600;
        }
        .request-tab.active .count {
            background: #ffe0d6;
            color: #ff6c37;
        }
        .data-row { 
            background: transparent; 
            border: 1px solid var(--border-primary); 
            border-radius: 4px;
            transition: all 0.2s;
        }
        .data-row:hover { 
            background: var(--bg-tertiary); 
        }
        .postman-orange { 
            background: #ff6c37; 
        }
        .postman-orange:hover { 
            background: #ff5722; 
        }
        .response-tabs { 
            display: flex; 
            border-bottom: 1px solid var(--border-primary); 
            background: var(--bg-primary); 
            padding: 0 16px;
        }
        .response-tab { 
            padding: 12px 16px; 
            cursor: pointer; 
            border-bottom: 3px solid transparent; 
            font-size: 13px; 
            color: var(--text-secondary);
            font-weight: 500;
            margin-bottom: -1px;
        }
        .response-tab:hover {
            color: var(--text-primary);
        }
        .response-tab.active { 
            border-bottom-color: #ff6c37; 
            color: #ff6c37; 
            font-weight: 600; 
        }
        input[type="text"], input[type="url"], input[type="number"], input[type="password"], select, textarea {
            font-size: 13px;
            background: var(--input-bg);
            color: var(--text-primary);
            border-color: var(--border-primary);
        }
        input[type="text"]:focus, input[type="url"]:focus, input[type="number"]:focus, input[type="password"]:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #ff6c37;
            box-shadow: 0 0 0 1px #ff6c37;
        }
        .request-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-primary);
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-tertiary);
        }
        .unread-badge {
            background: #ff6c37;
            color: white;
            font-size: 10px;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
            display: inline-block;
        }
        .icon {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .dark-mode-toggle {
            width: 40px;
            height: 20px;
            background: #4a4a4a;
            border-radius: 10px;
            position: relative;
            cursor: pointer;
            transition: background 0.3s;
        }
        .dark-mode-toggle.active {
            background: #ff6c37;
        }
        .dark-mode-toggle::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            background: white;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: transform 0.3s;
        }
        .dark-mode-toggle.active::after {
            transform: translateX(20px);
        }
    </style>
    <script>
        // Dark mode initialization
        function initDarkMode() {
            const darkModeSetting = '<?php echo $settings['darkMode'] ?? 'auto'; ?>';
            let isDark = false;
            
            if (darkModeSetting === 'dark') {
                isDark = true;
            } else if (darkModeSetting === 'light') {
                isDark = false;
            } else {
                isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            }
            
            document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
        }
        initDarkMode();
    </script>
</head>
<body>
    <!-- Top Navigation Bar -->
    <div class="top-bar flex items-center justify-between px-6 py-3">
        <div class="flex items-center gap-4">
            <h1 class="text-xl font-bold" style="color: var(--text-primary);">LocalMan</h1>
            <div class="text-xs" style="color: var(--text-tertiary);">v<?php echo LOCALMAN_VERSION; ?></div>
        </div>
        <div class="flex items-center gap-6">
            <div class="flex items-center gap-3">
                <span class="text-xs" style="color: var(--text-tertiary);">Theme:</span>
                <select id="darkModeSelect" onchange="toggleDarkMode(this.value)" class="text-xs px-3 py-1.5 rounded border-0" style="background: var(--bg-tertiary); color: var(--text-primary);">
                    <option value="auto" <?php echo ($settings['darkMode'] ?? 'auto') === 'auto' ? 'selected' : ''; ?>>Auto</option>
                    <option value="light" <?php echo ($settings['darkMode'] ?? 'auto') === 'light' ? 'selected' : ''; ?>>Light</option>
                    <option value="dark" <?php echo ($settings['darkMode'] ?? 'auto') === 'dark' ? 'selected' : ''; ?>>Dark</option>
                </select>
            </div>
            <div class="text-sm" style="color: var(--text-secondary);">
                <span style="color: var(--text-tertiary);">Project:</span> <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($currentProject['name']); ?></strong>
            </div>
            <button onclick="document.getElementById('projectModal').classList.remove('hidden')" 
                    class="px-4 py-1.5 rounded text-sm font-medium transition" style="background: var(--bg-tertiary); color: var(--text-primary);" onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background='var(--bg-tertiary)'">
                Projects
            </button>
        </div>
    </div>
    
    <?php if (isset($settings['availableUpdate']) && is_array($settings['availableUpdate']) && !empty($settings['availableUpdate']['hasUpdate'])): ?>
    <!-- Update Available Banner -->
    <div class="px-6 py-3" style="background: #fff3cd; border-bottom: 1px solid #ffc107;">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5" style="color: #856404;" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
                <span class="text-sm font-medium" style="color: #856404;">
                    A new version of LocalMan is available: 
                    <strong>v<?php echo htmlspecialchars($settings['availableUpdate']['latestVersion'] ?? ''); ?></strong>
                    (current: v<?php echo htmlspecialchars($settings['availableUpdate']['currentVersion'] ?? LOCALMAN_VERSION); ?>)
                </span>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="performAutoUpdate()" id="autoUpdateBtn"
                        class="px-4 py-1.5 rounded text-sm font-medium transition" 
                        style="background: #28a745; color: white;">
                    <span id="updateBtnText">Auto Update</span>
                </button>
                <a href="<?php echo htmlspecialchars($settings['availableUpdate']['url'] ?? 'https://github.com/madanielsen/localman/releases/latest'); ?>" 
                   target="_blank" 
                   class="px-4 py-1.5 rounded text-sm font-medium" 
                   style="background: #ffc107; color: #856404;">
                    View Release
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="flex" style="height: calc(100vh - 54px);">
        <!-- Sidebar -->
        <div class="sidebar" style="width: 260px;">
            <div class="p-4">
                <div class="mb-6">
                    <a href="?" class="tab-button flex items-center gap-3 px-4 py-3 rounded font-medium text-sm transition <?php echo ($action === 'home' || empty($action)) ? 'active' : ''; ?>" style="<?php echo ($action === 'home' || empty($action)) ? 'background: var(--bg-tertiary);' : ''; ?>" onmouseover="if (!this.classList.contains('active')) this.style.background='var(--bg-tertiary)'" onmouseout="if (!this.classList.contains('active')) this.style.background='transparent'">
                        <svg class="icon" viewBox="0 0 24 24">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="7 10 12 15 17 10"></polyline>
                            <line x1="12" y1="15" x2="12" y2="3"></line>
                        </svg>
                        <span>API Request</span>
                    </a>
                    <a href="?action=webhooks" class="tab-button flex items-center gap-3 px-4 py-3 rounded font-medium text-sm transition mt-1 <?php echo $action === 'webhooks' ? 'active' : ''; ?>" style="<?php echo $action === 'webhooks' ? 'background: var(--bg-tertiary);' : ''; ?>" onmouseover="if (!this.classList.contains('active')) this.style.background='var(--bg-tertiary)'" onmouseout="if (!this.classList.contains('active')) this.style.background='transparent'">
                        <svg class="icon" viewBox="0 0 24 24">
                            <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                        </svg>
                        <span>Webhooks</span>
                        <?php 
                        $unreadCount = countUnreadWebhooks($settings['currentProject']);
                        if ($unreadCount > 0): 
                        ?>
                            <span class="unread-badge"><?php echo $unreadCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="?action=history" class="tab-button flex items-center gap-3 px-4 py-3 rounded font-medium text-sm transition mt-1 <?php echo $action === 'history' ? 'active' : ''; ?>" style="<?php echo $action === 'history' ? 'background: var(--bg-tertiary);' : ''; ?>" onmouseover="if (!this.classList.contains('active')) this.style.background='var(--bg-tertiary)'" onmouseout="if (!this.classList.contains('active')) this.style.background='transparent'">
                        <svg class="icon" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                        <span>History</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content flex-1 overflow-y-auto">
            <div class="h-full">
        
        <?php if ($action === 'home' || empty($action)): ?>
            <div class="h-full flex flex-col">
                <!-- Request URL Bar -->
                <form method="POST" enctype="multipart/form-data" style="background: var(--bg-primary); border-bottom: 1px solid var(--border-primary);">
                    <div class="px-6 py-4">
                        <?php if ($error): ?>
                            <div class="border-l-4 border-red-500 p-3 mb-4 rounded text-sm" style="background: var(--bg-tertiary); color: #ef4444;">
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($_GET['success']) && $_GET['success'] === 'request_reset'): ?>
                            <div class="border-l-4 border-green-500 p-3 mb-4 rounded text-sm" style="background: #dcfce7; color: #16a34a;">
                                API Request settings have been reset to defaults.
                            </div>
                        <?php endif; ?>
                        
                        <div class="flex gap-2">
                            <select name="method" class="px-3 py-2 border rounded focus:outline-none focus:border-orange-500 font-semibold text-sm" style="width: 110px;">
                                <option value="GET" <?php echo ($currentProject['lastRequest']['method'] === 'GET') ? 'selected' : ''; ?>>GET</option>
                                <option value="POST" <?php echo ($currentProject['lastRequest']['method'] === 'POST') ? 'selected' : ''; ?>>POST</option>
                                <option value="PUT" <?php echo ($currentProject['lastRequest']['method'] === 'PUT') ? 'selected' : ''; ?>>PUT</option>
                                <option value="PATCH" <?php echo ($currentProject['lastRequest']['method'] === 'PATCH') ? 'selected' : ''; ?>>PATCH</option>
                                <option value="DELETE" <?php echo ($currentProject['lastRequest']['method'] === 'DELETE') ? 'selected' : ''; ?>>DELETE</option>
                            </select>
                            <input type="url" id="url-input" name="url" placeholder="Enter URL or paste text" 
                                   value="<?php echo htmlspecialchars($currentProject['lastRequest']['url']); ?>" 
                                   class="flex-1 px-4 py-2 border rounded focus:outline-none font-mono text-sm" oninput="updateFullUrl()" required>
                            <button type="submit" name="send_request" class="postman-orange text-white font-semibold px-8 py-2 rounded transition text-sm">
                                Send
                            </button>
                        </div>
                        <?php 
                        // Show full query string preview
                        $enabledParams = array_filter($currentProject['lastRequest']['params'] ?? [], function($p) { return $p['enabled'] ?? true; });
                        if (!empty($enabledParams) && !empty($currentProject['lastRequest']['url'])): 
                            $baseUrl = $currentProject['lastRequest']['url'];
                            $queryString = http_build_query(array_column($enabledParams, 'value', 'key'));
                            $fullUrl = $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . $queryString;
                        ?>
                        <div class="mt-2 text-xs" style="color: var(--text-tertiary);">
                            <strong>Full URL:</strong> <span class="font-mono" style="color: var(--text-secondary);"><?php echo htmlspecialchars($fullUrl); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Request Tabs -->
                    <div class="request-tabs" style="display: flex; justify-content: space-between; align-items: center;">
                        <div style="display: flex;">
                        <div class="request-tab active" onclick="switchRequestTab('params-tab')">
                            Params
                            <?php 
                            $enabledParamCount = 0;
                            foreach ($currentProject['lastRequest']['params'] ?? [] as $p) {
                                if (!empty($p['key']) && ($p['enabled'] ?? true)) $enabledParamCount++;
                            }
                            if ($enabledParamCount > 0): ?>
                            <span class="count"><?php echo $enabledParamCount; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="request-tab" onclick="switchRequestTab('authorization-tab')">
                            Authorization
                            <?php 
                            $authType = $currentProject['lastRequest']['authorization']['type'] ?? 'none';
                            if ($authType !== 'none'): ?>
                            <span class="count">1</span>
                            <?php endif; ?>
                        </div>
                        <div class="request-tab" onclick="switchRequestTab('headers-tab')">
                            Headers
                            <?php 
                            // Count default headers
                            $defaultHeadersEnabledCount = 0;
                            $defaultHeadersList = [
                                ['key' => 'User-Agent', 'enabled' => true],
                                ['key' => 'Accept', 'enabled' => true],
                                ['key' => 'Accept-Encoding', 'enabled' => true],
                                ['key' => 'Connection', 'enabled' => true],
                                ['key' => 'Cache-Control', 'enabled' => true],
                            ];
                            
                            // Check saved state for default headers
                            $savedHeaders = $currentProject['lastRequest']['headers'] ?? [];
                            foreach ($savedHeaders as $h) {
                                if (isset($h['isDefault']) && $h['isDefault'] && !empty($h['key']) && ($h['enabled'] ?? true)) {
                                    $defaultHeadersEnabledCount++;
                                }
                            }
                            // If no saved default headers, count from defaults
                            if ($defaultHeadersEnabledCount === 0) {
                                foreach ($defaultHeadersList as $dh) {
                                    if ($dh['enabled']) $defaultHeadersEnabledCount++;
                                }
                            }
                            
                            // Count custom headers
                            $customHeadersCount = 0;
                            foreach ($savedHeaders as $h) {
                                if ((!isset($h['isDefault']) || !$h['isDefault']) && !empty($h['key']) && ($h['enabled'] ?? true)) {
                                    $customHeadersCount++;
                                }
                            }
                            
                            $totalHeaderCount = $defaultHeadersEnabledCount + $customHeadersCount;
                            if ($totalHeaderCount > 0): ?>
                            <span class="count" id="headers-count"><?php echo $totalHeaderCount; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="request-tab" onclick="switchRequestTab('body-tab')">
                            Body
                            <?php 
                            $bodyType = $currentProject['lastRequest']['bodyType'] ?? 'none';
                            if ($bodyType !== 'none'): 
                                $bodyCount = 0;
                                if ($bodyType === 'raw' && !empty($currentProject['lastRequest']['body'])) {
                                    $bodyCount = 1;
                                } elseif (in_array($bodyType, ['form-data', 'x-www-form-urlencoded'])) {
                                    foreach ($currentProject['lastRequest']['formData'] ?? [] as $fd) {
                                        $key = trim($fd['key'] ?? '');
                                        if (!empty($key) && ($fd['enabled'] ?? true)) $bodyCount++;
                                    }
                                }
                                if ($bodyCount > 0): ?>
                            <span class="count" id="body-count"><?php echo $bodyCount; ?></span>
                            <?php endif; endif; ?>
                        </div>
                        </div>
                        <div style="padding: 0 16px;">
                            <a href="?action=home&reset=true" onclick="return confirm('Reset all API Request settings to defaults?');">
                                <button type="button" class="text-xs px-3 py-1.5 rounded font-medium transition" style="background: var(--bg-tertiary); color: var(--text-secondary);" onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background='var(--bg-tertiary)'" title="Reset all settings to defaults">
                                    Reset
                                </button>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Tab Contents -->
                    <div style="background: var(--bg-primary);">
                        <!-- Params Tab -->
                        <div id="params-tab" class="p-6">
                            <div class="mb-3 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">
                                <div class="grid grid-cols-12 gap-3">
                                    <div class="col-span-4">Key</div>
                                    <div class="col-span-5">Value</div>
                                    <div class="col-span-2">Description</div>
                                    <div class="col-span-1 text-center">✓</div>
                                </div>
                            </div>
                            
                            <div id="params-container">
                                <?php 
                                $savedParams = $currentProject['lastRequest']['params'] ?? [];
                                if (empty($savedParams)) {
                                    $savedParams = [['key' => '', 'value' => '', 'description' => '', 'enabled' => true]];
                                }
                                foreach ($savedParams as $idx => $param): 
                                ?>
                                <div class="data-row mb-2 p-3">
                                    <div class="grid grid-cols-12 gap-3 items-center">
                                        <div class="col-span-4">
                                            <input type="text" name="param_key[]" value="<?php echo htmlspecialchars($param['key']); ?>" 
                                                   placeholder="key" class="w-full px-3 py-2 border rounded text-sm param-input" oninput="updateFullUrl()">
                                        </div>
                                        <div class="col-span-5">
                                            <input type="text" name="param_value[]" value="<?php echo htmlspecialchars($param['value']); ?>" 
                                                   placeholder="value" class="w-full px-3 py-2 border rounded text-sm param-input" oninput="updateFullUrl()">
                                        </div>
                                        <div class="col-span-2">
                                            <input type="text" name="param_description[]" value="<?php echo htmlspecialchars($param['description'] ?? ''); ?>" 
                                                   placeholder="description" class="w-full px-3 py-2 border rounded text-sm">
                                        </div>
                                        <div class="col-span-1 flex items-center justify-center gap-2">
                                            <input type="checkbox" name="param_enabled[<?php echo $idx; ?>]" <?php echo ($param['enabled'] ?? true) ? 'checked' : ''; ?> class="w-4 h-4 accent-orange-500 param-checkbox" onchange="updateFullUrl()">
                                            <button type="button" onclick="this.closest('.data-row').remove(); updateFullUrl()" class="hover:text-red-600 text-lg leading-none" style="color: var(--text-tertiary);">×</button>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <button type="button" onclick="addParamRow()" class="mt-3 text-sm font-medium" style="color: var(--text-secondary);">
                                + Add parameter
                            </button>
                        </div>
                        
                        <!-- Authorization Tab -->
                        <div id="authorization-tab" class="p-6 hidden">
                            <?php $authType = $currentProject['lastRequest']['authorization']['type'] ?? 'none'; ?>
                            <div class="mb-4">
                                <label class="block text-xs font-semibold mb-2" style="color: var(--text-secondary);">Type</label>
                                <select name="auth_type" id="auth_type" onchange="switchAuthType(this.value)" class="px-3 py-2 border rounded text-sm w-64">
                                    <option value="none" <?php echo $authType === 'none' ? 'selected' : ''; ?>>No Auth</option>
                                    <option value="bearer" <?php echo $authType === 'bearer' ? 'selected' : ''; ?>>Bearer Token</option>
                                    <option value="basic" <?php echo $authType === 'basic' ? 'selected' : ''; ?>>Basic Auth</option>
                                </select>
                            </div>
                            
                            <div id="auth-none" class="<?php echo $authType !== 'none' ? 'hidden' : ''; ?>">
                                <div class="text-sm" style="color: var(--text-secondary);">This request does not use any authorization.</div>
                            </div>
                            
                            <div id="auth-bearer" class="<?php echo $authType !== 'bearer' ? 'hidden' : ''; ?>">
                                <label class="block text-xs font-semibold mb-2" style="color: var(--text-secondary);">Token</label>
                                <input type="text" name="auth_token" value="<?php echo htmlspecialchars($currentProject['lastRequest']['authorization']['token'] ?? ''); ?>" 
                                       placeholder="Token" class="w-full px-3 py-2 border rounded text-sm font-mono">
                            </div>
                            
                            <div id="auth-basic" class="<?php echo $authType !== 'basic' ? 'hidden' : ''; ?>">
                                <div class="mb-4">
                                    <label class="block text-xs font-semibold mb-2" style="color: var(--text-secondary);">Username</label>
                                    <input type="text" name="auth_username" value="<?php echo htmlspecialchars($currentProject['lastRequest']['authorization']['username'] ?? ''); ?>" 
                                           placeholder="Username" class="w-full px-3 py-2 border rounded text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold mb-2" style="color: var(--text-secondary);">Password</label>
                                    <input type="password" name="auth_password" value="<?php echo htmlspecialchars($currentProject['lastRequest']['authorization']['password'] ?? ''); ?>" 
                                           placeholder="Password" class="w-full px-3 py-2 border rounded text-sm">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Headers Tab -->
                        <div id="headers-tab" class="p-6 hidden">
                            <div class="mb-4 flex items-center justify-end">
                                <label class="flex items-center gap-2 cursor-pointer text-sm" style="color: var(--text-secondary);">
                                    <input type="checkbox" name="show_default_headers" id="show-default-headers" 
                                           <?php echo ($currentProject['lastRequest']['showDefaultHeaders'] ?? false) ? 'checked' : ''; ?> 
                                           onchange="toggleDefaultHeaders(this.checked)" class="w-4 h-4 accent-orange-500">
                                    <span>Show default headers</span>
                                </label>
                            </div>

                            <div class="mb-3 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">
                                <div class="grid grid-cols-12 gap-3">
                                    <div class="col-span-5">Key</div>
                                    <div class="col-span-6">Value</div>
                                    <div class="col-span-1 text-center">✓</div>
                                </div>
                            </div>
                            
                            <div id="headers-container">
                                <?php 
                                // Define default headers
                                $defaultHeaders = [
                                    ['key' => 'User-Agent', 'value' => 'LocalMan/'.LOCALMAN_VERSION, 'enabled' => true],
                                    ['key' => 'Accept', 'value' => '*/*', 'enabled' => true],
                                    ['key' => 'Accept-Encoding', 'value' => 'gzip, deflate, br', 'enabled' => true],
                                    ['key' => 'Connection', 'value' => 'keep-alive', 'enabled' => true],
                                    ['key' => 'Cache-Control', 'value' => 'no-cache', 'enabled' => true],
                                ];
                                
                                // Merge saved headers with default headers
                                $savedHeaders = $currentProject['lastRequest']['headers'] ?? [];
                                
                                // Update saved default headers with new defaults if needed
                                $defaultHeaderKeys = array_column($defaultHeaders, 'key');
                                $savedDefaultHeaders = [];
                                $savedCustomHeaders = [];
                                
                                foreach ($savedHeaders as $header) {
                                    if (isset($header['isDefault']) && $header['isDefault']) {
                                        $savedDefaultHeaders[$header['key']] = $header;
                                    } else {
                                        $savedCustomHeaders[] = $header;
                                    }
                                }
                                
                                // Create final default headers list (merge saved state with defaults)
                                $finalDefaultHeaders = [];
                                foreach ($defaultHeaders as $default) {
                                    if (isset($savedDefaultHeaders[$default['key']])) {
                                        $finalDefaultHeaders[] = $savedDefaultHeaders[$default['key']];
                                    } else {
                                        $default['isDefault'] = true;
                                        $finalDefaultHeaders[] = $default;
                                    }
                                }
                                
                                $showDefaults = $currentProject['lastRequest']['showDefaultHeaders'] ?? false;
                                $headerIndex = 0;
                                
                                // Display default headers if toggle is on
                                ?>
                                <?php 
                                $displayClass = $showDefaults ? '' : 'hidden';
                                ?>
                                <div class="text-xs font-bold mb-2 uppercase tracking-wide default-header-row <?php echo $displayClass; ?>" style="color: var(--text-secondary);">Default Headers</div>
                                <?php foreach ($finalDefaultHeaders as $header): 
                                    $displayClass = $showDefaults ? '' : 'hidden';
                                ?>
                                <div class="data-row mb-2 p-3 default-header-row <?php echo $displayClass; ?>" style="background: var(--bg-tertiary); opacity: 0.7;">
                                    <div class="grid grid-cols-12 gap-3 items-center">
                                        <div class="col-span-5">
                                            <input type="text" name="header_key[]" value="<?php echo htmlspecialchars($header['key']); ?>" 
                                                   placeholder="Key" readonly class="w-full px-3 py-2 border rounded text-sm" style="background: var(--bg-secondary); cursor: not-allowed;">
                                            <input type="hidden" name="header_is_default[<?php echo $headerIndex; ?>]" value="1">
                                        </div>
                                        <div class="col-span-6">
                                            <input type="text" name="header_value[]" value="<?php echo htmlspecialchars($header['value']); ?>" 
                                                   placeholder="Value" class="w-full px-3 py-2 border rounded text-sm">
                                        </div>
                                        <div class="col-span-1 flex items-center justify-center gap-2">
                                            <input type="checkbox" name="header_enabled[<?php echo $headerIndex; ?>]" <?php echo ($header['enabled'] ?? false) ? 'checked' : ''; ?> class="w-4 h-4 accent-orange-500 header-checkbox" onchange="updateHeaderCount()">
                                        </div>
                                    </div>
                                </div>
                                <?php 
                                $headerIndex++;
                                endforeach; 
                                
                                // Display custom headers
                                if (empty($savedCustomHeaders)) {
                                    $savedCustomHeaders = [['key' => '', 'value' => '', 'enabled' => true]];
                                }
                                foreach ($savedCustomHeaders as $header): 
                                ?>
                                <div class="data-row mb-2 p-3">
                                    <div class="grid grid-cols-12 gap-3 items-center">
                                        <div class="col-span-5">
                                            <input type="text" name="header_key[]" value="<?php echo htmlspecialchars($header['key']); ?>" 
                                                   placeholder="Key" class="w-full px-3 py-2 border rounded text-sm">
                                        </div>
                                        <div class="col-span-6">
                                            <input type="text" name="header_value[]" value="<?php echo htmlspecialchars($header['value']); ?>" 
                                                   placeholder="Value" class="w-full px-3 py-2 border rounded text-sm">
                                        </div>
                                        <div class="col-span-1 flex items-center justify-center gap-2">
                                            <input type="checkbox" name="header_enabled[<?php echo $headerIndex; ?>]" <?php echo ($header['enabled'] ?? true) ? 'checked' : ''; ?> class="w-4 h-4 accent-orange-500 header-checkbox" onchange="updateHeaderCount()">
                                            <button type="button" onclick="this.closest('.data-row').remove(); updateHeaderCount();" class="hover:text-red-600 text-lg leading-none" style="color: var(--text-tertiary);">×</button>
                                        </div>
                                    </div>
                                </div>
                                <?php 
                                $headerIndex++;
                                endforeach; 
                                ?>
                            </div>
                            
                            <button type="button" onclick="addHeaderRow()" class="mt-3 text-sm font-medium" style="color: var(--text-secondary);">
                                + Add header
                            </button>
                        </div>
                        
                        <!-- Body Tab -->
                        <div id="body-tab" class="p-6 hidden">
                            <?php $bodyType = $currentProject['lastRequest']['bodyType'] ?? 'none'; ?>
                            <div class="mb-4 flex items-center gap-2">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="body_type" value="none" <?php echo $bodyType === 'none' ? 'checked' : ''; ?> onchange="switchBodyType('none')" class="accent-orange-500">
                                    <span class="text-sm">none</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="body_type" value="form-data" <?php echo $bodyType === 'form-data' ? 'checked' : ''; ?> onchange="switchBodyType('form-data')" class="accent-orange-500">
                                    <span class="text-sm">form-data</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="body_type" value="x-www-form-urlencoded" <?php echo $bodyType === 'x-www-form-urlencoded' ? 'checked' : ''; ?> onchange="switchBodyType('x-www-form-urlencoded')" class="accent-orange-500">
                                    <span class="text-sm">x-www-form-urlencoded</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="body_type" value="raw" <?php echo $bodyType === 'raw' ? 'checked' : ''; ?> onchange="switchBodyType('raw')" class="accent-orange-500">
                                    <span class="text-sm">raw</span>
                                </label>
                            </div>
                            
                            <div id="body-none" class="<?php echo $bodyType !== 'none' ? 'hidden' : ''; ?>">
                                <div class="text-sm" style="color: var(--text-secondary);">This request does not have a body.</div>
                            </div>
                            
                            <div id="body-formdata" class="<?php echo $bodyType !== 'form-data' ? 'hidden' : ''; ?>">
                                <div class="mb-3 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">
                                    <div class="grid grid-cols-12 gap-3">
                                        <div class="col-span-5">Key</div>
                                        <div class="col-span-5">Value</div>
                                        <div class="col-span-1">Type</div>
                                        <div class="col-span-1 text-center">✓</div>
                                    </div>
                                </div>
                                
                                <div id="formdata-container">
                                    <?php 
                                    $savedFormData = $currentProject['lastRequest']['formData'] ?? [];
                                    if (empty($savedFormData)) {
                                        $savedFormData = [['key' => '', 'value' => '', 'type' => 'text', 'enabled' => true]];
                                    }
                                    foreach ($savedFormData as $idx => $item): 
                                    ?>
                                    <div class="data-row mb-2 p-3">
                                        <div class="grid grid-cols-12 gap-3 items-center">
                                            <div class="col-span-5">
                                                <input type="text" name="formdata_key[]" value="<?php echo htmlspecialchars($item['key']); ?>" 
                                                       placeholder="key" class="w-full px-3 py-2 border rounded text-sm formdata-key-input" oninput="updateBodyCount()">
                                            </div>
                                            <div class="col-span-5">
                                                <?php if (($item['type'] ?? 'text') === 'file'): ?>
                                                    <?php $hasStoredFile = !empty($item['fileName']) || !empty($item['value']); ?>
                                                    <?php if ($hasStoredFile): ?>
                                                    <!-- Stored file display -->
                                                    <div class="file-display-container">
                                                        <div class="flex items-center gap-2 px-3 py-2 border rounded" style="background: var(--bg-tertiary);">
                                                            <svg class="w-4 h-4 flex-shrink-0" style="color: var(--text-secondary);" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                            </svg>
                                                            <div class="flex-1 min-w-0">
                                                                <div class="text-sm font-medium truncate" style="color: var(--text-primary);"><?php echo htmlspecialchars(basename($item['fileName'] ?? $item['value'])); ?></div>
                                                                <?php if (!empty($item['fileContent'])): ?>
                                                                <div class="text-xs" style="color: var(--text-tertiary);"><?php echo round(strlen(base64_decode($item['fileContent'])) / 1024, 1); ?> KB</div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="flex items-center gap-1 px-2 py-1 rounded flex-shrink-0" style="background: #dcfce7; border: 1px solid #86efac;">
                                                                <svg class="w-3 h-3" style="color: #16a34a;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                                </svg>
                                                                <span class="text-xs font-medium" style="color: #16a34a;">Stored</span>
                                                            </div>
                                                            <button type="button" onclick="toggleFileUpload(this)" class="flex-shrink-0 p-1 rounded hover:bg-gray-200 transition" style="color: var(--text-secondary);" title="Replace file">
                                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                                                </svg>
                                                            </button>
                                                        </div>
                                                        <div class="file-upload-field hidden mt-2">
                                                            <input type="file" name="formdata_file[]" class="w-full px-3 py-2 border rounded text-sm formdata-value-input" data-saved-value="<?php echo htmlspecialchars($item['value']); ?>">
                                                        </div>
                                                    </div>
                                                    <?php else: ?>
                                                    <!-- No file stored, show upload field -->
                                                    <div class="file-display-container">
                                                        <input type="file" name="formdata_file[]" class="w-full px-3 py-2 border rounded text-sm formdata-value-input" data-saved-value="">
                                                    </div>
                                                    <?php endif; ?>
                                                    <input type="hidden" name="formdata_value[]" value="<?php echo htmlspecialchars($item['value']); ?>" class="formdata-value-hidden">
                                                    <?php if (!empty($item['fileContent'])): ?>
                                                    <input type="hidden" name="formdata_file_content[]" value="<?php echo htmlspecialchars($item['fileContent']); ?>">
                                                    <?php else: ?>
                                                    <input type="hidden" name="formdata_file_content[]" value="">
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                <input type="text" name="formdata_value[]" value="<?php echo htmlspecialchars($item['value']); ?>" 
                                                       placeholder="value" class="w-full px-3 py-2 border rounded text-sm formdata-value-input param-input">
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-span-1">
                                                <select name="formdata_type[]" class="w-full px-2 py-2 border rounded text-xs formdata-type-select" onchange="toggleFormDataInputType(this)">
                                                    <option value="text" <?php echo ($item['type'] ?? 'text') === 'text' ? 'selected' : ''; ?>>Text</option>
                                                    <option value="file" <?php echo ($item['type'] ?? 'text') === 'file' ? 'selected' : ''; ?>>File</option>
                                                </select>
                                            </div>
                                            <div class="col-span-1 flex items-center justify-center gap-2">
                                                <input type="checkbox" name="formdata_enabled[]" <?php echo ($item['enabled'] ?? true) ? 'checked' : ''; ?> class="w-4 h-4 accent-orange-500 formdata-checkbox" onchange="updateBodyCount()">
                                                <button type="button" onclick="this.closest('.data-row').remove(); updateBodyCount();" class="hover:text-red-600 text-lg leading-none" style="color: var(--text-tertiary);">×</button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <button type="button" onclick="addFormDataRow()" class="mt-3 text-sm font-medium" style="color: var(--text-secondary);">
                                    + Add field
                                </button>
                            </div>
                            
                            <div id="body-urlencoded" class="<?php echo $bodyType !== 'x-www-form-urlencoded' ? 'hidden' : ''; ?>">
                                <div class="text-sm mb-3" style="color: var(--text-secondary);">Use the form-data fields (they will be URL encoded)</div>
                            </div>
                            
                            <div id="body-raw" class="<?php echo $bodyType !== 'raw' ? 'hidden' : ''; ?>">
                                <div class="mb-4">
                                    <select class="px-3 py-2 border rounded text-sm">
                                        <option>Text</option>
                                        <option selected>JSON</option>
                                        <option>XML</option>
                                        <option>HTML</option>
                                        <option>JavaScript</option>
                                    </select>
                                </div>
                                <textarea name="body" rows="12" 
                                          class="w-full px-4 py-3 border rounded font-mono text-sm resize-none" 
                                          placeholder='{"key": "value"}' oninput="updateBodyCount()"><?php echo htmlspecialchars($currentProject['lastRequest']['body']); ?></textarea>
                            </div>
                        </div>
                    </div>
                </form>
                
                <!-- Response Section -->
                <?php if ($response_data): ?>
                <div class="flex-1 flex flex-col border-t" style="background: var(--bg-primary); border-color: var(--border-primary);">
                    <div class="border-b" style="border-color: var(--border-primary);">
                        <div class="px-6 py-3 flex items-center justify-between" style="background: var(--bg-tertiary);">
                            <div class="flex items-center gap-6">
                                <span class="text-sm font-semibold">Response</span>
                                <span class="px-3 py-1 rounded text-xs font-bold <?php echo $response_data['status'] >= 200 && $response_data['status'] < 300 ? 'bg-green-100 text-green-700' : ($response_data['status'] >= 400 ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'); ?>">
                                    <?php echo $response_data['status']; ?> <?php 
                                        $statusTexts = [
                                            200 => 'OK', 201 => 'Created', 204 => 'No Content',
                                            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
                                            500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable'
                                        ];
                                        echo $statusTexts[$response_data['status']] ?? '';
                                    ?>
                                </span>
                                <span class="text-sm" style="color: var(--text-secondary);">
                                    <span class="font-semibold"><?php echo $response_data['duration']; ?>ms</span>
                                </span>
                                <span class="text-sm" style="color: var(--text-secondary);">
                                    <span class="font-semibold"><?php echo round(strlen($response_data['body']) / 1024, 2); ?> KB</span>
                                </span>
                            </div>
                            <button onclick="document.getElementById('resp-body').parentElement.classList.add('hidden')" style="color: var(--text-tertiary);" onmouseover="this.style.color='var(--text-secondary)'" onmouseout="this.style.color='var(--text-tertiary)'">
                                <span class="text-xl">×</span>
                            </button>
                        </div>
                        
                        <div class="response-tabs">
                            <div class="response-tab active" onclick="switchResponseTab('resp-body')">Body</div>
                            <div class="response-tab" onclick="switchResponseTab('resp-headers')">Headers</div>
                            <div class="response-tab" onclick="switchResponseTab('resp-cookies')">Cookies</div>
                            <div class="response-tab" onclick="switchResponseTab('resp-test')">Test Results</div>
                        </div>
                    </div>
                    
                    <div class="flex-1 overflow-auto">
                        <div id="resp-body" class="p-6">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2 text-xs">
                                    <button id="resp-pretty-btn" onclick="switchResponseView('pretty')" class="px-3 py-1 rounded font-medium" style="background: var(--bg-tertiary); color: var(--text-primary);">Pretty</button>
                                    <button id="resp-raw-btn" onclick="switchResponseView('raw')" class="px-3 py-1 rounded font-medium" style="color: var(--text-secondary);">Raw</button>
                                    <button id="resp-preview-btn" onclick="switchResponseView('preview')" class="px-3 py-1 rounded font-medium" style="color: var(--text-secondary);">Preview</button>
                                </div>
                                <button onclick="copyResponse()" class="text-xs font-medium" style="color: var(--text-secondary);" id="copy-response-btn">Copy</button>
                            </div>
                            <div id="response-pretty" class="border rounded overflow-auto" style="background: var(--bg-tertiary); border-color: var(--border-primary); max-height: calc(100vh - 450px);">
                                <pre class="p-4 font-mono text-xs leading-relaxed" style="color: var(--text-primary);" id="response-body-content"><?php 
                                    $body = $response_data['body'];
                                    // Try to pretty print JSON
                                    $json = json_decode($body);
                                    if (json_last_error() === JSON_ERROR_NONE) {
                                        echo htmlspecialchars(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                    } else {
                                        echo htmlspecialchars($body);
                                    }
                                ?></pre>
                            </div>
                            <div id="response-raw" class="border rounded overflow-auto hidden" style="background: var(--bg-tertiary); border-color: var(--border-primary); max-height: calc(100vh - 450px);">
                                <pre class="p-4 font-mono text-xs leading-relaxed" style="color: var(--text-primary);"><?php echo htmlspecialchars($response_data['body']); ?></pre>
                            </div>
                            <div id="response-preview" class="border rounded overflow-auto hidden" style="background: var(--bg-tertiary); border-color: var(--border-primary); max-height: calc(100vh - 450px);">
                                <div class="p-4"><?php 
                                    // Check if response is HTML for preview
                                    if (stripos($response_data['headers'], 'content-type: text/html') !== false) {
                                        echo '<iframe style="width: 100%; height: 600px; border: none; background: white;" srcdoc="' . htmlspecialchars($response_data['body'], ENT_QUOTES) . '"></iframe>';
                                    } else {
                                        echo '<div style="color: var(--text-secondary); padding: 20px; text-align: center;">Preview not available for this content type</div>';
                                    }
                                ?></div>
                            </div>
                        </div>
                        
                        <div id="resp-headers" class="p-6 hidden">
                            <div class="border rounded overflow-auto" style="background: var(--bg-tertiary); border-color: var(--border-primary); max-height: calc(100vh - 450px);">
                                <pre class="p-4 font-mono text-xs leading-relaxed" style="color: var(--text-primary);"><?php echo htmlspecialchars($response_data['headers']); ?></pre>
                            </div>
                        </div>
                        
                        <div id="resp-cookies" class="p-6 hidden">
                            <div class="text-sm" style="color: var(--text-secondary);">No cookies in this response</div>
                        </div>
                        
                        <div id="resp-test" class="p-6 hidden">
                            <div class="text-sm" style="color: var(--text-secondary);">No test results</div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- Empty State -->
                <div class="flex-1 flex items-center justify-center">
                    <div class="empty-state">
                        <svg xmlns="http://www.w3.org/2000/svg" width="498.09869" height="646.465" viewBox="0 0 498.09869 646.465" xmlns:xlink="http://www.w3.org/1999/xlink" role="img" artist="Katerina Limpitsouni" source="https://undraw.co/" style="max-width: 300px; height: auto; opacity: 0.3; margin: 0 auto 20px;"><path d="M497.85558,369.39014,358.82286,339.39852l-1.80686-.38885a5.00224,5.00224,0,0,0-5.54552,7.10585L406.67216,458.217a5.0183,5.0183,0,0,0,3.72746,2.72926,4.96825,4.96825,0,0,0,4.37912-1.50154l38.34178-40.42814a2.91036,2.91036,0,0,1,2.13875-.93407,3.01064,3.01064,0,0,1,2.67965,1.558l7.8535,14.52626a4.88621,4.88621,0,0,0,5.47116,2.50722l.03909-.00865a4.893,4.893,0,0,0,3.87711-4.6473l1.57322-33.53178a3.05774,3.05774,0,0,1,.307-1.20476,3.254,3.254,0,0,1,.78832-.97333L499.968,378.15066a5.00564,5.00564,0,0,0-2.11243-8.76052Zm.84922,7.21694-22.11962,18.15759a4.93346,4.93346,0,0,0-.77862.78665l-117.235-53.16816a1.74378,1.74378,0,0,0-.32206-.113,2.00253,2.00253,0,0,0-1.66918,3.56479l96.39341,70.80742a4.79554,4.79554,0,0,0-1.30551.99549l-38.34133,40.42792a3.00049,3.00049,0,0,1-4.86432-.73653L353.26092,345.22777a3.00779,3.00779,0,0,1,3.32825-4.25969l1.84977.39973,138.98933,29.98084a3.00669,3.00669,0,0,1,1.27653,5.25843Z" transform="translate(-350.95066 -126.7675)" fill="#ff6c37"/><path d="M527.492,769.29283c-12.47123-3.36845-27.64871-17.37787-24.39937-36.6341,1.61151-9.55,7.79666-15.732,14.20994-19.72938,8.87087-5.52915,18.97378-7.61271,28.70983-8.13908,11.22989-.60717,22.44725.92373,33.59325,2.54269,11.00345,1.59829,22.00778,3.02939,33.06039,3.90316A426.181,426.181,0,0,0,741.70229,701.655q15.6504-3.61166,31.11314-8.47413a213.747,213.747,0,0,0,28.5787-10.6814c12.25583-5.884,32.18686-17.3338,30.56169-38.53888-.86174-11.24352-7.48306-20.2235-14.16178-26.47976-8.07544-7.56463-17.41979-12.54174-26.57194-17.37492-42.57048-22.4815-85.22406-44.6961-127.836-67.04222-9.94515-5.21531-19.95642-10.29093-29.60825-16.40265-9.03649-5.72208-18.03069-12.36888-25.30284-21.55935-6.35165-8.02718-12.02739-18.80349-11.82714-30.84974.16606-9.99023,4.71231-18.46777,10.28032-24.8036,13.7853-15.68634,33.28584-22.0464,50.91043-25.37786,21.89667-4.139,44.11031-4.11759,66.02128-8.11429,17.69177-3.22713,36.59531-9.457,50.98378-24.05564a45.59972,45.59972,0,0,0,12.42882-21.71495,42.67829,42.67829,0,0,0-2.55578-25.76791c-3.89761-9.30966-10.11483-16.72577-16.65218-22.79845a99.91441,99.91441,0,0,0-25.25625-16.87337c-19.43291-9.26029-39.86761-14.08181-59.77313-21.18824-9.76212-3.48516-19.623-7.30685-28.68774-13.29314-7.67975-5.07168-16.04558-11.90785-20.31823-22.07381-8.81554-20.97479,12.21534-33.47549,24.13206-38.21054a155.15636,155.15636,0,0,1,24.94085-7.13994c1.79691-.38581,1.03448-3.97259-.754-3.58863a149.21671,149.21671,0,0,0-26.19085,7.64761c-7.18613,2.98571-14.60076,6.9095-20.24543,13.66154a28.43768,28.43768,0,0,0-6.42551,22.23459c1.4951,10.0639,7.99422,17.81842,14.098,23.43518,8.07158,7.42755,17.27457,12.27825,26.6055,16.23233,9.82719,4.16437,19.86291,7.40982,29.89527,10.59326,20.04681,6.36116,40.993,12.03,59.0898,25.5661,12.65931,9.469,29.85671,27.22287,25.64575,49.2291-1.86927,9.76868-7.54569,17.40622-13.67139,23.0237a79.6032,79.6032,0,0,1-24.484,14.88719c-40.10267,16.13908-84.23837,4.59774-123.3075,26.518-13.28375,7.453-29.41873,21.02272-29.54861,42.37352-.06743,11.09128,4.37845,21.26357,9.87491,29.30381,6.5832,9.63,15.108,16.75124,23.78148,22.703a276.82338,276.82338,0,0,0,28.39866,16.44217q16.03077,8.42025,32.06858,16.81772l64.884,34.02583L785.05473,600.852c9.83916,5.15974,19.931,10.10922,28.877,17.68346,7.32486,6.20174,15.979,16.37607,15.23163,29.00981-.5935,10.03327-7.25874,17.27376-13.34932,22.11458-7.88668,6.26837-16.74607,10.32273-25.56086,13.69186-10.16729,3.886-20.50176,7.10958-30.85626,10.00791a416.59168,416.59168,0,0,1-64.73684,12.67106A425.69163,425.69163,0,0,1,628.895,708.482q-16.6374-.66953-33.22314-2.66157c-11.39758-1.37281-22.74638-3.48763-34.17334-4.4259-10.33591-.8487-20.77138-.652-30.95021,2.07575-8.49907,2.27761-17.47811,6.33735-23.92963,14.2589a31.54336,31.54336,0,0,0-6.69562,21.30473,38.25646,38.25646,0,0,0,7.84294,21.11157,35.4889,35.4889,0,0,0,18.97222,12.7361c1.78156.48118,2.54349-3.10534.754-3.58863Z" transform="translate(-350.95066 -126.7675)" fill="#e6e6e6"/><rect x="174.06608" y="644.22427" width="324.03261" height="2.24072" fill="#3f3d56"/><circle cx="472.13713" cy="517.5024" r="21.16809" fill="#e6e6e6"/><circle cx="433.51814" cy="219.14839" r="21.1681" fill="#e6e6e6"/><path d="M736.6097,127.653l-58.777,25.55173a10.69385,10.69385,0,0,0-5.53753,14.05476l18.2512,41.98347a10.6938,10.6938,0,0,0,14.05477,5.53757l58.777-25.55173A10.69382,10.69382,0,0,0,768.9157,175.174l-18.2512-41.98347A10.69386,10.69386,0,0,0,736.6097,127.653Zm2.4335,5.5978a4.55688,4.55688,0,0,1,1.09871-.31636L720.27009,170.4267l-40.8146-11.16918a4.58053,4.58053,0,0,1,.81072-.455Zm21.90145,50.3802-58.777,25.55173a4.58317,4.58317,0,0,1-6.02348-2.37327L678.05688,165.203l42.9598,11.75636a3.052,3.052,0,0,0,3.50205-1.5145l20.79933-39.24265,17.99983,41.40525A4.58318,4.58318,0,0,1,760.94465,183.631Z" transform="translate(-350.95066 -126.7675)" fill="#e6e6e6"/><circle cx="319.73326" cy="311.53784" r="53.51916" fill="#ff6c37"/><path d="M662.251,771.65067H647.49121a6.50753,6.50753,0,0,1-6.5-6.5V642.137a6.50753,6.50753,0,0,1,6.5-6.5H662.251a6.50753,6.50753,0,0,1,6.5,6.5V765.15067A6.50753,6.50753,0,0,1,662.251,771.65067Z" transform="translate(-350.95066 -126.7675)" fill="#2f2e41"/><path d="M691.44336,771.65067H676.68359a6.50753,6.50753,0,0,1-6.5-6.5V642.137a6.50753,6.50753,0,0,1,6.5-6.5h14.75977a6.50753,6.50753,0,0,1,6.5,6.5V765.15067A6.50753,6.50753,0,0,1,691.44336,771.65067Z" transform="translate(-350.95066 -126.7675)" fill="#2f2e41"/><path d="M752.60645,629.27372a6.54421,6.54421,0,0,1-1.91309-.28809,6.4603,6.4603,0,0,1-3.83594-3.168l-57.665-108.66113a6.49944,6.49944,0,0,1,2.69434-8.78809l13.03711-6.91894a6.49864,6.49864,0,0,1,8.78906,2.69433L771.377,612.805a6.49785,6.49785,0,0,1-2.69433,8.78808L755.64551,628.512A6.45965,6.45965,0,0,1,752.60645,629.27372Z" transform="translate(-350.95066 -126.7675)" fill="#ff6c37"/><path d="M632.56348,529.11649a6.46045,6.46045,0,0,1-2.28321-.417h-.001L515.20166,485.22586a6.49367,6.49367,0,0,1-3.77539-8.377l5.20605-13.80762a6.51872,6.51872,0,0,1,8.38623-3.77734l115.0752,43.46387a6.51786,6.51786,0,0,1,3.78613,8.375l-5.21582,13.80957A6.52931,6.52931,0,0,1,632.56348,529.11649Z" transform="translate(-350.95066 -126.7675)" fill="#ff6c37"/><rect x="279.59389" y="374.35506" width="79.06239" height="154.47574" rx="6" fill="#2f2e41"/><path d="M710.2148,385.89448c3.14447,6.90062,6.15456,15.24071,2.76979,22.62258a12.72387,12.72387,0,0,1-6.516,6.70769c-3.48856,1.39154-7.29105.59741-10.52366-1.0849-6.80615-3.542-11.19315-10.12369-17.2701-14.63276a20.53442,20.53442,0,0,0-9.504-4.0958c-3.9931-.52048-7.95718.42133-11.86275,1.14845-4.15382.77333-8.44682,1.18146-12.29128-.92481a18.04425,18.04425,0,0,1-7.5408-8.61525c-.79169-1.75741-3.37783-.23376-2.59041,1.51416,3.269,7.25667,10.02943,12.20159,18.15233,11.62967,4.298-.30262,8.44935-1.69044,12.75467-1.88923a17.04345,17.04345,0,0,1,10.90314,3.4856c6.67216,4.756,11.20045,12.06,18.85468,15.51434,3.61146,1.62982,7.74711,2.30915,11.55481.9055a15.14759,15.14759,0,0,0,7.73706-6.7377c5.02336-8.64913,1.797-18.64769-2.03711-27.0617-.79947-1.75445-3.38585-.23145-2.59041,1.51416Z" transform="translate(-350.95066 -126.7675)" fill="#2f2e41"/><path d="M676.60059,456.17508c-3.30567-.09277-7.4209-.208-10.58985-2.52343a8.13393,8.13393,0,0,1-3.20019-6.07227,5.47021,5.47021,0,0,1,1.86035-4.49316c1.6543-1.39893,4.07129-1.72754,6.67871-.96094l-2.7002-19.72608,1.98243-.27148,3.17285,23.18945-1.6543-.7583c-1.917-.87988-4.55176-1.32861-6.18848.05469a3.51473,3.51473,0,0,0-1.15234,2.895,6.14725,6.14725,0,0,0,2.38086,4.52783c2.4668,1.80176,5.74609,2.03516,9.4668,2.13867Z" transform="translate(-350.95066 -126.7675)" fill="#2f2e41"/><rect x="297.12845" y="297.33288" width="10.77148" height="2" fill="#2f2e41"/><rect x="331.12845" y="297.33288" width="10.77148" height="2" fill="#2f2e41"/><path d="M630.4559,528.23209l-47.29355-14.42094a6,6,0,0,1-4.06563-7.21509l5.52108-21.75436a6,6,0,0,1,8.39144-3.943l46.90816,22.29692a6.01089,6.01089,0,0,1,3.49516,7.73035l-5.21658,13.8101A6.01071,6.01071,0,0,1,630.4559,528.23209Z" transform="translate(-350.95066 -126.7675)" fill="#2f2e41"/><path d="M690.04241,515.92583,710.325,561.0175a6,6,0,0,0,7.67107,3.12113l20.88212-8.22628a6,6,0,0,0,2.85081-8.82249l-28.04671-43.714a6.01089,6.01089,0,0,0-8.11011-2.49011l-13.04,6.92017A6.01071,6.01071,0,0,0,690.04241,515.92583Z" transform="translate(-350.95066 -126.7675)" fill="#2f2e41"/><path d="M497.85558,369.39014,358.82286,339.39852l-1.80686-.38885a5.00224,5.00224,0,0,0-5.54552,7.10585L406.67216,458.217a5.0183,5.0183,0,0,0,3.72746,2.72926,4.96825,4.96825,0,0,0,4.37912-1.50154l38.34178-40.42814a2.91036,2.91036,0,0,1,2.13875-.93407,3.01064,3.01064,0,0,1,2.67965,1.558l7.8535,14.52626a4.88621,4.88621,0,0,0,5.47116,2.50722l.03909-.00865a4.893,4.893,0,0,0,3.87711-4.6473l1.57322-33.53178a3.05774,3.05774,0,0,1,.307-1.20476,3.254,3.254,0,0,1,.78832-.97333L499.968,378.15066a5.00564,5.00564,0,0,0-2.11243-8.76052Zm.84922,7.21694-22.11962,18.15759a4.93346,4.93346,0,0,0-.77862.78665,4.64685,4.64685,0,0,0-.53242.83481,4.79649,4.79649,0,0,0-.36273.95079l.00217.00977a4.73079,4.73079,0,0,0-.15751,1.04879l-1.57276,33.53167a2.90448,2.90448,0,0,1-2.32273,2.7876l-.01955.00433a2.91087,2.91087,0,0,1-3.2907-1.49442l-7.85566-14.536a4.98939,4.98939,0,0,0-4.09973-2.60589l-.00954.00211c-.11421-.00536-.228-.01094-.33956-.00675a4.86576,4.86576,0,0,0-2.27209.56424,4.79554,4.79554,0,0,0-1.30551.99549l-38.34133,40.42792a3.00049,3.00049,0,0,1-4.86432-.73653L353.26092,345.22777a3.00779,3.00779,0,0,1,3.32825-4.25969l1.84977.39973,138.98933,29.98084a3.00669,3.00669,0,0,1,1.27653,5.25843Z" transform="translate(-350.95066 -126.7675)" fill="#3f3d56"/><path d="M476.25014,395.75022l-.82617,1.8216-.51039-.22513-.00217-.00977L357.76929,344.22l97.81611,71.86487.00954-.00211.259.19874-1.17942,1.61283-1.70078-1.252-96.39341-70.80742a2.00253,2.00253,0,0,1,1.66918-3.56479,1.74378,1.74378,0,0,1,.32206.113l117.235,53.16816Z" transform="translate(-350.95066 -126.7675)" fill="#3f3d56"/></svg>
                        <p class="text-lg font-medium mb-2" style="color: var(--text-secondary);">Enter the URL and click Send to get a response</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
        <?php elseif ($action === 'webhooks'): ?>
            <div class="h-full flex flex-col">
                <!-- Webhook URL Bar -->
                <div style="background: var(--bg-primary); border-bottom: 1px solid var(--border-primary);">
                    <div class="px-6 py-4">
                        <?php if (isset($_GET['success']) && $_GET['success'] === 'webhook_reset'): ?>
                            <div class="border-l-4 border-green-500 p-3 mb-4 rounded text-sm" style="background: #dcfce7; color: #16a34a;">
                                Webhook response settings have been reset to defaults.
                            </div>
                        <?php endif; ?>
                        <div class="flex gap-2">
                            <div class="flex-1 px-4 py-2 border rounded font-mono text-sm" style="background: var(--input-bg); border-color: var(--border-primary); color: var(--text-primary);">
                                <span id="webhook-url"><?php echo $webhook_url; ?></span>
                            </div>
                            <button onclick="copyWebhookUrl(this)" class="postman-orange text-white font-semibold px-8 py-2 rounded transition text-sm">
                                Copy
                            </button>
                        </div>
                    </div>
                    
                    <!-- Webhook Tabs -->
                    <div class="request-tabs" style="display: flex; justify-content: space-between; align-items: center;">
                        <div style="display: flex;">
                            <div class="request-tab active" onclick="switchRequestTab('webhooks-list-tab')">
                                Webhooks
                                <?php if (count($webhook_history) > 0): ?>
                                <span class="count"><?php echo count($webhook_history); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="request-tab" onclick="switchRequestTab('webhook-config-tab')">Response Config</div>
                        </div>
                        <div style="padding: 0 16px; display: flex; gap: 8px;">
                            <a href="?action=webhooks&reset=true" onclick="return confirm('Reset webhook response settings to defaults?');">
                                <button type="button" class="text-xs px-3 py-1.5 rounded font-medium transition" style="background: var(--bg-tertiary); color: var(--text-secondary);" onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background='var(--bg-tertiary)'" title="Reset settings to defaults">
                                    Reset
                                </button>
                            </a>
                            <?php if (!empty($webhook_history)): ?>
                            <a href="?action=clear_webhooks" onclick="return confirm('Clear all webhooks?');">
                                <button class="text-xs px-3 py-1.5 rounded font-medium transition" style="background: var(--bg-tertiary); color: var(--text-secondary);" onmouseover="this.style.background='var(--bg-secondary)'; this.style.color='#dc2626'" onmouseout="this.style.background='var(--bg-tertiary)'; this.style.color='var(--text-secondary)'">
                                    Clear All
                                </button>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Tab Contents -->
                    <div style="background: var(--bg-primary);">
                        <!-- Webhooks List Tab -->
                        <div id="webhooks-list-tab">
                        <?php if (empty($webhook_history)): ?>
                            <div class="p-16 flex items-center justify-center">
                                <div class="empty-state">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="320" height="272" viewBox="0 0 800.292 680.201" xmlns:xlink="http://www.w3.org/1999/xlink" role="img" style="opacity: 0.3; margin: 0 auto 20px;">
                                        <g transform="translate(-445.17 -153)">
                                            <path d="M17.872,0H223.4c9.871,0,17.872,6.251,17.872,13.963V30.543c0,7.711-8,13.963-17.872,13.963H17.872C8,44.506,0,38.255,0,30.543V13.963C0,6.251,8,0,17.872,0Z" transform="translate(696.307 218.358)" fill="#f2f2f2"/>
                                            <g transform="translate(454.089 337.363)">
                                                <rect width="27.497" height="36.242" transform="translate(337.978 433.438)" fill="#ffb9b9"/>
                                                <path d="M237.2,598.367h58.45a33.713,33.713,0,0,0,14.519-3.308l10.776-5.18a6.471,6.471,0,0,0-1.078-12.069l-22.23-6.151-38.031-19.99-.028.144c-.711,3.6-1.813,9.044-1.953,9.374-3.9,4.468-7.907,6.527-11.908,6.121-6.98-.708-11.62-8.767-11.666-8.849l-.035-.062-.071.007a4.629,4.629,0,0,0-3.593,1.959c-1.592,2.4-.621,6.1-.461,6.656-1.907,1.866-2.763,12.285-2.829,13.126-2.757,2.922-4.067,5.732-3.894,8.351.152,2.289,1.421,4.415,3.773,6.319a16.323,16.323,0,0,0,10.261,3.552Z" transform="translate(105.469 -103.673)" fill="#090814"/>
                                                <path d="M516.437,201.656a37.847,37.847,0,1,1,49.186,36.117l-7.316,48.353-37.3-31.082s8.057-10.263,12.379-21.836a37.806,37.806,0,0,1-16.95-31.552Z" transform="translate(-489.648 -149.056)" fill="#ffb9b9"/>
                                                <path d="M423.15,236.869l53.268,2.557,6.36,14.311s61.683,27.4,65.194,54.063c0,0,15.916,44.391,50.883,42.932s-68.374,100.176-68.374,100.176L423.945,315.751l-3.975-62.557,3.18-16.325Z" transform="translate(-395.839 -140.507)" fill="#ff6c37"/>
                                                <path d="M453.606,417.683l21.592-2.446c-5.5,11.672-3.574,38.928.369,66.126,5.205,35.823,13.94,101.715,13.94,101.715l36.571-9.495V394.56c0-23.881-69.042-34.738-120.693-39.52-30.852-2.848-55.5-3.541-55.5-3.541l-71.489,86.01Z" transform="translate(-157.696 -127.095)" fill="#090814"/>
                                                <rect width="27.497" height="36.242" transform="translate(258.716 446.922) rotate(-145.769)" fill="#ffb9b9"/>
                                                <path d="M334.132,545.4c1.329-2.256,3.988-3.842,7.919-4.7.525-.659,7.093-8.791,9.718-9.271.178-.548,1.452-4.144,4.122-5.239a4.168,4.168,0,0,1,2.714-.146,6,6,0,0,1,1.363.548l.067.033-.011.067c-.011.1-.7,9.372,4.669,13.885,3.083,2.58,7.551,3.139,13.292,1.642.3-.2,4.267-4.077,6.881-6.657l.112-.1.212.391,19.983,37.532,14.912,17.593a6.463,6.463,0,0,1-5.9,10.578L402.37,599.78a33.832,33.832,0,0,1-13.862-5.428l-48.333-32.885a16.348,16.348,0,0,1-6.478-8.713C332.825,549.861,332.97,547.393,334.132,545.4Z" transform="translate(-114.391 -106.69)" fill="#090814"/>
                                                <path d="M338.354,444.312c-21.982,36.459-44.211,95.918-44.211,95.918l6,2.145,26.753,9.562,2.826,1.016,30.6-44.96c17.581-25.847,38.492-56.587,50.511-74.236V421.19c0-23.881-69.042-34.738-120.693-39.52l69.635,36.671C353.243,422.2,345.815,431.924,338.354,444.312Z" transform="translate(-55.558 -123.564)" fill="#090814"/>
                                                <path d="M328.734,319.584h0a29.653,29.653,0,0,1,46.485.815l91.447,119.715,145.756-16.762a26.25,26.25,0,0,1,29.172,24.065h0a26.25,26.25,0,0,1-22.437,28L452.008,499.446A56.982,56.982,0,0,1,394.6,471.62L326,353.27a29.653,29.653,0,0,1,2.735-33.686Z" transform="translate(-322 -132.097)" fill="#e6e6e6"/>
                                                <rect width="20.671" height="162.854" transform="translate(257.874 331.867)" fill="#e6e6e6"/>
                                                <rect width="20.671" height="162.854" transform="translate(116.014 331.867)" fill="#e6e6e6"/>
                                                <path d="M525.506,270.983s5.918,14.5,24.391,10.184l37.472-8.761s-51.527-68.306-36.332-63.073,40.208-39.966,40.208-39.966l15.182,16.419s22.006-15.18-10.594-29.675c0,0-38.668-16.427-68.888,7.448s-1.441,107.424-1.441,107.424h0Z" transform="translate(-507.516 -150.601)" fill="#090814"/>
                                                <path d="M570.565,239.834a16.739,16.739,0,1,0,0-33.478v2.234a14.492,14.492,0,1,1-5.706,1.165l-.879-2.053a16.741,16.741,0,0,0,6.585,32.131Z" transform="translate(-526.584 -144.077)" fill="#ff6c37"/>
                                                <path d="M419.329,340.706l-35.151,15.842,9.249,20.524,35.152-15.842a23.628,23.628,0,0,0,18.249-.816c10.957-4.938,16.527-16.292,12.44-25.36s-16.282-12.416-27.239-7.478a23.629,23.629,0,0,0-12.7,13.131Z" transform="translate(-210.389 -130.161)" fill="#ffb9b9"/>
                                                <path d="M474.685,286.135l28.622,82.685,66.784-20.671,12.721,34.982-99.036,27.148L423.589,303.3" transform="translate(-376.615 -134.743)" fill="#ff6c37"/>
                                            </g>
                                            <path d="M0,1.234H800.292V-1H0Z" transform="translate(445.17 831.967)" fill="#e6e6e6"/>
                                            <path d="M354.693,550.642c0-5.8,1.949-10.513,4.341-10.524h180.26c2.4.01,4.341,4.72,4.355,10.524V789.529a2.169,2.169,0,0,0,1.958,2.158q2.626.252,5.253.469c.044,0,.087.01.13.01.953.087,1.906.152,2.858.227h.011q.735-1.359,1.454-2.742a2.178,2.178,0,0,0,.247-1.006V536.989c0-1.6-.065-3.215-.174-4.807a61.639,61.639,0,0,0-.812-6.518c-1.829-10.058-5.966-16.868-10.665-16.89H354.433a5.071,5.071,0,0,0-1.993.422,7.767,7.767,0,0,0-2.458,1.722,15.243,15.243,0,0,0-2.414,3.3c-2.317,4.082-3.973,10.167-4.558,17.244,0,.065-.01.13-.01.2-.152,1.754-.227,3.54-.217,5.327l-.152,23.2-.1,13.837-.293,43.416-.108,15.415-.476,158.291h0a2.165,2.165,0,0,0,2.165,2.165h8.709a2.165,2.165,0,0,0,2.165-2.165Z" transform="translate(550.986 38.77)" fill="#e6e6e6"/>
                                            <path d="M216.883,324.518l12.541,55.169,8.012,8.776h5.78L219.39,288.155,218.212,305.2v.012Z" transform="translate(816.18 158.66)" fill="#090814"/>
                                            <path d="M372.68,501.442l73.559-.466,6.532-.044,4.656-.032,5.424-.032h.044a2.993,2.993,0,0,1,2.1.867l9.159,9.16a2.362,2.362,0,0,1,.487.7,1.054,1.054,0,0,1,.141.238,3.058,3.058,0,0,1-2.75,4.267H376.74a3.032,3.032,0,0,1-2.729-1.732l-.747-1.624-1.537-3.3-1.742-3.725a2.968,2.968,0,0,1-.281-1.267,3.015,3.015,0,0,1,2.978-3Z" transform="translate(600.039 38.118)" fill="#090814"/>
                                            <path d="M369.7,511.912l.752,1.63a3.031,3.031,0,0,0,2.721,1.73h95.3a3.048,3.048,0,0,0,2.746-4.267.906.906,0,0,0-.138-.238Z" transform="translate(603.602 38.936)" opacity="0.17" style="isolation:isolate"/>
                                            <path d="M216.883,305.2v.012l20.4,83.258h4.6L218.061,288.155Z" transform="translate(817.51 158.66)" opacity="0.17" style="isolation:isolate"/>
                                            <path d="M375.215,464.563l28.876-219.691a3.135,3.135,0,0,1,1.191-2.079l19.878-24.881,1.786-1.375a3.171,3.171,0,0,1,2.317-.617l2.988.4a3.135,3.135,0,0,1,2.707,3.508h0L405.033,447.456v.01L403.2,461.39l-2.177,16.533v.022l-3.378,25.725a3.066,3.066,0,0,1-1.353,2.187,3.111,3.111,0,0,1-2.526.455l-1.9-.476a3.182,3.182,0,0,1-1.8-1.234l-.845-1.2-.39-.563-13.079-36.055A3.114,3.114,0,0,1,375.215,464.563Z" transform="translate(634.145 14.55)" fill="#090814"/>
                                            <path d="M411.516,217.913,375.192,502.84l.395.557.841,1.2a3.173,3.173,0,0,0,1.8,1.235l1.9.477a3.146,3.146,0,0,0,3.876-2.64l37.3-283.837a3.135,3.135,0,0,0-2.7-3.517l-3-.394a3.091,3.091,0,0,0-2.307.62Z" transform="translate(647.795 14.55)" fill="#090814" opacity="0.17" style="isolation:isolate"/>
                                            <path d="M260.137,97.7l-19.276,27.513L217.951,299.53l12.421,24.641Z" transform="translate(798.101 142.912)" fill="#ff6c37"/>
                                            <g transform="translate(732.051 232.639)">
                                                <path d="M7.819,0H161.966a7.819,7.819,0,1,1,0,15.638H7.819A7.819,7.819,0,0,1,7.819,0Z" fill="#ff6c37"/>
                                                <path d="M0,0H32.393a7.819,7.819,0,1,1,0,15.638H0Z" transform="translate(129.573)" fill="#090814"/>
                                            </g>
                                            <path d="M22.6,43.192A20.6,20.6,0,0,1,22.6,2a2.06,2.06,0,0,1,0,4.119A16.477,16.477,0,1,0,39.073,22.6a2.06,2.06,0,1,1,4.119,0A20.619,20.619,0,0,1,22.6,43.192Z" transform="translate(794.348 151)" fill="#ff6c37"/>
                                        </g>
                                    </svg>
                                    <p class="text-lg font-medium mb-2" style="color: var(--text-secondary);">No webhooks captured yet</p>
                                    <p class="text-sm" style="color: var(--text-tertiary);">Send a request to the webhook URL above to see it here</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="p-6" style="background: var(--bg-primary);">
                                <?php foreach ($webhook_history as $webhook): ?>
                                    <div class="request-card rounded-lg mb-4">
                                        <div class="border-b px-5 py-3" style="background: var(--bg-secondary); border-color: var(--border-primary);">
                                            <div class="flex justify-between items-center">
                                                <div class="flex items-center gap-3">
                                                    <span class="px-3 py-1 rounded text-xs font-bold <?php 
                                                        $method_colors = [
                                                            'GET' => 'bg-blue-100 text-blue-700',
                                                            'POST' => 'bg-green-100 text-green-700',
                                                            'PUT' => 'bg-yellow-100 text-yellow-700',
                                                            'PATCH' => 'bg-purple-100 text-purple-700',
                                                            'DELETE' => 'bg-red-100 text-red-700'
                                                        ];
                                                        echo $method_colors[$webhook['method']] ?? 'bg-gray-100 text-gray-700';
                                                    ?>">
                                                        <?php echo htmlspecialchars($webhook['method']); ?>
                                                    </span>
                                                    <span class="text-sm font-medium" style="color: var(--text-primary);"><?php echo htmlspecialchars($webhook['timestamp']); ?></span>
                                                    <?php if (isset($webhook['project'])): ?>
                                                        <span class="text-xs px-2 py-1 rounded" style="background: var(--bg-tertiary); color: var(--text-primary);">
                                                            <?php echo htmlspecialchars($webhook['project']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="text-xs" style="color: var(--text-tertiary);">From: <?php echo htmlspecialchars($webhook['ip']); ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="p-5">
                                            <div class="text-xs font-bold mb-3 uppercase" style="color: var(--text-secondary);">Request</div>
                                            
                                            <?php if (!empty($webhook['query']) && count(array_diff_key($webhook['query'], ['action' => null, 'project' => null])) > 0): ?>
                                                <div class="mb-4">
                                                    <div class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Query Parameters</div>
                                                    <div class="border rounded p-3" style="background: var(--bg-tertiary); border-color: var(--border-primary);">
                                                        <pre class="font-mono text-xs" style="color: var(--text-primary);"><?php 
                                                            $query_filtered = array_diff_key($webhook['query'], ['action' => null, 'project' => null]);
                                                            echo htmlspecialchars(json_encode($query_filtered, JSON_PRETTY_PRINT)); 
                                                        ?></pre>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($webhook['headers'])): ?>
                                                <div class="mb-4">
                                                    <div class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Request Headers</div>
                                                    <div class="border rounded p-3 max-h-48 overflow-y-auto" style="background: var(--bg-tertiary); border-color: var(--border-primary);">
                                                        <pre class="font-mono text-xs" style="color: var(--text-primary);"><?php
                                                            foreach ($webhook['headers'] as $key => $value) {
                                                                echo htmlspecialchars($key) . ': ' . htmlspecialchars($value) . "\n";
                                                            }
                                                        ?></pre>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($webhook['body'])): ?>
                                                <div class="mb-4">
                                                    <div class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Request Body</div>
                                                    <div class="border rounded p-3" style="background: var(--bg-tertiary); border-color: var(--border-primary);">
                                                        <pre class="font-mono text-xs" style="color: var(--text-primary);"><?php 
                                                            $body = $webhook['body'];
                                                            $json = json_decode($body);
                                                            if (json_last_error() === JSON_ERROR_NONE) {
                                                                echo htmlspecialchars(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                                            } else {
                                                                echo htmlspecialchars($body);
                                                            }
                                                        ?></pre>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (isset($webhook['response'])): ?>
                                                <div class="text-xs font-bold mb-3 mt-6 uppercase" style="color: var(--text-secondary);">Response Sent</div>
                                                
                                                <div class="mb-4">
                                                    <div class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Status Code</div>
                                                    <span class="px-3 py-1 rounded text-xs font-bold <?php 
                                                        $status = $webhook['response']['status'] ?? 200;
                                                        echo $status >= 200 && $status < 300 ? 'bg-green-100 text-green-700' : ($status >= 400 ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'); 
                                                    ?>">
                                                        <?php echo $webhook['response']['status'] ?? 200; ?>
                                                    </span>
                                                </div>
                                                
                                                <?php if (!empty($webhook['response']['headers'])): ?>
                                                    <div class="mb-4">
                                                        <div class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Response Headers</div>
                                                        <div class="border rounded p-3" style="background: var(--bg-tertiary); border-color: var(--border-primary);">
                                                            <pre class="font-mono text-xs" style="color: var(--text-primary);"><?php
                                                                foreach ($webhook['response']['headers'] as $header) {
                                                                    echo htmlspecialchars($header) . "\n";
                                                                }
                                                            ?></pre>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($webhook['response']['body'])): ?>
                                                    <div>
                                                        <div class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Response Body</div>
                                                        <div class="border rounded p-3" style="background: var(--bg-tertiary); border-color: var(--border-primary);">
                                                            <pre class="font-mono text-xs" style="color: var(--text-primary);"><?php 
                                                                $responseBody = $webhook['response']['body'];
                                                                $json = json_decode($responseBody);
                                                                if (json_last_error() === JSON_ERROR_NONE) {
                                                                    echo htmlspecialchars(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                                                } else {
                                                                    echo htmlspecialchars($responseBody);
                                                                }
                                                            ?></pre>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        </div>
                        
                        <!-- Webhook Configuration Tab -->
                        <div id="webhook-config-tab" class="hidden p-6">
                            <h3 class="text-sm font-semibold mb-5" style="color: var(--text-primary);">Configure Webhook Response</h3>
                            <form method="POST" style="max-width: 1000px;">
                                <div class="mb-5">
                                    <label class="block text-xs font-semibold mb-2" style="color: var(--text-primary);">Response Status Code</label>
                                    <input type="number" name="webhook_status" value="<?php echo $currentProject['webhookResponse']['status']; ?>" 
                                           class="px-3 py-2 border rounded text-sm" style="width: 200px; background: var(--input-bg); border-color: var(--border-primary); color: var(--text-primary);">
                                </div>
                                
                                <div class="mb-5">
                                    <div class="mb-3 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">
                                        <div class="grid grid-cols-12 gap-3">
                                            <div class="col-span-5">Header Key</div>
                                            <div class="col-span-6">Value</div>
                                            <div class="col-span-1 text-center">✓</div>
                                        </div>
                                    </div>
                                    
                                    <div id="response-headers-container">
                                        <?php 
                                        $savedResponseHeaders = $currentProject['webhookResponse']['headers'] ?? [];
                                        
                                        // Convert old string format to array format if needed
                                        if (!empty($savedResponseHeaders)) {
                                            $normalizedHeaders = [];
                                            foreach ($savedResponseHeaders as $header) {
                                                if (is_string($header)) {
                                                    // Old format: "Key: Value"
                                                    $parts = explode(':', $header, 2);
                                                    $normalizedHeaders[] = [
                                                        'key' => trim($parts[0]),
                                                        'value' => isset($parts[1]) ? trim($parts[1]) : '',
                                                        'enabled' => true
                                                    ];
                                                } else {
                                                    // New format: array with key, value, enabled
                                                    $normalizedHeaders[] = $header;
                                                }
                                            }
                                            $savedResponseHeaders = $normalizedHeaders;
                                        }
                                        
                                        if (empty($savedResponseHeaders)) {
                                            $savedResponseHeaders = [['key' => 'Content-Type', 'value' => 'application/json', 'enabled' => true]];
                                        }
                                        
                                        foreach ($savedResponseHeaders as $idx => $header): 
                                        ?>
                                        <div class="data-row mb-2 p-3">
                                            <div class="grid grid-cols-12 gap-3 items-center">
                                                <div class="col-span-5">
                                                    <input type="text" name="response_header_key[]" value="<?php echo htmlspecialchars($header['key'] ?? ''); ?>" 
                                                           placeholder="Content-Type" class="w-full px-3 py-2 border rounded text-sm">
                                                </div>
                                                <div class="col-span-6">
                                                    <input type="text" name="response_header_value[]" value="<?php echo htmlspecialchars($header['value'] ?? ''); ?>" 
                                                           placeholder="application/json" class="w-full px-3 py-2 border rounded text-sm">
                                                </div>
                                                <div class="col-span-1 flex items-center justify-center gap-2">
                                                    <input type="checkbox" name="response_header_enabled[<?php echo $idx; ?>]" <?php echo ($header['enabled'] ?? true) ? 'checked' : ''; ?> class="w-4 h-4 accent-orange-500">
                                                    <button type="button" onclick="this.closest('.data-row').remove()" class="hover:text-red-600 text-lg leading-none" style="color: var(--text-tertiary);">×</button>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <button type="button" onclick="addResponseHeaderRow()" class="mt-3 text-sm font-medium" style="color: var(--text-secondary);">
                                        + Add response header
                                    </button>
                                </div>
                                    
                                    <div class="mb-5">
                                        <label class="block text-xs font-semibold mb-2" style="color: var(--text-primary);">Response Body</label>
                                        <textarea name="webhook_body" rows="8" 
                                                  class="w-full px-3 py-2 border rounded text-sm font-mono" style="background: var(--input-bg); border-color: var(--border-primary); color: var(--text-primary);"
                                                  placeholder='{"status": "success"}'><?php echo htmlspecialchars($currentProject['webhookResponse']['body']); ?></textarea>
                                    </div>
                                    
                                <button type="submit" name="save_webhook_config" class="postman-orange text-white font-semibold px-8 py-2 rounded text-sm transition">
                                    Save Configuration
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            
        <?php elseif ($action === 'history'): ?>
            <div class="h-full flex flex-col">
                <!-- History Header Bar -->
                <div style="background: var(--bg-primary); border-bottom: 1px solid var(--border-primary);">
                    <div class="px-6 py-4">
                        <div class="flex justify-between items-center">
                            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Request History</h2>
                            <?php if (!empty($request_history)): ?>
                                <a href="?action=clear_requests" onclick="return confirm('Clear all history?');">
                                    <button class="text-xs px-3 py-1.5 rounded font-medium transition" style="background: var(--bg-tertiary); color: var(--text-secondary);" onmouseover="this.style.background='var(--bg-secondary)'; this.style.color='#dc2626'" onmouseout="this.style.background='var(--bg-tertiary)'; this.style.color='var(--text-secondary)'">
                                        Clear All
                                    </button>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- History Content -->
                <div class="flex-1 overflow-y-auto" style="background: var(--bg-primary);">
                    <?php if (empty($request_history)): ?>
                        <div class="p-16 flex items-center justify-center">
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" width="647.63626" height="632.17383" viewBox="0 0 647.63626 632.17383" xmlns:xlink="http://www.w3.org/1999/xlink" role="img" artist="Katerina Limpitsouni" source="https://undraw.co/" style="max-width: 300px; height: auto; opacity: 0.3; margin: 0 auto 20px;"><path d="M687.3279,276.08691H512.81813a15.01828,15.01828,0,0,0-15,15v387.85l-2,.61005-42.81006,13.11a8.00676,8.00676,0,0,1-9.98974-5.31L315.678,271.39691a8.00313,8.00313,0,0,1,5.31006-9.99l65.97022-20.2,191.25-58.54,65.96972-20.2a7.98927,7.98927,0,0,1,9.99024,5.3l32.5498,106.32Z" transform="translate(-276.18187 -133.91309)" fill="#f2f2f2"/><path d="M725.408,274.08691l-39.23-128.14a16.99368,16.99368,0,0,0-21.23-11.28l-92.75,28.39L380.95827,221.60693l-92.75,28.4a17.0152,17.0152,0,0,0-11.28028,21.23l134.08008,437.93a17.02661,17.02661,0,0,0,16.26026,12.03,16.78926,16.78926,0,0,0,4.96972-.75l63.58008-19.46,2-.62v-2.09l-2,.61-64.16992,19.65a15.01489,15.01489,0,0,1-18.73-9.95l-134.06983-437.94a14.97935,14.97935,0,0,1,9.94971-18.73l92.75-28.4,191.24024-58.54,92.75-28.4a15.15551,15.15551,0,0,1,4.40966-.66,15.01461,15.01461,0,0,1,14.32032,10.61l39.0498,127.56.62012,2h2.08008Z" transform="translate(-276.18187 -133.91309)" fill="#3f3d56"/><path d="M398.86279,261.73389a9.0157,9.0157,0,0,1-8.61133-6.3667l-12.88037-42.07178a8.99884,8.99884,0,0,1,5.9712-11.24023l175.939-53.86377a9.00867,9.00867,0,0,1,11.24072,5.9707l12.88037,42.07227a9.01029,9.01029,0,0,1-5.9707,11.24072L401.49219,261.33887A8.976,8.976,0,0,1,398.86279,261.73389Z" transform="translate(-276.18187 -133.91309)" fill="#ff6c37"/><circle cx="190.15351" cy="24.95465" r="20" fill="#ff6c37"/><circle cx="190.15351" cy="24.95465" r="12.66462" fill="#fff"/><path d="M878.81836,716.08691h-338a8.50981,8.50981,0,0,1-8.5-8.5v-405a8.50951,8.50951,0,0,1,8.5-8.5h338a8.50982,8.50982,0,0,1,8.5,8.5v405A8.51013,8.51013,0,0,1,878.81836,716.08691Z" transform="translate(-276.18187 -133.91309)" fill="#e6e6e6"/><path d="M723.31813,274.08691h-210.5a17.02411,17.02411,0,0,0-17,17v407.8l2-.61v-407.19a15.01828,15.01828,0,0,1,15-15H723.93825Zm183.5,0h-394a17.02411,17.02411,0,0,0-17,17v458a17.0241,17.0241,0,0,0,17,17h394a17.0241,17.0241,0,0,0,17-17v-458A17.02411,17.02411,0,0,0,906.81813,274.08691Zm15,475a15.01828,15.01828,0,0,1-15,15h-394a15.01828,15.01828,0,0,1-15-15v-458a15.01828,15.01828,0,0,1,15-15h394a15.01828,15.01828,0,0,1,15,15Z" transform="translate(-276.18187 -133.91309)" fill="#3f3d56"/><path d="M801.81836,318.08691h-184a9.01015,9.01015,0,0,1-9-9v-44a9.01016,9.01016,0,0,1,9-9h184a9.01016,9.01016,0,0,1,9,9v44A9.01015,9.01015,0,0,1,801.81836,318.08691Z" transform="translate(-276.18187 -133.91309)" fill="#ff6c37"/><circle cx="433.63626" cy="105.17383" r="20" fill="#ff6c37"/><circle cx="433.63626" cy="105.17383" r="12.18187" fill="#fff"/></svg>
                                <p class="text-lg font-medium mb-2" style="color: var(--text-secondary);">No requests in history</p>
                                <p class="text-sm" style="color: var(--text-tertiary);">Send an API request to see it here</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="p-6">
                        <?php foreach ($request_history as $item): ?>
                            <div class="request-card rounded-lg mb-4">
                                <div class="border-b px-5 py-3" style="background: var(--bg-secondary); border-color: var(--border-primary);">
                                    <div class="flex justify-between items-center">
                                        <div class="flex items-center gap-3">
                                            <span class="px-3 py-1 rounded text-xs font-bold <?php 
                                                $method_colors = [
                                                    'GET' => 'bg-blue-100 text-blue-700',
                                                    'POST' => 'bg-green-100 text-green-700',
                                                    'PUT' => 'bg-yellow-100 text-yellow-700',
                                                    'PATCH' => 'bg-purple-100 text-purple-700',
                                                    'DELETE' => 'bg-red-100 text-red-700'
                                                ];
                                                echo $method_colors[$item['method']] ?? 'bg-gray-100 text-gray-700';
                                            ?>">
                                                <?php echo htmlspecialchars($item['method']); ?>
                                            </span>
                                            <span class="font-mono text-sm" style="color: var(--text-primary);"><?php echo htmlspecialchars($item['url']); ?></span>
                                        </div>
                                        <div class="flex items-center gap-4">
                                            <span class="px-3 py-1 rounded text-xs font-bold <?php echo $item['status'] >= 200 && $item['status'] < 300 ? 'bg-green-100 text-green-700' : ($item['status'] >= 400 ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'); ?>">
                                                <?php echo $item['status']; ?>
                                            </span>
                                            <span class="text-sm font-medium" style="color: var(--text-secondary);">
                                                <?php echo $item['duration']; ?>ms
                                            </span>
                                            <span class="text-xs" style="color: var(--text-tertiary);">
                                                <?php echo htmlspecialchars($item['timestamp']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="p-5">
                                    <?php if (isset($item['request'])): ?>
                                        <div class="text-xs font-bold mb-3 uppercase" style="color: var(--text-secondary);">Request</div>
                                        
                                        <?php if (!empty($item['request']['headers'])): ?>
                                            <div class="mb-4">
                                                <div class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Request Headers</div>
                                                <div class="border rounded p-3 max-h-48 overflow-y-auto" style="background: var(--bg-tertiary); border-color: var(--border-primary);">
                                                    <pre class="font-mono text-xs" style="color: var(--text-primary);"><?php
                                                        foreach ($item['request']['headers'] as $header) {
                                                            echo htmlspecialchars($header) . "\n";
                                                        }
                                                    ?></pre>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($item['request']['body'])): ?>
                                            <div class="mb-4">
                                                <div class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Request Body</div>
                                                <div class="border rounded p-3" style="background: var(--bg-tertiary); border-color: var(--border-primary);">
                                                    <pre class="font-mono text-xs" style="color: var(--text-primary);"><?php 
                                                        $reqBody = $item['request']['body'];
                                                        $json = json_decode($reqBody);
                                                        if (json_last_error() === JSON_ERROR_NONE) {
                                                            echo htmlspecialchars(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                                        } else {
                                                            echo htmlspecialchars($reqBody);
                                                        }
                                                    ?></pre>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php if (isset($item['response'])): ?>
                                        <div class="text-xs font-bold mb-3 mt-6 uppercase" style="color: var(--text-secondary);">Response</div>
                                        
                                        <?php if (!empty($item['response']['headers'])): ?>
                                            <div class="mb-4">
                                                <div class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Response Headers</div>
                                                <div class="border rounded p-3 max-h-48 overflow-y-auto" style="background: var(--bg-tertiary); border-color: var(--border-primary);">
                                                    <pre class="font-mono text-xs" style="color: var(--text-primary);"><?php echo htmlspecialchars($item['response']['headers']); ?></pre>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($item['response']['body'])): ?>
                                            <div>
                                                <div class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Response Body</div>
                                                <div class="border rounded p-3" style="background: var(--bg-tertiary); border-color: var(--border-primary);">
                                                    <pre class="font-mono text-xs" style="color: var(--text-primary);"><?php 
                                                        $respBody = $item['response']['body'];
                                                        $json = json_decode($respBody);
                                                        if (json_last_error() === JSON_ERROR_NONE) {
                                                            echo htmlspecialchars(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                                        } else {
                                                            echo htmlspecialchars($respBody);
                                                        }
                                                    ?></pre>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    </div>
    
    <!-- Project Management Modal -->
    <div id="projectModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="rounded-lg shadow-xl" style="width: 500px; max-height: 80vh; overflow-y: auto; background: var(--bg-primary);">
            <div class="border-b px-6 py-4 flex justify-between items-center" style="border-color: var(--border-primary);">
                <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Manage Projects</h3>
                <button onclick="document.getElementById('projectModal').classList.add('hidden')" class="text-2xl" style="color: var(--text-secondary);">×</button>
            </div>
            
            <div class="p-6">
                <div class="mb-6">
                    <h4 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Current Projects</h4>
                    <div class="space-y-2">
                        <?php foreach ($settings['projects'] as $projectKey => $project): ?>
                        <form method="POST" class="flex items-center justify-between p-3 rounded border <?php echo $settings['currentProject'] === $projectKey ? 'border-orange-500' : ''; ?>" style="<?php echo $settings['currentProject'] === $projectKey ? 'background: rgba(255, 108, 55, 0.1);' : 'background: var(--bg-tertiary); border-color: var(--border-primary);'; ?>">
                            <div>
                                <div class="font-medium text-sm" style="color: var(--text-primary);"><?php echo htmlspecialchars($project['name']); ?></div>
                                <div class="text-xs" style="color: var(--text-secondary);"><?php echo htmlspecialchars($projectKey); ?></div>
                            </div>
                            <?php if ($settings['currentProject'] !== $projectKey): ?>
                            <button type="submit" name="switch_project" class="text-xs px-3 py-1 rounded transition" style="background: var(--bg-tertiary); color: var(--text-primary);">
                                Switch
                            </button>
                            <input type="hidden" name="project_name" value="<?php echo htmlspecialchars($projectKey); ?>">
                            <?php else: ?>
                            <span class="text-xs font-semibold" style="color: #ff6c37;">Active</span>
                            <?php endif; ?>
                        </form>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="border-t pt-6" style="border-color: var(--border-primary);">
                    <h4 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Create New Project</h4>
                    <form method="POST" class="flex gap-2">
                        <input type="text" name="new_project_name" placeholder="Project name" required
                               class="flex-1 px-3 py-2 border rounded text-sm" style="background: var(--input-bg); border-color: var(--border-primary); color: var(--text-primary);">
                        <button type="submit" name="create_project" class="postman-orange text-white font-semibold px-4 py-2 rounded text-sm transition">
                            Create
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function performAutoUpdate() {
            const btn = document.getElementById('autoUpdateBtn');
            const btnText = document.getElementById('updateBtnText');
            
            if (!confirm('⚠️  AUTO-UPDATE WARNING\n\nThis will:\n• Download and install the latest version\n• DELETE your current settings (projects, requests, etc.)\n• Create backups: index.php.backup & localman.settings.json.backup\n\nYou will need to reconfigure after update.\n\nDo you want to continue?')) {
                return;
            }
            
            // Disable button and show loading state
            btn.disabled = true;
            btnText.textContent = 'Updating...';
            btn.style.opacity = '0.6';
            btn.style.cursor = 'not-allowed';
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'perform_auto_update=1'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✓ ' + data.message + '\n\nThe page will now reload with the new version.');
                    window.location.reload();
                } else {
                    alert('✗ Update failed: ' + data.message);
                    btn.disabled = false;
                    btnText.textContent = 'Auto Update';
                    btn.style.opacity = '1';
                    btn.style.cursor = 'pointer';
                }
            })
            .catch(error => {
                alert('✗ Update failed: ' + error.message);
                btn.disabled = false;
                btnText.textContent = 'Auto Update';
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
            });
        }
        
        function toggleDarkMode(mode) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'toggle_dark_mode=1&dark_mode=' + mode
            }).then(() => {
                let isDark = false;
                if (mode === 'dark') {
                    isDark = true;
                } else if (mode === 'light') {
                    isDark = false;
                } else {
                    isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                }
                document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
            });
        }
        
        function switchRequestTab(tabId) {
            // Hide all tab contents
            const tabs = ['params-tab', 'authorization-tab', 'headers-tab', 'body-tab', 'webhooks-list-tab', 'webhook-config-tab'];
            tabs.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.classList.add('hidden');
            });
            
            // Show selected tab
            const selectedTab = document.getElementById(tabId);
            if (selectedTab) selectedTab.classList.remove('hidden');
            
            // Update tab active state
            const tabButtons = document.querySelectorAll('.request-tab');
            tabButtons.forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
        }
        
        function switchAuthType(type) {
            document.getElementById('auth-none').classList.add('hidden');
            document.getElementById('auth-bearer').classList.add('hidden');
            document.getElementById('auth-basic').classList.add('hidden');
            
            if (type === 'bearer') {
                document.getElementById('auth-bearer').classList.remove('hidden');
            } else if (type === 'basic') {
                document.getElementById('auth-basic').classList.remove('hidden');
            } else {
                document.getElementById('auth-none').classList.remove('hidden');
            }
        }
        
        function switchBodyType(type) {
            document.getElementById('body-none')?.classList.add('hidden');
            document.getElementById('body-formdata')?.classList.add('hidden');
            document.getElementById('body-urlencoded')?.classList.add('hidden');
            document.getElementById('body-raw')?.classList.add('hidden');
            
            if (type === 'form-data') {
                document.getElementById('body-formdata')?.classList.remove('hidden');
            } else if (type === 'x-www-form-urlencoded') {
                document.getElementById('body-urlencoded')?.classList.remove('hidden');
            } else if (type === 'raw') {
                document.getElementById('body-raw')?.classList.remove('hidden');
            } else {
                document.getElementById('body-none')?.classList.remove('hidden');
            }
            
            updateBodyCount();
        }
        
        function switchResponseTab(tabId) {
            // Hide all response tab contents
            document.getElementById('resp-body')?.classList.add('hidden');
            document.getElementById('resp-headers')?.classList.add('hidden');
            document.getElementById('resp-cookies')?.classList.add('hidden');
            document.getElementById('resp-test')?.classList.add('hidden');
            
            // Show selected tab
            const selectedTab = document.getElementById(tabId);
            if (selectedTab) selectedTab.classList.remove('hidden');
            
            // Update tab active state
            const tabs = document.querySelectorAll('.response-tabs .response-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
        }
        
        function addParamRow() {
            const container = document.getElementById('params-container');
            const newRow = document.createElement('div');
            newRow.className = 'data-row mb-2 p-3';
            const idx = Date.now();
            newRow.innerHTML = `
                <div class="grid grid-cols-12 gap-3 items-center">
                    <div class="col-span-4">
                        <input type="text" name="param_key[]" placeholder="key" class="w-full px-3 py-2 border rounded text-sm param-input" oninput="updateFullUrl()">
                    </div>
                    <div class="col-span-5">
                        <input type="text" name="param_value[]" placeholder="value" class="w-full px-3 py-2 border rounded text-sm param-input" oninput="updateFullUrl()">
                    </div>
                    <div class="col-span-2">
                        <input type="text" name="param_description[]" placeholder="description" class="w-full px-3 py-2 border rounded text-sm">
                    </div>
                    <div class="col-span-1 flex items-center justify-center gap-2">
                        <input type="checkbox" name="param_enabled[${idx}]" checked class="w-4 h-4 accent-orange-500 param-checkbox" onchange="updateFullUrl()">
                        <button type="button" onclick="this.closest('.data-row').remove(); updateFullUrl()" class="hover:text-red-600 text-lg leading-none" style="color: var(--text-tertiary);">×</button>
                    </div>
                </div>
            `;
            container.appendChild(newRow);
        }
        
        function addHeaderRow() {
            const container = document.getElementById('headers-container');
            const newRow = document.createElement('div');
            newRow.className = 'data-row mb-2 p-3';
            const idx = Date.now();
            newRow.innerHTML = `
                <div class="grid grid-cols-12 gap-3 items-center">
                    <div class="col-span-5">
                        <input type="text" name="header_key[]" placeholder="Key" class="w-full px-3 py-2 border rounded text-sm">
                    </div>
                    <div class="col-span-6">
                        <input type="text" name="header_value[]" placeholder="Value" class="w-full px-3 py-2 border rounded text-sm">
                    </div>
                    <div class="col-span-1 flex items-center justify-center gap-2">
                        <input type="checkbox" name="header_enabled[${idx}]" checked class="w-4 h-4 accent-orange-500 header-checkbox" onchange="updateHeaderCount()">
                        <button type="button" onclick="this.closest('.data-row').remove(); updateHeaderCount();" class="hover:text-red-600 text-lg leading-none" style="color: var(--text-tertiary);">×</button>
                    </div>
                </div>
            `;
            container.appendChild(newRow);
            updateHeaderCount();
        }
        
        function addResponseHeaderRow() {
            const container = document.getElementById('response-headers-container');
            const newRow = document.createElement('div');
            newRow.className = 'data-row mb-2 p-3';
            const idx = Date.now();
            newRow.innerHTML = `
                <div class="grid grid-cols-12 gap-3 items-center">
                    <div class="col-span-5">
                        <input type="text" name="response_header_key[]" placeholder="Content-Type" class="w-full px-3 py-2 border rounded text-sm">
                    </div>
                    <div class="col-span-6">
                        <input type="text" name="response_header_value[]" placeholder="application/json" class="w-full px-3 py-2 border rounded text-sm">
                    </div>
                    <div class="col-span-1 flex items-center justify-center gap-2">
                        <input type="checkbox" name="response_header_enabled[${idx}]" checked class="w-4 h-4 accent-orange-500">
                        <button type="button" onclick="this.closest('.data-row').remove()" class="hover:text-red-600 text-lg leading-none" style="color: var(--text-tertiary);">×</button>
                    </div>
                </div>
            `;
            container.appendChild(newRow);
        }
        
        function toggleDefaultHeaders(show) {
            const defaultHeaderRows = document.querySelectorAll('.default-header-row');
            const defaultLabel = document.querySelector('.default-headers-label');
            defaultHeaderRows.forEach(row => {
                if (show) {
                    row.classList.remove('hidden');
                } else {
                    row.classList.add('hidden');
                }
            });
        }
        
        function toggleFormDataInputType(selectElement) {
            const row = selectElement.closest('.data-row');
            const valueContainer = row.querySelector('.col-span-5:nth-child(2)');
            const currentValueInput = valueContainer.querySelector('.formdata-value-input');
            const hiddenInput = valueContainer.querySelector('.formdata-value-hidden');
            const fileContentInput = valueContainer.querySelector('input[name="formdata_file_content[]"]');
            const selectedType = selectElement.value;
            
            let savedValue = '';
            let savedFileContent = '';
            if (currentValueInput) {
                savedValue = currentValueInput.value || currentValueInput.dataset.savedValue || '';
            } else if (hiddenInput) {
                savedValue = hiddenInput.value;
            }
            
            if (fileContentInput) {
                savedFileContent = fileContentInput.value;
            }
            
            // Clear the container
            valueContainer.innerHTML = '';
            
            if (selectedType === 'file') {
                const hasStoredFile = savedValue && savedFileContent;
                
                // Create file display container
                const displayContainer = document.createElement('div');
                displayContainer.className = 'file-display-container';
                
                if (hasStoredFile) {
                    // Show stored file display
                    const fileDisplay = document.createElement('div');
                    fileDisplay.className = 'flex items-center gap-2 px-3 py-2 border rounded';
                    fileDisplay.style.background = 'var(--bg-tertiary)';
                    fileDisplay.innerHTML = `
                        <svg class="w-4 h-4 flex-shrink-0" style="color: var(--text-secondary);" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium truncate" style="color: var(--text-primary);">${savedValue.split('/').pop().split('\\\\').pop()}</div>
                            <div class="text-xs" style="color: var(--text-tertiary);">File stored</div>
                        </div>
                        <div class="flex items-center gap-1 px-2 py-1 rounded flex-shrink-0" style="background: #dcfce7; border: 1px solid #86efac;">
                            <svg class="w-3 h-3" style="color: #16a34a;" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span class="text-xs font-medium" style="color: #16a34a;">Stored</span>
                        </div>
                        <button type="button" onclick="toggleFileUpload(this)" class="flex-shrink-0 p-1 rounded hover:bg-gray-200 transition" style="color: var(--text-secondary);" title="Replace file">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                            </svg>
                        </button>
                    `;
                    displayContainer.appendChild(fileDisplay);
                    
                    // Create hidden upload field
                    const uploadDiv = document.createElement('div');
                    uploadDiv.className = 'file-upload-field hidden mt-2';
                    const fileInput = document.createElement('input');
                    fileInput.type = 'file';
                    fileInput.name = 'formdata_file[]';
                    fileInput.className = 'w-full px-3 py-2 border rounded text-sm formdata-value-input';
                    fileInput.dataset.savedValue = savedValue;
                    uploadDiv.appendChild(fileInput);
                    displayContainer.appendChild(uploadDiv);
                } else {
                    // No stored file, show upload field directly
                    const fileInput = document.createElement('input');
                    fileInput.type = 'file';
                    fileInput.name = 'formdata_file[]';
                    fileInput.className = 'w-full px-3 py-2 border rounded text-sm formdata-value-input';
                    fileInput.dataset.savedValue = '';
                    displayContainer.appendChild(fileInput);
                }
                
                valueContainer.appendChild(displayContainer);
                
                // Create hidden inputs
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'formdata_value[]';
                hidden.value = savedValue;
                hidden.className = 'formdata-value-hidden';
                valueContainer.appendChild(hidden);
                
                const contentHidden = document.createElement('input');
                contentHidden.type = 'hidden';
                contentHidden.name = 'formdata_file_content[]';
                contentHidden.value = savedFileContent;
                valueContainer.appendChild(contentHidden);
            } else {
                // Create text input
                const textInput = document.createElement('input');
                textInput.type = 'text';
                textInput.name = 'formdata_value[]';
                textInput.value = savedValue;
                textInput.placeholder = 'value';
                textInput.className = 'w-full px-3 py-2 border rounded text-sm formdata-value-input';
                valueContainer.appendChild(textInput);
            }
        }
        
        function toggleFileUpload(button) {
            const container = button.closest('.file-display-container');
            const uploadField = container.querySelector('.file-upload-field');
            
            if (uploadField) {
                uploadField.classList.toggle('hidden');
            }
        }
        
        function updateFullUrl() {
            const urlInput = document.getElementById('url-input');
            const baseUrl = urlInput ? urlInput.value : '';
            
            if (!baseUrl) return;
            
            // Get all param inputs
            const paramRows = document.querySelectorAll('#params-container .data-row');
            const params = [];
            
            paramRows.forEach(row => {
                const keyInput = row.querySelector('input[name="param_key[]"]');
                const valueInput = row.querySelector('input[name="param_value[]"]');
                const checkbox = row.querySelector('.param-checkbox');
                
                if (keyInput && valueInput && checkbox && checkbox.checked) {
                    const key = keyInput.value.trim();
                    const value = valueInput.value;
                    if (key) {
                        params.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
                    }
                }
            });
            
            const fullUrlContainer = document.querySelector('.mt-2.text-xs');
            if (params.length > 0) {
                const queryString = params.join('&');
                const separator = baseUrl.includes('?') ? '&' : '?';
                const fullUrl = baseUrl + separator + queryString;
                
                if (fullUrlContainer) {
                    const urlSpan = fullUrlContainer.querySelector('.font-mono');
                    if (urlSpan) {
                        urlSpan.textContent = fullUrl;
                    }
                    fullUrlContainer.classList.remove('hidden');
                } else {
                    // Create the full URL display if it doesn't exist
                    const urlBar = document.querySelector('.px-6.py-4');
                    const newDiv = document.createElement('div');
                    newDiv.className = 'mt-2 text-xs';
                    newDiv.style.color = 'var(--text-tertiary)';
                    newDiv.innerHTML = '<strong>Full URL:</strong> <span class="font-mono" style="color: var(--text-secondary);">' + fullUrl + '</span>';
                    urlBar.appendChild(newDiv);
                }
            } else if (fullUrlContainer) {
                fullUrlContainer.classList.add('hidden');
            }
        }
        
        function updateHeaderCount() {
            const checkboxes = document.querySelectorAll('.header-checkbox');
            let count = 0;
            checkboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    count++;
                }
            });
            
            const countSpan = document.getElementById('headers-count');
            const headersTab = document.querySelector('.request-tab[onclick*="headers-tab"]');
            
            if (count > 0) {
                if (countSpan) {
                    countSpan.textContent = count;
                } else {
                    // Create count span if it doesn't exist
                    const newCountSpan = document.createElement('span');
                    newCountSpan.className = 'count';
                    newCountSpan.id = 'headers-count';
                    newCountSpan.textContent = count;
                    headersTab.appendChild(newCountSpan);
                }
            } else {
                if (countSpan) {
                    countSpan.remove();
                }
            }
        }
        
        function updateBodyCount() {
            const bodyTypeRadios = document.querySelectorAll('input[name="body_type"]');
            let selectedType = 'none';
            
            bodyTypeRadios.forEach(radio => {
                if (radio.checked) {
                    selectedType = radio.value;
                }
            });
            
            let count = 0;
            
            if (selectedType === 'raw') {
                const bodyTextarea = document.querySelector('textarea[name="body"]');
                if (bodyTextarea && bodyTextarea.value.trim()) {
                    count = 1;
                }
            } else if (selectedType === 'form-data' || selectedType === 'x-www-form-urlencoded') {
                const formDataRows = document.querySelectorAll('#formdata-container .data-row');
                formDataRows.forEach(row => {
                    const keyInput = row.querySelector('.formdata-key-input');
                    const checkbox = row.querySelector('.formdata-checkbox');
                    
                    if (keyInput && checkbox) {
                        const key = keyInput.value.trim();
                        if (key && checkbox.checked) {
                            count++;
                        }
                    }
                });
            }
            
            const countSpan = document.getElementById('body-count');
            const bodyTab = document.querySelector('.request-tab[onclick*="body-tab"]');
            
            if (count > 0) {
                if (countSpan) {
                    countSpan.textContent = count;
                } else {
                    // Create count span if it doesn't exist
                    const newCountSpan = document.createElement('span');
                    newCountSpan.className = 'count';
                    newCountSpan.id = 'body-count';
                    newCountSpan.textContent = count;
                    bodyTab.appendChild(newCountSpan);
                }
            } else {
                if (countSpan) {
                    countSpan.remove();
                }
            }
        }
        
        function addFormDataRow() {
            const container = document.getElementById('formdata-container');
            const newRow = document.createElement('div');
            newRow.className = 'data-row mb-2 p-3';
            newRow.innerHTML = `
                <div class="grid grid-cols-12 gap-3 items-center">
                    <div class="col-span-5">
                        <input type="text" name="formdata_key[]" placeholder="key" class="w-full px-3 py-2 border rounded text-sm formdata-key-input" oninput="updateBodyCount()">
                    </div>
                    <div class="col-span-5">
                        <input type="text" name="formdata_value[]" placeholder="value" class="w-full px-3 py-2 border rounded text-sm formdata-value-input">
                    </div>
                    <div class="col-span-1">
                        <select name="formdata_type[]" class="w-full px-2 py-2 border rounded text-xs formdata-type-select" onchange="toggleFormDataInputType(this)">
                            <option value="text">Text</option>
                            <option value="file">File</option>
                        </select>
                    </div>
                    <div class="col-span-1 flex items-center justify-center gap-2">
                        <input type="checkbox" name="formdata_enabled[]" checked class="w-4 h-4 accent-orange-500 formdata-checkbox" onchange="updateBodyCount()">
                        <button type="button" onclick="this.closest('.data-row').remove(); updateBodyCount();" class="hover:text-red-600 text-lg leading-none" style="color: var(--text-tertiary);">×</button>
                    </div>
                </div>
            `;
            container.appendChild(newRow);
        }
        
        function copyWebhookUrl(button) {
            const url = document.getElementById('webhook-url').textContent;
            
            // Check if Clipboard API is available
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(() => {
                    button.textContent = 'Copied!';
                    setTimeout(() => {
                        button.textContent = 'Copy';
                    }, 2000);
                }).catch(err => {
                    console.error('Failed to copy:', err);
                    fallbackCopy(url, button);
                });
            } else {
                fallbackCopy(url, button);
            }
            
            function fallbackCopy(text, btn) {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                try {
                    document.execCommand('copy');
                    btn.textContent = 'Copied!';
                    setTimeout(() => {
                        btn.textContent = 'Copy';
                    }, 2000);
                } catch (err) {
                    console.error('Fallback copy failed:', err);
                    btn.textContent = 'Failed';
                    setTimeout(() => {
                        btn.textContent = 'Copy';
                    }, 2000);
                }
                document.body.removeChild(textarea);
            }
        }
        
        function copyResponse() {
            const prettyView = document.getElementById('response-pretty');
            const rawView = document.getElementById('response-raw');
            let content = '';
            
            if (!prettyView.classList.contains('hidden')) {
                content = document.getElementById('response-body-content').textContent;
            } else if (!rawView.classList.contains('hidden')) {
                content = rawView.querySelector('pre').textContent;
            }
            
            if (!content) {
                content = document.getElementById('response-body-content').textContent;
            }
            
            const button = document.getElementById('copy-response-btn');
            
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(content).then(() => {
                    button.textContent = 'Copied!';
                    setTimeout(() => {
                        button.textContent = 'Copy';
                    }, 2000);
                }).catch(err => {
                    console.error('Failed to copy:', err);
                    fallbackCopyResponse(content, button);
                });
            } else {
                fallbackCopyResponse(content, button);
            }
        }
        
        function fallbackCopyResponse(text, button) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                button.textContent = 'Copied!';
                setTimeout(() => {
                    button.textContent = 'Copy';
                }, 2000);
            } catch (err) {
                console.error('Fallback copy failed:', err);
                button.textContent = 'Failed';
                setTimeout(() => {
                    button.textContent = 'Copy';
                }, 2000);
            }
            document.body.removeChild(textarea);
        }
        
        function switchResponseView(view) {
            // Hide all views
            document.getElementById('response-pretty')?.classList.add('hidden');
            document.getElementById('response-raw')?.classList.add('hidden');
            document.getElementById('response-preview')?.classList.add('hidden');
            
            // Show selected view
            document.getElementById('response-' + view)?.classList.remove('hidden');
            
            // Update button styles
            const buttons = ['resp-pretty-btn', 'resp-raw-btn', 'resp-preview-btn'];
            buttons.forEach(btnId => {
                const btn = document.getElementById(btnId);
                if (btn) {
                    btn.style.background = '';
                    btn.style.color = 'var(--text-secondary)';
                }
            });
            
            // Highlight active button
            const activeBtn = document.getElementById('resp-' + view + '-btn');
            if (activeBtn) {
                activeBtn.style.background = 'var(--bg-tertiary)';
                activeBtn.style.color = 'var(--text-primary)';
            }
        }
        
        // Auto-reload webhooks when new ones are received
        <?php if ($action === 'webhooks'): ?>
        let lastWebhookCount = <?php echo count($webhook_history); ?>;
        
        function checkForNewWebhooks() {
            // Don't reload if user is in Response Config tab
            const configTab = document.getElementById('webhook-config-tab');
            if (configTab && !configTab.classList.contains('hidden')) {
                return;
            }
            
            fetch('?action=webhook_count')
                .then(response => response.json())
                .then(data => {
                    if (data.count !== lastWebhookCount) {
                        window.location.reload();
                    }
                })
                .catch(error => console.error('Error checking webhooks:', error));
        }
        
        setInterval(checkForNewWebhooks, 2000);
        <?php endif; ?>
    </script>
</body>
</html>
