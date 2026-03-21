<?php

namespace Tests\Unit;

use App\Models\AdSpendCredit;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdSpendCreditTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_run_campaigns_when_active_with_balance(): void
    {
        $credit = AdSpendCredit::factory()->create();

        $this->assertTrue($credit->canRunCampaigns());
    }

    public function test_cannot_run_campaigns_when_balance_is_zero(): void
    {
        $credit = AdSpendCredit::factory()->depleted()->create();

        $this->assertFalse($credit->canRunCampaigns());
    }

    public function test_cannot_run_campaigns_when_suspended(): void
    {
        $credit = AdSpendCredit::factory()->create([
            'status' => AdSpendCredit::STATUS_SUSPENDED,
        ]);

        $this->assertFalse($credit->canRunCampaigns());
    }

    public function test_can_run_campaigns_during_grace_period(): void
    {
        $credit = AdSpendCredit::factory()->inGracePeriod()->create([
            'current_balance' => 100.00,
        ]);

        $this->assertTrue($credit->canRunCampaigns());
    }

    public function test_cannot_run_campaigns_when_paused(): void
    {
        $credit = AdSpendCredit::factory()->paused()->create([
            'current_balance' => 100.00,
        ]);

        $this->assertFalse($credit->canRunCampaigns());
    }

    public function test_deduct_reduces_balance(): void
    {
        $credit = AdSpendCredit::factory()->create([
            'current_balance' => 100.00,
        ]);

        $result = $credit->deduct(25.00, 'Test deduction');

        $this->assertTrue($result);
        $this->assertEquals(75.00, $credit->fresh()->current_balance);
    }

    public function test_deduct_fails_when_insufficient_balance(): void
    {
        $credit = AdSpendCredit::factory()->create([
            'current_balance' => 10.00,
        ]);

        $result = $credit->deduct(25.00, 'Test deduction');

        $this->assertFalse($result);
        $this->assertEquals(10.00, $credit->fresh()->current_balance);
    }

    public function test_deduct_creates_transaction_record(): void
    {
        $credit = AdSpendCredit::factory()->create([
            'current_balance' => 100.00,
        ]);

        $credit->deduct(30.00, 'Daily ad spend');

        $this->assertDatabaseHas('ad_spend_transactions', [
            'ad_spend_credit_id' => $credit->id,
            'type' => 'deduction',
            'description' => 'Daily ad spend',
        ]);
    }

    public function test_add_credit_increases_balance(): void
    {
        $credit = AdSpendCredit::factory()->create([
            'current_balance' => 50.00,
        ]);

        $credit->addCredit(100.00, 'Replenishment', 'ch_test_123');

        $this->assertEquals(150.00, $credit->fresh()->current_balance);
    }

    public function test_add_credit_creates_transaction_with_stripe_id(): void
    {
        $credit = AdSpendCredit::factory()->create();

        $credit->addCredit(200.00, 'Manual credit', 'ch_test_456');

        $this->assertDatabaseHas('ad_spend_transactions', [
            'ad_spend_credit_id' => $credit->id,
            'type' => 'credit',
            'amount' => 200.00,
            'stripe_charge_id' => 'ch_test_456',
        ]);
    }

    public function test_enter_grace_period_sets_correct_state(): void
    {
        $credit = AdSpendCredit::factory()->create();

        $credit->enterGracePeriod(24);

        $credit->refresh();
        $this->assertEquals(AdSpendCredit::PAYMENT_GRACE_PERIOD, $credit->payment_status);
        $this->assertNotNull($credit->grace_period_ends_at);
        $this->assertEquals(1, $credit->failed_charge_count);
    }

    public function test_mark_payment_failed_increments_failure_count(): void
    {
        $credit = AdSpendCredit::factory()->inGracePeriod()->create();

        $credit->markPaymentFailed();

        $credit->refresh();
        $this->assertEquals(AdSpendCredit::PAYMENT_FAILED, $credit->payment_status);
        $this->assertEquals(2, $credit->failed_charge_count); // 1 from inGracePeriod + 1
    }

    public function test_pause_campaigns_sets_paused_state(): void
    {
        $credit = AdSpendCredit::factory()->create();

        $credit->pauseCampaigns();

        $credit->refresh();
        $this->assertEquals(AdSpendCredit::PAYMENT_PAUSED, $credit->payment_status);
        $this->assertNotNull($credit->campaigns_paused_at);
    }

    public function test_restore_account_resets_all_failure_state(): void
    {
        $credit = AdSpendCredit::factory()->paused()->create();

        $credit->restoreAccount();

        $credit->refresh();
        $this->assertEquals(AdSpendCredit::PAYMENT_CURRENT, $credit->payment_status);
        $this->assertEquals(0, $credit->failed_charge_count);
        $this->assertNull($credit->grace_period_ends_at);
        $this->assertNull($credit->campaigns_paused_at);
        $this->assertNotNull($credit->last_successful_charge_at);
    }

    public function test_calculate_initial_credit(): void
    {
        $this->assertEquals(350.00, AdSpendCredit::calculateInitialCredit(50.00, 7));
        $this->assertEquals(150.00, AdSpendCredit::calculateInitialCredit(50.00, 3));
    }

    public function test_budget_multiplier_normal(): void
    {
        $credit = AdSpendCredit::factory()->create();

        $this->assertEquals(1.0, $credit->getBudgetMultiplier());
    }

    public function test_budget_multiplier_grace_period(): void
    {
        $credit = AdSpendCredit::factory()->inGracePeriod()->create([
            'current_balance' => 100.00,
        ]);

        $this->assertEquals(0.5, $credit->getBudgetMultiplier());
    }

    public function test_budget_multiplier_paused(): void
    {
        $credit = AdSpendCredit::factory()->paused()->create();

        $this->assertEquals(0.0, $credit->getBudgetMultiplier());
    }
}
