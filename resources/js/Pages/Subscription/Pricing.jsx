import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import SubscriptionTierSelector from '@/Components/SubscriptionTierSelector';

export default function Pricing({ auth, plans, setupFee = null }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Subscription Plans</h2>}
        >
            <Head title="Pricing" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <SubscriptionTierSelector plans={plans} />

                    {/* The one-and-done alternative: set up once, hand over,
                        nothing recurring. Highlighted when they chose this
                        path at QuickStart. */}
                    {setupFee && !setupFee.paid && (
                        <div className={`mt-10 rounded-xl border-2 bg-white p-6 sm:p-8 flex flex-col sm:flex-row sm:items-center gap-6 ${setupFee.intent ? 'border-brand-primary shadow-md' : 'border-gray-200'}`}>
                            <div className="flex-1 min-w-0">
                                <p className="text-xs font-semibold uppercase tracking-wide text-brand-dark mb-1">
                                    Don't want a subscription?
                                </p>
                                <h3 className="text-xl font-bold text-gray-900">One-time Google Ads setup</h3>
                                <p className="text-sm text-gray-600 mt-2">
                                    We build your Google Ads account, campaigns, ad copy and conversion
                                    tracking — everything arrives paused, you add your own Google billing,
                                    and the account is yours. One payment. Nothing recurring. No management.
                                </p>
                            </div>
                            <div className="flex-shrink-0 text-center">
                                <p className="text-3xl font-extrabold text-gray-900">US${setupFee.price_usd}</p>
                                <p className="text-xs text-gray-500 mb-3">once, ever</p>
                                <button
                                    onClick={() => router.post(route('setup-fee.checkout'))}
                                    className="px-6 py-3 bg-brand-primary hover:bg-brand-dark text-white rounded-md font-semibold"
                                >
                                    Pay setup fee
                                </button>
                            </div>
                        </div>
                    )}

                    {setupFee?.paid && (
                        <div className="mt-10 rounded-xl border border-green-200 bg-green-50 p-6 text-green-800">
                            <p className="font-semibold">Your one-time setup is paid ✓</p>
                            <p className="text-sm mt-1">We're building your account — you'll get the keys by email when it's ready. No subscription needed.</p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
