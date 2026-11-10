<?php

namespace Tests\Feature;

use App\Jobs\RefineImage;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\StorageHelper;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class SharedPrivateFilesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_private_object_writes_omit_the_acl_header(): void
    {
        config([
            'filesystems.private_disk' => 'private-s3',
            'filesystems.disks.private-s3' => [
                'driver' => 's3',
                'bucket' => 'private-test',
                'endpoint' => 'https://s3.example.test',
                'region' => 'us-east-1',
                'key' => 'fixture',
                'secret' => 'fixture',
                'http_handler' => function (RequestInterface $request) {
                    $this->assertSame('PUT', $request->getMethod());
                    $this->assertFalse($request->hasHeader('x-amz-acl'));

                    return Create::promiseFor(new Response(200));
                },
            ],
        ]);

        $this->assertTrue(StorageHelper::putPrivate('reports/report.pdf', 'PDF', 'application/pdf'));
    }

    public function test_a_queued_refinement_uses_the_disk_the_web_request_wrote_to(): void
    {
        config([
            'filesystems.private_disk' => 'private-s3',
            'filesystems.disks.s3.bucket' => null,
        ]);
        $queue = Queue::fake()->serializeAndRestore();
        $shared = Storage::fake('private-s3');
        $creative = Storage::fake('public');
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer, ['role' => 'owner']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $image = ImageCollateral::create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads (SEM)',
            's3_path' => 'original.png',
            'cloudfront_url' => 'https://example.test/original.png',
        ]);
        $reference = UploadedFile::fake()->image('reference.png');
        $png = $reference->get();
        $creative->put($image->s3_path, $png);
        $prompt = 'Use the reference image colours.';

        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id])
            ->post(route('image-collaterals.update', $image), ['prompt' => $prompt, 'context_image' => $reference])
            ->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        $paths = $shared->files('temp_context_images');
        $this->assertCount(1, $paths);
        $job = $queue->pushed(RefineImage::class)->sole();

        // The disk name travels with the job even if the worker's default changes.
        config(['filesystems.private_disk' => 'local']);
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->once())->method('refineImage')->with($prompt, [
            ['mime_type' => 'image/png', 'data' => base64_encode($png)],
            ['mime_type' => 'image/png', 'data' => base64_encode($png)],
        ])->willReturn(['mimeType' => 'image/png', 'data' => base64_encode($png)]);

        $job->handle($gemini);

        $shared->assertMissing($paths[0]);
        $this->assertDatabaseHas('image_collaterals', ['parent_id' => $image->id, 'is_active' => true]);
    }

    public function test_report_download_reads_shared_pdfs_and_preserves_legacy_local_pdfs(): void
    {
        config(['filesystems.private_disk' => 'private-s3']);
        $shared = Storage::fake('private-s3');
        $local = Storage::fake('local');
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['name' => 'Example Customer']);
        $user->customers()->attach($customer, ['role' => 'owner']);
        $shared->put("reports/{$customer->id}/weekly_2026-11-02.pdf", 'Shared PDF');
        $local->put("reports/{$customer->id}/weekly_2026-10-26.pdf", 'Legacy PDF');

        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id])
            ->get(route('reports.download', ['period' => 'weekly', 'date' => '2026-11-02']))
            ->assertDownload('weekly_report_Example_Customer_2026-11-02.pdf')
            ->assertStreamedContent('Shared PDF');

        $this->get(route('reports.download', ['period' => 'weekly', 'date' => '2026-10-26']))
            ->assertDownload('weekly_report_Example_Customer_2026-10-26.pdf')
            ->assertStreamedContent('Legacy PDF');
    }
}
