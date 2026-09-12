import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';
import CloudflareTurnstile from '@/Components/CloudflareTurnstile';

/*
 * The bot-check, plus the failure state it never had.
 *
 * Login and Register each mounted CloudflareTurnstile with onVerify and
 * onExpire and no onError. The widget calls its error-callback and the page did
 * nothing with it: no message, no token, no clue. The visitor pressed the
 * button, the server rejected the request for a missing
 * `cf_turnstile_response`, and they were left with a validation error about a
 * field they could not see and had no way to satisfy.
 *
 * That is not a rare path. A Turnstile widget only renders on hostnames listed
 * in its allowlist in the Cloudflare dashboard, so every new tenant skin hits
 * exactly this until someone remembers to add the domain — and the symptom, a
 * signup form that simply refuses, looks nothing like its cause.
 *
 * So: say what happened, and say what to do about it. The support link is the
 * escape hatch, because a visitor cannot fix a hostname allowlist themselves.
 *
 * Renders nothing at all unless BOTH Turnstile keys are configured —
 * HandleInertiaRequests only sends a site key when CloudflareTurnstile::enabled()
 * is true, which is the same predicate the server-side rule uses.
 *
 * @param {(token: string) => void} onToken  Receives '' when the token is lost.
 * @param {string} error  Server-side validation error for the token field.
 */
export default function TurnstileField({ onToken, error }) {
    const { turnstileSiteKey } = usePage().props;
    const [failed, setFailed] = useState(false);

    if (! turnstileSiteKey) {
        return null;
    }

    return (
        <div>
            <div className="flex justify-center">
                <CloudflareTurnstile
                    siteKey={turnstileSiteKey}
                    onVerify={(token) => {
                        setFailed(false);
                        onToken(token);
                    }}
                    onExpire={() => onToken('')}
                    onError={() => {
                        setFailed(true);
                        onToken('');
                    }}
                />
            </div>

            {failed && (
                <p role="alert" className="mt-2 text-center text-sm text-red-700">
                    The security check could not load. Refresh the page to try again — if it keeps
                    happening, email{' '}
                    <a className="font-medium underline" href="mailto:support@sitetospend.com">
                        support@sitetospend.com
                    </a>{' '}
                    and we will let you in.
                </p>
            )}

            {error && !failed && (
                <p role="alert" className="mt-2 text-center text-sm text-red-700">
                    {error}
                </p>
            )}
        </div>
    );
}
