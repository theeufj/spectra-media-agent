<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GoogleDisplayBannerAccessTest extends TestCase
{
    use DatabaseTransactions;

    private ImageCollateral $image;

    private User $owner;

    private string $path = 'collateral/display/banner.jpg';

    private string $bytes;

    private string $hash;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['filesystems.disks.s3.bucket' => null]);
        $this->bytes = UploadedFile::fake()->image('banner.jpg', 300, 250)->getContent();
        $this->hash = hash('sha256', $this->bytes);
        Storage::disk('public')->put($this->path, $this->bytes);
        $this->owner = User::factory()->create(['subscription_status' => 'active']);
        $campaign = Campaign::factory()->create();
        $campaign->customer->users()->attach($this->owner, ['role' => 'owner']);
        $strategy = Strategy::factory()->for($campaign)->create([
            'platform' => 'Google Ads', 'campaign_type' => 'display', 'signed_off_at' => now(),
            'conversion_goals' => ['primary_goal' => 'Sign-up'],
        ]);
        $this->image = $strategy->imageCollaterals()->create([
            'campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'is_active' => true,
            's3_path' => 'collateral/source.jpg', 'cloudfront_url' => 'https://assets.example/source.jpg',
            'google_display_banners' => [
                'brand' => 'Example', 'headline' => 'Join Example', 'cta' => 'Sign Up',
                'sizes' => ['300x250' => $this->path], 'hashes' => ['300x250' => $this->hash],
            ],
        ]);
    }

    private function url(array $params = []): string
    {
        return route('image-collaterals.display-banner', $params + ['image_collateral' => $this->image, 'size' => '300x250', 'hash' => $this->hash]);
    }

    public function test_review_and_polling_show_the_banner_under_its_source_without_revealing_a_storage_path(): void
    {
        $this->actingAs($this->owner)->get(route('campaigns.collateral.show', [$this->image->campaign, $this->image->strategy]))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reviewSummary.google_cta', 'Sign Up')
            ->has('imageCollaterals', 1)
            ->where('imageCollaterals.0.display_banner_preview.sizes.300x250', $this->hash)
            ->missing('imageCollaterals.0.google_display_banners'));
        $this->getJson(route('api.collateral.show', $this->image->strategy))
            ->assertOk()->assertJsonCount(1, 'imageCollaterals')
            ->assertJsonPath('imageCollaterals.0.display_banner_preview.cta', 'Sign Up')
            ->assertJsonMissingPath('imageCollaterals.0.google_display_banners');
    }

    public function test_only_the_owner_can_review_or_download_the_exact_generated_file(): void
    {
        $this->get($this->url())->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get($this->url())->assertNotFound();
        $this->get($this->url(['download' => 1]))->assertNotFound();
        $preview = $this->actingAs($this->owner)->get($this->url())->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame($this->bytes, $preview->getContent());
        $name = 'google-display-'.$this->image->id.'-300x250.jpg';
        $preview->assertHeader('Content-Disposition', 'inline; filename="'.$name.'"');
        $this->get($this->url(['download' => 1]))->assertDownload($name);
        $this->get($this->url(['size' => '728x90']))->assertNotFound();
        $this->get($this->url(['hash' => str_repeat('0', 64)]))->assertNotFound();
        $this->owner->forceFill(['subscription_status' => 'guest'])->save();
        $this->get($this->url(['download' => 1]))->assertForbidden();
        $this->get($this->url())->assertOk();
        Storage::disk('public')->put($this->path, 'different bytes');
        $this->get($this->url())->assertNotFound();
    }
}
