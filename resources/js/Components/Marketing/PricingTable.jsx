import React from 'react';
import { CheckIcon } from '@heroicons/react/24/outline';
import { brandTint, CtaLink, CTA_PRIMARY, CTA_SECONDARY, CTA_SIZE_COMPACT } from '@/Components/Marketing/Hero';

/*
 * The plan card, shared by the flagship's database-driven plans, the landing
 * page's three-card teaser and the real-estate page's single bespoke package.
 *
 * `price` is a node rather than a string because the two skins genuinely price
 * differently — one shows $149/mo, the other a launch fee plus a monthly — and
 * forcing both through one string format is how the pages got forked in the
 * first place. Everything around the price (chrome, badge, tick list, CTA,
 * small print) is identical and now only exists once.
 */

const GRID_COLUMNS = {
    1: 'max-w-lg mx-auto',
    2: 'sm:grid-cols-2 max-w-4xl mx-auto',
    3: 'md:grid-cols-3',
};

/**
 * The price line for a row of the `plans` table.
 *
 * Lives here because the landing teaser and the pricing page render the same
 * three cases and used to disagree about the third: the teaser said "Custom",
 * which describes the pricing without naming the next action, while the pricing
 * page said "Contact us". One of them was always going to be missed.
 */
export function PlanPrice({ plan }) {
    if (plan.price_cents > 0) {
        return (
            <>
                <span className="text-4xl font-extrabold">${Math.round(plan.price_cents / 100)}</span>
                <span className="text-xl font-medium">/{plan.billing_interval === 'year' ? 'year' : 'mo'}</span>
            </>
        );
    }

    if (!plan.is_free) {
        return <span className="text-3xl font-extrabold">Contact us</span>;
    }

    return (
        <>
            <span className="text-4xl font-extrabold">$0</span>
            <span className="text-xl font-medium">/mo</span>
        </>
    );
}

/**
 * @param {ReactNode} title
 * @param {ReactNode} sub
 * @param {Array<{
 *   id, name, description, eyebrow,
 *   price: ReactNode, features: string[], badge, highlighted, note,
 *   cta: {href, label}
 * }>} plans
 * @param {1|2|3}     columns
 * @param {ReactNode} footnote  Rendered centred under the grid.
 * @param {'white'|'gray'} background
 */
export default function PricingTable({ title, sub, plans = [], columns = plans.length >= 3 ? 3 : plans.length || 1, footnote, background = 'white' }) {
    return (
        <section className={`${background === 'gray' ? 'bg-gray-50' : 'bg-white'} py-16 sm:py-24`}>
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                {(title || sub) && (
                    <div className="mx-auto mb-14 max-w-2xl text-center">
                        {title && <h2 className="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl">{title}</h2>}
                        {sub && <p className="mt-4 text-lg text-gray-600">{sub}</p>}
                    </div>
                )}

                <div className={`grid grid-cols-1 gap-8 ${GRID_COLUMNS[columns] ?? GRID_COLUMNS[3]}`}>
                    {plans.map((plan) => (
                        <div
                            key={plan.id ?? plan.name}
                            className={`relative flex flex-col rounded-xl p-8 ${
                                plan.highlighted
                                    ? 'border-2 border-brand-dark shadow-lg'
                                    : 'border border-gray-200 bg-white shadow-sm'
                            }`}
                            style={plan.highlighted ? { backgroundColor: brandTint(5) } : undefined}
                        >
                            {plan.badge && (
                                // White on brand-dark is 4.96:1, which 12px text needs.
                                // On brand-primary it would be 3.33:1.
                                <span className="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-brand-dark px-3 py-1 text-xs font-semibold text-white">
                                    {plan.badge}
                                </span>
                            )}

                            {plan.eyebrow && (
                                <p className="text-sm font-semibold uppercase tracking-wider text-brand-darker">{plan.eyebrow}</p>
                            )}
                            <h3 className="text-2xl font-bold text-gray-900">{plan.name}</h3>
                            {plan.description && <p className="mt-2 text-sm text-gray-500">{plan.description}</p>}

                            <div className="mt-4 text-gray-900">{plan.price}</div>

                            {plan.features?.length > 0 && (
                                <ul className="mt-8 flex-grow space-y-4">
                                    {plan.features.map((feature) => (
                                        <li key={feature} className="flex items-start">
                                            {/*
                                                brand-dark is 4.96:1 on white. The green-500 tick
                                                the flagship used is 2.28:1 and the brand-accent
                                                tick the real-estate page used is 1.61:1 — neither
                                                clears the 3:1 a meaningful icon needs.
                                            */}
                                            <CheckIcon
                                                className="mr-3 h-5 w-5 flex-shrink-0 text-brand-dark"
                                                strokeWidth={2.5}
                                                aria-hidden="true"
                                            />
                                            <span className="text-gray-700">{feature}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {plan.note && <p className="mt-4 text-center text-xs text-gray-500">{plan.note}</p>}

                            {plan.cta && (
                                <div className="mt-6">
                                    <CtaLink
                                        href={plan.cta.href}
                                        className={`${plan.highlighted ? CTA_PRIMARY : CTA_SECONDARY} ${CTA_SIZE_COMPACT} w-full`}
                                    >
                                        {plan.cta.label}
                                    </CtaLink>
                                </div>
                            )}
                        </div>
                    ))}
                </div>

                {footnote && <div className="mt-12 text-center">{footnote}</div>}
            </div>
        </section>
    );
}
