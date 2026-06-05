<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Finds and fixes Google Tag (google_tag type) entries in the platform's own
 * GTM container that have invalid measurement IDs:
 *
 *   - Bare numeric IDs (e.g. "16797144138") → prepend "AW-"
 *   - Bare alphanumeric IDs (e.g. "K6YKBRF4VG") → prepend "G-"
 *
 * If GTM_PLATFORM_REFRESH_TOKEN is not set, the command walks through an
 * interactive OAuth flow in the terminal to obtain one.
 *
 * Usage:
 *   php artisan gtm:fix-platform-tags              # dry-run
 *   php artisan gtm:fix-platform-tags --apply      # apply fixes
 *   php artisan gtm:fix-platform-tags --apply --publish  # apply + publish live
 */
class FixPlatformGtmTags extends Command
{
    protected $signature = 'gtm:fix-platform-tags
                            {--container=GTM-KHFLQZ8S : Public container ID to target}
                            {--apply   : Write the fixes (default is dry-run)}
                            {--publish : Publish the workspace after applying fixes}';

    protected $description = 'Fix invalid google_tag measurement IDs in the platform GTM container';

    private string $baseUrl = 'https://tagmanager.googleapis.com/tagmanager/v2';
    private ?string $accessToken = null;

    private const GTM_SCOPES = [
        'https://www.googleapis.com/auth/tagmanager.edit.containers',
        'https://www.googleapis.com/auth/tagmanager.edit.containerversions',
        'https://www.googleapis.com/auth/tagmanager.publish',
        'https://www.googleapis.com/auth/tagmanager.readonly',
    ];

