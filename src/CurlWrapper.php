<?php

namespace CurlTracker;

use CurlTracker\Backends\CloudWatchBackend;
use CurlTracker\Backends\PushGatewayBackend;

/**
 * Manual wrapper for cURL functions with metrics tracking
 * Works everywhere - no extensions required
 */
class CurlWrapper
{
    private static $backend = null;
    private static $config = [];
    private static $activeCurls = [];

    public static function init(array $config = []): void
    {
        self::$config = array_merge([
            'backend' => 'cloudwatch', // 'cloudwatch' or 'pushgateway'
            'enabled' => true,
            'debug' => false,
            'service' => null, // Service name for the service dimension
            'default_dimensions' => [],
            'track_endpoints' => false, // false, true, or array of host patterns
            'sample_rate' => 100, // Percentage of requests to track (0 to 100)
            'excluded_urls' => ['monitoring.uengage.in'], // URLs to exclude from tracking
            // CloudWatch specific config
            'cloudwatch' => [
                'aws_region' => 'us-east-1',
                'namespace' => 'CurlMetrics',
                'aws' => []
            ],
            // PushGateway specific config
            'pushgateway' => [
                'url' => 'http://localhost:9091',
                'job_name' => 'curl_tracker',
                'instance' => gethostname() ?: 'unknown',
                'timeout' => 5,
                'auth' => null,
                'verify_ssl' => true,
                'default_labels' => []
            ]
        ], $config);

        if (!self::$config['enabled']) {
            return;
        }

        // Validate sample_rate
        $sampleRate = self::$config['sample_rate'];
        if (!is_numeric($sampleRate) || $sampleRate < 0 || $sampleRate > 100) {
            throw new \InvalidArgumentException("sample_rate must be a number between 0 and 100, got: {$sampleRate}");
        }

        self::initializeBackend();
    }

    /**
     * Initialize the selected metrics backend
     */
    private static function initializeBackend(): void
    {
        $backendType = strtolower(self::$config['backend']);
        
        try {
            switch ($backendType) {
                case 'cloudwatch':
                    self::$backend = new CloudWatchBackend();
                    $backendConfig = array_merge(
                        self::$config['cloudwatch'], 
                        ['debug' => self::$config['debug']]
                    );
                    break;
                    
                case 'pushgateway':
                    self::$backend = new PushGatewayBackend();
                    $backendConfig = array_merge(
                        self::$config['pushgateway'], 
                        ['debug' => self::$config['debug']]
                    );
                    break;
                    
                default:
                    throw new \InvalidArgumentException("Unsupported backend: {$backendType}");
            }

            self::$backend->initialize($backendConfig);

            if (!self::$backend->isReady()) {
                $status = self::$backend->getStatus();
                $error = $status['last_error'] ?? 'Unknown initialization error';
                throw new \RuntimeException("Backend initialization failed: {$error}");
            }

            if (self::$config['debug']) {
                error_log("[CurlTracker] Initialized {$backendType} backend successfully");
            }

        } catch (\Exception $e) {
            self::$backend = null;
            error_log("[CurlTracker] Failed to initialize backend: " . $e->getMessage());
        }
    }

    public static function curl_init($url = null)
    {
        $handle = curl_init($url);
        
        if ($handle && self::$backend) {
            $handleId = (int)$handle;
            self::$activeCurls[$handleId] = [
                'url' => $url,
                'start_time' => null
            ];
        }
        
        return $handle;
    }

    public static function curl_setopt($handle, $option, $value): bool
    {
        $result = curl_setopt($handle, $option, $value);
        
        if (self::$backend) {
            $handleId = (int)$handle;
            if (isset(self::$activeCurls[$handleId]) && $option === CURLOPT_URL) {
                self::$activeCurls[$handleId]['url'] = $value;
            }
        }
        
        return $result;
    }

    public static function curl_setopt_array($handle, array $options): bool
    {
        $result = curl_setopt_array($handle, $options);
        
        if (self::$backend && isset($options[CURLOPT_URL])) {
            $handleId = (int)$handle;
            if (isset(self::$activeCurls[$handleId])) {
                self::$activeCurls[$handleId]['url'] = $options[CURLOPT_URL];
            }
        }
        
        return $result;
    }

