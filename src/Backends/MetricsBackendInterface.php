<?php

namespace CurlTracker\Backends;

/**
 * Interface for metrics backends
 */
interface MetricsBackendInterface
{
    /**
     * Initialize the backend with configuration
     *
     * @param array $config Backend-specific configuration
     * @return void
     */
    public function initialize(array $config): void;

    /**
     * Check if the backend is properly configured and ready
     *
     * @return bool
     */
    public function isReady(): bool;

    /**
     * Publish metrics to the backend
     *
     * @param string $metricName The name of the metric
     * @param float $value The metric value
     * @param array $dimensions Dimensions/labels for the metric
     * @return bool Success status
     */
    public function publishMetrics(
        string $metricName,
        float $value,
        array $dimensions = []
    ): bool;

    /**
     * Get the backend name/type
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get backend-specific health/status information
     *
     * @return array
     */
    public function getStatus(): array;
}