<?php

namespace CurlTracker;

use Aws\CloudWatch\CloudWatchClient;
use Aws\Exception\AwsException;

/**
 * Manual wrapper for cURL functions with metrics tracking
 * Works everywhere - no extensions required
 */
class CurlWrapper
{
    private static $cloudWatchClient = null;
    private static $config = [];
    private static $activeCurls = [];

    public static function init(array $config = []): void
    {
        self::$config = array_merge([
            'aws_region' => 'us-east-1',
            'namespace' => 'CurlMetrics',
            'default_dimensions' => [],
            'enabled' => true,
            'debug' => false,
            'aws' => []
        ], $config);

        if (!self::$config['enabled']) {
            return;
        }

        $awsConfig = array_merge([
            'version' => 'latest',
            'region' => self::$config['aws_region'],
        ], self::$config['aws']);

        self::$cloudWatchClient = new CloudWatchClient($awsConfig);
    }

    public static function curl_init($url = null)
    {
        $handle = curl_init($url);
        
        if ($handle && self::$cloudWatchClient) {
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
        
        if (self::$cloudWatchClient) {
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
        
        if (self::$cloudWatchClient && isset($options[CURLOPT_URL])) {
            $handleId = (int)$handle;
            if (isset(self::$activeCurls[$handleId])) {
                self::$activeCurls[$handleId]['url'] = $options[CURLOPT_URL];
            }
        }
        
        return $result;
    }

    public static function curl_exec($handle)
    {
        if (self::$cloudWatchClient) {
            $handleId = (int)$handle;
            if (isset(self::$activeCurls[$handleId])) {
                self::$activeCurls[$handleId]['start_time'] = microtime(true);
            }
        }

        $result = curl_exec($handle);

        if (self::$cloudWatchClient) {
            self::trackMetrics($handle, $handleId);
        }

        return $result;
    }

    public static function curl_close($handle): void
    {
        if (self::$cloudWatchClient) {
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

        $curlInfo = self::$activeCurls[$handleId];
        $endTime = microtime(true);
        $responseTime = ($endTime - $curlInfo['start_time']) * 1000;

        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $url = $curlInfo['url'] ?? curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);

        if ($url) {
            self::recordMetrics(
                self::extractApiName($url),
                $responseTime,
                $httpCode >= 200 && $httpCode < 400
            );
        }
    }

    private static function extractApiName(string $url): string
    {
        $parsedUrl = parse_url($url);
        return $parsedUrl['host'] ?? 'unknown';
    }

    private static function recordMetrics(string $apiName, float $responseTime, bool $success): void
    {
        $dimensions = array_merge(self::$config['default_dimensions'], [
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

        self::publishMetrics($metricsData);
    }

    private static function publishMetrics(array $metricsData): void
    {
        try {
            self::$cloudWatchClient->putMetricData([
                'Namespace' => self::$config['namespace'],
                'MetricData' => $metricsData
            ]);

            if (self::$config['debug']) {
                error_log('[CurlTracker] Published ' . count($metricsData) . ' metrics to CloudWatch');
            }
        } catch (AwsException $e) {
            error_log("Failed to publish metrics to CloudWatch: " . $e->getMessage());
        }
    }

    public static function isEnabled(): bool
    {
        return self::$cloudWatchClient !== null;
    }
}