#!/usr/bin/env php
<?php

echo "=== cURL Metrics Tracker Compatibility Check ===\n\n";

// Check PHP version
$phpVersion = PHP_VERSION;
$phpOk = version_compare($phpVersion, '7.4.0', '>=');
echo "PHP Version: $phpVersion " . ($phpOk ? '✅' : '❌ (requires 7.4+)') . "\n";

// Check cURL extension
$curlOk = extension_loaded('curl');
echo "cURL Extension: " . ($curlOk ? '✅ Available' : '❌ Missing') . "\n";

// Check hooking extension
$uopzOk = extension_loaded('uopz');

echo "uopz Extension: " . ($uopzOk ? '✅ Available (required for zero-code hooking)' : '❌ Not available') . "\n";

echo "\n📋 Recommendations:\n";

if (!$phpOk) {
    echo "⚠️  Upgrade PHP to 7.4+ for optimal compatibility\n";
}

if (!$curlOk) {
    echo "⚠️  Install cURL extension: sudo apt-get install php-curl\n";
}

if (!$uopzOk) {
    echo "💡 For zero-code-change hooking, install uopz: sudo pecl install uopz\n";
    echo "   Then add 'extension=uopz.so' to your php.ini\n";
    echo "   Alternatively, use CurlWrapper approach (no extensions required)\n";
} else {
    echo "🚀 Perfect! You can use zero-code-change automatic hooking with CurlHook\n";
}

echo "\n🎯 Recommended Approaches:\n";

if ($uopzOk) {
    echo "\n1. Automatic Hooking (Zero Code Changes) ✅\n";
    echo "CurlTracker\\CurlHook::init(\$config)->enable();\n";
    echo "// All existing curl_* calls are automatically tracked!\n";
}

if (!$uopzOk) {
    echo "\n1. Wrapper Functions (Works everywhere) ✅\n";
    echo "CurlTracker\\CurlWrapper::init(\$config);\n";
    echo "// Replace curl_* with CurlWrapper::curl_*\n";
} else {
    echo "\n2. Wrapper Functions (Works everywhere) ✅\n";
    echo "CurlTracker\\CurlWrapper::init(\$config);\n";
    echo "// Replace curl_* with CurlWrapper::curl_*\n";

    echo "\n3. Helper Functions (Easy migration) ✅\n";
    echo "init_curl_metrics(\$config);\n";
    echo "// Replace curl_* with tracked_curl_*\n";
}

echo "\n☁️  AWS Setup:\n";
echo "Make sure you have AWS credentials configured and CloudWatch permissions.\n";

echo "\n🎉 Setup complete! Check README.md for detailed examples.\n";
