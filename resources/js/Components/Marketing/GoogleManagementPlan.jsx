import { Link } from '@inertiajs/react';
import { AD_PREVIEW_CTA, CtaLink } from '@/Components/Marketing/Hero';
import PricingTable, { PlanPrice, formatUsdCents } from '@/Components/Marketing/PricingTable';

export default function GoogleManagementPlan({ plans, onPricingPage = false, signedIn = false }) {
    const starter = plans.find(plan => plan.slug === 'starter');
    if (!starter) {
        if (!onPricingPage) return null;
        return (
            <section className="mx-auto max-w-3xl px-4 pb-12 text-center">
                <p className="text-gray-700">Paid signup is currently unavailable. You can still preview ads for your business.</p>
                <CtaLink href={AD_PREVIEW_CTA.href} className="mt-3 inline-block font-semibold text-brand-darker underline">{AD_PREVIEW_CTA.label}</CtaLink>
            </section>
        );
    }

    const signupAction = signedIn
        ? { href: '/subscription/pricing', label: 'View workspace billing' }
        : { href: '/register', label: 'Create your workspace' };

    return (
        <PricingTable
            title="Google Ads management"
            sub="One business workspace. You choose the advertising budget."
            plans={[{
                id: starter.id,
                name: starter.name,
                description: 'We prepare your ads and keywords, monitor your campaign after launch and show what you spend per tracked enquiry.',
                price: <PlanPrice plan={starter} />,
                features: starter.features ?? [],
                note: onPricingPage && <a href="#management-billing" className="font-medium text-brand-darker underline">See ad credit and existing-account setup terms</a>,
                cta: onPricingPage ? signupAction : { href: '/pricing', label: 'See pricing and billing details' },
            }]}
        />
    );
}

export function ManagementBillingDetails({ earlyExitFeeUsd, earlyExitMonths = 3, monthlyPriceCents }) {
    const feeCents = typeof earlyExitFeeUsd === 'number' ? Math.round(earlyExitFeeUsd * 100) : null;
    const firstMonthBalance = earlyExitMonths > 1 && feeCents !== null && monthlyPriceCents > 0
        ? Math.max(0, feeCents - monthlyPriceCents)
        : null;

    return (
        <section aria-labelledby="management-billing" className="border-y border-gray-200 bg-gray-50 py-12">
            <div className="mx-auto max-w-3xl space-y-5 px-4 sm:px-6">
                <h2 id="management-billing" className="scroll-mt-20 text-2xl font-bold text-gray-900">Before you launch</h2>
                <div>
                    <h3 className="font-semibold text-gray-900">Management and ad spend</h3>
                    <p className="mt-1 leading-relaxed text-gray-700">Your monthly subscription is billed in US dollars. On a managed account, the first ad credit covers seven days of your estimated advertising spend. For a US$20 daily budget, that means US$140 in ad credit. Actual media spend draws down the balance, and low balances trigger automatic top-ups.</p>
                </div>
                {earlyExitMonths > 0 && <div>
                    <h3 className="font-semibold text-gray-900">Building in an account you already own</h3>
                    <p className="mt-1 leading-relaxed text-gray-700">If we build campaigns in an ad account you own or control, cancelling or removing our access during your first {earlyExitMonths === 1 ? 'paid month' : `${earlyExitMonths} paid months`} can trigger a setup fee{feeCents !== null && <> of {formatUsdCents(feeCents)}</>}, less subscription fees already paid. {firstMonthBalance !== null && <>After one {formatUsdCents(monthlyPriceCents)} payment, the remaining fee would be {formatUsdCents(firstMonthBalance)}. </>}Accounts we provision and operate for you are exempt. <Link href="/terms-of-service#early-exit" className="font-semibold text-brand-darker underline">Read the early-exit terms</Link>.</p>
                </div>}
                <p className="text-sm text-gray-700">Questions about your account or budget? <a href="mailto:support@sitetospend.com" className="font-semibold text-brand-darker underline">Email support@sitetospend.com</a>.</p>
            </div>
        </section>
    );
}
