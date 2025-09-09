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
     * @param string $apiName The API name/identifier
     * @param float $responseTime Response time in milliseconds
     * @param bool $success Whether the request was successful
     * @param array $additionalDimensions Additional dimensions to include
     * @return bool Success status
     */
    public function publishMetrics(
        string $apiName,
        float $responseTime,
        bool $success,
        array $additionalDimensions = []
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