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
     * Create a new Meta Pixel under Spectra's Business Manager, then share it
     * with the customer's ad account. Spectra owns the pixel — not the client —
     * so we retain control and it can be reused across accounts if needed.
     */
    public function createPixel(): ?string
    {
        $businessId = config('services.facebook.business_manager_id');

        if (!$businessId) {
            Log::error('PixelService: FACEBOOK_BUSINESS_MANAGER_ID not configured — cannot create BM-owned pixel', [
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }

        // Create pixel at BM level so Spectra owns it
        $response = $this->post("/{$businessId}/adspixels", [
            'name' => 'Spectra — ' . $this->customer->name,
        ]);

        if (!$response || empty($response['id'])) {
            Log::error('PixelService: Failed to create BM-level pixel', [
                'customer_id' => $this->customer->id,
                'response'    => $response,
            ]);
            return null;
        }

        $pixelId   = $response['id'];
        $accountId = $this->customer->facebook_ads_account_id;

        // Share with the customer's ad account so their campaigns can use it
        if ($accountId) {
            $shared = $this->sharePixelWithAccount($pixelId, $accountId);
            if (!$shared) {
                Log::warning('PixelService: Pixel created but could not be shared with ad account', [
                    'customer_id' => $this->customer->id,
                    'pixel_id'    => $pixelId,
                    'account_id'  => $accountId,
                ]);
            }
        }

        $this->customer->update(['facebook_pixel_id' => $pixelId]);

        Log::info('PixelService: Created BM-owned pixel and shared with ad account', [
            'customer_id' => $this->customer->id,
            'pixel_id'    => $pixelId,
            'account_id'  => $accountId,
        ]);

        return $pixelId;
    }

    /**
     * Share a BM-owned pixel with a specific ad account.
     */
    public function sharePixelWithAccount(string $pixelId, string $accountId): bool
    {
        $businessId = config('services.facebook.business_manager_id');
        if (!$businessId) {
            return false;
        }

        $response = $this->post("/{$pixelId}/shared_accounts", [
            'business'   => $businessId,
            'account_id' => $accountId,
        ]);

        return !empty($response['success']);
    }
}
