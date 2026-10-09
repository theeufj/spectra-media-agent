import React from 'react';
import { Link } from '@inertiajs/react';

/** The same useful product copy and discovery links as the initial HTML. */
export default function PublicMarketingResources({ content }) {
    if (!content) return null;

    return (
        <section className="border-t border-gray-200 bg-gray-50 py-12 sm:py-20">
            <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div className="grid gap-8 md:grid-cols-3">
                    {content.sections.map(section => (
                        <div key={section.title}>
                            <h2 className="text-xl font-bold text-gray-900">{section.title}</h2>
                            <p className="mt-3 leading-relaxed text-gray-600">{section.body}</p>
                        </div>
                    ))}
                </div>
                <nav aria-label="Product and campaign guides" className="mt-8 flex flex-wrap gap-x-6 gap-y-3">
                    {content.links.map(link => <Link key={link.href} href={link.href} className="font-semibold text-brand-darker hover:underline">{link.label}</Link>)}
                </nav>
            </div>
        </section>
    );
}
