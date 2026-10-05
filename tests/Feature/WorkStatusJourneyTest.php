<?php

namespace Tests\Feature;

use App\Jobs\GenerateProposal;
use App\Jobs\TrackKeywordRankings;
use App\Models\Customer;
use App\Models\Keyword;
use App\Models\Proposal;
use App\Models\SeoRanking;
use App\Models\User;
use App\Support\WorkStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WorkStatusJourneyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_duplicate_runs_and_old_workers_cannot_replace_the_latest_status(): void
    {
        $customer = Customer::factory()->create();
        $first = WorkStatus::start($customer->id, 'rankings');
        $this->assertNotNull($first);
        $this->assertNull(WorkStatus::start($customer->id, 'rankings'));
        WorkStatus::update($customer->id, 'rankings', $first, 'running');
        $this->travel(31)->minutes();
        $this->assertSame('failed', WorkStatus::get($customer->id, 'rankings')['status']);
        $second = WorkStatus::start($customer->id, 'rankings');
        WorkStatus::update($customer->id, 'rankings', $first, 'completed');
        $this->assertSame($second, WorkStatus::get($customer->id, 'rankings')['id']);
        $this->assertSame('queued', WorkStatus::get($customer->id, 'rankings')['status']);
        $this->travelBack();
    }

    public function test_seo_status_uses_the_selected_account_and_rejects_a_foreign_session_id(): void
    {
        $user = User::factory()->create();
        $first = Customer::factory()->create();
        $selected = Customer::factory()->create();
        $foreign = Customer::factory()->create();
        $user->customers()->attach([$first->id => ['role' => 'owner'], $selected->id => ['role' => 'owner']]);
        WorkStatus::start($first->id, 'rankings');
        $expected = WorkStatus::start($selected->id, 'rankings');
        $secret = WorkStatus::start($foreign->id, 'rankings');
        $this->actingAs($user)->withSession(['active_customer_id' => $selected->id])
            ->getJson(route('seo.work-status'))->assertOk()->assertJsonPath('runs.rankings.id', $expected);
        $this->actingAs($user)->withSession(['active_customer_id' => $foreign->id])
            ->getJson(route('seo.work-status'))->assertOk()->assertJsonMissing(['id' => $secret]);
    }

    public function test_failed_proposal_retries_the_same_inputs_and_freezes_its_currency(): void
    {
        $user = User::factory()->create(['subscription_status' => 'active']);
        $customer = Customer::factory()->create(['currency_code' => 'AUD']);
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        $proposal = Proposal::create([
            'user_id' => $user->id, 'customer_id' => $customer->id,
            'client_name' => 'Saved Client', 'budget' => 5000, 'currency_code' => 'EUR',
            'goals' => 'Keep this brief', 'platforms' => ['Google Ads'], 'status' => 'failed', 'error' => 'Failed',
        ]);
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id])
            ->post(route('proposals.retry', $proposal))->assertRedirect();
        $proposal->refresh();
        $this->assertSame('generating', $proposal->status);
        $this->assertSame('queued', $proposal->generation_step);
        $this->assertSame('EUR', $proposal->currency_code);
        $this->assertSame('Keep this brief', $proposal->goals);
        Queue::assertPushed(GenerateProposal::class, 1);
        $this->post(route('proposals.retry', $proposal))->assertRedirect();
        Queue::assertPushed(GenerateProposal::class, 1);
    }

    public function test_a_stuck_proposal_becomes_retryable_without_exposing_an_exception(): void
    {
        $user = User::factory()->create(['subscription_status' => 'active']);
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        $proposal = Proposal::create([
            'user_id' => $user->id, 'customer_id' => $customer->id,
            'client_name' => 'Stuck Client', 'status' => 'generating',
        ]);
        $this->travel(31)->minutes();
        $this->actingAs($user)->withSession(['active_customer_id' => $customer->id])
            ->getJson(route('proposals.status', $proposal))->assertOk()->assertJsonPath('status', 'failed');
        $this->travelBack();
    }

    public function test_audit_inputs_survive_progress_and_failure_for_the_next_visit(): void
    {
        $customer = Customer::factory()->create();
        $run = WorkStatus::start($customer->id, 'seo-audit', ['url' => 'https://example.com/saved-page']);
        WorkStatus::update($customer->id, 'seo-audit', $run, 'running');
        WorkStatus::update($customer->id, 'seo-audit', $run, 'failed', 'Retry this audit.');
        $this->assertSame('https://example.com/saved-page', WorkStatus::get($customer->id, 'seo-audit')['context']['url']);
    }

    public function test_unavailable_ranking_data_does_not_replace_a_previous_measurement(): void
    {
        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        Keyword::create(['customer_id' => $customer->id, 'keyword_text' => 'saved keyword', 'match_type' => 'PHRASE', 'status' => 'active']);
        $ranking = SeoRanking::create([
            'customer_id' => $customer->id, 'keyword' => 'saved keyword', 'domain' => 'example.com',
            'position' => 4, 'date' => now()->toDateString(), 'search_engine' => 'google',
        ]);
        $this->app->instance(\App\Services\FirecrawlService::class, Mockery::mock(\App\Services\FirecrawlService::class)
            ->shouldReceive('isConfigured')->andReturn(false)->getMock());
        $this->app->instance(\App\Services\SEO\SearchConsoleService::class, Mockery::mock(\App\Services\SEO\SearchConsoleService::class)
            ->shouldReceive('isVerified')->andReturn(false)->getMock());
        $run = WorkStatus::start($customer->id, 'rankings');
        (new TrackKeywordRankings($customer->id, $run))->handle();
        $this->assertSame('failed', WorkStatus::get($customer->id, 'rankings')['status']);
        $this->assertSame(4, $ranking->fresh()->position);
    }

    public function test_an_unreadable_page_does_not_create_a_false_zero_score_audit(): void
    {
        $customer = Customer::factory()->create();
        $this->app->instance(\App\Services\Crawling\WebsiteRenderer::class, Mockery::mock(\App\Services\Crawling\WebsiteRenderer::class)
            ->shouldReceive('html')->andReturn('')->getMock());
        $this->expectException(\RuntimeException::class);
        try {
            (new \App\Services\SEO\SeoAuditService($customer))->audit('https://example.com/unreadable');
        } finally {
            $this->assertDatabaseMissing('seo_audits', ['customer_id' => $customer->id]);
        }
    }

    public function test_pdf_failure_keeps_the_saved_proposal_and_returns_a_recovery_message(): void
    {
        $user = User::factory()->create(['subscription_status' => 'active']);
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        $proposal = Proposal::create(['user_id' => $user->id, 'customer_id' => $customer->id, 'client_name' => 'Saved', 'status' => 'ready', 'proposal_data' => ['executive_summary' => 'Saved content']]);
        $this->app->instance(\App\Services\ProposalPdfService::class, new class extends \App\Services\ProposalPdfService
        {
            public function getPdfContent(Proposal $proposal): ?string
            {
                throw new \RuntimeException('Private provider detail');
            }
        });
        $this->actingAs($user)->get(route('proposals.export-pdf', $proposal))->assertRedirect()
            ->assertSessionHas('flash.message', 'The PDF could not be prepared. Your proposal is saved; try downloading again.');
        $this->assertSame('ready', $proposal->fresh()->status);
    }

    public function test_an_old_queued_proposal_job_cannot_overwrite_a_new_retry(): void
    {
        $user = User::factory()->create(['subscription_status' => 'active']);
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        $proposal = Proposal::create([
            'user_id' => $user->id, 'customer_id' => $customer->id, 'client_name' => 'Saved',
            'status' => 'generating', 'generation_started_at' => now()->subHour(),
        ]);
        $oldJob = new GenerateProposal($proposal);
        $proposal->markFailed('Stopped');
        $this->actingAs($user)->post(route('proposals.retry', $proposal))->assertRedirect();
        $oldJob->handle(new \App\Services\GeminiService, new \App\Services\ProposalPdfService);
        $oldJob->failed(new \RuntimeException('Old worker failed'));
        $this->assertSame('generating', $proposal->fresh()->status);
        $this->assertSame('queued', $proposal->fresh()->generation_step);
    }
}
