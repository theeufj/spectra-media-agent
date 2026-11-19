<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Services\GoogleAds\PerformanceMaxServices\AddAudienceSignals;
use PHPUnit\Framework\TestCase;

class PerformanceMaxSearchThemesTest extends TestCase
{
    public function test_a_retry_only_sends_missing_valid_reviewed_themes(): void
    {
        $service = new RecordedSearchThemes(['repair workbenches']);
        $themes = ['Repair Workbenches', '  Buy   studio benches ', 'buy studio benches', ['name' => 'broken'], str_repeat('x', 81), str_repeat('a ', 11)];
        $this->assertTrue($service->ensureSearchThemes('123', 'customers/123/assetGroups/45', $themes));
        $this->assertTrue($service->ensureSearchThemes('123', 'customers/123/assetGroups/45', $themes));
        $this->assertSame([['Buy studio benches']], $service->submitted);
    }

    public function test_missing_provider_readback_does_not_confirm_an_add(): void
    {
        $service = new RecordedSearchThemes([]);
        $service->accepted = false;
        $this->assertFalse($service->ensureSearchThemes('123', 'customers/123/assetGroups/45', ['repair workbenches']));
    }

    public function test_a_group_in_a_different_customer_account_cannot_receive_themes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RecordedSearchThemes([]))->ensureSearchThemes('123', 'customers/321/assetGroups/45', ['repair workbenches']);
    }
}

final class RecordedSearchThemes extends AddAudienceSignals
{
    public array $submitted = [];

    public bool $accepted = true;

    public function __construct(public array $existing)
    {
        $this->customer = (new Customer)->setRawAttributes(['google_ads_customer_id' => '123']);
    }

    protected function existingThemes(string $customerId, string $group): array
    {
        return $this->existing;
    }

    public function addSearchThemes(string $customerId, string $assetGroupResourceName, array $themes): int
    {
        $this->submitted[] = array_values($themes);
        if ($this->accepted) {
            $this->existing = [...$this->existing, ...$themes];
        }

        return count($themes);
    }
}
