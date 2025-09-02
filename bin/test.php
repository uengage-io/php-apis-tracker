<?php

require_once 'vendor/autoload.php';

use CurlTracker\CurlHook;

// Initialize once at application startup
CurlHook::init([
    'aws_region' => 'ap-south-1',
    'namespace' => 'uEngage/APIs',
    'debug' => true
])->enable();

// Your existing code works unchanged!
$ch = curl_init('https://google.com');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$result = curl_exec($ch);
echo $result;
curl_close($ch);
