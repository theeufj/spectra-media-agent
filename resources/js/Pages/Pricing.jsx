import { useEffect } from 'react';
import PageTitle from '@/Components/PageTitle';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import Hero from '@/Components/Marketing/Hero';
import GoogleManagementPlan, { ManagementBillingDetails } from '@/Components/Marketing/GoogleManagementPlan';
import FaqAccordion from '@/Components/Marketing/FaqAccordion';
import PublicMarketingResources from '@/Components/Marketing/PublicMarketingResources';
import { trackConversion } from '@/utils/conversions';

export default function Pricing({ auth, publicContent, plans = [], faqs = [], earlyExitFeeUsd, earlyExitMonths }) {
    useEffect(() => { trackConversion('pricing_visit'); }, []);

    return (
        <>
            <PageTitle />
            <div className="min-h-screen bg-white">
                <Header auth={auth} />
                <main>
                    <Hero eyebrow={publicContent.eyebrow} headline={publicContent.headline} sub={publicContent.intro} />
                    <GoogleManagementPlan plans={plans} onPricingPage signedIn={Boolean(auth?.user)} />
                    <ManagementBillingDetails earlyExitFeeUsd={earlyExitFeeUsd} earlyExitMonths={earlyExitMonths} monthlyPriceCents={plans.find(plan => plan.slug === 'starter')?.price_cents} />
                    {faqs.length > 0 && (
                        <section className="mx-auto max-w-3xl px-4 py-12 sm:px-6">
                            <h2 className="mb-6 text-2xl font-bold text-gray-900">Frequently asked questions</h2>
                            <FaqAccordion items={faqs} />
                        </section>
                    )}
                    <PublicMarketingResources content={publicContent} />
                </main>
                <Footer />
            </div>
        </>
    );
}