    public static function curl_exec($handle)
    {
        $handleId = null;
        if (self::$backend) {
            $handleId = (int)$handle;
            if (isset(self::$activeCurls[$handleId])) {
                self::$activeCurls[$handleId]['start_time'] = microtime(true);
            }
        }

        $result = curl_exec($handle);

        if (self::$backend && $handleId !== null) {
            self::trackMetrics($handle, $handleId);
        }

        return $result;
    }

    public static function curl_close($handle): void
    {
        if (self::$backend) {
            $handleId = (int)$handle;
            unset(self::$activeCurls[$handleId]);
        }
        
        curl_close($handle);
    }

    public static function curl_getinfo($handle, $option = null)
    {
        return curl_getinfo($handle, $option);
    }

    public static function curl_error($handle): string
    {
        return curl_error($handle);
    }

    public static function curl_errno($handle): int
    {
        return curl_errno($handle);
    }

    private static function trackMetrics($handle, int $handleId): void
    {
        if (!isset(self::$activeCurls[$handleId]) || self::$activeCurls[$handleId]['start_time'] === null) {
            return;
        }

        // Apply sampling logic
        if (!self::shouldSample()) {
            return;
        }

        $curlInfo = self::$activeCurls[$handleId];
        $endTime = microtime(true);
        $responseTime = ($endTime - $curlInfo['start_time']) * 1000;

        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $url = $curlInfo['url'] ?? curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);

