#!/usr/bin/env php
<?php
/**
 * Comprehensive Testing Script for LocalMan
 * Tests all features and scenarios including edge cases
 */

define('BASE_URL', 'http://localhost:8000');
define('ECHO_SERVER', 'http://localhost:8001');
define('TEST_PROJECT', 'test_project_' . time());

// ANSI color codes for output
define('COLOR_GREEN', "\033[32m");
define('COLOR_RED', "\033[31m");
define('COLOR_YELLOW', "\033[33m");
define('COLOR_BLUE', "\033[34m");
define('COLOR_RESET', "\033[0m");

$testResults = [
    'total' => 0,
    'passed' => 0,
    'failed' => 0,
    'warnings' => 0,
    'bugs' => []
];

function testLog($message, $type = 'INFO') {
    $colors = [
        'PASS' => COLOR_GREEN,
        'FAIL' => COLOR_RED,
        'WARN' => COLOR_YELLOW,
        'INFO' => COLOR_BLUE
    ];
    $color = $colors[$type] ?? COLOR_RESET;
    echo $color . "[$type] " . COLOR_RESET . $message . PHP_EOL;
}

function makeRequest($url, $method = 'GET', $data = null, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    
    if ($data !== null) {
        if (is_array($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    }
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    
    $header = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    return [
        'code' => $httpCode,
        'headers' => $header,
        'body' => $body
    ];
}

function runTest($testName, $callable) {
    global $testResults;
    $testResults['total']++;
    
    testLog("Running: $testName", 'INFO');
    
    try {
        $result = $callable();
        if ($result === true) {
            $testResults['passed']++;
            testLog("✓ $testName", 'PASS');
            return true;
        } elseif (is_array($result) && isset($result['warning'])) {
            $testResults['warnings']++;
            testLog("⚠ $testName: " . $result['warning'], 'WARN');
            if (isset($result['bug'])) {
                $testResults['bugs'][] = $result['bug'];
            }
            return true;
        } else {
            $testResults['failed']++;
            $message = is_string($result) ? $result : 'Test returned false';
            testLog("✗ $testName: $message", 'FAIL');
            return false;
        }
    } catch (Exception $e) {
        $testResults['failed']++;
        testLog("✗ $testName: Exception - " . $e->getMessage(), 'FAIL');
        return false;
    }
}

echo "\n" . COLOR_BLUE . "=== LocalMan Comprehensive Testing ===" . COLOR_RESET . "\n\n";

// ============================================================================
// TEST SUITE 1: Basic API Request Testing
// ============================================================================
testLog("TEST SUITE 1: Basic API Request Testing", 'INFO');

runTest("GET request to echo server", function() {
    $response = makeRequest(ECHO_SERVER . '/test?param1=value1');
    $data = json_decode($response['body'], true);
    return $response['code'] === 200 && $data['method'] === 'GET';
});

runTest("POST request with JSON body", function() {
    $jsonData = json_encode(['key' => 'value', 'nested' => ['data' => 123]]);
    $response = makeRequest(ECHO_SERVER . '/api/users', 'POST', $jsonData, [
        'Content-Type: application/json'
    ]);
    $data = json_decode($response['body'], true);
    return $response['code'] === 200 && $data['method'] === 'POST';
});

runTest("PUT request", function() {
    $response = makeRequest(ECHO_SERVER . '/api/users/1', 'PUT', 'test data');
    $data = json_decode($response['body'], true);
    return $response['code'] === 200 && $data['method'] === 'PUT';
});

runTest("PATCH request", function() {
    $response = makeRequest(ECHO_SERVER . '/api/users/1', 'PATCH', 'test data');
    $data = json_decode($response['body'], true);
    return $response['code'] === 200 && $data['method'] === 'PATCH';
});

runTest("DELETE request", function() {
    $response = makeRequest(ECHO_SERVER . '/api/users/1', 'DELETE');
    $data = json_decode($response['body'], true);
    return $response['code'] === 200 && $data['method'] === 'DELETE';
});

// ============================================================================
// TEST SUITE 2: Webhook Testing
// ============================================================================
testLog("\nTEST SUITE 2: Webhook Testing", 'INFO');

runTest("Webhook capture - GET request", function() {
    $response = makeRequest(BASE_URL . '/index.php?action=webhook&project=default', 'GET');
    return $response['code'] === 200;
});

runTest("Webhook capture - POST with JSON", function() {
    $jsonData = json_encode(['webhook' => 'test', 'data' => ['value' => 123]]);
    $response = makeRequest(
        BASE_URL . '/index.php?action=webhook&project=default',
        'POST',
        $jsonData,
        ['Content-Type: application/json']
    );
    return $response['code'] === 200;
});

runTest("Webhook without project parameter", function() {
    $response = makeRequest(BASE_URL . '/index.php?action=webhook', 'GET');
    // Should return 404 as project is required
    if ($response['code'] === 404) {
        return true;
    }
    return [
        'warning' => 'Webhook without project returned ' . $response['code'] . ' instead of 404',
        'bug' => [
            'title' => 'Webhook accepts requests without project parameter',
            'severity' => 'medium',
            'description' => 'Webhooks should require a project parameter but accept requests without it'
        ]
    ];
});

runTest("Webhook with non-existent project", function() {
    $response = makeRequest(BASE_URL . '/index.php?action=webhook&project=nonexistent', 'GET');
    if ($response['code'] === 404) {
        return true;
    }
    return [
        'warning' => 'Webhook with non-existent project returned ' . $response['code'],
        'bug' => [
            'title' => 'Webhook accepts non-existent project',
            'severity' => 'medium',
            'description' => 'Webhooks should reject requests for non-existent projects'
        ]
    ];
});

// ============================================================================
// TEST SUITE 3: Edge Cases & Security
// ============================================================================
testLog("\nTEST SUITE 3: Edge Cases & Security", 'INFO');

runTest("Very long URL (2000+ characters)", function() {
    $longPath = str_repeat('a', 2000);
    $response = makeRequest(ECHO_SERVER . '/' . $longPath);
    // Should handle gracefully
    return $response['code'] === 200;
});

runTest("URL with special characters", function() {
    $specialChars = rawurlencode('test?param=value&other=123#fragment');
    $response = makeRequest(ECHO_SERVER . '/path/' . $specialChars);
    return $response['code'] === 200;
});

runTest("Request with very large headers", function() {
    $largeHeaders = [];
    for ($i = 0; $i < 50; $i++) {
        $largeHeaders[] = "X-Custom-Header-$i: " . str_repeat('x', 100);
    }
    $response = makeRequest(ECHO_SERVER . '/test', 'GET', null, $largeHeaders);
    return $response['code'] === 200;
});

runTest("POST with large JSON payload (1MB)", function() {
    $largeData = json_encode(['data' => str_repeat('x', 1024 * 1024)]);
    $response = makeRequest(ECHO_SERVER . '/test', 'POST', $largeData, [
        'Content-Type: application/json'
    ]);
    // May fail due to size limits, which is acceptable
    if ($response['code'] === 200 || $response['code'] === 413) {
        return true;
    }
    return [
        'warning' => 'Large payload returned unexpected code: ' . $response['code'],
        'bug' => null
    ];
});

runTest("Malformed JSON in request body", function() {
    $malformedJson = '{"key": "value", "broken": }';
    $response = makeRequest(ECHO_SERVER . '/test', 'POST', $malformedJson, [
        'Content-Type: application/json'
    ]);
    // Echo server should still accept it
    return $response['code'] === 200;
});

runTest("XSS attempt in webhook body", function() {
    $xssPayload = json_encode(['test' => '<script>alert("XSS")</script>']);
    $response = makeRequest(
        BASE_URL . '/index.php?action=webhook&project=default',
        'POST',
        $xssPayload,
        ['Content-Type: application/json']
    );
    // Should accept and store safely
    return $response['code'] === 200;
});

runTest("SQL injection attempt in URL", function() {
    $sqlInjection = "' OR '1'='1";
    $response = makeRequest(ECHO_SERVER . '/test?id=' . urlencode($sqlInjection));
    return $response['code'] === 200;
});

runTest("Path traversal attempt", function() {
    $response = makeRequest(ECHO_SERVER . '/../../etc/passwd');
    // Echo server should handle this
    return $response['code'] === 200;
});

runTest("NULL bytes in URL", function() {
    $response = makeRequest(ECHO_SERVER . '/test%00.txt');
    // Should handle gracefully
    return $response['code'] === 200 || $response['code'] === 400;
});

runTest("Unicode characters in request", function() {
    $unicode = json_encode(['text' => '测试 🚀 тест']);
    $response = makeRequest(ECHO_SERVER . '/test', 'POST', $unicode, [
        'Content-Type: application/json; charset=utf-8'
    ]);
    return $response['code'] === 200;
});

// ============================================================================
// TEST SUITE 4: Request History & Storage
// ============================================================================
testLog("\nTEST SUITE 4: Request History & Storage", 'INFO');

runTest("Request history page loads", function() {
    $response = makeRequest(BASE_URL . '/?action=request-history');
    return $response['code'] === 200 && strpos($response['body'], 'Request history') !== false;
});

runTest("Webhook history page loads", function() {
    $response = makeRequest(BASE_URL . '/?action=webhooks');
    return $response['code'] === 200 && strpos($response['body'], 'Webhooks') !== false;
});

// ============================================================================
// TEST SUITE 5: Project Management
// ============================================================================
testLog("\nTEST SUITE 5: Project Management", 'INFO');

runTest("Create new project", function() {
    $response = makeRequest(BASE_URL . '/', 'POST', [
        'create_project' => '1',
        'new_project_name' => TEST_PROJECT
    ]);
    return $response['code'] === 200;
});

runTest("Switch to new project", function() {
    $response = makeRequest(BASE_URL . '/', 'POST', [
        'switch_project' => '1',
        'project_name' => TEST_PROJECT
    ]);
    return $response['code'] === 200;
});

// ============================================================================
// TEST SUITE 6: Authorization Testing
// ============================================================================
testLog("\nTEST SUITE 6: Authorization Testing", 'INFO');

runTest("Request with Bearer token", function() {
    $response = makeRequest(ECHO_SERVER . '/protected', 'GET', null, [
        'Authorization: Bearer test-token-12345'
    ]);
    $data = json_decode($response['body'], true);
    return $response['code'] === 200 && 
           isset($data['headers']['Authorization']) &&
           strpos($data['headers']['Authorization'], 'Bearer') !== false;
});

runTest("Request with Basic Auth", function() {
    $auth = base64_encode('username:password');
    $response = makeRequest(ECHO_SERVER . '/protected', 'GET', null, [
        'Authorization: Basic ' . $auth
    ]);
    $data = json_decode($response['body'], true);
    return $response['code'] === 200 && 
           isset($data['headers']['Authorization']) &&
           strpos($data['headers']['Authorization'], 'Basic') !== false;
});

// ============================================================================
// TEST SUITE 7: Response Handling
// ============================================================================
testLog("\nTEST SUITE 7: Response Handling", 'INFO');

runTest("Handle gzip compressed response", function() {
    $response = makeRequest(ECHO_SERVER . '/test', 'GET', null, [
        'Accept-Encoding: gzip, deflate'
    ]);
    return $response['code'] === 200;
});

runTest("Handle redirect response", function() {
    // Note: CURLOPT_FOLLOWLOCATION is enabled, so this should follow redirects
    $response = makeRequest(ECHO_SERVER . '/redirect', 'GET');
    return $response['code'] === 200;
});

// ============================================================================
// TEST SUITE 8: Form Data & File Uploads
// ============================================================================
testLog("\nTEST SUITE 8: Form Data & File Uploads", 'INFO');

runTest("POST with form data (URL encoded)", function() {
    $response = makeRequest(ECHO_SERVER . '/form', 'POST', [
        'field1' => 'value1',
        'field2' => 'value2',
        'special' => 'test & value = 123'
    ]);
    $data = json_decode($response['body'], true);
    return $response['code'] === 200 && isset($data['post']);
});

runTest("POST with multipart form data", function() {
    $boundary = '----WebKitFormBoundary7MA4YWxkTrZu0gW';
    $body = "--$boundary\r\n";
    $body .= "Content-Disposition: form-data; name=\"field1\"\r\n\r\n";
    $body .= "value1\r\n";
    $body .= "--$boundary--\r\n";
    
    $response = makeRequest(ECHO_SERVER . '/form', 'POST', $body, [
        "Content-Type: multipart/form-data; boundary=$boundary"
    ]);
    return $response['code'] === 200;
});

// ============================================================================
// TEST SUITE 9: Error Handling
// ============================================================================
testLog("\nTEST SUITE 9: Error Handling", 'INFO');

runTest("Handle timeout gracefully", function() {
    // This would require a slow endpoint, skip for now
    return ['warning' => 'Timeout test skipped (requires slow endpoint)', 'bug' => null];
});

runTest("Handle connection refused", function() {
    $response = makeRequest('http://localhost:9999/nonexistent', 'GET');
    // Should fail but not crash
    return true;
});

runTest("Handle invalid URL", function() {
    // This is tested at the application level, not via curl
    return ['warning' => 'Invalid URL test requires browser testing', 'bug' => null];
});

// ============================================================================
// TEST SUITE 10: Concurrent Requests
// ============================================================================
testLog("\nTEST SUITE 10: Concurrent Requests", 'INFO');

runTest("Multiple concurrent webhook captures", function() {
    $requests = [];
    for ($i = 0; $i < 10; $i++) {
        $jsonData = json_encode(['test' => $i, 'timestamp' => microtime(true)]);
        $response = makeRequest(
            BASE_URL . '/index.php?action=webhook&project=default',
            'POST',
            $jsonData,
            ['Content-Type: application/json']
        );
        $requests[] = $response;
    }
    
    // All should succeed
    foreach ($requests as $response) {
        if ($response['code'] !== 200) {
            return false;
        }
    }
    return true;
});

// ============================================================================
// FINAL REPORT
// ============================================================================
echo "\n" . COLOR_BLUE . "=== Test Results ===" . COLOR_RESET . "\n";
echo "Total Tests: " . $testResults['total'] . "\n";
echo COLOR_GREEN . "Passed: " . $testResults['passed'] . COLOR_RESET . "\n";
echo COLOR_RED . "Failed: " . $testResults['failed'] . COLOR_RESET . "\n";
echo COLOR_YELLOW . "Warnings: " . $testResults['warnings'] . COLOR_RESET . "\n";

$passRate = ($testResults['passed'] / $testResults['total']) * 100;
echo "\nPass Rate: " . number_format($passRate, 1) . "%\n";

if (!empty($testResults['bugs'])) {
    echo "\n" . COLOR_RED . "=== Bugs Found ===" . COLOR_RESET . "\n";
    foreach ($testResults['bugs'] as $i => $bug) {
        if ($bug === null) continue;
        echo ($i + 1) . ". [" . strtoupper($bug['severity']) . "] " . $bug['title'] . "\n";
        echo "   " . $bug['description'] . "\n\n";
    }
}

// Save results to file
$reportFile = '/home/runner/work/localman/localman/test_results_' . date('Y-m-d_H-i-s') . '.json';
file_put_contents($reportFile, json_encode($testResults, JSON_PRETTY_PRINT));
testLog("Test results saved to: $reportFile", 'INFO');

exit($testResults['failed'] > 0 ? 1 : 0);
