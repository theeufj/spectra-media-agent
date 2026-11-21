<?php

namespace Tests\Feature;

use App\Jobs\RecordSiteGoogleConversion;
use App\Jobs\Scheduled\RecordSevenDayReturnConversions;
use App\Models\Customer;
use App\Models\SpectraConversionEvent;
use App\Models\User;
use App\Services\FeatureUsage\FeatureRecorder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SevenDayReturnConversionsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_only_the_returning_user_on_that_workspace_generates_one_immutable_event(): void
    {
        $customer = Customer::factory()->create();
        $returned = User::factory()->create(['gbraid' => 'real-test-id', 'created_at' => now()->subDays(7)->subHour()]);
        $absent = User::factory()->create(['gclid' => 'other', 'created_at' => now()->subDays(7)->subHour()]);
        $customer->users()->attach([$returned->id, $absent->id]);
        FeatureRecorder::record(\App\Enums\ProductFeature::cases()[0], 'viewed', $customer->id, $returned->id);
        (new RecordSevenDayReturnConversions)->handle();
        (new RecordSevenDayReturnConversions)->handle();

        $this->assertSame(1, SpectraConversionEvent::where('event', 'seven_day_return')->where('user_id', $returned->id)->count());
        $this->assertFalse(SpectraConversionEvent::where('event', 'seven_day_return')->where('user_id', $absent->id)->exists());
        Queue::assertPushed(RecordSiteGoogleConversion::class);
    }

    public function test_signup_age_or_an_organic_visit_alone_never_counts_as_an_ad_return(): void
    {
        User::factory()->create(['gclid' => 'no-visit', 'created_at' => now()->subDays(7)->subHour()]);
        $organic = User::factory()->create(['created_at' => now()->subDays(7)->subHour()]);
        FeatureRecorder::record(\App\Enums\ProductFeature::cases()[0], 'viewed', null, $organic->id);
        (new RecordSevenDayReturnConversions)->handle();
        Queue::assertNotPushed(RecordSiteGoogleConversion::class);
    }
}