    public function handle(): int
    {
        $targetContainerId = $this->option('container');
        $apply   = $this->option('apply');
        $publish = $this->option('publish');

        if (!$apply) {
            $this->warn('DRY RUN — pass --apply to write changes');
            $this->newLine();
        }

        // ── Authenticate ────────────────────────────────────────────────────
        [$clientId, $clientSecret] = $this->resolveOAuthClient();
        if (!$clientId || !$clientSecret) {
            $this->error('Cannot find OAuth client credentials. Set GOOGLE_OAUTH_CLIENT_ID and GOOGLE_OAUTH_CLIENT_SECRET in .env');
            return self::FAILURE;
        }

        $refreshToken = config('services.gtm.platform_refresh_token');

        if (!$refreshToken) {
            $this->warn('GTM_PLATFORM_REFRESH_TOKEN is not set — starting OAuth flow...');
            $this->newLine();
            $refreshToken = $this->runOAuthFlow($clientId, $clientSecret);
            if (!$refreshToken) {
                return self::FAILURE;
            }
        }

        $this->accessToken = $this->exchangeRefreshToken($clientId, $clientSecret, $refreshToken);
        if (!$this->accessToken) {
            $this->error('Failed to obtain access token. The refresh token may be invalid or expired.');
            return self::FAILURE;
        }
        $this->info('Authenticated with GTM API');

        // ── Discover GTM account ID if not configured ────────────────────────
        $accountId = config('services.gtm.platform_account_id');
        if (!$accountId) {
            $accountId = $this->discoverAccountId($targetContainerId);
            if (!$accountId) {
                return self::FAILURE;
            }
            $this->warn("Tip: set GTM_PLATFORM_ACCOUNT_ID={$accountId} in .env to skip this step next time.");
            $this->newLine();
        }

        // ── Locate the container ─────────────────────────────────────────────
        $containerPath = $this->findContainerPath($accountId, $targetContainerId);
        if (!$containerPath) {
            $this->error("Container {$targetContainerId} not found under account {$accountId}");
            return self::FAILURE;
        }
        $this->info("Container: {$targetContainerId} ({$containerPath})");

        // ── Get default workspace ────────────────────────────────────────────
        $workspacePath = $this->getDefaultWorkspacePath($containerPath);
        if (!$workspacePath) {
            $this->error('Could not find a workspace in the container');
            return self::FAILURE;
        }
        $this->info("Workspace: {$workspacePath}");
        $this->newLine();

        // ── List tags and detect broken google_tag entries ───────────────────
        $tags = $this->listTags($workspacePath);
        if ($tags === null) {
            return self::FAILURE;
        }

        $this->info(count($tags) . ' tags in workspace');

        $fixes = [];
        foreach ($tags as $tag) {
            if (!in_array($tag['type'] ?? '', ['google_tag', 'googtag'])) {
                continue;
            }
            $currentId = $this->getMeasurementId($tag);
            if ($currentId === null || $this->isValidMeasurementId($currentId)) {
                continue;
            }
            $fixes[] = [
                'tag'      => $tag,
                'tag_name' => $tag['name'],
                'tag_id'   => $tag['tagId'],
                'current'  => $currentId,
                'fixed'    => $this->fixMeasurementId($currentId),
            ];
        }

        if (empty($fixes)) {
            $this->info('No broken google_tag measurement IDs found — nothing to fix');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn(count($fixes) . ' tag(s) need fixing:');
        $this->table(
            ['Tag Name', 'Tag ID', 'Current ID', 'Fixed ID'],
            array_map(fn($f) => [$f['tag_name'], $f['tag_id'], $f['current'], $f['fixed']], $fixes)
        );

        if (!$apply) {
            $this->newLine();
            $this->line('Run with --apply to write these changes.');
            return self::SUCCESS;
        }

        // ── Apply fixes ──────────────────────────────────────────────────────
        $allOk = true;
        foreach ($fixes as $fix) {
            if ($this->updateTagMeasurementId($workspacePath, $fix['tag'], $fix['fixed'])) {
                $this->info("Fixed \"{$fix['tag_name']}\": {$fix['current']} → {$fix['fixed']}");
            } else {
                $this->error("Failed to fix \"{$fix['tag_name']}\"");
                $allOk = false;
            }
        }

        // ── Optionally publish ───────────────────────────────────────────────
        if ($publish && $allOk) {
            $this->newLine();
            $this->info('Publishing container...');
            if ($this->publishWorkspace($workspacePath)) {
                $this->info('Container published — changes are now live');
            } else {
                $this->error('Publish failed — fixes are saved in the workspace but not live yet');
                return self::FAILURE;
            }
        } elseif ($allOk) {
            $this->newLine();
            $this->warn('Changes saved to workspace. Run with --publish to go live, or publish manually in the GTM UI.');
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    // ── OAuth helpers ─────────────────────────────────────────────────────────

    /**
     * Resolve OAuth client credentials: prefer env vars, fall back to google_ads_php.ini.
     */
    private function resolveOAuthClient(): array
    {
        $clientId     = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');

        if (!$clientId || !$clientSecret) {
            $ini          = @parse_ini_file(storage_path('app/google_ads_php.ini'), true) ?: [];
            $clientId     = $ini['OAUTH2']['clientId'] ?? null;
            $clientSecret = $ini['OAUTH2']['clientSecret'] ?? null;
        }

        return [$clientId, $clientSecret];
    }

    /**
     * Run an interactive terminal OAuth flow and return a refresh token.
     */
    private function runOAuthFlow(string $clientId, string $clientSecret): ?string
    {
        $scopes   = implode(' ', self::GTM_SCOPES);
        $redirect = 'http://localhost:8088'; // registered in Google Cloud Console

        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirect,
            'response_type' => 'code',
            'scope'         => $scopes,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ]);

        $this->line('1. Open this URL in your browser and sign in with the Google account that owns GTM-KHFLQZ8S:');
        $this->newLine();
        $this->line($authUrl);
        $this->newLine();
        $this->line('2. After authorising, your browser will redirect to http://localhost:8088/?code=XXXX');
        $this->line('   The page will show "This site can\'t be reached" — that\'s expected.');
        $this->line('   Copy the "code" value from the address bar URL.');
        $this->newLine();

        $code = $this->ask('Paste the code from the address bar');
        if (!$code) {
            $this->error('No code provided');
            return null;
        }

        // Strip any trailing parameters that may have been copied (e.g. &scope=...)
        $code = preg_replace('/[&?].*/', '', trim($code));

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirect,
            'grant_type'    => 'authorization_code',
        ]);

        if (!$response->successful()) {
            $this->error('Token exchange failed: ' . ($response->json()['error_description'] ?? $response->body()));
            return null;
        }

        $refreshToken = $response->json()['refresh_token'] ?? null;
        if (!$refreshToken) {
            $this->error('No refresh token returned — the account may already have a token. Try revoking access at myaccount.google.com/permissions and re-running.');
            return null;
        }

        $this->info("Refresh token obtained.");
        $this->newLine();
        $this->line("Add to .env to skip this step next time:");
        $this->line("GTM_PLATFORM_REFRESH_TOKEN={$refreshToken}");
        $this->newLine();

        // Also cache the access token we just got
        $this->accessToken = $response->json()['access_token'] ?? null;

