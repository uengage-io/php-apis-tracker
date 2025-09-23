# CurlTracker - Multi-Backend cURL Metrics Library

**Automatically track cURL API response times and publish metrics to AWS CloudWatch or Prometheus PushGateway.**

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.1-blue.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## Quick Start

### Installation

```bash
composer require uengage/curl-tracker
```

### PushGateway Backend Setup

```php
<?php
require_once 'vendor/autoload.php';
use CurlTracker\CurlHook;

CurlHook::init([
    'backend' => 'pushgateway',
    'pushgateway' => [
        'url' => 'http://localhost:9091',
        'job_name' => 'my_application'
    ]
]);
```

### CloudWatch Backend Setup

```php
<?php
require_once 'vendor/autoload.php';
use CurlTracker\CurlHook;

CurlHook::init([
    'backend' => 'cloudwatch',
    'cloudwatch' => [
        'aws_region' => 'us-east-1',
        'namespace' => 'MyApp/APIMetrics'
    ]
]);
```

### CodeIgniter 4 Setup

Add to `app/Config/Events.php`:

```php
<?php
use CurlTracker\CurlHook;

Events::on('pre_system', function() {
    if (class_exists('CurlTracker\CurlHook')) {
        $tracker = CurlHook::init([
            'backend' => env('METRICS_BACKEND', 'pushgateway'),
            'sample_rate' => env('METRICS_SAMPLE_RATE', 100),
            'pushgateway' => [
                'url' => env('PUSHGATEWAY_URL', 'http://localhost:9091'),
                'job_name' => env('APP_NAME', 'codeigniter_app')
            ],
            'cloudwatch' => [
                'aws_region' => env('AWS_REGION', 'us-east-1'),
                'namespace' => env('CLOUDWATCH_NAMESPACE', 'MyApp/Metrics')
            ]
        ]);

        // Enable automatic cURL hooking
        $tracker->enable();
    }
});
```

## Custom Metrics

Push custom business metrics alongside cURL tracking:

```php
use CurlTracker\CurlHook;

// Initialize CurlHook first
$tracker = CurlHook::init([
    'backend' => 'pushgateway',
    'pushgateway' => ['url' => 'http://localhost:9091']
]);

// Example: Track CI database query performance
$start = microtime(true);
$result = $this->db->query("SELECT COUNT(*) FROM users WHERE active = 1");
$duration = (microtime(true) - $start) * 1000;

// Publish custom metric
$tracker->publishMetric('db_query_duration', $duration, [
    'query_type' => 'user_count',
    'database' => 'primary'
]);
```

## Backend Configuration

### PushGateway
```php
'pushgateway' => [
    'url' => 'http://localhost:9091',
    'job_name' => 'my_app',
    'timeout' => 5,
    'auth' => [
        'username' => 'user',
        'password' => 'pass'
    ]
]
```

### CloudWatch
```php
'cloudwatch' => [
    'aws_region' => 'us-east-1',
    'namespace' => 'MyApp/Metrics',
    'aws' => [
        'credentials' => [...] // Optional
    ]
]
```

## Sample Rate Control

```php
CurlHook::init([
    'sample_rate' => 25, // Track only 25% of requests
    'backend' => 'pushgateway'
]);
```

## Requirements

- PHP 7.1+
- ext-curl
- ext-uopz or ext-runkit7 (for automatic cURL hooking)
- For CloudWatch: aws/aws-sdk-php ^3.0

## License

MIT License. See [LICENSE](LICENSE) for details.