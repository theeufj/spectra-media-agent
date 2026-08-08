<?php

namespace App\Enums;

/**
 * Lifecycle status of a Campaign as tracked by Spectra.
 *
 * This is Spectra's own state, distinct from `platform_status` / `primary_status`,
 * which mirror what the ad platform reports. The two drift by design; do not assume
 * one implies the other (see BILL-8).
 *
 * Historically this column held mixed casing — the DB default was 'DRAFT' and
 * CheckCampaignPolicyViolations wrote 'PAUSED', while readers compared against
 * lowercase 'draft'/'paused'. Those comparisons silently never matched, so
 * intentionally-paused campaigns were treated as broken by the health checker.
 * Canonical storage is now lowercase; use from() on write and tryFromLoose()
 * when reading anything that may predate normalisation.
 */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case PendingAdminDeployment = 'pending_admin_deployment';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Ended = 'ended';

    /**
     * Resolve a value of unknown casing/vintage, returning null if unrecognised.
     */
    public static function tryFromLoose(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    /**
     * Statuses where the campaign is not expected to be delivering impressions.
     * A campaign in one of these is intentionally quiet — never alert on it.
     */
    public static function nonDelivering(): array
    {
        return [self::Draft, self::PendingAdminDeployment, self::Paused, self::Completed, self::Ended];
    }

    public function isDelivering(): bool
    {
        return $this === self::Active;
    }

    /**
     * Human-facing label for the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingAdminDeployment => 'Pending deployment',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Ended => 'Ended',
        };
    }
}
