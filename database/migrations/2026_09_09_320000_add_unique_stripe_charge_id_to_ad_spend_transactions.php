<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One credit ledger row per Stripe charge.
 *
 * Stripe replays the original response for a repeated idempotency key rather
 * than charging again, so a retried top-up comes back as a success carrying the
 * *same* charge id. AdSpendCredit::addCredit() wrote a second row and a second
 * balance increase for money that was only collected once — $200 of balance for
 * $100 taken, at Spectra's expense. The application-side guard is a
 * firstOrCreate on this key; this index is what makes it a guarantee.
 *
 * Scoped to credit rows deliberately. A refund legitimately reuses the charge id
 * of the credit it reverses (StripeWebhookController::recordAdSpendRefund), and
 * a second partial refund on the same charge writes a second refund row, so a
 * plain unique index over the column would reject both.
 */
return new class extends Migration
{
    private const INDEX = 'ad_spend_transactions_credit_charge_unique';

    public function up(): void
    {
        // Rows written before the guard existed may already carry a duplicate —
        // they are the double-credits this index exists to stop. Creating the
        // index over them would fail the deploy, so park the duplicate's id in
        // metadata (the audit trail moves, it does not disappear) and free the
        // column. The earliest row of each group keeps the id, so the refund
        // lookup in recordAdSpendRefund still resolves. The balances are left
        // exactly as they are: correcting them is an accounting decision, not a
        // migration's.
        DB::statement(<<<'SQL'
            UPDATE ad_spend_transactions AS t
            SET metadata = (
                    COALESCE(t.metadata::jsonb, '{}'::jsonb)
                    || jsonb_build_object('duplicate_stripe_charge_id', t.stripe_charge_id)
                )::json,
                stripe_charge_id = NULL
            WHERE t.type = 'credit'
              AND t.stripe_charge_id IS NOT NULL
              AND EXISTS (
                  SELECT 1
                  FROM ad_spend_transactions AS earlier
                  WHERE earlier.type = 'credit'
                    AND earlier.stripe_charge_id = t.stripe_charge_id
                    AND earlier.id < t.id
              )
        SQL);

        // Partial index: NULLs never collide in Postgres, and rows that are not
        // credits are excluded outright.
        DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON ad_spend_transactions (stripe_charge_id) '
            ."WHERE type = 'credit' AND stripe_charge_id IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
