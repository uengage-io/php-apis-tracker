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

        $dimensions = array_merge($this->config['default_dimensions'], [
            ['Name' => 'ApiName', 'Value' => $apiName]
        ]);

        // Add additional dimensions
        foreach ($additionalDimensions as $name => $value) {
            $dimensions[] = ['Name' => $name, 'Value' => $value];
        }

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