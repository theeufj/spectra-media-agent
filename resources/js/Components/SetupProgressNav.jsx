import React from 'react';
import { Link } from '@inertiajs/react';
import { brandTint } from '@/Components/Marketing/Hero';
import { usePolling } from '@/hooks/usePolling';

/**
 * SetupProgressNav - the new-account checklist.
 *
 * Steps come from /api/setup-progress and mirror the real funnel:
 * scan → campaign → budget → payment → deploy. Each step carries a
 * `status` of completed | in_progress | failed | pending.
 *
 * Polls while the server reports work in flight (`is_working`) — the site
 * scan completes minutes after signup, and this card is how the user finds
 * out without refreshing.
 */
export default function SetupProgressNav() {
    const { data: setupData, error } = usePolling('/api/setup-progress', {
        interval: 8000,
        // Keep polling until nothing is moving server-side. The first
        // response arrives through the same mechanism (immediate fetch).
        until: (data) => data && data.is_working === false,
    });

    // Nothing to show yet, nothing worth showing (fetch failed — don't render
    // a broken empty card), or setup is done.
    if (!setupData || error) return null;
    if (!setupData.steps?.length || setupData.progress === 100) return null;

    const { steps, progress, completed_steps, total_steps, current_step } = setupData;

    /*
     * The server decides which step is current, not this component.
     *
     * It sends `current_step` (the first step that is not complete) and this
     * recomputed its own answer as "the first incomplete step that is also not
     * in progress" — so the moment anything was actually running, the server
     * pointed at the running step and the card highlighted the one after it.
     * The user was being pointed past the work in flight, on the one screen
     * whose job is to say what happens next.
     */
    const currentKey = current_step?.key
        ?? steps.find(s => !s.completed)?.key;

    /*
     * A later step cannot read as done while an earlier one has failed.
     *
     * The strip showed "Scan your website" in warning red and "Deploy your
     * ads" ticked green at the same time, which is contradictory to anyone
     * reading it left to right: how are ads live if the scan never finished?
     * The steps are a dependency chain, so anything after the first failure is
     * shown as blocked by it.
     */
    const firstFailureIndex = steps.findIndex(s => s.status === 'failed');
    const isBlocked = (index) => firstFailureIndex !== -1 && index > firstFailureIndex;

    return (
        <div
            className="border rounded-lg p-4 mb-4"
            style={{
                backgroundImage: `linear-gradient(to right, ${brandTint(10)}, #faf5ff)`,
                borderColor: brandTint(20),
            }}
        >
            <div className="flex items-center justify-between mb-3">
                <div className="flex items-center space-x-2">
                    <span className="text-lg">🚀</span>
                    <h3 className="text-sm font-semibold text-gray-900">Get Started</h3>
                </div>
                <span className="text-xs text-gray-500">{completed_steps}/{total_steps} complete</span>
            </div>

            {/* Progress Bar */}
            <div className="w-full bg-gray-200 rounded-full h-1.5 mb-3">
                <div
                    className="bg-gradient-to-r from-brand-primary to-purple-500 h-1.5 rounded-full transition-all duration-500"
                    style={{ width: `${progress}%` }}
                />
            </div>

            {/* Steps */}
            <div className="flex flex-col sm:flex-row gap-2">
                {steps.map((step, index) => {
                    /*
                       Not every step is somewhere you can go.
                       
                       Steps we do ourselves carry action_url: null — the whole
                       one-time-setup journey is mostly ours, and "the keys are
                       yours" has no page to visit. Rendering those as <Link>
                       took the entire dashboard down: Inertia calls
                       href.toString() while merging query data, so a null href
                       is a TypeError inside a useMemo, which the error boundary
                       catches as "Something went wrong". A US$999 customer met
                       that screen on the redirect back from paying.
                    */
                    const className = `
                        sm:flex-1 flex items-center space-x-2 px-3 py-2 rounded-lg text-xs
                        transition-all duration-200
                        ${stepClasses(step, step.key === currentKey, isBlocked(index))}
                    `;
                    const body = (
                        <>
                            <span className="flex-shrink-0">{stepMarker(step, isBlocked(index))}</span>
                            <div className="min-w-0">
                                <p className="font-medium truncate">{step.title}</p>
                            </div>
                        </>
                    );
                    // "Which one am I on" was carried only by a background
                    // colour, so a screen reader heard five equal links.
                    const current = step.key === currentKey ? 'step' : undefined;

                    if (! step.action_url) {
                        return (
                            <div key={step.key} className={className} aria-current={current} title={step.description}>
                                {body}
                            </div>
                        );
                    }

                    return (
                        <Link
                            key={step.key}
                            href={step.action_url}
                            className={className}
                            aria-current={current}
                            title={step.description}
                        >
                            {body}
                        </Link>
                    );
                })}
            </div>

            {/* One line of guidance for whatever the card is currently doing */}
            {steps.some(s => s.status === 'in_progress' || s.status === 'failed') && (
                <p className="mt-2 text-xs text-gray-600">
                    {steps.find(s => s.status === 'failed')?.description
                        ?? steps.find(s => s.status === 'in_progress')?.description}
                </p>
            )}
        </div>
    );
}

function stepClasses(step, isCurrent, blocked = false) {
    // Blocked reads before complete: a tick behind a failed step is a lie.
    if (blocked) return 'bg-gray-50 text-gray-400 hover:bg-gray-100';
    if (step.completed) return 'bg-green-100 text-green-700 hover:bg-green-200';
    if (step.status === 'failed') return 'bg-red-50 text-red-700 hover:bg-red-100 ring-2 ring-red-200';
    if (step.status === 'in_progress') return 'bg-blue-50 text-blue-700 hover:bg-blue-100';
    /*
     * The ring is the solid brand-dark, not a tint: a ring here has to stay a
     * class (this function returns nothing but classes) and brand-dark is 4.96:1
     * on white, comfortably over the 3:1 a UI boundary needs, where the /50 it
     * replaces drew nothing at all.
     */
    if (isCurrent) return 'bg-brand-tint-20 text-brand-darker hover:bg-brand-tint-30 ring-2 ring-brand-dark';
    return 'bg-white text-gray-500 hover:bg-gray-50';
}

function stepMarker(step, blocked = false) {
    if (blocked) return '·';
    if (step.completed) return '✓';
    if (step.status === 'failed') return '⚠️';
    if (step.status === 'in_progress') {
        return (
            <svg className="animate-spin h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" aria-label="in progress">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
        );
    }
    return stepIcon(step.key);
}

function stepIcon(stepKey) {
    const icons = {
        site_scan: '🔍',
        first_campaign: '🚀',
        budget_confirmed: '💰',
        conversion_tracking: '📈',
        payment: '💳',
        deployed: '📡',
    };
    return icons[stepKey] || '📋';
}
