<?php

namespace CurlTracker\Backends;

/**
 * PushGateway metrics backend
 */
class PushGatewayBackend implements MetricsBackendInterface
{
    private $config = [];
    private $lastError = null;
    private $ready = false;

    /**
     * Initialize the PushGateway backend
     */
    public function initialize(array $config): void
    {
        $this->config = array_merge([
            'url' => 'http://localhost:9091',
            'job_name' => 'curl_tracker',
            'instance' => gethostname() ?: 'unknown',
            'timeout' => 5,
            'auth' => null, // ['username' => 'user', 'password' => 'pass'] or ['token' => 'bearer_token']
            'verify_ssl' => true,
            'debug' => false,
            'default_labels' => []
        ], $config);

        // Validate required configuration
        if (empty($this->config['url'])) {
            $this->lastError = 'PushGateway URL is required';
            return;
        }

        $this->ready = true;
    }

    /**
     * Check if PushGateway backend is ready
     */
    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * Publish metrics to PushGateway
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

        // Build labels with the new naming convention
        $labels = array_merge($this->config['default_labels'], [
            'host' => $this->sanitizeLabelValue($host),
            'endpoint' => $this->sanitizeLabelValue($endpoint)
        ]);

        // Add service label if provided
        if (isset($additionalDimensions['service'])) {
            $labels['service'] = $this->sanitizeLabelValue($additionalDimensions['service']);
            unset($additionalDimensions['service']);
        }

        // Add any other additional dimensions as labels
        foreach ($additionalDimensions as $name => $value) {
            $labels[$this->sanitizeLabelName($name)] = $this->sanitizeLabelValue($value);
        }

        $labelString = $this->buildLabelString($labels);

        // Prepare metrics in Prometheus format with new metric names
        $metrics = [
            "api_response_time{$labelString} {$responseTime}"
        ];

        // Add success/error metrics
        if ($success) {
            $metrics[] = "api_success{$labelString} 1";
        } else {
            $metrics[] = "api_error{$labelString} 1";
        }

        $metricsData = implode("\n", $metrics) . "\n";

        return $this->pushMetrics($metricsData);
    }

    /**
     * Push metrics data to PushGateway
     */
    private function pushMetrics(string $metricsData): bool
    {
        $url = rtrim($this->config['url'], '/') . '/metrics/job/' . 
               urlencode($this->config['job_name']) . '/instance/' . 
               urlencode($this->config['instance']);

        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $metricsData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_SSL_VERIFYPEER => $this->config['verify_ssl'],
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/plain; version=0.0.4; charset=utf-8'
            ]
        ]);

        // Add authentication if configured
        if (!empty($this->config['auth'])) {
            if (isset($this->config['auth']['username'], $this->config['auth']['password'])) {
                curl_setopt($ch, CURLOPT_USERPWD, 
                    $this->config['auth']['username'] . ':' . $this->config['auth']['password']);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            } elseif (isset($this->config['auth']['token'])) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
                    curl_getinfo($ch, CURLINFO_HEADER_OUT) ?: [],
                    ['Authorization: Bearer ' . $this->config['auth']['token']]
                ));
            }
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || !empty($error)) {
            $this->lastError = "Failed to push metrics: " . $error;
            error_log("[CurlTracker] PushGateway error: " . $error);
            return false;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->lastError = "PushGateway returned HTTP {$httpCode}: {$result}";
            error_log("[CurlTracker] PushGateway HTTP {$httpCode}: {$result}");
            return false;
        }

        if ($this->config['debug']) {
            error_log("[CurlTracker] Successfully pushed metrics to PushGateway");
        }

        return true;
    }

    /**
     * Build Prometheus label string
     */
    private function buildLabelString(array $labels): string
    {
        if (empty($labels)) {
            return '';
        }

        $labelPairs = [];
        foreach ($labels as $name => $value) {
            $labelPairs[] = $name . '="' . addslashes($value) . '"';
        }

        return '{' . implode(',', $labelPairs) . '}';
    }

    /**
     * Sanitize label name for Prometheus
     */
    private function sanitizeLabelName(string $name): string
    {
        // Prometheus label names must match [a-zA-Z_:][a-zA-Z0-9_:]*
        $name = preg_replace('/[^a-zA-Z0-9_:]/', '_', $name);
        if (preg_match('/^[0-9]/', $name)) {
            $name = '_' . $name;
        }
        return $name;
    }

    /**
     * Sanitize label value for Prometheus
     */
    private function sanitizeLabelValue(string $value): string
    {
        // Escape quotes and backslashes
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * Get backend name
     */
    public function getName(): string
    {
        return 'PushGateway';
    }

    /**
     * Get PushGateway backend status
     */
    public function getStatus(): array
    {
        return [
            'ready' => $this->isReady(),
            'url' => $this->config['url'] ?? null,
            'job_name' => $this->config['job_name'] ?? null,
            'instance' => $this->config['instance'] ?? null,
            'last_error' => $this->lastError
        ];
    }
}