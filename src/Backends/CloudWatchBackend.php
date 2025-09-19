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
        string $metricName,
        float $value,
        array $dimensions = []
    ): bool {
        if (!$this->isReady()) {
            return false;
        }

        // Build CloudWatch dimensions format
        $cloudWatchDimensions = array_merge($this->config['default_dimensions'], []);

        // Convert dimensions to CloudWatch format
        foreach ($dimensions as $name => $dimensionValue) {
            $cloudWatchDimensions[] = ['Name' => $name, 'Value' => (string)$dimensionValue];
        }

        $timestamp = new \DateTime();

        $metricsData = [
            [
                'MetricName' => $metricName,
                'Value' => $value,
                'Unit' => 'None',
                'Dimensions' => $cloudWatchDimensions,
                'Timestamp' => $timestamp
            ]
        ];

        try {
            $this->client->putMetricData([
                'Namespace' => $this->config['namespace'],
                'MetricData' => $metricsData
            ]);

            if ($this->config['debug']) {
                error_log('[CurlTracker] Published metric to CloudWatch: ' . $metricName);
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