<?php

namespace Tests\Feature;

use App\Jobs\ReviewCreativeRelease;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Services\AdminMonitorService;
use App\Services\Campaigns\AdvertisingEvidence;
use App\Services\Creative\CreativeRelease;
use App\Services\Creative\CreativeReleaseReviewer;
use App\Services\GeminiService;
use App\Services\GoogleAds\PerformanceMaxServices\PerformanceMaxLogo;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PerformanceMaxBusinessLogoTest extends TestCase
{
    use DatabaseTransactions;

    private function image(Strategy $strategy, int $width, int $height, string $path, bool $designated = false): array
    {
        ob_start();
        imagepng(imagecreatetruecolor($width, $height));
        $bytes = (string) ob_get_clean();
        Storage::disk('public')->put($path, $bytes);
        $image = ImageCollateral::create(['campaign_id' => $strategy->campaign_id, 'strategy_id' => $strategy->id,
            'platform' => $strategy->platform, 'source' => 'uploaded', 'layout' => $designated ? 'logo' : 'photo',
            'generation_metadata' => $designated ? ['role' => 'LOGO', 'provider' => 'customer_upload', 'sha256' => hash('sha256', $bytes)] : null,
            's3_path' => $path, 'cloudfront_url' => '/'.$path, 'is_active' => true, 'should_deploy' => true]);

        return [$image, $bytes];
    }

    public static function visualVerdicts(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('visualVerdicts')]
    public function test_logo_role_requires_a_customer_selection_the_visual_verdict_and_exact_bytes(bool $passes): void
    {
        Storage::fake('public');
        $strategy = Strategy::factory()->create(['platform' => 'Google Ads', 'campaign_type' => 'performance_max', 'signed_off_at' => now()]);
        $strategy->adCopies()->create(['platform' => $strategy->platform, 'headlines' => ['Bicycle repairs'],
            'descriptions' => ['Have your bike serviced.'], 'should_deploy' => true]);
        [$square, $photo] = $this->image($strategy, 300, 300, 'customer-logo-photo.png');
        [$landscape] = $this->image($strategy, 600, 314, 'landscape.png');
        $release = app(CreativeRelease::class);
        $this->assertSame(AssetFieldType::SQUARE_MARKETING_IMAGE, PerformanceMaxLogo::imageField($square, $strategy, $photo));
        $this->assertContains(PerformanceMaxLogo::REQUIRED, $release->state($strategy)['issues']);
        [$logo, $bytes] = $this->image($strategy, 128, 128, 'customer-upload.png', true);
        $this->assertNull(PerformanceMaxLogo::imageField($logo, $strategy, $bytes));
        $model = $this->createMock(GeminiService::class);
        $model->expects($this->once())->method('generateContent')->willReturnCallback(function ($unused, $prompt) use ($logo, $square, $landscape, $passes) {
            $this->assertStringContainsString('proposed as the advertiser’s business logo: '.$logo->id, $prompt);

            return ['text' => json_encode(['assets' => array_map(fn ($image) => ['id' => $image->id, 'passed' => $image !== $logo || $passes,
                'feedback' => $image === $logo && ! $passes ? 'This is a photograph.' : 'Matches the supplied identity and placement.'], [$square, $landscape, $logo])])];
        });
        $copyReviewer = $this->createMock(AdminMonitorService::class);
        $copyReviewer->method('reviewAdCopy')->willReturn(['programmatic_validation' => ['is_valid' => true],
            'gemini_feedback' => ['factual_accuracy' => true, 'intent_relevance' => true, 'blocking_issues' => []]]);
        $fingerprint = $release->selection($strategy)['fingerprint'];
        (new ReviewCreativeRelease($strategy->id, $fingerprint))->handle($release, new CreativeReleaseReviewer($copyReviewer, $model, app(AdvertisingEvidence::class)));
        $strategy->refresh();
        if (! $passes) {
            $this->assertSame('needs_review', $release->state($strategy)['status']);
            $this->assertSame([], $strategy->creative_release_review['logo_hashes']);

            return;
        }
        $release->approve($strategy, $fingerprint);
        $this->assertSame(AssetFieldType::LOGO, PerformanceMaxLogo::imageField($logo, $strategy, $bytes));
        $this->assertNull(PerformanceMaxLogo::imageField($logo, $strategy, $bytes.'modified'));
        $this->assertNull(PerformanceMaxLogo::imageField($logo, Strategy::factory()->create(), $bytes));
        $logo->update(['layout' => 'photo', 'generation_metadata' => null]);
        $this->assertFalse($release->isApproved($strategy));
    }

    public function test_first_party_logo_is_scoped_to_its_source_shape_and_render_hash(): void
    {
        Storage::fake('public');
        $strategy = Strategy::factory()->create(['campaign_type' => 'performance_max']);
        [$logo, $bytes] = $this->image($strategy, 128, 128, 'original.png', true);
        $meta = ['provider' => 'first_party_asset', 'role' => 'LOGO', 'sha256' => hash('sha256', $bytes)];
        $logo->generation_metadata = $meta;
        $this->assertNull(PerformanceMaxLogo::digest($logo, $strategy));
        $logo->generation_metadata = $meta + ['source_public_path' => 'images/brand.png', 'source_sha256' => hash('sha256', 'original')];
        $this->assertSame(AssetFieldType::LOGO, PerformanceMaxLogo::imageField($logo, $strategy, $bytes, duringReview: true));
        [$wide, $landscape] = $this->image($strategy, 600, 314, 'wrong-shape.png', true);
        $this->assertNull(PerformanceMaxLogo::imageField($wide, $strategy, $landscape, duringReview: true));
    }
}
