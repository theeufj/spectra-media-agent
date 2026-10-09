import PublicMarketingResources from '@/Components/Marketing/PublicMarketingResources';
import React from 'react';
import PageTitle from '@/Components/PageTitle';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import Hero, { brandTint } from '@/Components/Marketing/Hero';
import FeatureGrid from '@/Components/Marketing/FeatureGrid';
import CtaBand from '@/Components/Marketing/CtaBand';
import { BoltIcon, EyeIcon, LockClosedIcon } from '@heroicons/react/24/outline';

/*
 * No <Head> here. LandingController builds the metadata and app.blade.php
 * prints it server-side. See App\Http\Controllers\Concerns\RendersPageMeta.
 *
 * Like Features.jsx, this page hand-rolled its sections and carried the two
 * defects that follows from it: `from-brand-primary/10` and `bg-brand-primary/10`,
 * neither of which compiles to any CSS — an opacity modifier on a brand token
 * is unparseable to Tailwind 3, so the hero gradient and the stats panel both
 * rendered plain white — and 🔍 🤖 🛡️ as icons, which render as whatever the
 * visitor's OS ships and are announced literally by a screen reader.
 */

const values = [
    {
        icon: EyeIcon,
        title: 'Transparency',
        body: 'Your subscription and advertising budget are separate. Managed ad-spend credits are reconciled against actual platform spend.',
    },
    {
        icon: BoltIcon,
        title: 'Always on',
        body: 'The best marketing never sleeps. The agents fix issues, test ideas and shift budget while you are focused elsewhere.',
    },
    {
        icon: LockClosedIcon,
        title: 'Your data, your control',
        body: 'Customer data and brand assets stay yours. Accounts sit under our management umbrella; ownership and visibility do not.',
    },
];

const stats = [
    { stat: '6', label: 'AI agent specialisms' },
    { stat: 'Review', label: 'Profile, budget and ads before launch' },
    { stat: 'USD', label: 'Subscription prices; media spend separate' },
    { stat: 'One-time', label: 'Google Ads setup available' },
];

const differences = [
    {
        label: 'Your brand, instantly',
        body: 'No twenty-page brand questionnaire. We read your website and pick up your colours, fonts and tone straight away.',
    },
    {
        label: 'Campaign health stays visible',
        body: 'Agents check delivery and policy issues, attempt supported repairs and surface problems that need your input.',
    },
    {
        label: 'We watch your competitors for you',
        body: 'Every week we look at who is advertising in your space, read their sites, and work out how you beat them.',
    },
    {
        label: 'What you see is what you pay',
        body: 'Your subscription covers management. Advertising spend is additional and shown separately. Review both before launching.',
    },
];

export default function About({ auth, publicContent }) {
    return (
        <>
            <PageTitle />
            <div className="min-h-screen bg-white">
                <Header auth={auth} />

                <main>
                    <Hero
                        eyebrow={publicContent.eyebrow}
                        headline={publicContent.headline}
                        sub={publicContent.intro}
                    />

                    {/* Mission */}
                    <section className="bg-white py-12 sm:py-24">
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="grid grid-cols-1 items-center gap-8 lg:grid-cols-2 lg:gap-16">
                                <div>
                                    <h2 className="text-3xl font-bold text-gray-900 sm:text-4xl">Our mission</h2>
                                    <div className="mt-5 space-y-4 text-base leading-relaxed text-gray-600 sm:mt-6 sm:text-lg">
                                        <p>
                                            Traditional agencies charge thousands a month, lock you into a long contract, and
                                            send a report once a week if you are lucky. We built sitetospend because that is
                                            not good enough for most businesses.
                                        </p>
                                        <p>
                                            Our agents work every hour of every day — finding competitors, shifting budget to
                                            the right times, fixing rejected ads the moment they happen, always testing
                                            something new. It costs a fraction of a retainer and it never takes a day off.
                                        </p>
                                        <p>
                                            We are not here to replace your marketing instincts. We are here to hand every
                                            business the tools that used to need an enormous ad budget to justify.
                                        </p>
                                    </div>
                                </div>
                                {/*
                                    color-mix, not `bg-brand-primary/10` — the
                                    latter compiles to nothing, so this panel has
                                    been rendering as plain white on white.
                                */}
                                <dl
                                    className="space-y-5 rounded-2xl p-6 sm:space-y-8 sm:p-10"
                                    style={{ backgroundColor: brandTint(10) }}
                                >
                                    {stats.map((item) => (
                                        <div key={item.label} className="flex items-center gap-4">
                                            <dd className="w-24 flex-shrink-0 text-right text-2xl font-extrabold text-brand-darker sm:text-3xl">
                                                {item.stat}
                                            </dd>
                                            <dt className="font-medium text-gray-700">{item.label}</dt>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        </div>
                    </section>

                    <FeatureGrid title="What we stand for" items={values} />

                    {/* How we're different */}
                    <section className="bg-white py-12 sm:py-24">
                        <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                            <h2 className="text-center text-3xl font-bold text-gray-900 sm:text-4xl">
                                How we're different
                            </h2>
                            <dl className="mt-8 space-y-6 sm:mt-12">
                                {differences.map((item) => (
                                    <div key={item.label} className="flex items-start gap-4">
                                        <span
                                            className="mt-2 h-2 w-2 flex-shrink-0 rounded-full bg-brand-dark"
                                            aria-hidden="true"
                                        />
                                        <div>
                                            <dt className="font-bold text-gray-900">{item.label}</dt>
                                            <dd className="mt-1 leading-relaxed text-gray-600">{item.body}</dd>
                                        </div>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    </section>

                    <CtaBand
                        title="Ready to see the difference?"
                        body="Start free and find out what AI-run advertising does for your business."
                        primaryCta={{ href: '/register', label: 'Get started free' }}
                        secondaryCta={{ href: '/pricing', label: 'View pricing' }}
                    />
                <PublicMarketingResources content={publicContent} />
                </main>

                <Footer />
            </div>
        </>
    );
}
