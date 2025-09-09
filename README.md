# CurlTracker - Multi-Backend cURL Metrics Library

**Automatically track cURL API response times and publish metrics to AWS CloudWatch or Prometheus PushGateway with zero code changes.**

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.1-blue.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## Features

- =€ **Zero Code Changes**: Automatic cURL hooking using PHP extensions
- =Ê **Multiple Backends**: CloudWatch, PushGateway, or extend with custom backends
- ¡ **Low Overhead**: Minimal performance impact on your applications  
- =' **Flexible Configuration**: Environment-specific settings and dimensions
- =á **Error Handling**: Graceful degradation when backends are unavailable
- =È **Rich Metrics**: Response time, request count, success/error rates

## Quick Start

### Installation

```bash
composer require uengage/curl-tracker
```

### PushGateway Backend (Recommended for Development)

```php
<?php
require_once 'vendor/autoload.php';

use CurlTracker\CurlWrapper;

// Configure for PushGateway
CurlWrapper::init([
    'backend' => 'pushgateway',
    'pushgateway' => [
        'url' => 'http://localhost:9091',
        'job_name' => 'my_application'
    ]
]);

// Your existing cURL code - no changes needed!
$ch = CurlWrapper::curl_init('https://api.example.com/data');
CurlWrapper::curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = CurlWrapper::curl_exec($ch);
CurlWrapper::curl_close($ch);
```

### CloudWatch Backend

```php
<?php
require_once 'vendor/autoload.php';

use CurlTracker\CurlWrapper;

// Configure for CloudWatch
CurlWrapper::init([
    'backend' => 'cloudwatch',
    'cloudwatch' => [
        'aws_region' => 'us-east-1',
        'namespace' => 'MyApp/APIMetrics',
        'aws' => [
            // AWS credentials automatically detected from:
            // - Environment variables
            // - IAM role (EC2/ECS/Lambda)
            // - AWS credentials file
        ]
    ]
]);

// Your existing cURL code works unchanged
$ch = CurlWrapper::curl_init('https://api.example.com/users');
CurlWrapper::curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = CurlWrapper::curl_exec($ch);
CurlWrapper::curl_close($ch);
```

## Backends

### PushGateway Backend

Perfect for development, testing, and environments where you're running Prometheus.

**Configuration:**
```php
'pushgateway' => [
    'url' => 'http://localhost:9091',           // PushGateway URL
    'job_name' => 'curl_tracker',               // Prometheus job name
    'instance' => gethostname(),                // Instance identifier
    'timeout' => 5,                             // HTTP timeout
    'verify_ssl' => true,                       // SSL verification
    'auth' => [                                 // Optional authentication
        'username' => 'user',
        'password' => 'pass'
        // OR
        'token' => 'bearer-token'
    ]
]
```

**Metrics Generated:**
- `curl_response_time_milliseconds{api_name="api.example.com"}` 
- `curl_requests_total{api_name="api.example.com"}`
- `curl_requests_success_total{api_name="api.example.com"}`
- `curl_requests_error_total{api_name="api.example.com"}`

### CloudWatch Backend

Perfect for production AWS environments.

**Configuration:**
```php
'cloudwatch' => [
    'aws_region' => 'us-east-1',
    'namespace' => 'MyApp/CurlMetrics',
    'aws' => [
        // Standard AWS SDK configuration
        'credentials' => [...],  // Optional: explicit credentials
        'profile' => 'default',  // Optional: AWS profile
    ]
]
```

**Metrics Generated:**
- `ResponseTime` (Milliseconds)
- `RequestCount` (Count) 
- `SuccessCount` (Count)
- `ErrorCount` (Count)

All with dimensions: `ApiName`, plus your custom dimensions.

## Advanced Configuration

### Custom Dimensions/Labels

```php
CurlWrapper::init([
    'backend' => 'pushgateway',
    'default_dimensions' => [
        'environment' => 'production',
        'version' => '1.2.3',
        'datacenter' => 'us-east-1'
    ],
    'pushgateway' => [
        'url' => 'http://localhost:9091',
        'default_labels' => [
            'service' => 'payment-api',
            'team' => 'backend'
        ]
    ]
]);
```

### Environment-Based Configuration

