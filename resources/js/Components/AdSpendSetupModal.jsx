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

// Payment Form Component
const PaymentForm = ({ campaign, onSuccess, onCancel }) => {
    const stripe = useStripe();
    const elements = useElements();
    const [error, setError] = useState(null);
    const [processing, setProcessing] = useState(false);

    // Calculate campaign duration in days
    const getCampaignDurationDays = () => {
        if (campaign?.start_date && campaign?.end_date) {
            // Handle both ISO strings and date strings
            const startDate = new Date(campaign.start_date);
            const endDate = new Date(campaign.end_date);
            const diffTime = endDate.getTime() - startDate.getTime();
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            return diffDays > 0 ? diffDays : 30;
        }
        return 30; // Default assumption
    };

    // Calculate daily budget from campaign settings
    const calculateDailyBudget = () => {
        // If campaign has explicit daily_budget, use it
        if (campaign?.daily_budget) {
            const daily = Number(campaign.daily_budget);
            if (!isNaN(daily) && daily > 0) {
                return daily;
            }
        }
        
        // Calculate from total_budget and campaign duration
        const totalBudget = Number(campaign?.total_budget);
        const durationDays = getCampaignDurationDays();
        
        if (!isNaN(totalBudget) && totalBudget > 0 && durationDays > 0) {
            return totalBudget / durationDays;
        }
        
        // Default minimum
        return 50;
    };

    const campaignDurationDays = getCampaignDurationDays();
    const estimatedDailySpend = calculateDailyBudget();
    
    // Charge the lesser of 7 days OR the full campaign duration
    const daysToCharge = Math.min(7, campaignDurationDays);
    const upfrontCharge = estimatedDailySpend * daysToCharge;

    const handleSubmit = async (event) => {
        event.preventDefault();
        setProcessing(true);
        setError(null);

        if (!stripe || !elements) {
            return;
        }

        const cardElement = elements.getElement(CardElement);

        // Create payment method
        const { error: pmError, paymentMethod } = await stripe.createPaymentMethod({
            type: 'card',
            card: cardElement,
        });

        if (pmError) {
            setError(pmError.message);
            setProcessing(false);
            return;
        }

        // Send to server to set up payment method and initialize credit
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
            {/* Campaign Budget Summary */}
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

            {/* Info Box */}
            <div className="bg-indigo-50 border border-indigo-200 rounded-lg p-4">
                <h4 className="font-medium text-indigo-900 mb-2">How Ad Spend Billing Works</h4>
                <ul className="text-sm text-indigo-700 space-y-1">
                    <li>• We charge <strong>{daysToCharge} days of estimated ad spend</strong> upfront as credit
                        {daysToCharge < 7 && <span className="text-indigo-500"> (your campaign is only {campaignDurationDays} days)</span>}
                    </li>
                    <li>• Your actual daily spend is deducted each morning at 6 AM</li>
                    <li>• Balance is automatically topped up when low</li>
                    <li>• Full transparency – view all transactions in your dashboard</li>
                </ul>
            </div>

            {/* Estimated Charge */}
            <div className="bg-gray-50 rounded-lg p-4">
                <div className="flex justify-between items-center">
                    <div>
                        <p className="text-sm text-gray-500">Daily Budget</p>
                        <p className="text-lg font-semibold text-gray-900">${estimatedDailySpend.toFixed(2)}/day</p>
                    </div>
                    <div className="text-right">
                        <p className="text-sm text-gray-500">Initial Charge ({daysToCharge} days)</p>
                        <p className="text-2xl font-bold text-indigo-600">${upfrontCharge.toFixed(2)}</p>
                    </div>
                </div>
            </div>

            {/* Card Input */}
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
                        processing
                            ? 'bg-gray-400 cursor-not-allowed'
                            : 'bg-indigo-600 hover:bg-indigo-700'
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
export default function AdSpendSetupModal({ show, onClose, onSuccess, campaign, campaignName }) {
    return (
        <Modal show={show} onClose={onClose} maxWidth="lg">
            <div className="p-6">
                <div className="mb-6">
                    <h2 className="text-xl font-bold text-gray-900">Set Up Ad Spend Billing</h2>
                    <p className="mt-1 text-sm text-gray-500">
                        Before deploying <strong>{campaignName}</strong>, we need to set up your ad spend billing.
                    </p>
                </div>

                <Elements stripe={stripePromise}>
                    <PaymentForm 
                        campaign={campaign}
                        onSuccess={onSuccess}
                        onCancel={onClose}
                    />
                </Elements>
            </div>
        </Modal>
    );
}
