<?php

namespace CurlTracker\Backends;

use Aws\CloudWatch\CloudWatchClient;
use Aws\Exception\AwsException;

/**
 * CloudWatch metrics backend
 */
class CloudWatchBackend implements MetricsBackendInterface
{
    private $client = null;
    private $config = [];
    private $lastError = null;

    /**
     * Initialize the CloudWatch backend
     */
    public function initialize(array $config): void
    {
        $this->config = array_merge([
            'aws_region' => 'us-east-1',
            'namespace' => 'CurlMetrics',
            'default_dimensions' => [],
            'debug' => false,
            'aws' => []
        ], $config);

        $awsConfig = array_merge([
            'version' => 'latest',
            'region' => $this->config['aws_region'],
        ], $this->config['aws']);

        try {
            $this->client = new CloudWatchClient($awsConfig);
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("[CurlTracker] Failed to initialize CloudWatch client: " . $e->getMessage());
        }
    }

    /**
     * Check if CloudWatch client is ready
     */
    public function isReady(): bool
    {
        return $this->client !== null;
    }

    /**
     * Publish metrics to CloudWatch
     */
    public function publishMetrics(
        string $apiName,
        float $responseTime,
        bool $success,
        array $additionalDimensions = []
    ): bool {
        if (!$this->isReady()) {
            return false;
        }

        // Parse host and endpoint from apiName
        $host = $apiName;
        $endpoint = '*';
        if (strpos($apiName, '/') !== false) {
            $parts = explode('/', $apiName, 2);
            $host = $parts[0];
            $endpoint = '/' . $parts[1];
        }

        // Build base dimensions with the new naming convention
        $dimensions = array_merge($this->config['default_dimensions'], [
            ['Name' => 'host', 'Value' => $host],
            ['Name' => 'endpoint', 'Value' => $endpoint]
        ]);

        // Add service dimension if provided
        if (isset($additionalDimensions['service'])) {
            $dimensions[] = ['Name' => 'service', 'Value' => $additionalDimensions['service']];
            unset($additionalDimensions['service']);
        }

        // Add any other additional dimensions
        foreach ($additionalDimensions as $name => $value) {
            $dimensions[] = ['Name' => $name, 'Value' => $value];
        }

        $timestamp = new \DateTime();

        $metricsData = [
            [
                'MetricName' => 'api_response_time',
                'Value' => $responseTime,
                'Unit' => 'Milliseconds',
                'Dimensions' => $dimensions,
                'Timestamp' => $timestamp
            ]
        ];

        // Add success/error metrics
        if ($success) {
            $metricsData[] = [
                'MetricName' => 'api_success',
                'Value' => 1,
                'Unit' => 'Count',
                'Dimensions' => $dimensions,
                'Timestamp' => $timestamp
            ];
        } else {
            $metricsData[] = [
                'MetricName' => 'api_error',
                'Value' => 1,
                'Unit' => 'Count',
                'Dimensions' => $dimensions,
                'Timestamp' => $timestamp
            ];
        }

        try {
            $this->client->putMetricData([
                'Namespace' => $this->config['namespace'],
                'MetricData' => $metricsData
            ]);

            if ($this->config['debug']) {
                error_log('[CurlTracker] Published ' . count($metricsData) . ' metrics to CloudWatch');
            }

            return true;
        } catch (AwsException $e) {
            $this->lastError = $e->getMessage();
            error_log("[CurlTracker] Failed to publish metrics to CloudWatch: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get backend name
     */
    public function getName(): string
    {
        return 'CloudWatch';
    }

    /**
     * Get CloudWatch backend status
     */
    public function getStatus(): array
    {
        return [
            'ready' => $this->isReady(),
            'region' => $this->config['aws_region'] ?? null,
            'namespace' => $this->config['namespace'] ?? null,
            'last_error' => $this->lastError
        ];
    }
}