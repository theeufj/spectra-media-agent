import { budgetCents } from '@/contracts/campaign';
import React, { useState } from 'react';
import Modal from '@/Components/Modal';
import { brandTint } from '@/Components/Marketing/Hero';
import { fetchJson } from '@/utils/http';
import { money } from '@/utils/format';
import { useCurrency } from '@/hooks/useCurrency';
import StripeCardElements from '@/Components/StripeCardElements';
import { CardElement, useStripe, useElements } from '@stripe/react-stripe-js';


// Card styling
const CARD_ELEMENT_OPTIONS = {
    style: {
        base: {
            color: '#32325d',
            fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
            fontSmoothing: 'antialiased',
            fontSize: '16px',
            '::placeholder': {
                color: '#aab7c4'
            }
        },
        invalid: {
            color: '#fa755a',
            iconColor: '#fa755a'
        }
    }
};

// Shared budget calculations
const useBudgetCalcs = (campaign, existingCredit) => {
    const getCampaignDurationDays = () => {
        if (campaign?.start_date && campaign?.end_date) {
            const startDate = new Date(campaign.start_date);
            const endDate = new Date(campaign.end_date);
            const diffTime = endDate.getTime() - startDate.getTime();
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
            return diffDays > 0 ? diffDays : 30;
        }
        return 30;
    };

    const calculateDailyBudget = () => {
        if (campaign?.funding_daily_budget || campaign?.daily_budget) {
            try { return budgetCents(campaign.funding_daily_budget || campaign.daily_budget) / 100; } catch { /* Fall back to the campaign total. */ }
        }
        const totalBudget = Number(campaign?.total_budget);
        const durationDays = getCampaignDurationDays();
        if (Number.isFinite(totalBudget) && totalBudget > 0 && durationDays > 0) {
            return Math.round(totalBudget * 100 / durationDays) / 100;
        }
        return 50;
    };

    const campaignDurationDays = getCampaignDurationDays();
    const estimatedDailySpend = calculateDailyBudget();
    const daysToCharge = campaign?.funding_days ?? Math.min(7, campaignDurationDays);
    // Show exactly what the server charges: daily × days. Substituting
    // total_budget for short campaigns showed one number on the button and
    // put a different one on the card.
    const runway = Math.round(estimatedDailySpend * daysToCharge * 100) / 100;
    const upfrontCharge = Math.round(Math.max(0, runway - (existingCredit?.available_cents != null ? existingCredit.available_cents / 100 : Number(existingCredit?.current_balance || 0))) * 100) / 100;

    return { campaignDurationDays, estimatedDailySpend, daysToCharge, upfrontCharge };
};

// Budget summary block shared by both form variants
const BudgetSummary = ({ campaign, campaignDurationDays, estimatedDailySpend, daysToCharge, upfrontCharge }) => {
    // This panel states the amount actually taken from their card. Nine of
    // seventeen customers are on AUD, and every figure here said "$".
    const currency = useCurrency();

    return (
    <>
        <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h4 className="font-medium text-blue-900 mb-2">Campaign Budget Details</h4>
            <div className="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <p className="text-blue-600">Total Budget</p>
                    <p className="font-semibold text-blue-900">{money(campaign?.total_budget || 0, currency, { maximumFractionDigits: 0 })}</p>
                </div>
                <div>
                    <p className="text-blue-600">Duration</p>
                    <p className="font-semibold text-blue-900">{campaignDurationDays} days</p>
                </div>
                <div>
                    <p className="text-blue-600">Calculated Daily Budget</p>
                    <p className="font-semibold text-blue-900">{money(estimatedDailySpend, currency)}/day</p>
                </div>
                <div>
                    <p className="text-blue-600">Campaign Dates</p>
                    <p className="font-semibold text-blue-900 text-xs">
                        {campaign?.start_date ? new Date(campaign.start_date).toLocaleDateString() : 'TBD'} → {campaign?.end_date ? new Date(campaign.end_date).toLocaleDateString() : 'TBD'}
                    </p>
                </div>
            </div>
        </div>

        <div className="rounded-lg border p-4" style={{ backgroundColor: brandTint(10), borderColor: brandTint(30) }}>
            <h4 className="font-medium text-brand-darker mb-2">How Ad Spend Billing Works</h4>
            <ul className="text-sm text-brand-darker space-y-1">
                <li>• After approval, each ready channel reserves up to <strong>{daysToCharge} days of its allocation</strong>. Your card covers only the shortfall after available credit.</li>
                <li>• Your actual daily spend is deducted each morning at 6 AM</li>
                <li>• Balance is automatically topped up when low</li>
                <li>• Full transparency – view all transactions in your dashboard</li>
            </ul>
        </div>

        <div className="bg-gray-50 rounded-lg p-4">
            <div className="flex justify-between items-center">
                <div>
                    <p className="text-sm text-gray-500">Daily Budget</p>
                    <p className="text-lg font-semibold text-gray-900">{money(estimatedDailySpend, currency)}/day</p>
                </div>
                <div className="text-right">
                    <p className="text-sm text-gray-500">Maximum seven-day shortfall</p>
                    <p className="text-2xl font-bold text-brand-dark">{money(upfrontCharge, currency)}</p>
                </div>
            </div>
        </div>
        </>
    );
};

