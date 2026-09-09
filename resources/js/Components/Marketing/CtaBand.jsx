import React from 'react';
import { ArrowLongRightIcon } from '@heroicons/react/24/outline';
import { CtaLink } from '@/Components/Marketing/Hero';

/*
 * The closing call to action.
 *
 * Flat brand-darker, not the from-brand-dark → brand-darker gradient the three
 * flagship pages used. On the brand-dark end of that gradient white/80 is only
 * 3.68:1 and white/90 is 4.29:1, so every supporting line had to be pure white
 * or fail — which is exactly how this band ended up with a white h2 above an
 * eyebrow and a subheading that were left inheriting the page's gray-800, at
 * 2.0:1 over the orange and 1.4:1 over the purple it faded into.
 *
 * On flat brand-darker: white 7.65:1, white/80 5.44:1, brand-accent 4.76:1 —
 * a legible hierarchy instead of one legible line. text-white sits on the
 * section so anything added later inherits a colour that works here.
 */

const ON_DARK_PRIMARY =
    'inline-flex items-center justify-center gap-2 rounded-lg bg-white px-8 py-4 text-lg font-semibold text-brand-darker shadow-lg transition-colors hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-brand-darker';

const ON_DARK_SECONDARY =
    'inline-flex items-center justify-center gap-2 rounded-lg border-2 border-white px-8 py-4 text-lg font-semibold text-white transition-colors hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-brand-darker';

/**
 * @param {ReactNode} title
 * @param {ReactNode} body
 * @param {{href,label}} primaryCta
 * @param {{href,label}} secondaryCta
 * @param {string}    note
 */
export default function CtaBand({ title, body, primaryCta, secondaryCta, note }) {
    return (
        <section className="bg-brand-darker py-16 text-white sm:py-24">
            <div className="mx-auto max-w-3xl px-4 text-center sm:px-6 lg:px-8">
                <h2 className="text-3xl font-extrabold sm:text-4xl">{title}</h2>
                {body && <p className="mt-4 text-lg text-white/80">{body}</p>}

                {(primaryCta || secondaryCta) && (
                    <div className="mt-8 flex flex-col items-center justify-center gap-4 sm:flex-row">
                        {primaryCta && (
                            <CtaLink href={primaryCta.href} className={ON_DARK_PRIMARY} onClick={primaryCta.onClick}>
                                {primaryCta.label}
                                <ArrowLongRightIcon className="h-5 w-5" aria-hidden="true" />
                            </CtaLink>
                        )}
                        {secondaryCta && (
                            <CtaLink href={secondaryCta.href} className={ON_DARK_SECONDARY} onClick={secondaryCta.onClick}>
                                {secondaryCta.label}
                            </CtaLink>
                        )}
                    </div>
                )}

                {note && <p className="mt-8 text-sm text-white/80">{note}</p>}
            </div>
        </section>
    );
}
