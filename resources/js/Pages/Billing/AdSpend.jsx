import React, { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
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
const PaymentForm = ({ onSuccess, buttonText = 'Update Payment Method', isRetry = false }) => {
    const stripe = useStripe();
    const elements = useElements();
    const [error, setError] = useState(null);
    const [processing, setProcessing] = useState(false);
    const [succeeded, setSucceeded] = useState(false);

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

        // Send to server
        try {
            const response = await fetch('/billing/ad-spend/update-payment-method', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({
                    payment_method_id: paymentMethod.id,
                    retry_payment: isRetry,
                }),
            });

            const result = await response.json();

            if (result.success) {
                setSucceeded(true);
                if (onSuccess) onSuccess(result);
            } else {
                setError(result.error || 'Failed to update payment method');
            }
        } catch (err) {
            setError('An error occurred. Please try again.');
        }

        setProcessing(false);
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <div className="p-4 border border-gray-300 rounded-lg bg-white">
                <CardElement options={CARD_ELEMENT_OPTIONS} />
            </div>
            
            {error && (
                <div className="p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
                    {error}
                </div>
            )}
            
            {succeeded && (
                <div className="p-3 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
                    Payment method updated successfully!
                </div>
            )}
            
            <button
                type="submit"
                disabled={!stripe || processing || succeeded}
                className={`w-full py-3 px-4 rounded-lg font-medium text-white transition-colors ${
                    processing || succeeded
                        ? 'bg-gray-400 cursor-not-allowed'
                        : isRetry
                            ? 'bg-red-600 hover:bg-red-700'
                            : 'bg-indigo-600 hover:bg-indigo-700'
                }`}
            >
                {processing ? 'Processing...' : succeeded ? 'Updated!' : buttonText}
            </button>
        </form>
    );
};

