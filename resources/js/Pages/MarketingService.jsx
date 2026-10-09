import { Link } from '@inertiajs/react';
import PageTitle from '@/Components/PageTitle';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import Hero from '@/Components/Marketing/Hero';
import CtaBand from '@/Components/Marketing/CtaBand';
import PricingTable, { PlanPrice } from '@/Components/Marketing/PricingTable';

export default function MarketingService({ auth, service, plans = [] }) {
    const setupOnly = service.slug === 'google-ads-setup';

    return (
        <>
            <PageTitle />
            <div className="min-h-screen bg-gray-50 flex flex-col">
                <Header auth={auth} />
                <main className="flex-1">
                    <Hero eyebrow={service.eyebrow} headline={service.headline} sub={service.intro} />
                    <div className="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
                        <article className="prose-article rounded-2xl border border-gray-100 bg-white p-6 sm:p-10" dangerouslySetInnerHTML={{ __html: service.content }} />
                        <section className="mt-12" aria-labelledby="service-questions">
                            <h2 id="service-questions" className="text-2xl font-bold text-gray-900">Questions before you start</h2>
                            <dl className="mt-6 space-y-6">
                                {service.faqs.map(faq => (
                                    <div key={faq.question} className="rounded-xl border border-gray-200 bg-white p-6">
                                        <dt className="font-semibold text-gray-900">{faq.question}</dt>
                                        <dd className="mt-2 leading-relaxed text-gray-600">{faq.answer}</dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                        <nav aria-label="Related services" className="mt-10 flex flex-wrap gap-4 text-sm font-semibold text-brand-darker">
                            <Link href="/google-ads-management" className="py-3 hover:underline">Google Ads management</Link>
                            <Link href="/ai-ads-management" className="py-3 hover:underline">AI ads management</Link>
                            <Link href="/google-ads-setup" className="py-3 hover:underline">One-time Google Ads setup</Link>
                        </nav>
                    </div>
                    {!setupOnly && plans.length > 0 && (
                        <PricingTable
                            title="Current management plans"
                            sub="Subscription prices are in USD. Advertising spend is additional."
                            plans={plans.map(plan => ({
                                ...plan,
                                price: <PlanPrice plan={plan} />,
                                features: plan.features ?? [],
                                cta: { href: '/register', label: 'Review my business' },
                            }))}
                            footnote={<Link href="/pricing" className="text-brand-darker font-semibold hover:underline">Compare all plans and the one-time option</Link>}
                        />
                    )}
                    <CtaBand
                        title={setupOnly ? 'Prepare your business for a one-time build' : 'Start with your business, offer and goal'}
                        body="Create your account and review the business information before choosing a paid plan."
                        primaryCta={{ href: '/register', label: 'Get started' }}
                        secondaryCta={{ href: '/pricing', label: 'Compare pricing' }}
                    />
                </main>
                <Footer />
            </div>
        </>
    );
}
