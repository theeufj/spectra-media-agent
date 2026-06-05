<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Finds and fixes Google Tag (google_tag type) entries in the platform's own
 * GTM container that have invalid measurement IDs — specifically:
 *
 *   - Bare numeric IDs (e.g. "16797144138") → prepend "AW-"
 *   - Bare alphanumeric IDs (e.g. "K6YKBRF4VG") → prepend "G-"
 *
 * Valid formats accepted by GTM's google_tag type are "AW-XXXXXXXXX" and "G-XXXXXXXXX".
 * Tags that already match one of those formats are left untouched.
 *
 * Usage:
 *   php artisan gtm:fix-platform-tags              # dry-run — shows what would change
 *   php artisan gtm:fix-platform-tags --apply      # apply fixes
 *   php artisan gtm:fix-platform-tags --apply --publish  # apply + publish live
 */
class FixPlatformGtmTags extends Command
{
    protected $signature = 'gtm:fix-platform-tags
                            {--container=GTM-KHFLQZ8S : Public container ID to target}
                            {--apply             : Actually write the fixes (default is dry-run)}
                            {--publish           : Publish the workspace after applying fixes}';

    protected $description = 'Fix invalid google_tag measurement IDs in the platform GTM container';

    private string $baseUrl = 'https://tagmanager.googleapis.com/tagmanager/v2';
    private ?string $accessToken = null;

    public function handle(): int
    {
        $targetContainerId = $this->option('container');
        $apply   = $this->option('apply');
        $publish = $this->option('publish');

        if (!$apply) {
            $this->warn('DRY RUN — pass --apply to write changes');
            $this->newLine();
        }

        $this->accessToken = $this->getAccessToken();
        if (!$this->accessToken) {
            $this->error('Failed to obtain GTM access token. Check GTM_PLATFORM_REFRESH_TOKEN and GOOGLE_OAUTH_CLIENT_ID/SECRET in .env');
            return self::FAILURE;
        }
        $this->info('Authenticated with GTM API');

        // ── Locate the container ────────────────────────────────────────────
        $accountId = config('services.gtm.platform_account_id');
        if (!$accountId) {
            $this->error('GTM_PLATFORM_ACCOUNT_ID is not configured');
            return self::FAILURE;
        }

        $containerPath = $this->findContainerPath($accountId, $targetContainerId);
        if (!$containerPath) {
            $this->error("Container {$targetContainerId} not found under account {$accountId}");
            return self::FAILURE;
        }
        $this->info("Found container: {$containerPath}");

        // ── Get default workspace ───────────────────────────────────────────
        $workspacePath = $this->getDefaultWorkspacePath($containerPath);
        if (!$workspacePath) {
            $this->error('Could not find a workspace in the container');
            return self::FAILURE;
        }
        $this->info("Workspace: {$workspacePath}");
        $this->newLine();

        // ── List all tags and find broken google_tag entries ────────────────
        $tags = $this->listTags($workspacePath);
        if ($tags === null) {
            $this->error('Failed to list tags');
            return self::FAILURE;
        }

        $this->info(count($tags) . ' tags found in workspace');

        $fixes = [];
        foreach ($tags as $tag) {
            if (($tag['type'] ?? '') !== 'google_tag') {
                continue;
            }

            $currentId = $this->getMeasurementId($tag);
            if ($currentId === null || $this->isValidMeasurementId($currentId)) {
                continue;
            }

            $fixedId = $this->fixMeasurementId($currentId);
            $fixes[] = [
                'tag'       => $tag,
                'tag_name'  => $tag['name'],
                'tag_id'    => $tag['tagId'],
                'current'   => $currentId,
                'fixed'     => $fixedId,
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

        // ── Apply fixes ─────────────────────────────────────────────────────
        $allOk = true;
        foreach ($fixes as $fix) {
            $updated = $this->updateTagMeasurementId($workspacePath, $fix['tag'], $fix['fixed']);
            if ($updated) {
                $this->info("Fixed \"{$fix['tag_name']}\": {$fix['current']} → {$fix['fixed']}");
            } else {
                $this->error("Failed to fix \"{$fix['tag_name']}\"");
                $allOk = false;
            }
        }

        // ── Publish ─────────────────────────────────────────────────────────
        if ($publish && $allOk) {
            $this->newLine();
            $this->info('Publishing container...');
            $published = $this->publishWorkspace($workspacePath);
            if ($published) {
                $this->info('Container published successfully');
            } else {
                $this->error('Publish failed — changes are saved in the workspace but not live yet');
                return self::FAILURE;
            }
        } elseif ($publish && !$allOk) {
            $this->warn('Skipping publish because one or more tags failed to update');
        } else {
            $this->newLine();
            $this->warn('Changes saved to workspace. Run with --publish to make them live, or publish manually in the GTM UI.');
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function getAccessToken(): ?string
    {
        $refreshToken = config('services.gtm.platform_refresh_token');
        $clientId     = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');

        if (!$refreshToken || !$clientId || !$clientSecret) {
            return null;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        return $response->successful() ? ($response->json()['access_token'] ?? null) : null;
    }

    private function findContainerPath(string $accountId, string $publicId): ?string
    {
        $response = Http::withToken($this->accessToken)
            ->get("{$this->baseUrl}/accounts/{$accountId}/containers");

        if (!$response->successful()) {
            $this->error('Failed to list containers: ' . ($response->json()['error']['message'] ?? $response->body()));
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

        // Prefer "Default Workspace"; fall back to first available
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
        // Purely numeric → Google Ads conversion ID
        if (preg_match('/^\d+$/', $id)) {
            return 'AW-' . $id;
        }

        // Alphanumeric without prefix → GA4 Measurement ID
        return 'G-' . $id;
    }

    private function updateTagMeasurementId(string $workspacePath, array $tag, string $fixedId): bool
    {
        // Build the updated parameter list, replacing the tagId value
        $params = array_map(function ($param) use ($fixedId) {
            if ($param['key'] === 'tagId') {
                $param['value'] = $fixedId;
            }
            return $param;
        }, $tag['parameter'] ?? []);

        $payload = array_merge($tag, ['parameter' => $params]);

        // The GTM API update endpoint uses the tag's own path
        $tagPath = $tag['path'];
        $response = Http::withToken($this->accessToken)
            ->put("{$this->baseUrl}/{$tagPath}", $payload);

        if (!$response->successful()) {
            $this->line('  API error: ' . ($response->json()['error']['message'] ?? $response->body()));
        }

        return $response->successful();
    }

    private function publishWorkspace(string $workspacePath): bool
    {
        $response = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/{$workspacePath}:create_version", [
                'name'  => 'Fix invalid google_tag measurement IDs',
                'notes' => 'Automated fix: added AW-/G- prefix to bare measurement IDs',
            ]);

        if (!$response->successful()) {
            $this->line('  Publish error: ' . ($response->json()['error']['message'] ?? $response->body()));
            return false;
        }

        // create_version returns the version; publish it
        $versionPath = $response->json()['containerVersion']['path'] ?? null;
        if (!$versionPath) {
            return false;
        }

        $publishResponse = Http::withToken($this->accessToken)
            ->post("{$this->baseUrl}/{$versionPath}:publish");

        return $publishResponse->successful();
    }
}