// Main Component
const AdSpend = ({ auth, credit, transactions, paymentFailed }) => {
    const [showPaymentForm, setShowPaymentForm] = useState(false);
    const [retrying, setRetrying] = useState(false);

    const handleRetryPayment = async () => {
        setRetrying(true);
        try {
            const response = await fetch('/billing/ad-spend/retry', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            });
            const result = await response.json();
            if (result.success) {
                router.reload();
            } else {
                alert(result.error || 'Payment retry failed. Please update your payment method.');
                setShowPaymentForm(true);
            }
        } catch (err) {
            alert('An error occurred. Please try again.');
        }
        setRetrying(false);
    };

    const formatCurrency = (amount) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(amount);
    };

    const formatDate = (dateString) => {
        return new Date(dateString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const getStatusColor = (status) => {
        switch (status) {
            case 'active':
                return 'bg-green-100 text-green-800';
            case 'suspended':
                return 'bg-yellow-100 text-yellow-800';
            case 'paused':
                return 'bg-red-100 text-red-800';
            default:
                return 'bg-gray-100 text-gray-800';
        }
    };

    const getTransactionTypeColor = (type) => {
        switch (type) {
            case 'charge':
                return 'text-green-600';
            case 'deduction':
                return 'text-red-600';
            case 'refund':
                return 'text-blue-600';
            default:
                return 'text-gray-600';
        }
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Ad Spend Billing</h2>}
        >
            <Head title="Ad Spend Billing" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    
                    {/* Payment Failed Alert */}
                    {paymentFailed && (
                        <div className="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
                            <div className="flex items-start">
                                <div className="flex-shrink-0">
                                    <svg className="h-6 w-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                </div>
                                <div className="ml-3 flex-1">
                                    <h3 className="text-lg font-medium text-red-800">Payment Failed</h3>
                                    <p className="mt-1 text-red-700">
                                        Your last payment attempt failed. Please update your payment method to avoid campaign disruption.
                                        {credit?.failed_payments_count >= 2 && (
                                            <span className="font-bold"> Your campaigns have been paused.</span>
                                        )}
                                    </p>
                                    <div className="mt-4 flex space-x-4">
                                        <button
                                            onClick={handleRetryPayment}
                                            disabled={retrying}
                                            className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
                                        >
                                            {retrying ? 'Retrying...' : 'Retry Payment'}
                                        </button>
                                        <button
                                            onClick={() => setShowPaymentForm(true)}
                                            className="px-4 py-2 bg-white text-red-600 border border-red-600 rounded-lg hover:bg-red-50"
                                        >
                                            Update Payment Method
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Credit Balance Card */}
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6">
                            <div className="flex items-center justify-between mb-6">
                                <h3 className="text-lg font-semibold text-gray-900">Ad Spend Credit Balance</h3>
                                <span className={`px-3 py-1 rounded-full text-sm font-medium ${getStatusColor(credit?.status || 'active')}`}>
                                    {credit?.status?.charAt(0).toUpperCase() + credit?.status?.slice(1) || 'No Account'}
                                </span>
                            </div>

                            {credit ? (
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div className="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-xl p-6 text-white">
                                        <p className="text-indigo-100 text-sm">Current Balance</p>
                                        <p className="text-3xl font-bold mt-1">{formatCurrency(credit.current_balance)}</p>
                                        <p className="text-indigo-200 text-sm mt-2">
                                            ~{credit.days_remaining || 0} days remaining
                                        </p>
                                    </div>
                                    
                                    <div className="bg-gray-50 rounded-xl p-6">
                                        <p className="text-gray-500 text-sm">Daily Budget</p>
                                        <p className="text-2xl font-bold text-gray-900 mt-1">{formatCurrency(credit.daily_budget)}</p>
                                        <p className="text-gray-500 text-sm mt-2">Set across all campaigns</p>
                                    </div>
                                    
                                    <div className="bg-gray-50 rounded-xl p-6">
                                        <p className="text-gray-500 text-sm">Estimated Daily Spend</p>
                                        <p className="text-2xl font-bold text-gray-900 mt-1">{formatCurrency(credit.estimated_daily_spend)}</p>
                                        <p className="text-gray-500 text-sm mt-2">Based on recent activity</p>
                                    </div>
                                </div>
                            ) : (
                                <div className="text-center py-8">
                                    <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                                    </svg>
                                    <p className="mt-2 text-gray-500">No ad spend account yet</p>
                                    <p className="text-sm text-gray-400">Your credit account will be created when you launch your first campaign</p>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Payment Method Section */}
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6">
                            <div className="flex items-center justify-between mb-6">
                                <h3 className="text-lg font-semibold text-gray-900">Payment Method</h3>
                                {!showPaymentForm && (
                                    <button
                                        onClick={() => setShowPaymentForm(true)}
                                        className="text-indigo-600 hover:text-indigo-800 text-sm font-medium"
                                    >
                                        Update Payment Method
                                    </button>
                                )}
                            </div>

                            {showPaymentForm ? (
                                <Elements stripe={stripePromise}>
                                    <PaymentForm
                                        isRetry={paymentFailed}
                                        buttonText={paymentFailed ? 'Update & Retry Payment' : 'Update Payment Method'}
                                        onSuccess={() => {
                                            setShowPaymentForm(false);
                                            router.reload();
                                        }}
                                    />
                                    <button
                                        onClick={() => setShowPaymentForm(false)}
                                        className="mt-3 w-full text-center text-gray-500 hover:text-gray-700 text-sm"
                                    >
                                        Cancel
                                    </button>
                                </Elements>
                            ) : (
                                <div className="flex items-center p-4 bg-gray-50 rounded-lg">
                                    <svg className="h-8 w-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                                    </svg>
                                    <div className="ml-4">
                                        <p className="text-gray-900 font-medium">Card on file</p>
                                        <p className="text-gray-500 text-sm">Using your default payment method</p>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* How It Works */}
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6">
                            <h3 className="text-lg font-semibold text-gray-900 mb-4">How Ad Spend Billing Works</h3>
                            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div className="text-center p-4">
                                    <div className="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                        <span className="text-indigo-600 font-bold">1</span>
                                    </div>
                                    <h4 className="font-medium text-gray-900">Initial Charge</h4>
                                    <p className="text-sm text-gray-500 mt-1">7 days of estimated spend charged upfront</p>
                                </div>
                                <div className="text-center p-4">
                                    <div className="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                        <span className="text-indigo-600 font-bold">2</span>
                                    </div>
                                    <h4 className="font-medium text-gray-900">Daily Billing</h4>
                                    <p className="text-sm text-gray-500 mt-1">Actual spend deducted each morning at 6 AM</p>
                                </div>
                                <div className="text-center p-4">
                                    <div className="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                        <span className="text-indigo-600 font-bold">3</span>
                                    </div>
                                    <h4 className="font-medium text-gray-900">Auto Top-Up</h4>
                                    <p className="text-sm text-gray-500 mt-1">Balance auto-replenished when low</p>
                                </div>
                                <div className="text-center p-4">
                                    <div className="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                        <span className="text-indigo-600 font-bold">4</span>
                                    </div>
                                    <h4 className="font-medium text-gray-900">Full Transparency</h4>
                                    <p className="text-sm text-gray-500 mt-1">View all transactions below</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Transaction History */}
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6">
                            <h3 className="text-lg font-semibold text-gray-900 mb-4">Transaction History</h3>
                            
                            {transactions && transactions.length > 0 ? (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {transactions.map((transaction) => (
                                                <tr key={transaction.id} className="hover:bg-gray-50">
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {formatDate(transaction.created_at)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <span className={`text-sm font-medium capitalize ${getTransactionTypeColor(transaction.type)}`}>
                                                            {transaction.type}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 text-sm text-gray-900">
                                                        {transaction.description}
                                                    </td>
                                                    <td className={`px-6 py-4 whitespace-nowrap text-sm text-right font-medium ${getTransactionTypeColor(transaction.type)}`}>
                                                        {transaction.type === 'deduction' ? '-' : '+'}{formatCurrency(transaction.amount)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                                                        {formatCurrency(transaction.balance_after)}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="text-center py-8">
                                    <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                    </svg>
                                    <p className="mt-2 text-gray-500">No transactions yet</p>
                                    <p className="text-sm text-gray-400">Transactions will appear here once your campaigns start running</p>
                                </div>
                            )}
                        </div>
                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
};

export default AdSpend;
