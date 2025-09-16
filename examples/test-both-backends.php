<?php

require_once __DIR__ . '/../vendor/autoload.php';

use CurlTracker\CurlHook;

function testBackend($backendType, $config)
{
    echo "\n=== Testing $backendType Backend with CurlHook ===\n";

    // Initialize CurlHook which will initialize CurlWrapper internally
    $hook = CurlHook::init($config);

    try {
        $hook->enable();
        echo "✅ CurlHook enabled successfully\n";
    } catch (Exception $e) {
        echo "❌ Failed to enable CurlHook: " . $e->getMessage() . "\n";
        echo "This is expected if uopz or runkit7 extensions are not available\n";
        return;
    }

    $status = $hook->getBackendStatus();
    echo "Backend status: " . json_encode($status, JSON_PRETTY_PRINT) . "\n";

    if (!$hook->isEnabled()) {
        echo "❌ Backend not ready. Skipping test.\n";
        return;
    }

    echo "Making test API call with native curl functions (hooked)...\n";

    // Use native curl functions - they will be hooked automatically
    $ch = curl_init('https://www.google.com/test-url');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $start = microtime(true);
    $response = curl_exec($ch);
    $duration = (microtime(true) - $start) * 1000;

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "Response code: $httpCode\n";
    echo "Duration: " . round($duration, 2) . "ms\n";

    if ($response !== false) {
        echo "✅ Metrics should have been published to $backendType\n";
    } else {
        echo "❌ Request failed\n";
    }

    // Clean up
    $hook->disable();
    echo "✅ CurlHook disabled\n";
}


// Test PushGateway (if available)
$pushgatewayConfig = [
    'backend' => 'pushgateway',
    'enabled' => true,
    'debug' => true,
    'track_endpoints' => true,
    'default_dimensions' => [
        'environment' => 'test',
        'script' => 'test-both-backends'
    ],
    'pushgateway' => [
        'url' => 'https://monitoring.uengage.in/pushgateway',
        'job_name' => 'curl_tracker_test',
        'instance' => 'test-script',
        'timeout' => 5
    ]
];

testBackend('PushGateway', $pushgatewayConfig);

// Test CloudWatch (requires AWS credentials)
$cloudwatchConfig = [
    'backend' => 'cloudwatch',
    'enabled' => true,
    'debug' => true,
    'default_dimensions' => [
        ['Name' => 'Environment', 'Value' => 'test'],
        ['Name' => 'Script', 'Value' => 'test-both-backends']
    ],
    'cloudwatch' => [
        'aws_region' => 'us-east-1',
        'namespace' => 'CurlTracker/Test',
        'aws' => [
                'profile' => 'flash',
        ]
    ]
];

testBackend('CloudWatch', $cloudwatchConfig);

echo "\n=== Test Complete ===\n";
echo "PushGateway: Check http://localhost:9091/metrics for curl_* metrics\n";
echo "CloudWatch: Check AWS Console > CloudWatch > Metrics > CurlTracker/Test\n";