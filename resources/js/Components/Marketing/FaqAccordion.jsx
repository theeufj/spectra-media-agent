import React from 'react';
import { ChevronDownIcon } from '@heroicons/react/24/outline';

/*
 * The FAQ list, collapsed.
 *
 * Three pages had grown three different answers to the same problem. The
 * landing page rendered every answer expanded — 2,585px of unbroken prose on a
 * 390px phone, a quarter of the whole page, sitting between the pricing table
 * and the closing CTA. The two pricing pages each hand-rolled an accordion off
 * `useState`, and both mounted the answer only while open:
 *
 *     {openFAQ === index && <div>{faq.answer}</div>}
 *
 * which is the version that actually costs traffic. The FAQPage schema on those
 * pages asserts answer text that is not in the DOM for eleven of twelve
 * questions, and Google's structured-data policy requires the answer to be
 * present on the page. Content inside a closed <details> *is* present — it is
 * indexed and weighted normally under mobile-first indexing — so collapsing is
 * free where unmounting is not.
 *
 * Native <details>/<summary> rather than a button and a state hook: the browser
 * supplies the disclosure semantics, the expanded state and the keyboard
 * handling, and — the part that matters for a client-rendered Inertia app — it
 * works in the HTML before React hydrates, so a crawler that stops at first
 * paint still sees every answer.
 *
 * @param {Array<{question: string, answer: string}>} items
 * @param {number|null} defaultOpen  Index left open on load, or null for all closed.
 *                                   The first question answers "what is this",
 *                                   which is worth showing without a click.
 */
export default function FaqAccordion({ items, defaultOpen = 0 }) {
    return (
        <div className="divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-200 bg-white">
            {items.map((faq, index) => (
                <details key={faq.question} className="group" open={index === defaultOpen}>
                    {/*
                        list-none plus the webkit marker rule removes the default
                        disclosure triangle in both engines; without the second
                        Safari draws its own arrow next to our chevron.

                        py-4 on a text-base line gives a 56px row, over the 44px
                        minimum a touch target needs, and the whole row is the
                        target rather than just the words.
                    */}
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 transition-colors hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-dark [&::-webkit-details-marker]:hidden">
                        <h3 className="text-base font-semibold text-gray-900 sm:text-lg">{faq.question}</h3>
                        <ChevronDownIcon
                            className="h-5 w-5 flex-shrink-0 text-brand-dark transition-transform duration-200 group-open:rotate-180"
                            aria-hidden="true"
                        />
                    </summary>
                    <p className="px-5 pb-5 text-[15px] leading-relaxed text-gray-600">{faq.answer}</p>
                </details>
            ))}
        </div>
    );
}
