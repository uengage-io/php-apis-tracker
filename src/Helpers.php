<?php

use CurlTracker\CurlWrapper;

/**
 * Global helper functions for easy migration
 */

if (!function_exists('init_curl_metrics')) {
    function init_curl_metrics(array $config = []): void {
        CurlWrapper::init($config);
    }
}

if (!function_exists('tracked_curl_init')) {
    function tracked_curl_init($url = null) {
        return CurlWrapper::curl_init($url);
    }
}

if (!function_exists('tracked_curl_setopt')) {
    function tracked_curl_setopt($handle, $option, $value): bool {
        return CurlWrapper::curl_setopt($handle, $option, $value);
    }
}

if (!function_exists('tracked_curl_setopt_array')) {
    function tracked_curl_setopt_array($handle, array $options): bool {
        return CurlWrapper::curl_setopt_array($handle, $options);
    }
}

if (!function_exists('tracked_curl_exec')) {
    function tracked_curl_exec($handle) {
        return CurlWrapper::curl_exec($handle);
    }
}

if (!function_exists('tracked_curl_getinfo')) {
    function tracked_curl_getinfo($handle, $option = null) {
        return CurlWrapper::curl_getinfo($handle, $option);
    }
}

if (!function_exists('tracked_curl_close')) {
    function tracked_curl_close($handle): void {
        CurlWrapper::curl_close($handle);
    }
}

if (!function_exists('tracked_curl_error')) {
    function tracked_curl_error($handle): string {
        return CurlWrapper::curl_error($handle);
    }
}

if (!function_exists('tracked_curl_errno')) {
    function tracked_curl_errno($handle): int {
        return CurlWrapper::curl_errno($handle);
    }
}