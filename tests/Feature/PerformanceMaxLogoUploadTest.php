<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Role;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PerformanceMaxLogoUploadTest extends TestCase
{
    use DatabaseTransactions;

    private function setupCampaign(string $type = 'performance_max'): array
    {
        Storage::fake('public');
        config(['filesystems.disks.s3.bucket' => null]);
        $campaign = Campaign::factory()->create();
        $strategy = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'campaign_type' => $type]);
        $user = User::factory()->create(['pm_type' => 'card']);
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'user'])));
        $campaign->customer->users()->attach($user->id, ['role' => 'owner']);
        $this->actingAs($user);

        return [$campaign, $strategy];
    }

    public function test_customer_can_upload_a_verified_logo_for_review_as_a_separate_asset_role(): void
    {
        [$campaign, $strategy] = $this->setupCampaign();
        $file = UploadedFile::fake()->image('my-wordmark.png', 128, 128);
        $hash = hash_file('sha256', $file->getPathname());
        $this->post(route('campaigns.collateral.image.upload', [$campaign, $strategy]), ['role' => 'logo', 'images' => [$file]])->assertRedirect()->assertSessionHasNoErrors();
        $image = $strategy->imageCollaterals()->sole();
        $this->assertSame('logo', $image->layout);
        $this->assertSame('uploaded', $image->source);
        $this->assertSame('square', $image->format);
        $this->assertTrue($image->should_deploy);
        $this->assertSame('LOGO', $image->generation_metadata['role']);
        $this->assertSame('customer_upload', $image->generation_metadata['provider']);
        $this->assertSame($hash, $image->generation_metadata['sha256']);
        $this->assertSame($hash, hash('sha256', Storage::disk('public')->get($image->s3_path)));
        $this->assertNull($strategy->fresh()->creative_approval);
    }

    public function test_logo_role_rejects_wrong_format_size_dimensions_and_multiple_files(): void
    {
        [$campaign, $strategy] = $this->setupCampaign();
        $url = route('campaigns.collateral.image.upload', [$campaign, $strategy]);
        foreach ([UploadedFile::fake()->image('small.png', 80, 80), UploadedFile::fake()->image('wide.png', 640, 400),
            UploadedFile::fake()->image('photo.webp', 200, 200), UploadedFile::fake()->image('large.jpg', 256, 256)->size(5121)] as $file) {
            $this->post($url, ['role' => 'logo', 'images' => [$file]])->assertSessionHasErrors('images.0');
        }
        $this->post($url, ['role' => 'logo', 'images' => [UploadedFile::fake()->image('a.png', 256, 256), UploadedFile::fake()->image('b.png', 256, 256)]])->assertSessionHasErrors('images');
        $this->assertSame(0, $strategy->imageCollaterals()->count());
    }

    public function test_ordinary_upload_keeps_its_role_and_search_cannot_claim_a_performance_max_logo(): void
    {
        [$campaign, $strategy] = $this->setupCampaign('search');
        $url = route('campaigns.collateral.image.upload', [$campaign, $strategy]);
        $this->post($url, ['role' => 'logo', 'images' => [UploadedFile::fake()->image('logo.png', 256, 256)]])->assertSessionHasErrors('role');
        $this->post($url, ['images' => [UploadedFile::fake()->image('picture.png', 800, 480)]])->assertRedirect()->assertSessionHasNoErrors();
        $image = $strategy->imageCollaterals()->sole();
        $this->assertNull($image->generation_metadata['role'] ?? null);
    }

    public function test_customer_cannot_attach_a_logo_to_another_campaign_or_strategy(): void
    {
        [$campaign, $strategy] = $this->setupCampaign();
        $other = Campaign::factory()->create();
        $this->post(route('campaigns.collateral.image.upload', [$other, $strategy]), ['role' => 'logo', 'images' => [UploadedFile::fake()->image('logo.png', 256, 256)]])->assertNotFound();
        $this->assertSame(0, ImageCollateral::where('strategy_id', $strategy->id)->count());
    }
}
