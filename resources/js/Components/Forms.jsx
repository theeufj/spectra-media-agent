import React from 'react';

/*
 * The field and button treatments the auth screens share.
 *
 * Both pages had hand-written the same `mt-1 block w-full rounded-md
 * border-gray-300 …` string on every input and the same button class on every
 * submit — including `bg-brand-primary`, which puts white on #ff4d00 at
 * **3.33:1**. Body-size button labels need 4.5:1. That is the same trap
 * Components/Marketing/Hero.jsx documents at length, and the reason the
 * marketing CTAs resolved to brand-dark instead; the auth screens simply never
 * got the memo, so the two most important buttons on the site — Register and
 * Sign in — were the two that failed.
 *
 * Errors are red-700 (5.9:1), not red-600 (4.3:1), and carry role="alert" so a
 * screen reader announces them when they appear rather than only on focus.
 */

export const FIELD =
    'block h-11 w-full rounded-lg border-gray-300 shadow-sm transition-colors focus:border-brand-dark focus:ring-brand-dark sm:text-sm';

// h-11 keeps every control over the 44px a touch target needs.
export const SUBMIT =
    'flex h-11 w-full items-center justify-center rounded-lg bg-brand-dark px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-darker focus:outline-none focus:ring-2 focus:ring-brand-dark focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-600';

export const OAUTH_BUTTON =
    'flex h-11 w-full items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-dark focus:ring-offset-2';

/**
 * A labelled input with its error message.
 *
 * @param {string} id     Also the form field name.
 * @param {string} label  Always rendered — never a placeholder standing in for
 *                        one, which disappears the moment someone types.
 */
export function Field({ id, label, error, hint, className = '', ...props }) {
    return (
        <div>
            <label htmlFor={id} className="block text-sm font-medium text-gray-700">
                {label}
            </label>
            <input
                id={id}
                name={id}
                aria-invalid={error ? 'true' : undefined}
                aria-describedby={error ? `${id}-error` : hint ? `${id}-hint` : undefined}
                className={`mt-1.5 ${FIELD} ${error ? 'border-red-500' : ''} ${className}`}
                {...props}
            />
            {hint && !error && (
                <p id={`${id}-hint`} className="mt-1.5 text-xs text-gray-500">
                    {hint}
                </p>
            )}
            {error && (
                <p id={`${id}-error`} role="alert" className="mt-1.5 text-sm text-red-700">
                    {error}
                </p>
            )}
        </div>
    );
}

/** The "Or continue with" rule above the OAuth buttons. */
export function OrDivider({ children = 'Or continue with' }) {
    return (
        <div className="relative my-6">
            <div className="absolute inset-0 flex items-center" aria-hidden="true">
                <div className="w-full border-t border-gray-200" />
            </div>
            <div className="relative flex justify-center">
                <span className="bg-white px-3 text-sm text-gray-500">{children}</span>
            </div>
        </div>
    );
}
