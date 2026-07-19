<?php

/**
 * Copyright 2018 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/*
 * This script is a simplified version of the GenerateUserCredentials.php example
 * from the Google Ads PHP Client Library.
 */

require __DIR__ . '/../vendor/autoload.php';

use Google\Auth\Credentials\UserRefreshCredentials;
use Google\Auth\OAuth2;

$iniPath = __DIR__ . '/../storage/app/google_ads_php.ini';
$ini = parse_ini_file($iniPath, true);

if (!$ini || !isset($ini['OAUTH2'])) {
    die("Error: Could not parse google_ads_php.ini or OAUTH2 section missing.\n");
}

$clientId = $ini['OAUTH2']['clientId'];
$clientSecret = $ini['OAUTH2']['clientSecret'];

if ($clientId === 'INSERT_CLIENT_ID_HERE' || $clientSecret === 'INSERT_CLIENT_SECRET_HERE') {
    die("Error: Please update spectra/storage/app/google_ads_php.ini with your Client ID and Client Secret.\n");
}

$redirectUri = 'http://localhost:8088';

$oauth2 = new OAuth2([
    'clientId' => $clientId,
    'clientSecret' => $clientSecret,
    'authorizationUri' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'redirectUri' => $redirectUri,
    'tokenCredentialUri' => 'https://oauth2.googleapis.com/token',
    // adwords: Google Ads API. datamanager: Data Manager API (events:ingest) for
    // server-side / offline conversions. Both are needed on the one MCC token.
    'scope' => 'https://www.googleapis.com/auth/adwords https://www.googleapis.com/auth/datamanager',
]);

$authUrl = $oauth2->buildFullAuthorizationUri(['access_type' => 'offline', 'prompt' => 'consent']);

// Print the URL rather than auto-opening — auto-open lands in whatever profile
// is default, which is often the wrong Google account. Paste it into the browser
// profile that is signed into the MCC account.
$url = (string) $authUrl;
printf("Open this URL in the browser profile signed into your MCC Google account:\n\n");
printf("%s\n\n", $url);

// Start a temporary local server to capture the OAuth callback
printf("Waiting for Google OAuth callback on %s (up to 5 minutes) ...\n", $redirectUri);

$server = stream_socket_server('tcp://127.0.0.1:8088', $errno, $errstr);
if (!$server) {
    die("Error: Could not start local server: $errstr ($errno)\n");
}

$conn = stream_socket_accept($server, 300); // wait up to 5 minutes
if (!$conn) {
    fclose($server);
    die("Error: Timed out waiting for OAuth callback.\n");
}

$request = fread($conn, 4096);

// Send a nice response to the browser
$html = '<html><body style="font-family:sans-serif;text-align:center;padding:60px;">'
    . '<h2>✅ Authorization received!</h2>'
    . '<p>You can close this tab and return to your terminal.</p>'
    . '</body></html>';
$response = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: " . strlen($html) . "\r\n\r\n" . $html;
fwrite($conn, $response);
fclose($conn);
fclose($server);

// Extract the authorization code from the GET request
if (preg_match('/[?&]code=([^\s&]+)/', $request, $matches)) {
    $code = urldecode($matches[1]);
} else {
    die("Error: Could not extract authorization code from callback.\nRequest: $request\n");
}

printf("Authorization code received. Exchanging for tokens...\n");

$oauth2->setCode($code);
$authToken = $oauth2->fetchAuthToken();

if (isset($authToken['refresh_token'])) {
    $token   = $authToken['refresh_token'];
    $outPath = __DIR__ . '/../storage/app/mcc_refresh_token.new';
    file_put_contents($outPath, $token);
    @chmod($outPath, 0600);

    // Never print the full token — it is a long-lived credential. Show a masked
    // preview so you can confirm it minted, and write the value to a 0600 file.
    $masked = substr($token, 0, 8) . str_repeat('•', 12) . substr($token, -4);
    printf("\n✅ Refresh token minted (adwords + datamanager scopes): %s\n", $masked);
    printf("   Written to: storage/app/mcc_refresh_token.new (chmod 600)\n\n");
    printf("Next: put this value in the SERVER .env as GOOGLE_ADS_MCC_REFRESH_TOKEN,\n");
    printf("then run `php artisan config:cache`. MccAccount::getActive() reads it from\n");
    printf("env, so the new scope applies to both the Ads API and Data Manager.\n");
} else {
    printf("\nError: Could not retrieve refresh token.\n");
    printf("Response: %s\n", json_encode($authToken));
}
