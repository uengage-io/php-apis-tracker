<?php

require_once __DIR__ . '/../vendor/autoload.php';

use CurlTracker\CurlWrapper;

// CloudWatch Configuration Example
CurlWrapper::init([
    'backend' => 'cloudwatch',
    'enabled' => true,
    'debug' => true,
    
    // Default dimensions to add to all metrics
    'default_dimensions' => [
        ['Name' => 'Environment', 'Value' => 'production'],
        ['Name' => 'Application', 'Value' => 'my-api-client']
    ],
    
    // CloudWatch specific configuration
    'cloudwatch' => [
        'aws_region' => 'us-east-1',
        'namespace' => 'MyApplication/CurlMetrics',
        
        // AWS SDK configuration
        'aws' => [
            // Option 1: Use AWS credentials file or IAM role
            // 'credentials' => [
            //     'key'    => 'your-access-key-id',
            //     'secret' => 'your-secret-access-key',
            // ],
            
            // Option 2: Use AWS profile
            // 'profile' => 'my-aws-profile',
            
            // Option 3: Use environment variables (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY)
            // No credentials needed here
            
            // Additional AWS SDK options
            'version' => 'latest',
            'region' => 'us-east-1'
        ]
    ]
]);

// Example usage
echo "Making API call with CloudWatch tracking...\n";

$ch = CurlWrapper::curl_init('https://api.github.com/users/octocat');
CurlWrapper::curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
CurlWrapper::curl_setopt($ch, CURLOPT_USERAGENT, 'CurlTracker Example');

$response = CurlWrapper::curl_exec($ch);
$httpCode = CurlWrapper::curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "Response code: $httpCode\n";
echo "Backend status: " . json_encode(CurlWrapper::getBackendStatus(), JSON_PRETTY_PRINT) . "\n";

CurlWrapper::curl_close($ch);