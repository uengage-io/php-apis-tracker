<?php

require_once __DIR__ . '/../vendor/autoload.php';

use CurlTracker\CurlWrapper;

function testBackend($backendType, $config)
{
    echo "\n=== Testing {$backendType} Backend ===\n";

    CurlWrapper::init($config);

    $status = CurlWrapper::getBackendStatus();
    echo "Backend status: " . json_encode($status, JSON_PRETTY_PRINT) . "\n";

    if (!CurlWrapper::isEnabled()) {
        echo "❌ Backend not ready. Skipping test.\n";
        return;
    }

    echo "✅ Backend initialized successfully\n";
    echo "Making test API call...\n";

    // Make a test API call
    $ch = CurlWrapper::curl_init('https://httpbin.org/delay/1');
    CurlWrapper::curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    CurlWrapper::curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $start = microtime(true);
    $response = CurlWrapper::curl_exec($ch);
    $duration = (microtime(true) - $start) * 1000;

    $httpCode = CurlWrapper::curl_getinfo($ch, CURLINFO_HTTP_CODE);
    CurlWrapper::curl_close($ch);

    echo "Response code: {$httpCode}\n";
    echo "Duration: " . round($duration, 2) . "ms\n";

    if ($response !== false) {
        echo "✅ Metrics should have been published to {$backendType}\n";
    } else {
        echo "❌ Request failed\n";
    }
}

// Test PushGateway (if available)
$pushgatewayConfig = [
    'backend' => 'pushgateway',
    'enabled' => true,
    'debug' => true,
    'default_dimensions' => [
        'environment' => 'test',
        'script' => 'test-both-backends'
    ],
    'pushgateway' => [
        'url' => 'http://localhost:9091',
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