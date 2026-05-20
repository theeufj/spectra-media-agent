<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class YouTubeExchangeCode extends Command
{
    protected $signature = 'youtube:exchange-code {code}';
    protected $description = 'Exchange a Google OAuth authorization code for a YouTube refresh token';

    public function handle(): int
    {
        $code         = $this->argument('code');
        $clientId     = config('services.youtube.client_id');
        $clientSecret = config('services.youtube.client_secret');
        $redirectUri  = 'https://sitetospend.com/youtube/auth/callback';

        $this->info("Exchanging authorization code for tokens...");

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if (!$response->successful()) {
            $this->error("Token exchange failed: " . $response->body());
            return self::FAILURE;
        }

        $data         = $response->json();
        $refreshToken = $data['refresh_token'] ?? null;

        if (!$refreshToken) {
            $this->error("No refresh_token in response. Visit /youtube/auth again to get a fresh code.");
            return self::FAILURE;
        }

        // Write to .env
        $envPath    = base_path('.env');
        $envContent = file_get_contents($envPath);

        if (str_contains($envContent, 'GOOGLE_YOUTUBE_REFRESH_TOKEN=')) {
            $envContent = preg_replace('/^GOOGLE_YOUTUBE_REFRESH_TOKEN=.*/m', "GOOGLE_YOUTUBE_REFRESH_TOKEN={$refreshToken}", $envContent);
        } else {
            $envContent .= "\nGOOGLE_YOUTUBE_REFRESH_TOKEN={$refreshToken}\n";
        }

        file_put_contents($envPath, $envContent);

        $this->info("Refresh token saved to .env:");
        $this->line($refreshToken);
        $this->newLine();
        $this->info("Run: php artisan config:clear");
        $this->info("Then: php artisan pmax:repair-assets --strategy=730");

        return self::SUCCESS;
    }
}
