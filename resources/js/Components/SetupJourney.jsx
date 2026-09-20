import React from 'react';
import { Link } from '@inertiajs/react';

export const SETUP_STAGES = ['Your business', 'Your campaign', 'Review your ads', 'Your account is ready'];

export function SetupStages({ stage = 0 }) {
    return (
        <nav aria-label="Setup progress" className="mb-8">
            <ol className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                {SETUP_STAGES.map((title, index) => (
                    <li key={title} aria-current={index === stage ? 'step' : undefined}
                        className={`flex items-center gap-3 rounded-lg border px-4 py-3 text-sm ${index === stage ? 'border-brand-primary bg-white font-semibold text-brand-dark' : 'border-gray-200 text-gray-500'}`}>
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-current text-xs" aria-hidden="true">{index + 1}</span>
                        {title}
                    </li>
                ))}
            </ol>
        </nav>
    );
}

export function HandoverChecklist({ items = [] }) {
    return (
        <section className="rounded-xl border border-gray-200 bg-white p-6">
            <h2 className="text-xl font-semibold text-gray-900">Before you switch on ads</h2>
            <p className="mt-2 text-sm text-gray-600">Account creation and launch readiness are separate. Check each remaining item in your Google Ads account.</p>
            <ul className="mt-6 divide-y divide-gray-100">
                {items.map(item => (
                    <li key={item.title} className="flex items-start gap-3 py-4">
                        <span className={`mt-0.5 text-sm ${item.done ? 'text-green-700' : 'text-amber-700'}`} aria-label={item.done ? 'Confirmed' : 'Action needed'}>{item.done ? '✓' : '○'}</span>
                        <div><h3 className="font-medium text-gray-900">{item.title}</h3><p className="mt-1 text-sm text-gray-600">{item.detail}</p>
                            {item.url && <Link href={item.url} className="mt-2 inline-block text-sm font-medium text-brand-dark underline">Set up tracking</Link>}
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}
