<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProvisionSpectraFacebookPixel extends Command
{
    protected $signature   = 'facebook:provision-spectra-pixel';
    protected $description = 'Create a Meta Pixel for sitetospend.com under the Spectra Business Manager and output the ID to set in env.';

    public function handle(): int
    {
        $businessId  = config('services.facebook.business_manager_id');
        $accessToken = config('services.facebook.system_user_token');
        $apiVersion  = 'v22.0';

        if (!$businessId || !$accessToken) {
            $this->error('FACEBOOK_BUSINESS_MANAGER_ID and FACEBOOK_SYSTEM_USER_TOKEN must both be set in .env');
            return 1;
        }

        // Check if already set — nothing to do
        $existing = config('services.facebook.spectra_pixel_id');
        if ($existing) {
            $this->info("FACEBOOK_SPECTRA_PIXEL_ID is already set: {$existing}");
            $this->line('If you want to create a new one anyway, unset the env var first.');
            return 0;
        }

        $this->info("Creating Meta Pixel under Business Manager {$businessId}...");

        $response = Http::withToken($accessToken)
            ->post("https://graph.facebook.com/{$apiVersion}/{$businessId}/adspixels", [
                'name' => 'Spectra — sitetospend.com',
            ]);

        if (!$response->successful() || empty($response->json('id'))) {
            $error = $response->json('error.message') ?? $response->body();
            $this->error("Failed to create pixel: {$error}");
            Log::error('ProvisionSpectraFacebookPixel: ' . $error);
            return 1;
        }

        $pixelId = $response->json('id');

        $this->newLine();
        $this->info("Pixel created successfully.");
        $this->newLine();
        $this->line("  Pixel ID : <comment>{$pixelId}</comment>");
        $this->line("  Pixel name : Spectra — sitetospend.com");
        $this->newLine();
        $this->warn("Next steps:");
        $this->line("  1. In Laravel Forge, add this environment variable:");
        $this->line("       <comment>FACEBOOK_SPECTRA_PIXEL_ID={$pixelId}</comment>");
        $this->line("  2. Redeploy or run: php artisan config:cache");
        $this->line("  3. The pixel fires via CAPI on every new signup from a Facebook ad.");
        $this->newLine();
        $this->line("  You can also verify it in Meta Business Manager:");
        $this->line("  Events Manager → Data Sources → Spectra — sitetospend.com");

        return 0;
    }
}
