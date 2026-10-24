<?php

namespace App\Support;

/** Budget receipts accepted by both the maintenance activity log and customer email. */
final class ConfirmedBudgetChanges
{
    public const PLATFORMS = [
        'google' => 'Google Ads',
        'facebook' => 'Facebook Ads',
        'microsoft' => 'Microsoft Ads',
        'linkedin' => 'LinkedIn Ads',
    ];

    public static function from(array $adjustments): array
    {
        return array_values(array_filter($adjustments, static function ($change): bool {
            return is_array($change)
                && ($change['type'] ?? null) === 'budget_updated'
                && ($change['verified'] ?? null) === true
                && is_int($change['old_budget_micros'] ?? null)
                && is_int($change['new_budget_micros'] ?? null)
                && $change['old_budget_micros'] >= 0
                && $change['new_budget_micros'] >= 0
                && $change['old_budget_micros'] !== $change['new_budget_micros']
                && is_string($change['platform'] ?? null)
                && isset(self::PLATFORMS[$change['platform']])
                && is_string($change['currency'] ?? null)
                && preg_match('/^[A-Z]{3}$/D', $change['currency']) === 1
                && is_string($change['resource_name'] ?? null)
                && trim($change['resource_name']) !== ''
                && is_string($change['reason'] ?? null)
                && trim($change['reason']) !== '';
        }));
    }

    public static function dailyAmount(int $micros, string $currency): string
    {
        // Preserve every recorded micro without turning the receipt into a float.
        $fraction = rtrim(str_pad((string) ($micros % 1_000_000), 6, '0', STR_PAD_LEFT), '0');

        return $currency.' '.number_format(intdiv($micros, 1_000_000), 0, '.', ',')
            .'.'.str_pad($fraction, 2, '0').'/day';
    }
}
