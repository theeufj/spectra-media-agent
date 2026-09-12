import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import PlatformIcon from '@/Components/PlatformIcon';
import TurnstileField from '@/Components/TurnstileField';
import { Field, OrDivider, OAUTH_BUTTON, SUBMIT } from '@/Components/Forms';

/*
 * The page had no heading of any kind — it opened on a bare "Email" label — and
 * its submit button was white on brand-primary at 3.33:1. Both are fixed by the
 * shared pieces now: Forms.jsx owns the field and button treatments, and
 * TurnstileField owns the bot-check including the failure state this page used
 * to swallow silently.
 */
export default function Login({ status, enabledPlatforms = [], canResetPassword = false }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
        cf_turnstile_response: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), { onFinish: () => reset('password') });
    };

    return (
        <GuestLayout>
            <Head title="Log in" />

            <div className="mb-6 text-center">
                <h1 className="text-2xl font-bold tracking-tight text-gray-900">Welcome back</h1>
                <p className="mt-1.5 text-sm text-gray-600">Sign in to pick up where you left off.</p>
            </div>

            {status && (
                <p role="status" className="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm font-medium text-green-800">
                    {status}
                </p>
            )}

            <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <form onSubmit={submit} className="space-y-4">
                    <Field
                        id="email"
                        label="Email"
                        type="email"
                        value={data.email}
                        autoComplete="username"
                        autoFocus
                        required
                        error={errors.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />

                    <Field
                        id="password"
                        label="Password"
                        type="password"
                        value={data.password}
                        autoComplete="current-password"
                        required
                        error={errors.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />

                    <div className="flex items-center justify-between gap-4">
                        {/* py-3 so the label, which is the real target, clears 44px. */}
                        <label className="flex min-h-[44px] cursor-pointer items-center">
                            <input
                                type="checkbox"
                                name="remember"
                                checked={data.remember}
                                onChange={(e) => setData('remember', e.target.checked)}
                                className="h-4 w-4 rounded border-gray-300 text-brand-dark shadow-sm focus:ring-brand-dark"
                            />
                            <span className="ml-2 text-sm text-gray-600">Remember me</span>
                        </label>

                        {canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className="inline-flex min-h-[44px] items-center text-sm font-medium text-brand-darker hover:underline"
                            >
                                Forgot password?
                            </Link>
                        )}
                    </div>

                    <TurnstileField
                        onToken={(token) => setData('cf_turnstile_response', token)}
                        error={errors.cf_turnstile_response}
                    />

                    <button type="submit" disabled={processing} className={SUBMIT}>
                        {processing ? 'Signing in…' : 'Sign in'}
                    </button>
                </form>

                {enabledPlatforms.length > 0 && (
                    <>
                        <OrDivider />
                        <div className="space-y-3">
                            {enabledPlatforms.map((platform) => (
                                <a
                                    key={platform.slug}
                                    href={route(`${platform.slug}.redirect`)}
                                    className={OAUTH_BUTTON}
                                >
                                    <PlatformIcon slug={platform.slug} />
                                    Sign in with {platform.name}
                                </a>
                            ))}
                        </div>
                    </>
                )}
            </div>

            <p className="mt-6 text-center text-sm text-gray-600">
                Don't have an account?{' '}
                <Link
                    href={route('register')}
                    className="font-semibold text-brand-darker hover:underline"
                >
                    Create one free
                </Link>
            </p>
        </GuestLayout>
    );
}
