import React, { useState } from 'react';
import Modal from '@/Components/Modal';
import { loadStripe } from '@stripe/stripe-js';
import { Elements, CardElement, useStripe, useElements } from '@stripe/react-stripe-js';

// Initialize Stripe
const stripePromise = loadStripe(import.meta.env.VITE_STRIPE_KEY);

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
const useBudgetCalcs = (campaign) => {
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
        if (campaign?.daily_budget) {
            const daily = Number(campaign.daily_budget);
            if (!isNaN(daily) && daily > 0) return daily;
        }
        const totalBudget = Number(campaign?.total_budget);
        const durationDays = getCampaignDurationDays();
        if (!isNaN(totalBudget) && totalBudget > 0 && durationDays > 0) {
            return totalBudget / durationDays;
        }
        return 50;
    };

    const campaignDurationDays = getCampaignDurationDays();
    const estimatedDailySpend = calculateDailyBudget();
    const daysToCharge = Math.min(7, campaignDurationDays);
    const totalBudget = Number(campaign?.total_budget || 0);
    const upfrontCharge = (daysToCharge === campaignDurationDays && totalBudget > 0)
        ? totalBudget
        : Math.round(estimatedDailySpend * daysToCharge * 100) / 100;

    return { campaignDurationDays, estimatedDailySpend, daysToCharge, upfrontCharge };
};

// Budget summary block shared by both form variants
const BudgetSummary = ({ campaign, campaignDurationDays, estimatedDailySpend, daysToCharge, upfrontCharge }) => (
    <>
        <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h4 className="font-medium text-blue-900 mb-2">Campaign Budget Details</h4>
            <div className="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <p className="text-blue-600">Total Budget</p>
                    <p className="font-semibold text-blue-900">${Number(campaign?.total_budget || 0).toLocaleString()}</p>
                </div>
                <div>
                    <p className="text-blue-600">Duration</p>
                    <p className="font-semibold text-blue-900">{campaignDurationDays} days</p>
                </div>
                <div>
                    <p className="text-blue-600">Calculated Daily Budget</p>
                    <p className="font-semibold text-blue-900">${estimatedDailySpend.toFixed(2)}/day</p>
                </div>
                <div>
                    <p className="text-blue-600">Campaign Dates</p>
                    <p className="font-semibold text-blue-900 text-xs">
                        {campaign?.start_date ? new Date(campaign.start_date).toLocaleDateString() : 'TBD'} → {campaign?.end_date ? new Date(campaign.end_date).toLocaleDateString() : 'TBD'}
                    </p>
                </div>
            </div>
        </div>

        <div className="bg-flame-orange-50 border border-flame-orange-200 rounded-lg p-4">
            <h4 className="font-medium text-flame-orange-900 mb-2">How Ad Spend Billing Works</h4>
            <ul className="text-sm text-flame-orange-700 space-y-1">
                <li>• We charge <strong>{daysToCharge} days of estimated ad spend</strong> upfront as credit
                    {daysToCharge < 7 && <span className="text-flame-orange-500"> (your campaign is only {campaignDurationDays} days)</span>}
                </li>
                <li>• Your actual daily spend is deducted each morning at 6 AM</li>
                <li>• Balance is automatically topped up when low</li>
                <li>• Full transparency – view all transactions in your dashboard</li>
            </ul>
        </div>

        <div className="bg-gray-50 rounded-lg p-4">
            <div className="flex justify-between items-center">
                <div>
                    <p className="text-sm text-gray-500">Daily Budget</p>
                    <p className="text-lg font-semibold text-gray-900">${estimatedDailySpend.toFixed(2)}/day</p>
                </div>
                <div className="text-right">
                    <p className="text-sm text-gray-500">Initial Charge ({daysToCharge} days)</p>
                    <p className="text-2xl font-bold text-flame-orange-600">${upfrontCharge.toFixed(2)}</p>
                </div>
            </div>
        </div>
    </>
);

