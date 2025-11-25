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

$oauth2 = new OAuth2([
    'clientId' => $clientId,
    'clientSecret' => $clientSecret,
    'authorizationUri' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'redirectUri' => 'urn:ietf:wg:oauth:2.0:oob',
    'tokenCredentialUri' => 'https://oauth2.googleapis.com/token',
    'scope' => 'https://www.googleapis.com/auth/adwords'
]);

printf("Log into the Google account you want to use to manage your ads.\n");
printf("Paste the following URL into your browser:\n%s\n\n", $oauth2->buildFullAuthorizationUri());
printf("Retrieve the authorization code and paste it here:\n");

$code = trim(fgets(STDIN));

$oauth2->setCode($code);
$authToken = $oauth2->fetchAuthToken();

if (isset($authToken['refresh_token'])) {
    printf("\nRefresh token: %s\n", $authToken['refresh_token']);
    printf("Copy this refresh token and save it to your Customer record in the database (google_ads_refresh_token column).\n");
    printf("You can also add it to google_ads_php.ini for testing, but the application expects it in the database.\n");
} else {
    printf("\nError: Could not retrieve refresh token. Response:\n");
    print_r($authToken);
}
