import React from 'react';

/**
 * One consistent voice for long-running jobs: a spinner while the server
 * works, green when it lands, red with the reason when it fails, amber when
 * we've waited too long to keep promising. Purely presentational — pair it
 * with useJobWatch for the state.
 *
 * @param {'running'|'done'|'failed'|'timeout'|null} state  null renders nothing
 * @param {string} title
 * @param {string} [message]
 * @param {() => void} [onDismiss]  shown as an × on terminal states
 */
export default function JobProgressBanner({ state, title, message, onDismiss }) {
    if (!state) return null;

    const styles = {
        running: 'bg-blue-50 border-blue-200 text-blue-800',
        done: 'bg-green-50 border-green-200 text-green-800',
        failed: 'bg-red-50 border-red-200 text-red-800',
        timeout: 'bg-amber-50 border-amber-200 text-amber-800',
    };

    return (
        <div role="status" className={`border rounded-lg p-4 mb-6 flex items-start gap-3 ${styles[state]}`}>
            <span className="flex-shrink-0 mt-0.5">
                {state === 'running' && (
                    <svg data-testid="job-spinner" className="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24" aria-label="working">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                    </svg>
                )}
                {state === 'done' && <span aria-hidden="true">✓</span>}
                {state === 'failed' && <span aria-hidden="true">⚠️</span>}
                {state === 'timeout' && <span aria-hidden="true">⏱</span>}
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold">{title}</p>
                {message && <p className="text-sm mt-0.5">{message}</p>}
            </div>
            {onDismiss && state !== 'running' && (
                <button onClick={onDismiss} aria-label="Dismiss" className="flex-shrink-0 opacity-60 hover:opacity-100">
                    ×
                </button>
            )}
        </div>
    );
}
