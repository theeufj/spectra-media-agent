import React from 'react';
import { CheckIcon } from '@heroicons/react/24/outline';
import { brandTint, CtaLink, CTA_PRIMARY, CTA_SIZE } from '@/Components/Marketing/Hero';

/*
 * Two ways of presenting the same {icon, title, body} list: a card grid and the
 * alternating illustrated rows the two "How It Works" pages use. They live in
 * one file because they take the same item shape and share the icon chip; a page
 * picks whichever fits and neither skin has to reimplement it.
 *
 * Icons are heroicons components, not emoji. Emoji render as whatever the
 * visitor's OS ships, are announced verbatim by screen readers ("eye", "brain"),
 * and were the single biggest visual difference between the two skins.
 */

// Tailwind scans source for literal class names, so these have to be whole
// strings — a template built from `columns` would never make it into the bundle.
const GRID_COLUMNS = {
    2: 'sm:grid-cols-2',
    3: 'sm:grid-cols-2 lg:grid-cols-3',
    4: 'sm:grid-cols-2 lg:grid-cols-4',
};

const SECTION_BACKGROUND = {
    white: 'bg-white',
    gray: 'bg-gray-50',
};

/*
 * brand-darker on a 10% brand tint is 6.75:1. text-brand-primary, the obvious
 * choice, is 2.93:1 on that tint and misses even the 3:1 icons are allowed.
 */
function IconChip({ icon: Icon, size = 'sm' }) {
    const box = size === 'lg' ? 'h-14 w-14' : 'h-10 w-10';
    const glyph = size === 'lg' ? 'h-7 w-7' : 'h-6 w-6';

    return (
        <span
            className={`inline-flex ${box} items-center justify-center rounded-lg text-brand-darker`}
            style={{ backgroundColor: brandTint(10) }}
        >
            <Icon className={glyph} aria-hidden="true" />
        </span>
    );
}

/**
 * A grid of feature cards.
 *
 * @param {string}    eyebrow
 * @param {ReactNode} title
 * @param {ReactNode} sub
 * @param {Array<{icon, title, body}>} items
 * @param {2|3|4}     columns
 * @param {'white'|'gray'} background
 * @param {ReactNode} footer   Rendered centred under the grid — usually a link out.
 */
export default function FeatureGrid({ eyebrow, title, sub, items, columns = 3, background = 'gray', footer }) {
    return (
        <section className={`${SECTION_BACKGROUND[background]} py-16 sm:py-24`}>
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                {(eyebrow || title || sub) && (
                    <div className="mx-auto mb-14 max-w-2xl text-center">
                        {eyebrow && (
                            <p className="text-sm font-semibold uppercase tracking-wider text-brand-darker">{eyebrow}</p>
                        )}
                        {title && (
                            <h2 className="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">{title}</h2>
                        )}
                        {sub && <p className="mt-4 text-lg leading-relaxed text-gray-600">{sub}</p>}
                    </div>
                )}

                <div className={`grid grid-cols-1 gap-8 ${GRID_COLUMNS[columns]}`}>
                    {items.map((item) => (
                        <div key={item.title} className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                            <IconChip icon={item.icon} />
                            <h3 className="mb-2 mt-4 text-lg font-semibold text-gray-900">{item.title}</h3>
                            <p className="text-sm leading-relaxed text-gray-600">{item.body}</p>
                        </div>
                    ))}
                </div>

                {footer && <div className="mt-12 text-center">{footer}</div>}
            </div>
        </section>
    );
}

/**
 * The same items as full-width alternating rows: copy and bullets on one side,
 * an illustrated brand-tinted panel on the other.
 *
 * @param {Array<{icon, title, body, bullets, panel:{title, detail}}>} items
 * @param {{href,label}} cta   Repeated after each row, as both skins already did.
 * @param {string}    note     Small print under each CTA.
 */
export function FeatureSteps({ items, cta, note }) {
    return (
        <section className="bg-white py-16 sm:py-24">
            <div className="mx-auto max-w-7xl space-y-24 px-4 sm:px-6 lg:px-8">
                {items.map((item, index) => {
                    // Every second row swaps sides on wide screens only; on a phone
                    // the copy must stay above its panel or the reading order breaks.
                    const flipped = index % 2 === 1;

                    return (
                        <div key={item.title} className="grid grid-cols-1 items-center gap-12 lg:grid-cols-2">
                            <div className={flipped ? 'lg:order-2' : undefined}>
                                <IconChip icon={item.icon} size="lg" />
                                <h2 className="mb-4 mt-6 text-3xl font-bold text-gray-900">{item.title}</h2>
                                <p className="mb-6 text-lg leading-relaxed text-gray-600">{item.body}</p>

                                {item.bullets && (
                                    <ul className="space-y-3">
                                        {item.bullets.map((bullet) => (
                                            <li key={bullet} className="flex items-start text-gray-600">
                                                <CheckIcon
                                                    className="mr-3 h-5 w-5 flex-shrink-0 text-brand-dark"
                                                    strokeWidth={2.5}
                                                    aria-hidden="true"
                                                />
                                                {bullet}
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                {cta && (
                                    <div className="mt-8">
                                        <CtaLink href={cta.href} className={`${CTA_PRIMARY} ${CTA_SIZE}`}>
                                            {cta.label}
                                        </CtaLink>
                                        {note && <p className="mt-3 text-sm text-gray-500">{note}</p>}
                                    </div>
                                )}
                            </div>

                            <div
                                className={`flex min-h-[300px] items-center justify-center rounded-2xl border p-8 ${
                                    flipped ? 'lg:order-1' : ''
                                }`}
                                style={{ backgroundColor: brandTint(5), borderColor: brandTint(25) }}
                            >
                                <div className="text-center">
                                    {/* 4.66:1 for the glyph and 7.19:1 for the caption on the 5% tint. */}
                                    <item.icon className="mx-auto h-16 w-16 text-brand-dark" aria-hidden="true" />
                                    <p className="mt-4 font-semibold text-brand-darker">{item.panel.title}</p>
                                    <p className="mt-2 text-sm text-gray-500">{item.panel.detail}</p>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
