<?php

namespace App\Console\Commands;

use App\Services\StorageHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Prove that a generated creative can be written and then read back by a
 * stranger — which is what every image on the site actually depends on.
 *
 * Storage failed silently for months. Uploads were succeeding, rows were
 * written with URLs, jobs reported success, and every one of those URLs served
 * a 403 to the browser. Nothing in the application looked at the finished
 * result from the outside, so nothing noticed: the collateral page rendered
 * 24 broken images, `getimagesize()` returned false in the Performance Max
 * eligibility check so no campaign ever qualified, and the platform services
 * that fetch the bytes by public URL before uploading them to Google and
 * Facebook could never have attached a creative to an ad.
 *
 * The write half and the read half are separate systems — credentials and a
 * bucket on one side, a public domain and its permissions on the other — and
 * only the write half was ever exercised. This checks both, and checks the
 * read half unauthenticated, the way a browser or Google's asset fetcher sees
 * it. Run it after any storage or provider change.
 */
class StorageCheck extends Command
{
    protected $signature = 'storage:check {--keep : leave the probe object in place}';

    protected $description = 'Verify that an uploaded file is publicly readable';

    public function handle(): int
    {
        $path = 'diag/storage-check-'.Str::random(12).'.txt';
        $body = 'storage-check '.$this->laravel['config']->get('app.url');

        $this->line('Driver:   '.(StorageHelper::usesS3() ? 's3 ('.config('filesystems.disks.s3.endpoint').')' : 'local public disk'));
        $this->line('Bucket:   '.(config('filesystems.disks.s3.bucket') ?: '—'));
        $this->line('Base URL: '.(config('filesystems.disks.s3.url') ?: config('app.url').'/storage'));
        $this->line('Object ACL: '.(config('filesystems.disks.s3.acl') ?: 'none sent'));
        $this->newLine();

        try {
            [, $url] = StorageHelper::put($path, $body, 'text/plain');
        } catch (\Throwable $e) {
            $this->error('WRITE FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('write ok  → '.$url);

        // Unauthenticated on purpose. A read through the SDK proves only that
        // our own credentials work, which they did throughout the outage.
        try {
            $response = Http::withOptions(['http_errors' => false])->timeout(20)->get($url);
        } catch (\Throwable $e) {
            $this->error('PUBLIC READ FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('keep')) {
            StorageHelper::delete($path);
        }

        if (! $response->successful()) {
            $this->error('PUBLIC READ FAILED: HTTP '.$response->status().' from '.$url);
            $this->line('The upload succeeded, so the credentials and bucket are fine. What is');
            $this->line('wrong is the public side: AWS_URL must be the bucket\'s own public');
            $this->line('domain, and the bucket itself must be public. On DigitalOcean Spaces');
            $this->line('this also needs AWS_OBJECT_ACL=public-read; on Cloudflare R2 it must');
            $this->line('be left blank, because R2 has no object ACLs.');

            return self::FAILURE;
        }

        if (trim($response->body()) !== $body) {
            $this->error('PUBLIC READ returned different content than was written.');
            $this->line('That points at AWS_URL naming a domain served by a different bucket.');

            return self::FAILURE;
        }

        $this->info('public read ok → HTTP '.$response->status().', content matches');
        $this->newLine();
        $this->info('Storage is healthy: an uploaded creative is readable by anyone with the link.');

        return self::SUCCESS;
    }
}