// Form for users who already have a payment method on file — no card entry needed
const SavedCardForm = ({ campaign, strategy, creativeFingerprints, existingCredit, onSuccess, onCancel }) => {
    const currency = useCurrency();
    const [error, setError] = useState(null);
    const [processing, setProcessing] = useState(false);
    const { campaignDurationDays, estimatedDailySpend, daysToCharge, upfrontCharge } = useBudgetCalcs(campaign, existingCredit);

    const handleSubmit = async (event) => {
        event.preventDefault();
        setProcessing(true);
        setError(null);

        try {
            const result = await fetchJson('/billing/ad-spend/setup-for-deployment', {
                method: 'POST',
                json: {
                    campaign_id: campaign?.id,
                    strategy_id: strategy?.id,
                    creative_fingerprints: creativeFingerprints,
                    daily_budget: campaign?.daily_budget ?? estimatedDailySpend,
                    days_to_charge: daysToCharge,
                },
            });

            if (result.success) {
                onSuccess(result);
            } else {
                setError(result.error || 'Failed to set up ad spend billing');
            }
        } catch (err) {
            // The refusals a payer needs to read — self-funded, unconfirmed
            // budget, no card on file — arrive as 400/404/422 with the reason in
            // the body. response.json() on a 419 HTML page threw before we could
            // show any of them, so every one read "an error occurred".
            setError(err?.body?.error || 'An error occurred. Please try again.');
        }

        setProcessing(false);
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-6">
            <BudgetSummary
                campaign={campaign}
                campaignDurationDays={campaignDurationDays}
                estimatedDailySpend={estimatedDailySpend}
                daysToCharge={daysToCharge}
                upfrontCharge={upfrontCharge}
            />

            <div className="flex items-center gap-3 p-4 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800">
                <svg className="w-5 h-5 flex-shrink-0 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>Your saved card can fund each channel after launch approval and verification.</span>
            </div>

            {error && (
                <div role="alert" className="p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
                    {error}
                </div>
            )}

            <div className="flex space-x-3">
                <button
                    type="button"
                    onClick={onCancel}
                    className="flex-1 py-3 px-4 rounded-lg font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 transition-colors"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    disabled={processing}
                    className={`flex-1 py-3 px-4 rounded-lg font-medium text-white transition-colors ${
                        processing ? 'bg-gray-400 cursor-not-allowed' : 'bg-brand-dark hover:bg-brand-darker'
                    }`}
                >
                    {processing ? 'Processing...' : 'Continue to launch review'}
                </button>
            </div>

            <p className="text-xs text-gray-500 text-center">
                Your card is charged only after you approve the ads and budget and a channel qualifies.
                You can manage your billing in Settings → Ad Spend Billing.
            </p>
        </form>
    );
};

