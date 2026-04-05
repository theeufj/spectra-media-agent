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
    'scope' => 'https://www.googleapis.com/auth/adwords',
]);

$authUrl = $oauth2->buildFullAuthorizationUri(['access_type' => 'offline', 'prompt' => 'consent']);

printf("Log into the Google account you want to use to manage your ads.\n");
printf("Opening browser...\n\n");

// Try to open the URL in the default browser
$url = (string) $authUrl;
if (PHP_OS_FAMILY === 'Darwin') {
    exec('open ' . escapeshellarg($url));
} elseif (PHP_OS_FAMILY === 'Linux') {
    exec('xdg-open ' . escapeshellarg($url));
} else {
    printf("Paste the following URL into your browser:\n%s\n\n", $url);
}

// Start a temporary local server to capture the OAuth callback
printf("Waiting for Google OAuth callback on %s ...\n", $redirectUri);

$server = stream_socket_server('tcp://127.0.0.1:8088', $errno, $errstr);
if (!$server) {
    die("Error: Could not start local server: $errstr ($errno)\n");
}

$conn = stream_socket_accept($server, 120); // wait up to 2 minutes
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
    printf("\n✅ Refresh token: %s\n\n", $authToken['refresh_token']);
    printf("Save this to:\n");
    printf("  1. storage/app/google_ads_php.ini (refreshToken field) for local testing\n");
    printf("  2. mcc_accounts table (refresh_token column, encrypted) for production\n");
    printf("  3. Or set GOOGLE_ADS_MCC_REFRESH_TOKEN in .env\n");
} else {
    printf("\nError: Could not retrieve refresh token.\n");
    printf("Response: %s\n", json_encode($authToken));
}
