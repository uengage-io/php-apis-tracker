<?php

namespace CurlTracker;

/**
 * Zero-code-change cURL metrics tracking using function hooking
 * Requires uopz PHP extension
 */
class CurlHook
{
    private static $instance = null;
    private $config;
    private $hooked = false;

    private function __construct(array $config = [])
    {
        $this->config = array_merge([
            'enabled' => true,
            'debug' => false,
        ], $config);

        // Initialize CurlWrapper with the provided config
        CurlWrapper::init($config);
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

        if (!extension_loaded('uopz')) {
            throw new \RuntimeException(
                'Cannot enable cURL hooking: uopz extension is not available. ' .
                'Install it with: pecl install uopz'
            );
        }

        if (!function_exists('uopz_set_return')) {
            throw new \RuntimeException(
                'Cannot enable cURL hooking: uopz_set_return function is not available.'
            );
        }

        try {
            // Hook curl_init
            uopz_set_return('curl_init', function($url = null) {
                $handle = \curl_init($url);
                if ($handle) {
                    CurlWrapper::trackInit($handle, $url);
                }
                return $handle;
            }, true);

            // Hook curl_setopt
            uopz_set_return('curl_setopt', function($handle, $option, $value) {
                CurlWrapper::trackSetopt($handle, $option, $value);
                return \curl_setopt($handle, $option, $value);
            }, true);

            // Hook curl_exec
            uopz_set_return('curl_exec', function($handle) {
                CurlWrapper::trackExecStart($handle);
                $result = \curl_exec($handle);
                CurlWrapper::trackExecEnd($handle);
                return $result;
            }, true);

            // Hook curl_close
            uopz_set_return('curl_close', function($handle) {
                CurlWrapper::trackClose($handle);
                \curl_close($handle);
            }, true);

            $this->hooked = true;
            $this->debug('Enabled cURL hooking via uopz');
            return true;

        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Failed to enable cURL hooking: ' . $e->getMessage()
            );
        }
    }

    public function disable(): void
    {
        if (!$this->hooked) {
            return;
        }

        if (function_exists('uopz_unset_return')) {
            uopz_unset_return('curl_init');
            uopz_unset_return('curl_setopt');
            uopz_unset_return('curl_exec');
            uopz_unset_return('curl_close');
        }

        $this->hooked = false;
        $this->debug('Disabled cURL hooking');
    }

    private function debug(string $message): void
    {
        if ($this->config['debug']) {
            error_log('[CurlTracker] ' . $message);
        }
    }

    public function isEnabled(): bool
    {
        return $this->hooked && CurlWrapper::isEnabled();
    }

    /**
     * Get the backend status from CurlWrapper
     */
    public function getBackendStatus(): array
    {
        return CurlWrapper::getBackendStatus();
    }

    /**
     * Get configuration for debugging
     */
    public function getConfig(): array
    {
        return array_merge($this->config, CurlWrapper::getConfig());
    }

    /**
     * Publish a custom metric to the configured backend
     *
     * @param string $metricName The name of the metric
     * @param float $value The metric value
     * @param array $labels Additional labels/dimensions for the metric
     * @return bool True if the metric was published successfully, false otherwise
     */
    public function publishMetric(string $metricName, float $value, array $labels = []): bool
    {
        if (!$this->config['enabled']) {
            return false;
        }

        // Apply sampling logic using CurlWrapper
        if (!CurlWrapper::shouldSample()) {
            return false;
        }

        // Build dimensions using CurlWrapper
        $dimensions = CurlWrapper::buildAdditionalDimensions();

        // Merge with user-provided labels
        $dimensions = array_merge($dimensions, $labels);

        // Delegate to CurlWrapper's publishMetric method
        return CurlWrapper::publishMetric($metricName, $value, $dimensions);
    }

    public function __destruct()
    {
        if ($this->hooked) {
            $this->disable();
        }
    }
}
