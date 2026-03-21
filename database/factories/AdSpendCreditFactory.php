<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\AdSpendCredit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AdSpendCredit>
 */
class AdSpendCreditFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'initial_credit_amount' => 350.00,
            'current_balance' => 350.00,
            'currency' => 'USD',
            'status' => AdSpendCredit::STATUS_ACTIVE,
            'payment_status' => AdSpendCredit::PAYMENT_CURRENT,
            'last_successful_charge_at' => now(),
            'failed_charge_count' => 0,
        ];
    }

    public function lowBalance(): static
    {
        return $this->state(fn () => [
            'current_balance' => 15.00,
            'status' => AdSpendCredit::STATUS_LOW_BALANCE,
        ]);
    }

    public function depleted(): static
    {
        return $this->state(fn () => [
            'current_balance' => 0,
            'status' => AdSpendCredit::STATUS_DEPLETED,
        ]);
    }

    public function inGracePeriod(): static
    {
        return $this->state(fn () => [
            'payment_status' => AdSpendCredit::PAYMENT_GRACE_PERIOD,
            'grace_period_ends_at' => now()->addHours(24),
            'failed_charge_count' => 1,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn () => [
            'payment_status' => AdSpendCredit::PAYMENT_PAUSED,
            'campaigns_paused_at' => now(),
            'failed_charge_count' => 3,
        ]);
    }
}
