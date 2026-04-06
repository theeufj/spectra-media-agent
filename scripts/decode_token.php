<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
$config = require __DIR__ . '/../config/microsoftads.php';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'refresh_token' => $config['refresh_token'],
        'grant_type' => 'refresh_token',
        'scope' => 'https://ads.microsoft.com/msads.manage offline_access',
    ]),
    CURLOPT_RETURNTRANSFER => true,
]);
$response = json_decode(curl_exec($ch), true);

if (!isset($response['access_token'])) {
    echo "Token refresh failed:\n";
    print_r($response);
    exit(1);
}

// Decode JWT payload
$parts = explode('.', $response['access_token']);
if (count($parts) === 3) {
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    echo "=== Token Identity Info ===\n";
    echo "Issuer: " . ($payload['iss'] ?? 'N/A') . "\n";
    echo "Tenant ID: " . ($payload['tid'] ?? 'N/A') . "\n";
    echo "Audience: " . ($payload['aud'] ?? 'N/A') . "\n";
    echo "Identity Provider: " . ($payload['idp'] ?? 'N/A') . "\n";
    echo "Subject: " . ($payload['sub'] ?? 'N/A') . "\n";
    echo "UPN: " . ($payload['upn'] ?? 'N/A') . "\n";
    echo "Email: " . ($payload['email'] ?? 'N/A') . "\n";
    echo "Name: " . ($payload['name'] ?? 'N/A') . "\n";
    echo "OID: " . ($payload['oid'] ?? 'N/A') . "\n";
    echo "\nAll keys: " . implode(', ', array_keys($payload)) . "\n";
} else {
    echo "Token is not a JWT (opaque token, " . strlen($response['access_token']) . " chars)\n";
    echo "First 50 chars: " . substr($response['access_token'], 0, 50) . "\n";
}
