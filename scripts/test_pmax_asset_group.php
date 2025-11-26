<?php

use App\Models\Customer;
use App\Services\GoogleAds\PerformanceMaxServices\CreateAssetGroup;
use Illuminate\Support\Facades\Log;

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$customerId = '6387517170';
$campaignResourceName = 'customers/6387517170/campaigns/23306150791';

// Find a customer to pass to the service
$customer = Customer::where('google_ads_customer_id', $customerId)->first();
if (!$customer) {
    $customer = Customer::first(); // Fallback
}

echo "Using Customer ID: $customerId\n";
echo "Using Campaign: $campaignResourceName\n";

try {
    $service = new CreateAssetGroup($customer, true);

    $timestamp = now()->format('Ymd_His');
    $assetGroupName = 'Test Asset Group ' . $timestamp;
    $finalUrls = ['https://www.example.com'];

    echo "Creating Asset Group: $assetGroupName\n";

    $assetGroupResourceName = $service($customerId, $campaignResourceName, $assetGroupName, $finalUrls);

    if ($assetGroupResourceName) {
        echo "Asset Group created successfully!\n";
        echo "Resource Name: $assetGroupResourceName\n";
    } else {
        echo "Failed to create Asset Group (returned null).\n";
    }

} catch (\Exception $e) {
    echo "Error creating Asset Group: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