        if ($url && !self::isUrlExcluded($url)) {
            $apiName = self::extractApiName($url);
            $success = $httpCode >= 200 && $httpCode < 400;
            $dimensions = self::buildAdditionalDimensions();

            // Add API name and endpoint to dimensions
            $dimensions['api_name'] = $apiName;

            // Parse host and endpoint from apiName for backward compatibility
            $host = $apiName;
            $endpoint = '*';
            if (strpos($apiName, '/') !== false) {
                $parts = explode('/', $apiName, 2);
                $host = $parts[0];
                $endpoint = '/' . $parts[1];
            }
            $dimensions['host'] = $host;
            $dimensions['endpoint'] = $endpoint;

            // Publish response time metric
            self::$backend->publishMetrics('api_response_time', $responseTime, $dimensions);

            // Publish success/error metrics
            if ($success) {
                self::$backend->publishMetrics('api_success', 1, $dimensions);
            } else {
                self::$backend->publishMetrics('api_error', 1, $dimensions);
            }
        }
    }

    public static function extractApiName(string $url): string
    {
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? 'unknown';
        
        // If endpoint tracking is disabled, return only the host
        if (empty(self::$config['track_endpoints'])) {
            return $host;
        }
        
        // If track_endpoints is true, track all endpoints
        if (self::$config['track_endpoints'] === true) {
            return self::buildEndpointName($parsedUrl);
        }
        
        // If track_endpoints is an array, check if this host matches any pattern
        if (is_array(self::$config['track_endpoints'])) {
            foreach (self::$config['track_endpoints'] as $pattern) {
                if (self::matchesPattern($host, $pattern)) {
                    return self::buildEndpointName($parsedUrl);
                }
            }
        }
        
        // Default to host-only tracking
        return $host;
    }
    
    public static function buildEndpointName(array $parsedUrl): string
    {
        $host = $parsedUrl['host'] ?? 'unknown';
        $path = $parsedUrl['path'] ?? '/';
        
        // Normalize path to remove trailing slashes and clean up multiple slashes
        $path = preg_replace('/\/+/', '/', trim($path, '/'));
        if (empty($path)) {
            $path = '/';
        } else {
            $path = '/' . $path;
        }
        
        return $host . $path;
    }
    
    public static function matchesPattern(string $host, string $pattern): bool
    {
        // Support wildcard matching with asterisks
        if (strpos($pattern, '*') !== false) {
            // First escape dots, then replace asterisks with regex wildcards
            $regexPattern = str_replace('.', '\.', $pattern);
            $regexPattern = str_replace('*', '.*', $regexPattern);
            return preg_match('/^' . $regexPattern . '$/', $host) === 1;
        }
        
        // Exact match
        return $host === $pattern;
    }

    public static function buildAdditionalDimensions(): array
    {
        $dimensions = [];
        
        // Add service dimension if configured
        if (!empty(self::$config['service'])) {
            $dimensions['service'] = self::$config['service'];
        }
        
        // Convert default_dimensions format based on backend
        if (!empty(self::$config['default_dimensions'])) {
            foreach (self::$config['default_dimensions'] as $dimension) {
                if (is_array($dimension) && isset($dimension['Name'], $dimension['Value'])) {
                    // CloudWatch format
                    $dimensions[$dimension['Name']] = $dimension['Value'];
                } elseif (is_string($dimension)) {
                    // Simple string format
                    $dimensions['tag'] = $dimension;
                }
            }
        }

        return $dimensions;
    }

    public static function shouldSample(): bool
    {
        $sampleRate = self::$config['sample_rate'];

        // If sample rate is 100, always sample
        if ($sampleRate >= 100) {
            return true;
        }

        // If sample rate is 0, never sample
        if ($sampleRate <= 0) {
            return false;
        }

        // Generate random number between 1-100 and check if it's within sample rate
        return (mt_rand(1, 100) <= $sampleRate);
    }

    public static function isUrlExcluded(string $url): bool
    {
        if (empty(self::$config['excluded_urls'])) {
            return false;
        }

        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? '';

        foreach (self::$config['excluded_urls'] as $excludedPattern) {
            if (self::matchesPattern($host, $excludedPattern)) {
                return true;
            }
        }

        return false;
    }

    public static function isEnabled(): bool
    {
        return self::$backend !== null && self::$backend->isReady();
    }

    /**
     * Get the current backend status
     */
    public static function getBackendStatus(): array
    {
        if (!self::$backend) {
            return [
                'backend' => null,
                'ready' => false,
                'error' => 'No backend initialized'
            ];
        }

        $status = self::$backend->getStatus();
        $status['backend'] = self::$backend->getName();
        
        return $status;
    }

    /**
     * Get configuration for debugging
     */
    public static function getConfig(): array
    {
        return self::$config;
    }

    /**
     * Publish a metric to the configured backend
     *
     * @param string $metricName The name of the metric to publish
     * @param mixed $value The value of the metric
     * @param array $dimensions Additional dimensions for the metric
     * @return bool True if the metric was published successfully, false otherwise
     */
    public static function publishMetric(string $metricName, $value, array $dimensions = []): bool
    {
        if (!self::$backend || !self::$backend->isReady()) {
            return false;
        }

        try {
            self::$backend->publishMetrics($metricName, $value, $dimensions);
            return true;
        } catch (\Exception $e) {
            if (self::$config['debug']) {
                error_log("[CurlTracker] Failed to publish metric: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Track curl handle initialization (for use by hooks)
     *
     * @param resource $handle The cURL handle
     * @param string|null $url The initial URL
     */
    public static function trackInit($handle, $url = null): void
    {
        if (self::$backend) {
            $handleId = (int)$handle;
            self::$activeCurls[$handleId] = [
                'url' => $url,
                'start_time' => null
            ];
        }
    }

    /**
     * Track curl option setting (for use by hooks)
     *
     * @param resource $handle The cURL handle
     * @param int $option The option to set
     * @param mixed $value The option value
     */
    public static function trackSetopt($handle, int $option, $value): void
    {
        if (self::$backend) {
            $handleId = (int)$handle;
            if (isset(self::$activeCurls[$handleId]) && $option === CURLOPT_URL) {
                self::$activeCurls[$handleId]['url'] = $value;
            }
        }
    }

    /**
     * Track curl execution start (for use by hooks)
     *
     * @param resource $handle The cURL handle
     */
    public static function trackExecStart($handle): void
    {
        if (self::$backend) {
            $handleId = (int)$handle;
            if (isset(self::$activeCurls[$handleId])) {
                self::$activeCurls[$handleId]['start_time'] = microtime(true);
            }
        }
    }

    /**
     * Track curl execution end and publish metrics (for use by hooks)
     *
     * @param resource $handle The cURL handle
     */
    public static function trackExecEnd($handle): void
    {
        if (self::$backend) {
            $handleId = (int)$handle;
            self::trackMetrics($handle, $handleId);
        }
    }

    /**
     * Track curl handle close (for use by hooks)
     *
     * @param resource $handle The cURL handle
     */
    public static function trackClose($handle): void
    {
        if (self::$backend) {
            $handleId = (int)$handle;
            unset(self::$activeCurls[$handleId]);
        }
    }
}