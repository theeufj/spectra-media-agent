<?php

namespace Tests\Feature;

use App\Services\StorageHelper;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The suite must not be able to reach the real bucket.
 *
 * StorageHelper chooses S3 whenever a bucket and a key are configured, and it
 * reads both from the environment. A developer .env holding real credentials
 * therefore sent `Storage::fake('public')` straight past: the email-asset
 * upload test went out over the network to the production bucket. (My first
 * write-up of this said it wrote an object there; it did not — the call was
 * refused. What is true, and is enough, is that the suite was dialling a
 * production endpoint and its verdict depended on local credentials.)
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

    /** @return array<string, mixed> */
    private function putParams(): array
    {
        $m = new \ReflectionMethod(StorageHelper::class, 'putParams');
        $m->setAccessible(true);

        return $m->invoke(null, 'collateral/images/1/a.jpeg', 'bytes', 'image/jpeg');
    }

    public function test_no_acl_is_sent_when_the_provider_has_none(): void
    {
        /*
           A Forge bucket is Cloudflare R2, which has no object-level ACLs —
           readability is a property of the bucket and the domain it is served
           on. 'public-read' was hardcoded on every upload, which is an
           assumption about S3 that R2 does not share.
        */
        Config::set('filesystems.disks.s3.acl', null);

        $this->assertArrayNotHasKey('ACL', $this->putParams());
    }

    public function test_an_acl_is_sent_where_one_is_configured(): void
    {
        // DigitalOcean Spaces does have them, and an object uploaded without
        // one there is private — which is the outage in the other direction.
        Config::set('filesystems.disks.s3.acl', 'public-read');

        $this->assertSame('public-read', $this->putParams()['ACL']);
    }

    public function test_a_blank_env_value_counts_as_no_acl(): void
    {
        // AWS_OBJECT_ACL= in an env file arrives as '', not null, and sending
        // an empty ACL is an API error rather than a default.
        Config::set('filesystems.disks.s3.acl', '');

        $this->assertArrayNotHasKey('ACL', $this->putParams());
    }
}