        return $refreshToken;
    }

    private function exchangeRefreshToken(string $clientId, string $clientSecret, string $refreshToken): ?string
    {
        // If runOAuthFlow already set the access token, reuse it
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        return $response->successful() ? ($response->json()['access_token'] ?? null) : null;
    }

    /**
     * List all GTM accounts accessible to the token and find which one
     * contains the target container.
     */
    private function discoverAccountId(string $targetContainerId): ?string
    {
        $this->info("Discovering GTM account for {$targetContainerId}...");

        $response = Http::withToken($this->accessToken)
            ->get("{$this->baseUrl}/accounts");

        if (!$response->successful()) {
            $this->error('Failed to list GTM accounts: ' . ($response->json()['error']['message'] ?? $response->body()));
            return null;
        }

        foreach ($response->json()['account'] ?? [] as $account) {
            $accountId = $account['accountId'];
            $containersResp = Http::withToken($this->accessToken)
                ->get("{$this->baseUrl}/accounts/{$accountId}/containers");

            if (!$containersResp->successful()) {
                continue;
            }

            foreach ($containersResp->json()['container'] ?? [] as $container) {
                if (($container['publicId'] ?? '') === $targetContainerId) {
                    $this->info("Found in account \"{$account['name']}\" (ID: {$accountId})");
                    return $accountId;
                }
            }
        }

        $this->error("Could not find {$targetContainerId} in any accessible GTM account");
        return null;
    }

    // ── GTM API helpers ───────────────────────────────────────────────────────

    private function findContainerPath(string $accountId, string $publicId): ?string
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->baseUrl}/accounts/{$accountId}/containers");

        if (!$response->successful()) {
            return null;
        }

        foreach ($response->json()['container'] ?? [] as $container) {
            if (($container['publicId'] ?? '') === $publicId) {
                return $container['path'];
            }
        }

        return null;
    }

    private function getDefaultWorkspacePath(string $containerPath): ?string
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->baseUrl}/{$containerPath}/workspaces");

        if (!$response->successful()) {
            return null;
        }

        $workspaces = $response->json()['workspace'] ?? [];

        foreach ($workspaces as $ws) {
            if ($ws['name'] === 'Default Workspace') {
                return $ws['path'];
            }
        }

        return !empty($workspaces) ? $workspaces[0]['path'] : null;
    }

    private function listTags(string $workspacePath): ?array
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->baseUrl}/{$workspacePath}/tags");

        if (!$response->successful()) {
            $this->error('Failed to list tags: ' . ($response->json()['error']['message'] ?? $response->body()));
            return null;
        }

        return $response->json()['tag'] ?? [];
    }

    private function getMeasurementId(array $tag): ?string
    {
        foreach ($tag['parameter'] ?? [] as $param) {
            if ($param['key'] === 'tagId') {
                return $param['value'] ?? null;
            }
        }
        return null;
    }

    private function isValidMeasurementId(string $id): bool
    {
        return (bool) preg_match('/^(G|AW)-[A-Z0-9]+$/i', $id);
    }

    private function fixMeasurementId(string $id): string
    {
        return preg_match('/^\d+$/', $id) ? 'AW-' . $id : 'G-' . $id;
    }

    private function updateTagMeasurementId(string $workspacePath, array $tag, string $fixedId): bool
    {
        $params = array_map(function ($param) use ($fixedId) {
            if ($param['key'] === 'tagId') {
                $param['value'] = $fixedId;
            }
            return $param;
        }, $tag['parameter'] ?? []);

        $payload  = array_merge($tag, ['parameter' => $params]);
        $response = Http::withToken($this->accessToken)
            ->put("{$this->baseUrl}/{$tag['path']}", $payload);

        if (!$response->successful()) {
            $this->line('  API error: ' . ($response->json()['error']['message'] ?? $response->body()));
        }

        return $response->successful();
    }

    private function publishWorkspace(string $workspacePath): bool
    {
        // Create a version from the workspace
        $versionResp = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/{$workspacePath}:create_version", [
                'name'  => 'Fix invalid google_tag measurement IDs',
                'notes' => 'Automated fix: added AW-/G- prefix to bare measurement IDs',
            ]);

        if (!$versionResp->successful()) {
            $this->line('  create_version error: ' . ($versionResp->json()['error']['message'] ?? $versionResp->body()));
            return false;
        }

        $versionPath = $versionResp->json()['containerVersion']['path'] ?? null;
        if (!$versionPath) {
            $this->error('Version created but no path returned');
            return false;
        }

        $publishResp = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/{$versionPath}:publish");

        if (!$publishResp->successful()) {
            $this->line('  publish error: ' . ($publishResp->json()['error']['message'] ?? $publishResp->body()));
        }

        return $publishResp->successful();
    }
}
