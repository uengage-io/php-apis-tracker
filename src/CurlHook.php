<?php

namespace CurlTracker;

/**
 * Zero-code-change cURL metrics tracking using function hooking
 * Requires uopz or runkit7 PHP extension
 */
class CurlHook
{
    private static $instance = null;
    private $config;
    private $activeCurls = [];
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
            $self = $this;
            
            uopz_set_return('curl_init', function($url = null) use ($self) {
                $handle = \curl_init($url);
                if ($handle) {
                    $self->trackCurlInit($handle, $url);
                }
                return $handle;
            }, true);

            uopz_set_return('curl_setopt', function($handle, $option, $value) use ($self) {
                $self->trackCurlSetopt($handle, $option, $value);
                return \curl_setopt($handle, $option, $value);
            }, true);

            uopz_set_return('curl_exec', function($handle) use ($self) {
                $self->trackCurlExecStart($handle);
                $result = \curl_exec($handle);
                $self->trackCurlExecEnd($handle);
                return $result;
            }, true);

            uopz_set_return('curl_close', function($handle) use ($self) {
                $self->trackCurlClose($handle);
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
        // Delegate to CurlWrapper for consistent tracking
        $handleId = (int)$handle;
        $this->activeCurls[$handleId] = [
            'url' => $url,
            'start_time' => null
        ];
    }

    public function trackCurlSetopt($handle, $option, $value): void
    {
        // Delegate to CurlWrapper for consistent tracking
        $handleId = (int)$handle;
        if (isset($this->activeCurls[$handleId]) && $option === CURLOPT_URL) {
            $this->activeCurls[$handleId]['url'] = $value;
        }
    }

    public function trackCurlExecStart($handle): void
    {
        // Delegate to CurlWrapper for consistent tracking
        $handleId = (int)$handle;
        if (isset($this->activeCurls[$handleId])) {
            $this->activeCurls[$handleId]['start_time'] = microtime(true);
        }
    }

    public function trackCurlExecEnd($handle): void
    {
        // Use CurlWrapper's internal tracking mechanism
        // We need to reconstruct the data that CurlWrapper would use
        $handleId = (int)$handle;
        if (!isset($this->activeCurls[$handleId]) || $this->activeCurls[$handleId]['start_time'] === null) {
            return;
        }

        $curlData = $this->activeCurls[$handleId];
        $endTime = microtime(true);
        $responseTime = ($endTime - $curlData['start_time']) * 1000;

        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $url = $curlData['url'] ?? curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);

        if ($url && CurlWrapper::isEnabled()) {
            $success = $httpCode >= 200 && $httpCode < 400;

            // Get the backend from CurlWrapper and publish metrics directly
            $status = CurlWrapper::getBackendStatus();
            if ($status['ready']) {
                // Use reflection to access CurlWrapper's private backend and call publishMetrics
                $reflection = new \ReflectionClass(CurlWrapper::class);
                $backendProperty = $reflection->getProperty('backend');
                $backendProperty->setAccessible(true);
                $backend = $backendProperty->getValue();

                $configProperty = $reflection->getProperty('config');
                $configProperty->setAccessible(true);
                $wrapperConfig = $configProperty->getValue();

                // Build dimensions similar to CurlWrapper
                $dimensions = [];
                if (!empty($wrapperConfig['service'])) {
                    $dimensions['service'] = $wrapperConfig['service'];
                }

                if (!empty($wrapperConfig['default_dimensions'])) {
                    foreach ($wrapperConfig['default_dimensions'] as $dimension) {
                        if (is_array($dimension) && isset($dimension['Name'], $dimension['Value'])) {
                            $dimensions[$dimension['Name']] = $dimension['Value'];
                        } elseif (is_string($dimension)) {
                            $dimensions['tag'] = $dimension;
                        }
                    }
                }

                // Extract API name using CurlWrapper's logic
                $apiName = $this->extractApiNameUsingWrapperLogic($url, $wrapperConfig);

                $backend->publishMetrics($apiName, $responseTime, $success, $dimensions);
            }
        }
    }

    public function trackCurlClose($handle): void
    {
        // Clean up local tracking
        $handleId = (int)$handle;
        unset($this->activeCurls[$handleId]);
    }

    private function extractApiNameUsingWrapperLogic(string $url, array $config): string
    {
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? 'unknown';

        // Use the same logic as CurlWrapper::extractApiName
        if (empty($config['track_endpoints'])) {
            return $host;
        }

        if ($config['track_endpoints'] === true) {
            return $this->buildEndpointName($parsedUrl);
        }

        if (is_array($config['track_endpoints'])) {
            foreach ($config['track_endpoints'] as $pattern) {
                if ($this->matchesPattern($host, $pattern)) {
                    return $this->buildEndpointName($parsedUrl);
                }
            }
        }

        return $host;
    }

    private function buildEndpointName(array $parsedUrl): string
    {
        $host = $parsedUrl['host'] ?? 'unknown';
        $path = $parsedUrl['path'] ?? '/';

        $path = preg_replace('/\/+/', '/', trim($path, '/'));
        if (empty($path)) {
            $path = '/';
        } else {
            $path = '/' . $path;
        }

        return $host . $path;
    }

    private function matchesPattern(string $host, string $pattern): bool
    {
        if (strpos($pattern, '*') !== false) {
            $regexPattern = str_replace('.', '\.', $pattern);
            $regexPattern = str_replace('*', '.*', $regexPattern);
            return preg_match('/^' . $regexPattern . '$/', $host) === 1;
        }

        return $host === $pattern;
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

    public function __destruct()
    {
        if ($this->hooked) {
            $this->disable();
        }
    }
}