```php
$config = [
    'backend' => $_ENV['METRICS_BACKEND'] ?? 'pushgateway',
    'enabled' => $_ENV['METRICS_ENABLED'] ?? true,
    'debug' => $_ENV['METRICS_DEBUG'] ?? false,
];

if ($config['backend'] === 'pushgateway') {
    $config['pushgateway'] = [
        'url' => $_ENV['PUSHGATEWAY_URL'] ?? 'http://localhost:9091',
        'job_name' => $_ENV['APP_NAME'] ?? 'my_app',
    ];
} else {
    $config['cloudwatch'] = [
        'aws_region' => $_ENV['AWS_REGION'] ?? 'us-east-1',
        'namespace' => $_ENV['CLOUDWATCH_NAMESPACE'] ?? 'MyApp/Metrics',
    ];
}

CurlWrapper::init($config);
```

## Zero-Code-Change Setup (Advanced)

For completely automatic tracking without changing existing code:

### 1. Install uopz Extension (Recommended)

```bash
# Ubuntu/Debian
sudo apt-get install php-uopz

# macOS with Homebrew
brew install php-uopz

# Or compile from source
pecl install uopz
```

### 2. Auto-Hook Setup

```php
// bootstrap.php - include before your application starts
<?php
require_once 'vendor/autoload.php';
use CurlTracker\CurlHook;

CurlHook::init([
    'backend' => 'pushgateway',
    'pushgateway' => [
        'url' => 'http://localhost:9091'
    ]
]);
```

Now ALL cURL calls in your application are automatically tracked!

## Monitoring Your Metrics

### PushGateway + Prometheus + Grafana

1. **View raw metrics**: http://localhost:9091/metrics
2. **Query in Prometheus**: http://localhost:9090
   ```promql
   # Response time by API
   curl_response_time_milliseconds
   
   # Request rate
   rate(curl_requests_total[5m])
   
   # Error rate
   rate(curl_requests_error_total[5m]) / rate(curl_requests_total[5m])
   ```
3. **Create Grafana dashboards**: Import or create custom dashboards

### CloudWatch

1. **AWS Console**: CloudWatch ’ Metrics ’ Custom Namespaces
2. **CloudWatch Insights**:
   ```sql
   SELECT ApiName, AVG(ResponseTime), COUNT(RequestCount)
   FROM MyApp/CurlMetrics
   GROUP BY ApiName
   ```

## Troubleshooting

### Check Backend Status

```php
$status = CurlWrapper::getBackendStatus();
echo json_encode($status, JSON_PRETTY_PRINT);
```

**Sample output:**
```json
{
    "backend": "PushGateway",
    "ready": true,
    "url": "http://localhost:9091",
    "job_name": "curl_tracker",
    "last_error": null
}
```

### Common Issues

**PushGateway Backend:**
- L **Connection refused**: Ensure PushGateway is running on the configured URL
- L **Authentication failed**: Check auth credentials if using authentication
- L **SSL errors**: Set `verify_ssl => false` for self-signed certificates (dev only)

**CloudWatch Backend:**
- L **Credentials not found**: Configure AWS credentials via environment, IAM role, or profile
- L **Access denied**: Ensure your credentials have `cloudwatch:PutMetricData` permission
- L **Region mismatch**: Verify the AWS region configuration

### Debug Mode

```php
CurlWrapper::init([
    'debug' => true,  // Enable debug logging
    'backend' => 'pushgateway'
]);
```

Check your error logs for detailed information about metrics publishing.

## Performance Impact

CurlTracker is designed for minimal overhead:
- **PushGateway**: ~2-5ms per request
- **CloudWatch**: ~10-20ms per request (due to AWS API calls)
- **Memory**: <1MB additional memory usage
- **No impact** when backends are disabled or unavailable

## Example Projects

See the `examples/` directory:
- **cloudwatch-config.php** - Complete CloudWatch setup
- **pushgateway-config.php** - Complete PushGateway setup  
- **test-both-backends.php** - Test script for both backends

## Requirements

- PHP 7.1+
- ext-curl
- For CloudWatch: aws/aws-sdk-php ^3.0
- For zero-code setup: ext-uopz or ext-runkit7

## Contributing

1. Fork the repository
2. Create your feature branch
3. Make your changes
4. Add tests for new functionality  
5. Submit a pull request

## License

MIT License. See [LICENSE](LICENSE) for details.

## Changelog

### v2.0.0
- ( **Multi-backend support**: CloudWatch + PushGateway
- ( **Configurable backends**: Switch between backends via configuration
- ( **Enhanced authentication**: Support for basic auth and bearer tokens
- ( **Better error handling**: Graceful degradation and status reporting
- = **Breaking change**: Configuration format updated

### v1.0.3
- = Fixed CloudWatch dimension handling
- =Ú Improved documentation

---

**Made with d for better API monitoring**