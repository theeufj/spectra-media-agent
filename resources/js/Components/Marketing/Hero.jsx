import React from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLongRightIcon } from '@heroicons/react/24/outline';

/*
 * Components/Marketing/* exists because the two skins were forked page sets —
 * Landing/HowItWorks/Pricing duplicated wholesale as RealEstate* — so an
 * improvement made on one brand had no path back to the other and every
 * contrast fix had to be made twice. Everything here is driven by the brand CSS
 * variables (brand-primary/dark/darker/accent, set per tenant by TenantTheme),
 * never a literal hex, so one change reaches both skins.
 *
 * Every colour pair below is computed against BOTH palettes:
 *   sitetospend      primary #ff4d00  dark #cc3d00  darker #992e00  accent #ffc300
 *   realpropertyads  primary #1b3c6b  dark #122a4e  darker #0a1c35  accent #c9a660
 * The orange skin is always the tighter of the two, so the ratios quoted in the
 * comments are sitetospend's; the navy skin clears them with room to spare.
 */

/**
 * A brand colour mixed down towards white, for tinted grounds.
 *
 * Not `bg-brand-primary/10`. tailwind.config.js maps brand.* to a bare
 * `var(--color-brand-primary)`, and Tailwind 3 can only apply an opacity
 * modifier to a value it can parse into channels — so every `/5`, `/10`, `/20`
 * and `/30` on a brand token compiles to **no CSS at all**. Verified against the
 * shipped bundle: `.bg-brand-primary` exists, `.bg-brand-primary\/10` does not,
 * which is why the flagship's hero gradient and every "brand tinted" panel on
 * both skins render as plain white today, and why the dark bands ended up with
 * inherited gray-800 text on them.
 *
 * color-mix reaches the same variable and actually produces a colour. Where it
 * is unsupported the declaration is dropped and the element falls back to the
 * white it already shows, so this cannot render worse than the current state.
 *
 * @param {number} percent  How much brand colour, 0-100.
 * @param {'primary'|'dark'|'darker'|'accent'} token
 */
export const brandTint = (percent, token = 'primary') =>
    `color-mix(in srgb, var(--color-brand-${token}) ${percent}%, white)`;

const CTA_BASE =
    'inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-dark focus:ring-offset-2';

/*
 * Size is a separate constant rather than part of CTA_PRIMARY because Tailwind
 * resolves conflicting utilities by their order in the generated stylesheet,
 * not by their order in the class attribute — appending `py-3 text-base` to a
 * string that already contains `py-4 text-lg` silently loses.
 */
export const CTA_SIZE = 'px-8 py-4 text-lg';
export const CTA_SIZE_COMPACT = 'px-6 py-3 text-base';

/*
 * brand-primary is 3.33:1 on white — it cannot legibly carry a label on the
 * flagship skin, which is why the resting fill is brand-dark (4.96:1) and the
 * hover is brand-darker (7.65:1) rather than the primary→dark pair the rest of
 * the marketing pages used to hand-roll.
 */
export const CTA_PRIMARY = `${CTA_BASE} bg-brand-dark text-white shadow-sm hover:bg-brand-darker`;

/*
 * The secondary half of the pair. brand-darker on white is 7.65:1 for the label
 * and the brand-dark border is 4.96:1 against white — comfortably over the 3:1
 * a UI boundary needs, so the button still reads as a button without a fill.
 */
export const CTA_SECONDARY = `${CTA_BASE} border-2 border-brand-dark bg-white text-brand-darker hover:bg-gray-50`;

/**
 * A marketing call to action.
 *
 * In-app routes go through Inertia's <Link> so the click does not tear down the
 * SPA shell; mailto:, tel: and off-site URLs have to be plain anchors, because
 * Link would try to fetch them as an Inertia page and the browser would never
 * hand them to the mail client.
 */
export function CtaLink({ href, className, onClick, children }) {
    const isExternal = /^(https?:|mailto:|tel:)/i.test(href);

    if (isExternal) {
        return (
            <a href={href} className={className} onClick={onClick}>
                {children}
            </a>
        );
    }

    return (
        <Link href={href} className={className} onClick={onClick}>
            {children}
        </Link>
    );
}

/**
 * The top of a marketing page: eyebrow, headline, subheading, an optional slot
 * for a conversion form, and a primary/secondary CTA pair.
 *
 * Centred by design and with no alignment prop. The flagship's old hero was a
 * left-hand column beside an empty right half — the exact defect this replaces —
 * so an align="left" switch would only make it possible to reintroduce.
 *
 * @param {string}    eyebrow      Short category line, rendered as a pill.
 * @param {ReactNode} headline     The h1. A node so a page can colour one phrase.
 * @param {ReactNode} sub          Supporting sentence under the headline.
 * @param {ReactNode} children     Optional block between sub and the CTA row.
 * @param {{href,label}} primaryCta
 * @param {{href,label}} secondaryCta
 * @param {string}    note         Small trust line under the CTAs.
 */
export default function Hero({ eyebrow, headline, sub, children, primaryCta, secondaryCta, note }) {
    return (
        <section
            className="bg-white"
            style={{ backgroundImage: `linear-gradient(to bottom, ${brandTint(10)}, #fff)` }}
        >
            <div className="mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-24 lg:px-8">
                <div className="mx-auto max-w-3xl text-center">
                    {eyebrow && (
                        /*
                         * brand-darker on a 10% brand tint is 6.75:1. The obvious
                         * text-brand-primary here is 2.93:1 — it fails even the
                         * relaxed 3:1 bar for large text.
                         */
                        <p
                            className="mb-4 inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-medium text-brand-darker sm:mb-6"
                            style={{ backgroundColor: brandTint(10) }}
                        >
                            <span className="h-1.5 w-1.5 rounded-full bg-brand-dark" aria-hidden="true" />
                            {eyebrow}
                        </p>
                    )}

                    <h1 className="text-3xl font-bold leading-tight tracking-tight text-gray-900 sm:text-5xl lg:text-6xl">
                        {headline}
                    </h1>

                    {sub && (
                        <p className="mx-auto mt-4 max-w-2xl text-base leading-relaxed text-gray-600 sm:mt-6 sm:text-lg lg:text-xl">
                            {sub}
                        </p>
                    )}
                </div>

                {children && <div className="mx-auto mt-6 max-w-2xl sm:mt-10">{children}</div>}

                {(primaryCta || secondaryCta || note) && (
                    <div className="mx-auto mt-10 max-w-3xl text-center">
                        {(primaryCta || secondaryCta) && (
                            <div className="flex flex-col items-center justify-center gap-4 sm:flex-row">
                                {primaryCta && (
                                    <CtaLink href={primaryCta.href} className={`${CTA_PRIMARY} ${CTA_SIZE}`} onClick={primaryCta.onClick}>
                                        {primaryCta.label}
                                        <ArrowLongRightIcon className="h-5 w-5" aria-hidden="true" />
                                    </CtaLink>
                                )}
                                {secondaryCta && (
                                    <CtaLink href={secondaryCta.href} className={`${CTA_SECONDARY} ${CTA_SIZE}`} onClick={secondaryCta.onClick}>
                                        {secondaryCta.label}
                                    </CtaLink>
                                )}
                            </div>
                        )}
                        {/*
                         * text-gray-500 is 2.54:1 and carried this exact "no credit
                         * card required" line on three marketing pages. gray-500 is
                         * 4.83:1 and still reads as secondary.
                         */}
                        {note && <p className="mt-4 text-sm text-gray-500">{note}</p>}
                    </div>
                )}
            </div>
        </section>
    );
}
