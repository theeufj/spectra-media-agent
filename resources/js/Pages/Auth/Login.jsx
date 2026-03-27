import { Head, Link, useForm, usePage } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import PlatformIcon from '@/Components/PlatformIcon';
import CloudflareTurnstile from '@/Components/CloudflareTurnstile';

export default function Login({ status, enabledPlatforms = [], canResetPassword = false }) {
    const { turnstileSiteKey } = usePage().props;
    
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
        cf_turnstile_response: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Log in" />

            {status && (
                <div className="mb-4 font-medium text-sm text-green-600">
                    {status}
                </div>
            )}

            <div className="w-full mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
                {/* Email/Password Login Form */}
                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label htmlFor="email" className="block text-sm font-medium text-gray-700">
                            Email
                        </label>
                        <input
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500 sm:text-sm"
                            autoComplete="username"
                            autoFocus
                            onChange={(e) => setData('email', e.target.value)}
                            required
                        />
                        {errors.email && (
                            <p className="mt-1 text-sm text-red-600">{errors.email}</p>
                        )}
                    </div>

                    <div>
                        <label htmlFor="password" className="block text-sm font-medium text-gray-700">
                            Password
                        </label>
                        <input
                            id="password"
                            type="password"
                            name="password"
                            value={data.password}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500 sm:text-sm"
                            autoComplete="current-password"
                            onChange={(e) => setData('password', e.target.value)}
                            required
                        />
                        {errors.password && (
                            <p className="mt-1 text-sm text-red-600">{errors.password}</p>
                        )}
                    </div>

                    <div className="flex items-center justify-between">
                        <label className="flex items-center">
                            <input
                                type="checkbox"
                                name="remember"
                                checked={data.remember}
                                onChange={(e) => setData('remember', e.target.checked)}
                                className="rounded border-gray-300 text-flame-orange-600 shadow-sm focus:ring-flame-orange-500"
                            />
                            <span className="ml-2 text-sm text-gray-600">Remember me</span>
                        </label>

                        {canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className="text-sm text-gray-600 hover:text-gray-900 underline"
                            >
                                Forgot password?
                            </Link>
                        )}
                    </div>

                    {/* Cloudflare Turnstile Bot Detection */}
                    {turnstileSiteKey && (
                        <div className="flex justify-center">
                            <CloudflareTurnstile
                                siteKey={turnstileSiteKey}
                                onVerify={(token) => setData('cf_turnstile_response', token)}
                                onExpire={() => setData('cf_turnstile_response', '')}
                            />
                        </div>
                    )}
                    {errors.cf_turnstile_response && (
                        <p className="text-sm text-red-600 text-center">{errors.cf_turnstile_response}</p>
                    )}

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-flame-orange-600 hover:bg-flame-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-flame-orange-500 disabled:opacity-50"
                    >
                        {processing ? 'Signing in...' : 'Sign in'}
                    </button>
                </form>

                {/* OAuth Divider */}
                {enabledPlatforms.length > 0 && (
                    <>
                        <div className="relative my-6">
                            <div className="absolute inset-0 flex items-center">
                                <div className="w-full border-t border-gray-300" />
                            </div>
                            <div className="relative flex justify-center text-sm">
                                <span className="px-2 bg-white text-gray-500">Or continue with</span>
                            </div>
                        </div>

                        <div className="space-y-3">
                            {enabledPlatforms.map((platform) => (
                                <a
                                    key={platform.slug}
                                    href={route(`${platform.slug}.redirect`)}
                                    className="w-full flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                                >
                                    <PlatformIcon slug={platform.slug} />
                                    Sign in with {platform.name}
                                </a>
                            ))}
                        </div>
                    </>
                )}

                <div className="mt-4 text-center">
                    <Link
                        href={route('register')}
                        className="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-flame-orange-500"
                    >
                        Don't have an account? Register
                    </Link>
                </div>
            </div>
        </GuestLayout>
    );
}
