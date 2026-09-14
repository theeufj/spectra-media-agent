<?php

namespace Tests\Feature;

use App\Services\StorageHelper;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The suite must not be able to reach the real bucket.
 *
 * StorageHelper chooses S3 whenever a bucket and a key are configured, and it
 * reads both from the environment. A developer .env holding real credentials
 * therefore sent `Storage::fake('public')` straight past: the email-asset
 * upload test went out over the network and wrote an object into the
 * production bucket, then failed with a 500 when the call did not come back.
 *
 * CI has no AWS variables, so it passed there and failed locally — the suite's
 * result depended on whose machine ran it, which is the property that makes a
 * green run worth nothing. Forced off in phpunit.xml, exactly as the live
 * platform APIs already are; this fails if that ever comes undone.
 */
class StorageIsolationTest extends TestCase
{
    public function test_the_test_suite_never_resolves_to_s3(): void
    {
        $this->assertFalse(
            StorageHelper::usesS3(),
            'tests are configured to reach the real S3 bucket — check the AWS_* overrides in phpunit.xml',
        );
    }

    public function test_a_faked_disk_actually_receives_the_write(): void
    {
        Storage::fake('public');

        StorageHelper::put('email-assets/header.png', 'binary', 'image/png');

        // The assertion the upload tests were silently not making.
        Storage::disk('public')->assertExists('email-assets/header.png');
    }
}
