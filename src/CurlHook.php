<?php

namespace CurlTracker;

use Aws\CloudWatch\CloudWatchClient;
use Aws\Exception\AwsException;

/**
 * Zero-code-change cURL metrics tracking using function hooking
 * Requires uopz or runkit7 PHP extension
 */
class CurlHook
{
    private static $instance = null;
    private $cloudWatchClient;
    private $config;
    private $activeCurls = [];
    private $hooked = false;

    private function __construct(array $config = [])
    {
        $this->config = array_merge([
            'aws_region' => 'us-east-1',
            'namespace' => 'CurlMetrics',
            'default_dimensions' => [],
            'enabled' => true,
            'debug' => false,
            'aws' => []
        ], $config);

        $awsConfig = array_merge([
            'version' => 'latest',
            'region' => $this->config['aws_region'],
        ], $this->config['aws']);

        $this->cloudWatchClient = new CloudWatchClient($awsConfig);
    }

    public static function init(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    public function enable(): bool
    {
        if (!$this->config['enabled'] || $this->hooked) {
            return $this->hooked;
        }

        // Try uopz first (preferred)
        if (extension_loaded('uopz') && $this->enableUopzHooking()) {
            $this->hooked = true;
            $this->debug('Enabled cURL hooking via uopz');
            return true;
        }

        // Fallback to runkit
        if ((extension_loaded('runkit7') || extension_loaded('runkit')) && $this->enableRunkitHooking()) {
            $this->hooked = true;
            $this->debug('Enabled cURL hooking via runkit');
            return true;
        }

        throw new \RuntimeException(
            'Cannot enable cURL hooking: Neither uopz nor runkit7 extension is available.'
        );
    }

    public function disable(): void
    {
        if (!$this->hooked) {
            return;
        }

        if (extension_loaded('uopz')) {
            $this->disableUopzHooking();
        } elseif (extension_loaded('runkit7') || extension_loaded('runkit')) {
            $this->disableRunkitHooking();
        }

        $this->hooked = false;
        $this->debug('Disabled cURL hooking');
    }

    private function enableUopzHooking(): bool
    {
        if (!function_exists('uopz_set_return')) {
            return false;
        }

        try {
            uopz_set_return('curl_init', function($url = null) {
                $handle = \curl_init($url);
                if ($handle) {
                    $this->trackCurlInit($handle, $url);
                }
                return $handle;
            }, true);

            uopz_set_return('curl_setopt', function($handle, $option, $value) {
                $this->trackCurlSetopt($handle, $option, $value);
                return \curl_setopt($handle, $option, $value);
            }, true);

            uopz_set_return('curl_exec', function($handle) {
                $this->trackCurlExecStart($handle);
                $result = \curl_exec($handle);
                $this->trackCurlExecEnd($handle);
                return $result;
            }, true);

            uopz_set_return('curl_close', function($handle) {
                $this->trackCurlClose($handle);
                \curl_close($handle);
            }, true);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function enableRunkitHooking(): bool
    {
        $rename = function_exists('runkit7_function_rename') ? 'runkit7_function_rename' : 'runkit_function_rename';
        $add = function_exists('runkit7_function_add') ? 'runkit7_function_add' : 'runkit_function_add';

        if (!function_exists($rename) || !function_exists($add)) {
            return false;
        }

        try {
            $rename('curl_init', '_original_curl_init');
            $rename('curl_setopt', '_original_curl_setopt');
            $rename('curl_exec', '_original_curl_exec');
            $rename('curl_close', '_original_curl_close');

            $add('curl_init', '$url = null', '
                $handle = _original_curl_init($url);
                $tracker = \\CurlTracker\\CurlHook::getInstance();
                if ($tracker && $handle) {
                    $tracker->trackCurlInit($handle, $url);
                }
                return $handle;
            ');

            $add('curl_setopt', '$handle, $option, $value', '
                $tracker = \\CurlTracker\\CurlHook::getInstance();
                if ($tracker) {
                    $tracker->trackCurlSetopt($handle, $option, $value);
                }
                return _original_curl_setopt($handle, $option, $value);
            ');

            $add('curl_exec', '$handle', '
                $tracker = \\CurlTracker\\CurlHook::getInstance();
                if ($tracker) {
                    $tracker->trackCurlExecStart($handle);
                }
                $result = _original_curl_exec($handle);
                if ($tracker) {
                    $tracker->trackCurlExecEnd($handle);
                }
                return $result;
            ');

            $add('curl_close', '$handle', '
                $tracker = \\CurlTracker\\CurlHook::getInstance();
                if ($tracker) {
                    $tracker->trackCurlClose($handle);
                }
                _original_curl_close($handle);
            ');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function disableUopzHooking(): void
    {
        if (function_exists('uopz_unset_return')) {
            uopz_unset_return('curl_init');
            uopz_unset_return('curl_setopt');
            uopz_unset_return('curl_exec');
            uopz_unset_return('curl_close');
        }
    }

    private function disableRunkitHooking(): void
    {
        $rename = function_exists('runkit7_function_rename') ? 'runkit7_function_rename' : 'runkit_function_rename';
        $remove = function_exists('runkit7_function_remove') ? 'runkit7_function_remove' : 'runkit_function_remove';

        if (function_exists($remove)) {
            try {
                $remove('curl_init');
                $remove('curl_setopt');
                $remove('curl_exec');
                $remove('curl_close');

                $rename('_original_curl_init', 'curl_init');
                $rename('_original_curl_setopt', 'curl_setopt');
                $rename('_original_curl_exec', 'curl_exec');
                $rename('_original_curl_close', 'curl_close');
            } catch (\Throwable $e) {
                // Ignore cleanup errors
            }
        }
    }

    public function trackCurlInit($handle, $url): void
    {
        $handleId = (int)$handle;
        $this->activeCurls[$handleId] = [
            'url' => $url,
            'start_time' => null
        ];
    }

    public function trackCurlSetopt($handle, $option, $value): void
    {
        $handleId = (int)$handle;
        if (isset($this->activeCurls[$handleId]) && $option === CURLOPT_URL) {
            $this->activeCurls[$handleId]['url'] = $value;
        }
    }

    public function trackCurlExecStart($handle): void
    {
        $handleId = (int)$handle;
        if (isset($this->activeCurls[$handleId])) {
            $this->activeCurls[$handleId]['start_time'] = microtime(true);
        }
    }

    public function trackCurlExecEnd($handle): void
    {
        $handleId = (int)$handle;
        if (!isset($this->activeCurls[$handleId]) || $this->activeCurls[$handleId]['start_time'] === null) {
            return;
        }

        $curlData = $this->activeCurls[$handleId];
        $endTime = microtime(true);
        $responseTime = ($endTime - $curlData['start_time']) * 1000;

        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $url = $curlData['url'] ?? curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);

        if ($url) {
            $this->recordMetrics(
                $this->extractApiName($url),
                $responseTime,
                $httpCode >= 200 && $httpCode < 400
            );
        }
    }

    public function trackCurlClose($handle): void
    {
        $handleId = (int)$handle;
        unset($this->activeCurls[$handleId]);
    }

    private function extractApiName(string $url): string
    {
        $parsedUrl = parse_url($url);
        return $parsedUrl['host'] ?? 'unknown';
    }

    private function recordMetrics(string $apiName, float $responseTime, bool $success): void
    {
        $dimensions = array_merge($this->config['default_dimensions'], [
            ['Name' => 'ApiName', 'Value' => $apiName]
        ]);

        $timestamp = new \DateTime();

        $metricsData = [
            [
                'MetricName' => 'ResponseTime',
                'Value' => $responseTime,
                'Unit' => 'Milliseconds',
                'Dimensions' => $dimensions,
                'Timestamp' => $timestamp
            ],
            [
                'MetricName' => 'RequestCount',
                'Value' => 1,
                'Unit' => 'Count',
                'Dimensions' => $dimensions,
                'Timestamp' => $timestamp
            ],
            [
                'MetricName' => $success ? 'SuccessCount' : 'ErrorCount',
                'Value' => 1,
                'Unit' => 'Count',
                'Dimensions' => $dimensions,
                'Timestamp' => $timestamp
            ]
        ];

        $this->publishMetrics($metricsData);
    }

    private function publishMetrics(array $metricsData): void
    {
        try {
            $this->cloudWatchClient->putMetricData([
                'Namespace' => $this->config['namespace'],
                'MetricData' => $metricsData
            ]);

            $this->debug('Published ' . count($metricsData) . ' metrics to CloudWatch');
        } catch (AwsException $e) {
            error_log("Failed to publish metrics to CloudWatch: " . $e->getMessage());
        }
    }

    private function debug(string $message): void
    {
        if ($this->config['debug']) {
            error_log('[CurlTracker] ' . $message);
        }
    }

    public function isEnabled(): bool
    {
        return $this->hooked;
    }

    public function __destruct()
    {
        if ($this->hooked) {
            $this->disable();
        }
    }
}