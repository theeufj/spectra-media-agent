<?php

namespace App\Services\Conversions;

use App\Models\Customer;
use App\Services\Crawling\PublicWebsiteFetcher;
use Illuminate\Support\Carbon;

class AdvertiserOutcomeOrigin
{
    public function file(Customer $customer, string $origin): array
    {
        $token = hash_hmac('sha256', 'sitetospend-outcomes|'.$customer->uuid.'|'.$origin, app('encrypter')->getKey());

        return ['url' => $origin.'/.well-known/sitetospend-'.$customer->uuid.'.txt', 'contents' => 'sitetospend-site-verification='.$token];
    }

    public function verified(Customer $customer, string $origin): bool
    {
        return $customer->getAttribute('verified_outcome_origin') === hash('sha256', $this->file($customer, $origin)['contents'])
            && $customer->getAttribute('outcome_origin_verified_at') && Carbon::parse($customer->getAttribute('outcome_origin_verified_at'))->gt(now()->subDays(30));
    }

    public function verify(Customer $customer, string $origin): bool
    {
        if ($this->verified($customer, $origin)) {
            return true;
        }
        $file = $this->file($customer, $origin);
        try {
            $response = app(PublicWebsiteFetcher::class)->getWithoutRedirects($file['url']);
        } catch (\Throwable $failure) {
            report($failure);

            return false;
        }
        if ($response->status() !== 200 || ! hash_equals($file['contents'], trim($response->body()))) {
            return false;
        }
        $customer->forceFill(['verified_outcome_origin' => hash('sha256', $file['contents']), 'outcome_origin_verified_at' => now()])->save();

        return true;
    }
}
