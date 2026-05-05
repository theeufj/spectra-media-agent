<?php

namespace App\Services\FacebookAds;

use App\Models\Customer;
use Illuminate\Support\Facades\Log;

class PixelService extends BaseFacebookAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Look up the pixel ID associated with this customer's ad account.
     * Stores it on the customer record and returns it.
     */
    public function resolvePixelId(): ?string
    {
        if ($this->customer->facebook_pixel_id) {
            return $this->customer->facebook_pixel_id;
        }

        if (!$this->customer->facebook_ads_account_id) {
            return null;
        }

        $accountId = $this->customer->facebook_ads_account_id;
        $response  = $this->get("/act_{$accountId}/adspixels", [
            'fields' => 'id,name,creation_time',
            'limit'  => 5,
        ]);

        if (!$response || empty($response['data'])) {
            Log::info('PixelService: No existing pixel found, will create one', [
                'customer_id' => $this->customer->id,
            ]);
            return $this->createPixel();
        }

        $pixelId = $response['data'][0]['id'];
        $this->customer->update(['facebook_pixel_id' => $pixelId]);

        Log::info('PixelService: Resolved pixel ID from ad account', [
            'customer_id' => $this->customer->id,
            'pixel_id'    => $pixelId,
        ]);

        return $pixelId;
    }

    /**
     * Create a new Meta Pixel under the customer's ad account.
     */
    public function createPixel(): ?string
    {
        if (!$this->customer->facebook_ads_account_id) {
            return null;
        }

        $accountId = $this->customer->facebook_ads_account_id;
        $response  = $this->post("/act_{$accountId}/adspixels", [
            'name' => 'Spectra — ' . $this->customer->name,
        ]);

        if (!$response || empty($response['id'])) {
            Log::error('PixelService: Failed to create pixel', [
                'customer_id' => $this->customer->id,
                'response'    => $response,
            ]);
            return null;
        }

        $pixelId = $response['id'];
        $this->customer->update(['facebook_pixel_id' => $pixelId]);

        Log::info('PixelService: Created new pixel', [
            'customer_id' => $this->customer->id,
            'pixel_id'    => $pixelId,
        ]);

        return $pixelId;
    }

    /**
     * Share a pixel from the Business Manager with a specific ad account.
     * Required if the pixel was created at BM level, not account level.
     */
    public function sharePixelWithAccount(string $pixelId, string $accountId): bool
    {
        $businessId = config('services.facebook.business_id');
        if (!$businessId) {
            return false;
        }

        $response = $this->post("/{$pixelId}/shared_accounts", [
            'business' => $businessId,
            'account_id' => $accountId,
        ]);

        return !empty($response['success']);
    }
}
