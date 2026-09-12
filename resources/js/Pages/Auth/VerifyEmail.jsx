import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { EnvelopeIcon } from '@heroicons/react/24/outline';
import { brandTint } from '@/Components/Marketing/Hero';
import { SUBMIT } from '@/Components/Forms';

/*
 * The wall every new client hits between registering and reaching Quick Start,
 * so it is worth it being clear about what happens next.
 *
 * The icon medallion was `bg-brand-primary/10` — an opacity modifier on a brand
 * token, which compiles to no CSS — so the circle it was drawn in did not exist
 * and the glyph floated on white. And the page's only heading was an <h2>, on a
 * page with no <h1> above it.
 */
export default function VerifyEmail({ status }) {
    const { post, processing } = useForm({});

    const submit = (e) => {
        e.preventDefault();
        post(route('verification.send'));
    };

    return (
        <GuestLayout>
            <Head title="Verify your email" />

            <div className="rounded-xl border border-gray-200 bg-white p-6 text-center shadow-sm sm:p-8">
                <span
                    className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full text-brand-darker"
                    style={{ backgroundColor: brandTint(12) }}
                >
                    <EnvelopeIcon className="h-8 w-8" aria-hidden="true" />
                </span>

                <h1 className="text-2xl font-bold tracking-tight text-gray-900">Check your email</h1>
                <p className="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-gray-600">
                    We've sent you a verification link. Click it and we'll take you straight to setting up
                    your first campaign.
                </p>

                {status === 'verification-link-sent' && (
                    <p
                        role="status"
                        className="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm font-medium text-green-800"
                    >
                        A new verification link is on its way.
                    </p>
                )}

                <form onSubmit={submit} className="mt-6">
                    <button type="submit" disabled={processing} className={SUBMIT}>
                        {processing ? 'Sending…' : 'Resend the link'}
                    </button>
                </form>

                <p className="mt-4 text-xs leading-relaxed text-gray-500">
                    Not there? It can take a minute, and it sometimes lands in spam.
                </p>

                <div className="mt-6 border-t border-gray-200 pt-4">
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="inline-flex min-h-[44px] items-center text-sm font-medium text-gray-600 hover:text-gray-900 hover:underline"
                    >
                        Sign out and use a different account
                    </Link>
                </div>
            </div>
        </GuestLayout>
    );
}
