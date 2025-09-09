<?php

require_once __DIR__ . '/../vendor/autoload.php';

use CurlTracker\CurlWrapper;

// PushGateway Configuration Example
CurlWrapper::init([
    'backend' => 'pushgateway',
    'enabled' => true,
    'debug' => true,
    
    // Default dimensions to add to all metrics
    'default_dimensions' => [
        'environment' => 'production',
        'application' => 'my-api-client',
        'version' => '1.0.0'
    ],
    
    // PushGateway specific configuration
    'pushgateway' => [
        'url' => 'http://localhost:9091',
        'job_name' => 'curl_tracker',
        'instance' => gethostname() ?: 'unknown',
        'timeout' => 10,
        'verify_ssl' => true,
        
        // Authentication (optional)
        // Option 1: Basic Auth
        // 'auth' => [
        //     'username' => 'your-username',
        //     'password' => 'your-password'
        // ],
        
        // Option 2: Bearer Token
        // 'auth' => [
        //     'token' => 'your-bearer-token'
        // ],
        
        // Default labels to add to all metrics
        'default_labels' => [
            'service' => 'api-client',
            'datacenter' => 'us-east-1'
        ]
    ]
]);

// Example usage
echo "Making API call with PushGateway tracking...\n";

$ch = CurlWrapper::curl_init('https://api.github.com/users/octocat');
CurlWrapper::curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
CurlWrapper::curl_setopt($ch, CURLOPT_USERAGENT, 'CurlTracker Example');

$response = CurlWrapper::curl_exec($ch);
$httpCode = CurlWrapper::curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "Response code: $httpCode\n";
echo "Backend status: " . json_encode(CurlWrapper::getBackendStatus(), JSON_PRETTY_PRINT) . "\n";

CurlWrapper::curl_close($ch);

echo "\nYou can now check your metrics at: http://localhost:9091/metrics\n";
echo "Or query them in Prometheus: curl http://localhost:9090/api/v1/query?query=curl_response_time_milliseconds\n";