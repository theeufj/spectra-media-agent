import React from 'react';

/**
 * The band of headline numbers that sits under a landing hero.
 *
 * @param {Array<{value, label, detail}>} items
 */
export default function StatsStrip({ items }) {
    return (
        /*
         * brand-darker, not brand-primary. The real-estate version ran
         * text-brand-accent labels on a brand-primary ground, which is 4.78:1 in
         * navy but only 2.07:1 in orange — the shared component has to hold on
         * both. On brand-darker the accent is 4.76:1, white is 7.65:1 and
         * white/80 is 5.44:1, all on the tighter of the two skins.
         *
         * text-white on the section, not just on each node: anything added here
         * later inherits a legible colour instead of the page's gray-800, which
         * is what put 2.0:1 text on the flagship's dark bands.
         */
        <section className="bg-brand-darker text-white">
            <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
                <div className="grid grid-cols-1 gap-8 text-center sm:grid-cols-3">
                    {items.map((stat) => (
                        <div key={stat.label}>
                            <p className="text-4xl font-bold">{stat.value}</p>
                            <p className="mt-1 font-medium text-brand-accent">{stat.label}</p>
                            {stat.detail && <p className="mt-1 text-sm text-white/80">{stat.detail}</p>}
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
