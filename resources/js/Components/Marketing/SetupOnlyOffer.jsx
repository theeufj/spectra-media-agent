import React from 'react';
import { CtaLink, CTA_PRIMARY, CTA_SIZE_COMPACT, brandTint } from '@/Components/Marketing/Hero';

/*
 * The one-and-done alternative to a subscription.
 *
 * This product is built end to end — SetupFeeService opens a USD Stripe
 * Checkout, QuickStart offers it as a service_type, DeployCampaign leaves a
 * setup_only account paused for handover, AutomatedCampaignMaintenance skips it
 * afterwards — and until now it appeared on exactly one screen, behind a login,
 * at Subscription/Pricing. Someone comparing us against an agency's setup quote
 * had no way to find it, and no crawler or answer engine could cite a price that
 * was never served in public HTML.
 *
 * The price is read from config('services.stripe.setup_fee_usd_cents') through
 * the controller rather than written here, for the same reason the AggregateOffer
 * range is: a number typed into marketing copy is a number that goes stale the
 * first time the fee moves.
 *
 * @param {number} priceUsd  Whole dollars, from the controller.
 * @param {string} href      Where the CTA goes. Signup, not checkout — the fee
 *                           is charged against a customer record that does not
 *                           exist until QuickStart creates one.
 */
export default function SetupOnlyOffer({ priceUsd, href = '/register' }) {
    return (
        <div
            className="flex flex-col gap-5 rounded-xl border-2 p-5 text-left sm:flex-row sm:items-center sm:gap-8 sm:p-8"
            style={{ borderColor: brandTint(30), backgroundColor: brandTint(5) }}
        >
            <div className="min-w-0 flex-1">
                <p className="text-xs font-semibold uppercase tracking-wide text-brand-darker">
                    Don't want a subscription?
                </p>
                <h3 className="mt-1 text-lg font-bold text-gray-900 sm:text-xl">One-time Google Ads setup</h3>
                <p className="mt-2 text-sm leading-relaxed text-gray-600">
                    We build your Google Ads account, campaigns, ad copy and conversion tracking. Everything
                    arrives paused, you add your own Google billing, and the account is yours. One payment,
                    nothing recurring, no management.
                </p>
            </div>
            <div className="flex-shrink-0 sm:text-center">
                <p className="text-3xl font-extrabold text-gray-900">US${priceUsd}</p>
                <p className="mb-3 text-xs text-gray-500">once, ever</p>
                <CtaLink href={href} className={`${CTA_PRIMARY} ${CTA_SIZE_COMPACT} w-full sm:w-auto`}>
                    Set mine up
                </CtaLink>
            </div>
        </div>
    );
}