// Form for users with no payment method on file — collects card via Stripe Elements
const NewCardForm = ({ campaign, strategy, creativeFingerprints, existingCredit, onSuccess, onCancel }) => {
    const currency = useCurrency();
    const stripe = useStripe();
    const elements = useElements();
    const [error, setError] = useState(null);
    const [processing, setProcessing] = useState(false);
    const { campaignDurationDays, estimatedDailySpend, daysToCharge, upfrontCharge } = useBudgetCalcs(campaign, existingCredit);

    const handleSubmit = async (event) => {
        event.preventDefault();
        setProcessing(true);
        setError(null);

        if (!stripe || !elements) { setProcessing(false); return; }

        const cardElement = elements.getElement(CardElement);
        const { error: pmError, paymentMethod } = await stripe.createPaymentMethod({
            type: 'card',
            card: cardElement,
        });

        if (pmError) {
            setError(pmError.message);
            setProcessing(false);
            return;
        }

        try {
            const result = await fetchJson('/billing/ad-spend/setup-for-deployment', {
                method: 'POST',
                json: {
                    payment_method_id: paymentMethod.id,
                    campaign_id: campaign?.id,
                    strategy_id: strategy?.id,
                    creative_fingerprints: creativeFingerprints,
                    daily_budget: campaign?.daily_budget ?? estimatedDailySpend,
                    days_to_charge: daysToCharge,
                },
            });

            if (result.success) {
                onSuccess(result);
            } else {
                setError(result.error || 'Failed to set up ad spend billing');
            }
        } catch (err) {
            setError(err?.body?.error || 'An error occurred. Please try again.');
        }

        setProcessing(false);
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-6">
            <BudgetSummary
                campaign={campaign}
                campaignDurationDays={campaignDurationDays}
                estimatedDailySpend={estimatedDailySpend}
                daysToCharge={daysToCharge}
                upfrontCharge={upfrontCharge}
            />

            <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                    Payment Method
                </label>
                <div className="p-4 border border-gray-300 rounded-lg bg-white">
                    <CardElement options={CARD_ELEMENT_OPTIONS} />
                </div>
            </div>

            {error && (
                <div role="alert" className="p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
                    {error}
                </div>
            )}

            <div className="flex space-x-3">
                <button
                    type="button"
                    onClick={onCancel}
                    className="flex-1 py-3 px-4 rounded-lg font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 transition-colors"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    disabled={!stripe || processing}
                    className={`flex-1 py-3 px-4 rounded-lg font-medium text-white transition-colors ${
                        processing ? 'bg-gray-400 cursor-not-allowed' : 'bg-brand-dark hover:bg-brand-darker'
                    }`}
                >
                    {processing ? 'Processing...' : 'Continue to launch review'}
                </button>
            </div>

            <p className="text-xs text-gray-500 text-center">
                Your card is charged only after you approve the ads and budget and a channel qualifies.
                You can manage your billing in Settings → Ad Spend Billing.
            </p>
        </form>
    );
};

// Main Modal Component
export default function AdSpendSetupModal({ show, onClose, onSuccess, onContinueWithCredit, campaign, campaignName, strategy, creativeFingerprints, existingCredit, hasPaymentMethod }) {
    const isTopUp = existingCredit && existingCredit.status === 'active';
    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <div className="p-6">
                <div className="mb-6">
                    <h2 className="text-xl font-bold text-gray-900">
                        {isTopUp ? 'Fund This Campaign' : 'Set Up Ad Spend Billing'}
                    </h2>
                    <p className="mt-1 text-sm text-gray-500">
                        {isTopUp
                            ? <>You can save a card for <strong>{campaignName}</strong> to cover any amount beyond your available advertising credit.</>
                            : <>Before deploying <strong>{campaignName}</strong>, you can save a payment method for its approved advertising.</>
                        }
                    </p>
                </div>

                {strategy && <p className="mb-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-900">You will review a launch for {strategy.platform}. Funding uses this channel’s approved daily allocation.</p>}
                {hasPaymentMethod ? (
                    <SavedCardForm
                        campaign={campaign}
                        strategy={strategy}
                        creativeFingerprints={creativeFingerprints}
                        existingCredit={existingCredit}
                        onSuccess={onSuccess}
                        onCancel={onClose}
                    />
                ) : (
                    <StripeCardElements>
                        <NewCardForm
                            campaign={campaign}
                            strategy={strategy}
                            creativeFingerprints={creativeFingerprints}
                            existingCredit={existingCredit}
                            onSuccess={onSuccess}
                            onCancel={onClose}
                        />
                    </StripeCardElements>
                )}
                {!hasPaymentMethod && onContinueWithCredit && (
                    <button className="mt-3 text-sm font-medium text-brand-dark underline" type="button" onClick={onContinueWithCredit}>Review launch using available credit</button>
                )}
            </div>
        </Modal>
    );
}