// Form for users who already have a payment method on file — no card entry needed
const SavedCardForm = ({ campaign, onSuccess, onCancel }) => {
    const [error, setError] = useState(null);
    const [processing, setProcessing] = useState(false);
    const { campaignDurationDays, estimatedDailySpend, daysToCharge, upfrontCharge } = useBudgetCalcs(campaign);

    const handleSubmit = async (event) => {
        event.preventDefault();
        setProcessing(true);
        setError(null);

        try {
            const response = await fetch('/billing/ad-spend/setup-for-deployment', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({
                    daily_budget: estimatedDailySpend,
                    days_to_charge: daysToCharge,
                }),
            });

            const result = await response.json();

            if (result.success) {
                onSuccess(result);
            } else {
                setError(result.error || 'Failed to set up ad spend billing');
            }
        } catch (err) {
            setError('An error occurred. Please try again.');
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
                <span>Card on file from your subscription will be charged.</span>
            </div>

            {error && (
                <div className="p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
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
                        processing ? 'bg-gray-400 cursor-not-allowed' : 'bg-flame-orange-600 hover:bg-flame-orange-700'
                    }`}
                >
                    {processing ? 'Processing...' : `Pay $${upfrontCharge.toFixed(2)} & Deploy`}
                </button>
            </div>

            <p className="text-xs text-gray-500 text-center">
                By continuing, you authorize us to charge your card for ad spend.
                You can manage your billing in Settings → Ad Spend Billing.
            </p>
        </form>
    );
};

// Form for users with no payment method on file — collects card via Stripe Elements
const NewCardForm = ({ campaign, onSuccess, onCancel }) => {
    const stripe = useStripe();
    const elements = useElements();
    const [error, setError] = useState(null);
    const [processing, setProcessing] = useState(false);
    const { campaignDurationDays, estimatedDailySpend, daysToCharge, upfrontCharge } = useBudgetCalcs(campaign);

    const handleSubmit = async (event) => {
        event.preventDefault();
        setProcessing(true);
        setError(null);

        if (!stripe || !elements) return;

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
            const response = await fetch('/billing/ad-spend/setup-for-deployment', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({
                    payment_method_id: paymentMethod.id,
                    daily_budget: estimatedDailySpend,
                    days_to_charge: daysToCharge,
                }),
            });

            const result = await response.json();

            if (result.success) {
                onSuccess(result);
            } else {
                setError(result.error || 'Failed to set up ad spend billing');
            }
        } catch (err) {
            setError('An error occurred. Please try again.');
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
                <div className="p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
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
                        processing ? 'bg-gray-400 cursor-not-allowed' : 'bg-flame-orange-600 hover:bg-flame-orange-700'
                    }`}
                >
                    {processing ? 'Processing...' : `Pay $${upfrontCharge.toFixed(2)} & Deploy`}
                </button>
            </div>

            <p className="text-xs text-gray-500 text-center">
                By continuing, you authorize us to charge your card for ad spend.
                You can manage your billing in Settings → Ad Spend Billing.
            </p>
        </form>
    );
};

// Main Modal Component
export default function AdSpendSetupModal({ show, onClose, onSuccess, campaign, campaignName, existingCredit, hasPaymentMethod }) {
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
                            ? <>Add funds for <strong>{campaignName}</strong>. Your existing balance of <strong>${Number(existingCredit.current_balance).toFixed(2)}</strong> will be topped up.</>
                            : <>Before deploying <strong>{campaignName}</strong>, we need to fund your ad spend account.</>
                        }
                    </p>
                </div>

                {hasPaymentMethod ? (
                    <SavedCardForm
                        campaign={campaign}
                        onSuccess={onSuccess}
                        onCancel={onClose}
                    />
                ) : (
                    <Elements stripe={stripePromise}>
                        <NewCardForm
                            campaign={campaign}
                            onSuccess={onSuccess}
                            onCancel={onClose}
                        />
                    </Elements>
                )}
            </div>
        </Modal>
    );
}
