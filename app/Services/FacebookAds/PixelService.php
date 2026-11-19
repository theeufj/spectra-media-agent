<?php

namespace App\Services\FacebookAds;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
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

        if (! $this->customer->facebook_ads_account_id) {
            return null;
        }

        $accountId = preg_replace('/^act_/', '', $this->customer->facebook_ads_account_id);
        $response = $this->get("/act_{$accountId}/adspixels", [
            'fields' => 'id,name,creation_time',
            'limit' => 100,
        ]);

        if (! is_array($response['data'] ?? null)) {
            return null;
        }

        $pixel = collect($response['data'])->first(
            fn ($item) => ctype_digit((string) ($item['id'] ?? '')) && ! str_starts_with($item['name'] ?? '', BusinessManagerService::REVIEW_PIXEL_PREFIX),
        );
        if (! $pixel) {
            Log::info('PixelService: No existing pixel found, will create one', [
                'customer_id' => $this->customer->id,
            ]);

            return isset($response['paging']['next']) ? null : $this->createPixel();
        }

        $pixelId = $pixel['id'];
        $this->customer->update(['facebook_pixel_id' => $pixelId]);

        Log::info('PixelService: Resolved pixel ID from ad account', [
            'customer_id' => $this->customer->id,
            'pixel_id' => $pixelId,
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
        $businessId = (string) config('services.facebook.business_manager_id');
        $accountId = preg_replace('/^act_/', '', (string) $this->customer->facebook_ads_account_id);

        if (! ctype_digit($businessId) || ! ctype_digit($accountId ?? '') || ! $this->customer->uuid) {
            return null;
        }

        return Cache::lock('meta-workspace-pixel:'.$this->customer->uuid, 60)->get(function () use ($businessId, $accountId): ?string {
            $name = 'Site to Spend workspace '.$this->customer->uuid.' dataset';
            $owned = $this->get("/{$businessId}/owned_pixels", ['fields' => 'id,name', 'limit' => 100]);
            if (! is_array($owned['data'] ?? null)) {
                return null;
            }
            $pixelId = (string) (collect($owned['data'])->firstWhere('name', $name)['id'] ?? '');
            if ($pixelId === '') {
                if (isset($owned['paging']['next'])) {
                    return null;
                }
                $pixelId = (string) ($this->post("/{$businessId}/adspixels", ['name' => $name])['id'] ?? '');
            }
            if (! ctype_digit($pixelId) || ! $this->sharePixelWithAccount($pixelId, $accountId)) {
                Log::warning('PixelService: Meta did not verify dataset sharing with the ad account', [
                    'customer_id' => $this->customer->id,
                    'pixel_id' => $pixelId,
                    'account_id' => $accountId,
                ]);

                return null;
            }
            $this->customer->update(['facebook_pixel_id' => $pixelId]);

            return $pixelId;
        }) ?: null;
    }

    /**
     * Share a BM-owned pixel with a specific ad account.
     */
    public function sharePixelWithAccount(string $pixelId, string $accountId): bool
    {
        $businessId = config('services.facebook.business_manager_id');
        if (! $businessId) {
            return false;
        }

        $response = $this->post("/{$pixelId}/shared_accounts", [
            'business' => $businessId,
            'account_id' => $accountId,
        ]);

        return ! empty($response['success']) && $this->isLinked($pixelId, $accountId);
    }

    public function isLinked(string $pixelId, string $accountId): bool
    {
        $accountId = preg_replace('/^act_/', '', $accountId);
        if (! ctype_digit($pixelId) || ! ctype_digit($accountId ?? '')) {
            return false;
        }

        $response = $this->get("/act_{$accountId}/adspixels", ['fields' => 'id', 'limit' => 100]);

        return collect($response['data'] ?? [])->contains(fn ($row) => (string) ($row['id'] ?? '') === $pixelId);
    }
}
