<?php
/**
 * LocalMan - A Postman-like standalone PHP application
 * One file to send API requests and capture webhooks
 * 
 * @version 1.0.0
 * @license MIT
 */

// Define storage directories
define('STORAGE_DIR', __DIR__ . '/storage');
define('WEBHOOKS_DIR', STORAGE_DIR . '/webhooks');
define('REQUESTS_DIR', STORAGE_DIR . '/requests');

// Create storage directories if they don't exist
if (!file_exists(WEBHOOKS_DIR)) {
    mkdir(WEBHOOKS_DIR, 0755, true);
}
if (!file_exists(REQUESTS_DIR)) {
    mkdir(REQUESTS_DIR, 0755, true);
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
    
    file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));
    
    // Clean up old files (keep only last 50)
    $files = glob($dir . '/*.json');
    if (count($files) > 50) {
        rsort($files);
        foreach (array_slice($files, 50) as $oldFile) {
            unlink($oldFile);
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

// Handle actions
$action = $_GET['action'] ?? 'home';
$response_data = null;
$error = null;

// Handle webhook capture
if ($action === 'webhook') {
    $webhook_data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'method' => $_SERVER['REQUEST_METHOD'],
        'headers' => getallheaders(),
        'query' => $_GET,
        'body' => file_get_contents('php://input'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    saveEntry('webhook', $webhook_data);
    
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'Webhook received']);
    exit;
}

// Handle AJAX request to get webhook count
if ($action === 'webhook_count') {
    $webhooks = loadHistory('webhook');
    header('Content-Type: application/json');
    echo json_encode(['count' => count($webhooks)]);
    exit;
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
    $headers = $_POST['headers'] ?? '';
    $body = $_POST['body'] ?? '';
    
    if (empty($url)) {
        $error = 'URL is required';
    } else {
        try {
            // Parse headers
            $header_array = [];
            if (!empty($headers)) {
                $header_lines = explode("\n", $headers);
                foreach ($header_lines as $line) {
                    $line = trim($line);
                    if (!empty($line)) {
                        $header_array[] = $line;
                    }
                }
            }
            
            // Make request using cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            // Set method
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            
            // Set headers
            if (!empty($header_array)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $header_array);
            }
            
            // Set body for POST, PUT, PATCH
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE']) && !empty($body)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            
            $start_time = microtime(true);
            $response = curl_exec($ch);
            $duration = round((microtime(true) - $start_time) * 1000, 2);
            
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $response_headers = substr($response, 0, $header_size);
            $response_body = substr($response, $header_size);
            
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            curl_close($ch);
            
            if ($curl_error) {
                $error = 'cURL Error: ' . $curl_error;
            } else {
                $response_data = [
                    'url' => $url,
                    'method' => $method,
                    'status' => $http_code,
                    'duration' => $duration,
                    'headers' => $response_headers,
                    'body' => $response_body,
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                
                // Save to history
                saveEntry('request', [
                    'url' => $url,
                    'method' => $method,
                    'status' => $http_code,
                    'duration' => $duration,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// Get webhook URL
$webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") 
    . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . "/index.php?action=webhook";

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
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <header class="bg-gradient-to-r from-indigo-600 to-purple-600 text-white py-8 mb-8 shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <h1 class="text-4xl font-bold mb-2">🚀 LocalMan</h1>
            <p class="text-lg opacity-90">Send API requests and capture webhooks - All in one file!</p>
        </div>
    </header>
    
    <div class="max-w-7xl mx-auto px-4 pb-8">
        <div class="flex gap-2 mb-6 border-b-2 border-gray-300">
            <a href="?" class="px-6 py-3 rounded-t-lg text-base font-medium transition-colors <?php echo $action === 'home' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'; ?>">API Request</a>
            <a href="?action=webhooks" class="px-6 py-3 rounded-t-lg text-base font-medium transition-colors <?php echo $action === 'webhooks' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'; ?>">Webhooks</a>
            <a href="?action=history" class="px-6 py-3 rounded-t-lg text-base font-medium transition-colors <?php echo $action === 'history' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'; ?>">History</a>
        </div>
        
        <?php if ($action === 'home' || empty($action)): ?>
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-2xl font-bold mb-6 text-gray-800">Send API Request</h2>
                
                <?php if ($error): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="mb-6">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Request</label>
                        <div class="flex gap-2">
                            <select name="method" class="px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono text-sm">
                                <option value="GET" <?php echo (($_POST['method'] ?? 'GET') === 'GET') ? 'selected' : ''; ?>>GET</option>
                                <option value="POST" <?php echo (($_POST['method'] ?? '') === 'POST') ? 'selected' : ''; ?>>POST</option>
                                <option value="PUT" <?php echo (($_POST['method'] ?? '') === 'PUT') ? 'selected' : ''; ?>>PUT</option>
                                <option value="PATCH" <?php echo (($_POST['method'] ?? '') === 'PATCH') ? 'selected' : ''; ?>>PATCH</option>
                                <option value="DELETE" <?php echo (($_POST['method'] ?? '') === 'DELETE') ? 'selected' : ''; ?>>DELETE</option>
                            </select>
                            <input type="url" name="url" placeholder="https://api.example.com/endpoint" 
                                   value="<?php echo htmlspecialchars($_POST['url'] ?? ''); ?>" 
                                   class="flex-1 px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono text-sm" required>
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Headers (one per line, e.g., Content-Type: application/json)</label>
                        <textarea name="headers" rows="4" 
                                  class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono text-sm resize-y" 
                                  placeholder="Content-Type: application/json&#10;Authorization: Bearer token123"><?php echo htmlspecialchars($_POST['headers'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Body</label>
                        <textarea name="body" rows="6" 
                                  class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono text-sm resize-y" 
                                  placeholder='{"key": "value"}'><?php echo htmlspecialchars($_POST['body'] ?? ''); ?></textarea>
                    </div>
                    
                    <button type="submit" name="send_request" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-8 py-3 rounded-lg transition-colors">
                        Send Request
                    </button>
                </form>
                
                <?php if ($response_data): ?>
                    <div class="mt-8 border-t pt-6">
                        <div class="flex justify-between items-center bg-gray-50 p-4 rounded-lg mb-4">
                            <div>
                                <span class="inline-block px-3 py-1 rounded font-semibold text-sm <?php echo $response_data['status'] >= 200 && $response_data['status'] < 300 ? 'bg-green-100 text-green-800' : ($response_data['status'] >= 400 ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800'); ?>">
                                    Status: <?php echo $response_data['status']; ?>
                                </span>
                                <span class="ml-4 text-gray-600 font-medium">
                                    Time: <?php echo $response_data['duration']; ?>ms
                                </span>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <h3 class="text-lg font-semibold text-indigo-600 mb-2">Response Headers</h3>
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 overflow-x-auto">
                                <pre class="font-mono text-sm text-gray-800 whitespace-pre-wrap break-all"><?php echo htmlspecialchars($response_data['headers']); ?></pre>
                            </div>
                        </div>
                        
                        <div>
                            <h3 class="text-lg font-semibold text-indigo-600 mb-2">Response Body</h3>
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 overflow-x-auto">
                                <pre class="font-mono text-sm text-gray-800 whitespace-pre-wrap break-all"><?php 
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
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
        <?php elseif ($action === 'webhooks'): ?>
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">Webhook Capture</h2>
                    <?php if (!empty($webhook_history)): ?>
                        <a href="?action=clear_webhooks" onclick="return confirm('Clear all webhooks?');">
                            <button class="bg-red-600 hover:bg-red-700 text-white font-semibold px-6 py-2 rounded-lg transition-colors">
                                Clear All
                            </button>
                        </a>
                    <?php endif; ?>
                </div>
                
                <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6 rounded">
                    <p class="font-semibold text-blue-800 mb-2">Your Webhook URL:</p>
                    <div class="bg-white border-2 border-dashed border-blue-300 p-3 rounded font-mono text-sm break-all">
                        <?php echo htmlspecialchars($webhook_url); ?>
                    </div>
                    <p class="text-sm text-blue-700 mt-2">Send requests to this URL to capture them below.</p>
                </div>
                
                <h3 class="text-lg font-semibold text-gray-700 mb-4">Captured Webhooks (<span id="webhook-count"><?php echo count($webhook_history); ?></span>)</h3>
                
                <div id="webhook-container">
                    <?php if (empty($webhook_history)): ?>
                        <div class="text-center py-12 text-gray-400">
                            <div class="text-5xl mb-3">📭</div>
                            <p class="text-lg">No webhooks captured yet</p>
                            <p class="text-sm mt-2">Send a request to the webhook URL above to see it here</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($webhook_history as $webhook): ?>
                            <div class="bg-gray-50 border-l-4 border-indigo-500 rounded-lg p-4 mb-4">
                                <div class="flex justify-between items-center mb-3 font-semibold">
                                    <span>
                                        <span class="inline-block px-2 py-1 rounded text-xs font-bold mr-2 
                                            <?php 
                                                $method_colors = [
                                                    'GET' => 'bg-blue-100 text-blue-800',
                                                    'POST' => 'bg-green-100 text-green-800',
                                                    'PUT' => 'bg-yellow-100 text-yellow-800',
                                                    'PATCH' => 'bg-purple-100 text-purple-800',
                                                    'DELETE' => 'bg-red-100 text-red-800'
                                                ];
                                                echo $method_colors[$webhook['method']] ?? 'bg-gray-100 text-gray-800';
                                            ?>">
                                            <?php echo htmlspecialchars($webhook['method']); ?>
                                        </span>
                                        <span class="text-gray-700"><?php echo htmlspecialchars($webhook['timestamp']); ?></span>
                                    </span>
                                    <span class="text-gray-600 text-sm">From: <?php echo htmlspecialchars($webhook['ip']); ?></span>
                                </div>
                                
                                <?php if (!empty($webhook['headers'])): ?>
                                    <div class="mb-3">
                                        <strong class="text-gray-700 text-sm">Headers:</strong>
                                        <div class="bg-white border border-gray-200 rounded p-3 mt-1 max-h-40 overflow-y-auto">
                                            <pre class="font-mono text-xs text-gray-700"><?php echo htmlspecialchars(print_r($webhook['headers'], true)); ?></pre>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($webhook['query']) && count($webhook['query']) > 1): // More than just 'action' ?>
                                    <div class="mb-3">
                                        <strong class="text-gray-700 text-sm">Query Parameters:</strong>
                                        <div class="bg-white border border-gray-200 rounded p-3 mt-1">
                                            <pre class="font-mono text-xs text-gray-700"><?php 
                                                $query_filtered = array_filter($webhook['query'], function($key) {
                                                    return $key !== 'action';
                                                }, ARRAY_FILTER_USE_KEY);
                                                echo htmlspecialchars(json_encode($query_filtered, JSON_PRETTY_PRINT)); 
                                            ?></pre>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($webhook['body'])): ?>
                                    <div>
                                        <strong class="text-gray-700 text-sm">Body:</strong>
                                        <div class="bg-white border border-gray-200 rounded p-3 mt-1">
                                            <pre class="font-mono text-xs text-gray-700"><?php 
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
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <script>
                // Auto-reload webhooks when new ones are received
                let lastWebhookCount = <?php echo count($webhook_history); ?>;
                
                function checkForNewWebhooks() {
                    fetch('?action=webhook_count')
                        .then(response => response.json())
                        .then(data => {
                            if (data.count !== lastWebhookCount) {
                                // New webhook received, reload the page
                                window.location.reload();
                            }
                        })
                        .catch(error => console.error('Error checking webhooks:', error));
                }
                
                // Check every 2 seconds
                setInterval(checkForNewWebhooks, 2000);
            </script>
            
        <?php elseif ($action === 'history'): ?>
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">Request History</h2>
                    <?php if (!empty($request_history)): ?>
                        <a href="?action=clear_requests" onclick="return confirm('Clear all history?');">
                            <button class="bg-red-600 hover:bg-red-700 text-white font-semibold px-6 py-2 rounded-lg transition-colors">
                                Clear All
                            </button>
                        </a>
                    <?php endif; ?>
                </div>
                
                <?php if (empty($request_history)): ?>
                    <div class="text-center py-12 text-gray-400">
                        <div class="text-5xl mb-3">📝</div>
                        <p class="text-lg">No requests in history</p>
                        <p class="text-sm mt-2">Send an API request to see it here</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($request_history as $item): ?>
                        <div class="flex justify-between items-center bg-gray-50 hover:bg-gray-100 p-4 rounded-lg mb-3 transition-colors">
                            <div class="flex-1">
                                <span class="inline-block px-2 py-1 rounded text-xs font-bold mr-3 
                                    <?php 
                                        $method_colors = [
                                            'GET' => 'bg-blue-100 text-blue-800',
                                            'POST' => 'bg-green-100 text-green-800',
                                            'PUT' => 'bg-yellow-100 text-yellow-800',
                                            'PATCH' => 'bg-purple-100 text-purple-800',
                                            'DELETE' => 'bg-red-100 text-red-800'
                                        ];
                                        echo $method_colors[$item['method']] ?? 'bg-gray-100 text-gray-800';
                                    ?>">
                                    <?php echo htmlspecialchars($item['method']); ?>
                                </span>
                                <span class="text-gray-700 font-mono text-sm"><?php echo htmlspecialchars($item['url']); ?></span>
                            </div>
                            <div class="text-right ml-4">
                                <div>
                                    <span class="inline-block px-3 py-1 rounded font-semibold text-sm <?php echo $item['status'] >= 200 && $item['status'] < 300 ? 'bg-green-100 text-green-800' : ($item['status'] >= 400 ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800'); ?>">
                                        <?php echo $item['status']; ?>
                                    </span>
                                    <span class="ml-2 text-gray-600 text-sm font-medium">
                                        <?php echo $item['duration']; ?>ms
                                    </span>
                                </div>
                                <div class="text-gray-400 text-xs mt-1">
                                    <?php echo htmlspecialchars($item['timestamp']); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
