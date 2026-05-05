<?php
/**
 * Microsoft Advertising OAuth2 Refresh Token Generator
 *
 * Prerequisites:
 * 1. Azure AD app must support "Accounts in any organizational directory and personal Microsoft accounts"
 * 2. Redirect URI http://localhost:8888/callback must be added under Authentication > Web
 * 3. MICROSOFT_ADS_CLIENT_ID and MICROSOFT_ADS_CLIENT_SECRET must be set in .env
 *
 * Usage: php scripts/generate_microsoft_ads_token.php
 */

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$clientId = $_ENV['MICROSOFT_ADS_CLIENT_ID'] ?? '';
$clientSecret = $_ENV['MICROSOFT_ADS_CLIENT_SECRET'] ?? '';

if (!$clientId || !$clientSecret) {
    echo "ERROR: MICROSOFT_ADS_CLIENT_ID and MICROSOFT_ADS_CLIENT_SECRET must be set in .env\n";
    exit(1);
}

$redirectUri = 'https://localhost/';
$scope = 'https://ads.microsoft.com/msads.manage offline_access';

// Use tenant-specific endpoint for work/school account
$tenantId = 'common';

// Step 1: Generate authorization URL
$authUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize?" . http_build_query([
    'client_id' => $clientId,
    'response_type' => 'code',
    'redirect_uri' => $redirectUri,
    'scope' => $scope,
    'response_mode' => 'query',
    'prompt' => 'login',
]);

echo "\n=== Microsoft Advertising OAuth2 Token Generator ===\n\n";
echo "Step 1: Open this URL in your browser:\n\n";
echo $authUrl . "\n\n";
echo "Step 2: Sign in with your Microsoft Advertising account (josh@sitetospend.com)\n";
echo "Step 3: After granting access, you'll be redirected to a URL starting with https://localhost/?code=\n\n";
echo "Please paste the full URL you are redirected to here:\n";

$stdin = fopen('php://stdin', 'r');
$redirectedUrl = trim(fgets($stdin));

preg_match('/code=([^\s&]+)/', $redirectedUrl, $matches);
$code = $matches[1] ?? null;

if (!$code) {
    echo "ERROR: No authorization code found in the URL.\n";
    exit(1);
}

$code = urldecode($code);
echo "Authorization code received!\n\n";

// Step 3: Exchange code for tokens
echo "Exchanging code for tokens...\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
        'scope' => $scope,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);

if ($httpCode !== 200 || !isset($data['refresh_token'])) {
    echo "ERROR: Token exchange failed (HTTP $httpCode)\n";
    echo "Response: $response\n";
    exit(1);
}

$refreshToken = $data['refresh_token'];
$accessToken = $data['access_token'] ?? '';

echo "\n=== SUCCESS ===\n\n";
echo "Refresh Token:\n$refreshToken\n\n";
echo "Add this to your .env:\n";
echo "MICROSOFT_ADS_REFRESH_TOKEN=$refreshToken\n\n";

// Ask to auto-update .env
echo "Auto-update .env? (y/n): ";
$stdin = fopen('php://stdin', 'r');
$answer = trim(fgets($stdin));

if (strtolower($answer) === 'y') {
    $envFile = __DIR__ . '/../.env';
    $envContent = file_get_contents($envFile);
    $envContent = preg_replace(
        '/^MICROSOFT_ADS_REFRESH_TOKEN=.*$/m',
        'MICROSOFT_ADS_REFRESH_TOKEN=' . $refreshToken,
        $envContent
    );
    file_put_contents($envFile, $envContent);
    echo "\n.env updated successfully!\n";
} else {
    echo "\nSkipped. Remember to update .env manually.\n";
}

// Verify by fetching account info
echo "\nVerifying token by fetching account info...\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://clientcenter.api.bingads.microsoft.com/Api/v13/CustomerManagement/GetUser',
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['UserId' => null]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
        'DeveloperToken: ' . ($_ENV['MICROSOFT_ADS_DEVELOPER_TOKEN'] ?? ''),
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    echo "Token verified - API connection successful!\n";
} else {
    echo "Note: Verification returned HTTP $httpCode (token may still be valid, API requires additional headers)\n";
}

echo "\nDone!\n";
