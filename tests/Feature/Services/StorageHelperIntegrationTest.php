<?php

namespace Tests\Feature\Services;

use App\Services\StorageHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * @group integration
 */
class StorageHelperIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected array $uploadedPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_INTEGRATION_TESTS=true to run.');
        }
    }

    protected function tearDown(): void
    {
        // Delete test files from S3/local after each test
        foreach ($this->uploadedPaths as $path) {
            try {
                $disk = config('filesystems.disks.s3.key') ? 's3' : 'public';
                Storage::disk($disk)->delete($path);
            } catch (\Exception) {
                // Best-effort cleanup
            }
        }
        parent::tearDown();
    }

    public function test_puts_text_file_and_returns_path_and_url(): void
    {
        $path     = 'integration-tests/test-' . now()->timestamp . '.txt';
        $contents = 'Integration test file ' . now()->toIso8601String();

        [$returnedPath, $url] = StorageHelper::put($path, $contents, 'text/plain');

        $this->uploadedPaths[] = $returnedPath;

        $this->assertSame($path, $returnedPath);
        $this->assertNotEmpty($url);
        $this->assertStringContainsString($path, $url);
    }

    public function test_puts_json_file(): void
    {
        $path     = 'integration-tests/test-' . now()->timestamp . '.json';
        $contents = json_encode(['test' => true, 'timestamp' => now()->timestamp]);

        [$returnedPath, $url] = StorageHelper::put($path, $contents, 'application/json');

        $this->uploadedPaths[] = $returnedPath;

        $this->assertSame($path, $returnedPath);
        $this->assertNotEmpty($url);
    }

    public function test_gets_file_after_put(): void
    {
        $path     = 'integration-tests/get-test-' . now()->timestamp . '.txt';
        $contents = 'Readable integration test content';

        StorageHelper::put($path, $contents, 'text/plain');
        $this->uploadedPaths[] = $path;

        $retrieved = StorageHelper::get($path);

        $this->assertSame($contents, $retrieved);
    }

    public function test_put_returns_public_url_with_http_scheme(): void
    {
        $path = 'integration-tests/url-test-' . now()->timestamp . '.txt';

        [$returnedPath, $url] = StorageHelper::put($path, 'url test', 'text/plain');
        $this->uploadedPaths[] = $returnedPath;

        $this->assertTrue(
            str_starts_with($url, 'http://') || str_starts_with($url, 'https://'),
            "URL should start with http(s)://: {$url}"
        );
    }

    public function test_puts_binary_image_data(): void
    {
        // Minimal valid 1x1 white PNG
        $png      = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==');
        $path     = 'integration-tests/image-test-' . now()->timestamp . '.png';

        [$returnedPath, $url] = StorageHelper::put($path, $png, 'image/png');
        $this->uploadedPaths[] = $returnedPath;

        $this->assertSame($path, $returnedPath);
        $this->assertNotEmpty($url);
    }
}
