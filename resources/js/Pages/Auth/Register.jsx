import { useEffect } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PlatformIcon from '@/Components/PlatformIcon';
import TurnstileField from '@/Components/TurnstileField';
import { Field, OrDivider, OAUTH_BUTTON, SUBMIT } from '@/Components/Forms';
import { brandTint } from '@/Components/Marketing/Hero';
import { SparklesIcon } from '@heroicons/react/24/outline';
import { trackConversion } from '@/utils/conversions';

function getDemoUrl() {
    try {
        return new URLSearchParams(window.location.search).get('demo_url') || '';
    } catch {
        return '';
    }
}

/*
 * The signup form. Like Login, it had no heading — it opened on "Create your
 * account to get started." set as body text — and its submit button was white
 * on brand-primary at 3.33:1, below the 4.5:1 a 14px label needs. Both come
 * from Forms.jsx now.
 *
 * The demo banner was violet, which is not a brand token and so ignored the
 * tenant skin entirely: a realpropertyads.com signup showed a violet panel
 * under a navy header.
 */
export default function Register({ enabledPlatforms = [] }) {
    const demoUrl = getDemoUrl();
    const demoDomain = demoUrl ? new URL(demoUrl).hostname.replace(/^www\./, '') : '';

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        cf_turnstile_response: '',
        demo_url: demoUrl,
    });

    // Fire a conversion for visitors arriving from the landing page demo — they've already
    // seen value from the tool so this signup represents a higher-intent lead.
    useEffect(() => {
        if (demoUrl) {
            trackConversion('sandbox_launched');
        }
    }, []);

    const submit = (e) => {
        e.preventDefault();
        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Register" />

            <div className="mb-6 text-center">
                <h1 className="text-2xl font-bold tracking-tight text-gray-900">Create your account</h1>
                <p className="mt-1.5 text-sm text-gray-600">
                    Free to explore. No credit card until you go live.
                </p>
            </div>

            {demoDomain && (
                <div
                    className="mb-4 flex items-start gap-2.5 rounded-lg border px-4 py-3 text-sm text-gray-700"
                    style={{ backgroundColor: brandTint(8), borderColor: brandTint(30) }}
                >
                    <SparklesIcon className="mt-0.5 h-4 w-4 shrink-0 text-brand-darker" aria-hidden="true" />
                    <span>
                        We'll analyse <strong className="font-semibold">{demoDomain}</strong> and set up your
                        brand guidelines automatically.
                    </span>
                </div>
            )}

            <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <form onSubmit={submit} className="space-y-4">
                    <input type="hidden" name="demo_url" value={data.demo_url} />

                    <Field
                        id="name"
                        label="Name"
                        type="text"
                        value={data.name}
                        autoComplete="name"
                        autoFocus
                        required
                        error={errors.name}
                        onChange={(e) => setData('name', e.target.value)}
                    />

                    <Field
                        id="email"
                        label="Email"
                        type="email"
                        value={data.email}
                        autoComplete="username"
                        required
                        error={errors.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />

                    <Field
                        id="password"
                        label="Password"
                        type="password"
                        value={data.password}
                        autoComplete="new-password"
                        required
                        hint="At least 8 characters."
                        error={errors.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />

                    <Field
                        id="password_confirmation"
                        label="Confirm password"
                        type="password"
                        value={data.password_confirmation}
                        autoComplete="new-password"
                        required
                        error={errors.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />

                    <TurnstileField
                        onToken={(token) => setData('cf_turnstile_response', token)}
                        error={errors.cf_turnstile_response}
                    />

                    <button type="submit" disabled={processing} className={SUBMIT}>
                        {processing ? 'Creating account…' : 'Create account'}
                    </button>

                    <p className="text-center text-xs leading-relaxed text-gray-500">
                        By creating an account you agree to our{' '}
                        <Link href={route('terms')} className="underline hover:text-gray-700">
                            Terms
                        </Link>{' '}
                        and{' '}
                        <Link href={route('privacy')} className="underline hover:text-gray-700">
                            Privacy Policy
                        </Link>
                        .
                    </p>
                </form>

                {enabledPlatforms.length > 0 && (
                    <>
                        <OrDivider />
                        <div className="space-y-3">
                            {enabledPlatforms.map((platform) => (
                                <a
                                    key={platform.slug}
                                    href={
                                        route(`${platform.slug}.redirect`) +
                                        (demoUrl ? `?demo_url=${encodeURIComponent(demoUrl)}` : '')
                                    }
                                    className={OAUTH_BUTTON}
                                >
                                    <PlatformIcon slug={platform.slug} />
                                    Sign up with {platform.name}
                                </a>
                            ))}
                        </div>
                    </>
                )}
            </div>

            <p className="mt-6 text-center text-sm text-gray-600">
                Already have an account?{' '}
                <Link href={route('login')} className="font-semibold text-brand-darker hover:underline">
                    Sign in
                </Link>
            </p>
        </GuestLayout>
    );
}
