# Microsoft Ads - Azure Service Principal Setup

## Step 1: Create the Microsoft Advertising Service Principal

Open [Azure Cloud Shell](https://portal.azure.com) and run:

```bash
az ad sp create --id d42ffc93-c136-491d-b4fd-6f18168c68fd
```

## Step 2: Grant Admin Consent

Open this URL in your browser:

```
https://login.microsoftonline.com/2805471f-0c3f-4262-b01d-a89083ef3008/v2.0/adminconsent?client_id=56b6495e-93a0-4ad4-88de-81c8d7910115&state=12345&scope=d42ffc93-c136-491d-b4fd-6f18168c68fd/msads.manage
```

Sign in and approve.

## Step 3: Regenerate Token

```bash
php scripts/generate_microsoft_ads_token.php
```

## Step 4: Test API Connection

```bash
php artisan microsoftads:test
```
