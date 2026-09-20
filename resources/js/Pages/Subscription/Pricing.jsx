import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import SubscriptionTierSelector from '@/Components/SubscriptionTierSelector';
import { SetupStages } from '@/Components/SetupJourney';

export default function Pricing({ auth, plans, setupFee = null, journey = null }) {
    const [processing, setProcessing] = useState(false);
    const checkout = () => {
        setProcessing(true);
        router.post(route('setup-fee.checkout'), {}, { onFinish: () => setProcessing(false) });
    };
    const ready = journey?.checkout_ready === true;
    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title={setupFee?.intent ? 'Your Google Ads setup' : 'Pricing'} />
            <main className="mx-auto max-w-5xl px-4 py-10">
                {setupFee?.intent && <SetupStages stage={1} />}
                {!setupFee?.intent && <SubscriptionTierSelector plans={plans} />}
                {setupFee && !setupFee.paid && <section className="my-8 rounded-xl border border-gray-200 bg-white p-6 sm:p-8">
                    <p className="text-sm font-medium text-brand-dark">One payment · No subscription</p>
                    <h1 className="mt-2 text-2xl font-semibold text-gray-900">One-time Google Ads setup</h1>
                    <p className="mt-3 max-w-2xl text-gray-600">We build your Google Ads account, campaign, ads and tracking setup. You review the ads before we create the campaign paused, then we invite you to manage the account.</p>
                    {journey?.business && <dl className="my-6 grid gap-4 rounded-lg bg-gray-50 p-5 text-sm sm:grid-cols-2">
                        <div><dt className="text-gray-500">Business</dt><dd className="mt-1 font-medium text-gray-900">{journey.business.name}</dd><dd className="break-all text-gray-600">{journey.business.website}</dd></div>
                        <div><dt className="text-gray-500">Google Ads account</dt><dd className="mt-1 font-medium text-gray-900">{journey.business.country} · {journey.business.currency_code}</dd><dd className="text-gray-600">Ad spend is paid separately to Google in this currency.</dd></div>
                    </dl>}
                    <p className="mt-4 text-sm text-gray-600">You will need to accept the Google invitation, add Google billing and install and test tracking before launching. We do not start your ads when you pay.</p>
                    <div className="mt-8 flex flex-wrap items-center gap-5 border-t border-gray-100 pt-6">
                        <div><p className="text-3xl font-semibold text-gray-900">US${setupFee.price_usd}</p><p className="text-sm text-gray-500">One-time setup fee · charged in USD</p></div>
                        {ready ? <button disabled={processing} onClick={checkout} className="rounded-lg bg-brand-dark px-6 py-3 font-medium text-white disabled:opacity-50">{processing ? 'Opening secure checkout…' : 'Pay setup fee'}</button>
                            : <Link href={journey?.steps?.[0]?.action_url || route('quick-start')} className="rounded-lg bg-brand-dark px-6 py-3 font-medium text-white">Confirm business details first</Link>}
                    </div>
                    {!ready && <p className="mt-4 text-sm text-amber-800">Before payment, we need readable business information and your confirmation that the profile is accurate.</p>}
                    {journey && <Link href={route('brand-guidelines.index')} className="mt-4 inline-block text-sm text-brand-dark underline">Review your business profile</Link>}
                </section>}
                {setupFee?.paid && <section className="rounded-xl border border-green-200 bg-green-50 p-6 text-green-900">
                    <h1 className="text-xl font-semibold">Your one-time setup is paid</h1>
                    <p className="mt-2 text-sm">Your payment is recorded. Follow the build and see what remains in your setup checklist.</p>
                    <Link href={route('dashboard')} className="mt-5 inline-block rounded-lg bg-brand-dark px-5 py-3 font-medium text-white">Continue to your setup</Link>
                </section>}
                {setupFee?.intent && <details className="mt-8"><summary className="cursor-pointer text-sm text-gray-500">Compare ongoing management plans</summary><div className="mt-6"><SubscriptionTierSelector plans={plans} /></div></details>}
            </main>
        </AuthenticatedLayout>
    );
}
