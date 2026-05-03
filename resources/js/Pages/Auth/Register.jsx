import { useEffect } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import PlatformIcon from '@/Components/PlatformIcon';
import CloudflareTurnstile from '@/Components/CloudflareTurnstile';
import { trackConversion } from '@/utils/conversions';

function getDemoUrl() {
    try {
        return new URLSearchParams(window.location.search).get('demo_url') || '';
    } catch {
        return '';
    }
}

export default function Register({ enabledPlatforms = [] }) {
    const { turnstileSiteKey } = usePage().props;
    const demoUrl = getDemoUrl();
    const demoDomain = demoUrl ? (new URL(demoUrl).hostname.replace(/^www\./, '')) : '';

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

            {demoDomain && (
                <div className="mb-4 flex items-center gap-2 rounded-lg bg-violet-50 border border-violet-200 px-4 py-3 text-sm text-violet-800">
                    <svg className="h-4 w-4 shrink-0 text-violet-500" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                    </svg>
                    <span>We'll analyse <strong>{demoDomain}</strong> and set up your brand guidelines automatically.</span>
                </div>
            )}

            <div className="w-full mt-2 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
                <div className="mb-4 text-sm text-gray-600 text-center">
                    Create your account to get started.
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <input type="hidden" name="demo_url" value={data.demo_url} />

                    <div>
                        <label htmlFor="name" className="block text-sm font-medium text-gray-700">
                            Name
                        </label>
                        <input
                            id="name"
                            type="text"
                            name="name"
                            value={data.name}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500 sm:text-sm"
                            autoComplete="name"
                            autoFocus
                            onChange={(e) => setData('name', e.target.value)}
                            required
                        />
                        {errors.name && (
                            <p className="mt-1 text-sm text-red-600">{errors.name}</p>
                        )}
                    </div>

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
                            autoComplete="new-password"
                            onChange={(e) => setData('password', e.target.value)}
                            required
                        />
                        {errors.password && (
                            <p className="mt-1 text-sm text-red-600">{errors.password}</p>
                        )}
                    </div>

                    <div>
                        <label htmlFor="password_confirmation" className="block text-sm font-medium text-gray-700">
                            Confirm Password
                        </label>
                        <input
                            id="password_confirmation"
                            type="password"
                            name="password_confirmation"
                            value={data.password_confirmation}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500 sm:text-sm"
                            autoComplete="new-password"
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            required
                        />
                        {errors.password_confirmation && (
                            <p className="mt-1 text-sm text-red-600">{errors.password_confirmation}</p>
                        )}
                    </div>

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
                        {processing ? 'Creating account...' : 'Register'}
                    </button>
                </form>

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
                                    href={route(`${platform.slug}.redirect`) + (demoUrl ? `?demo_url=${encodeURIComponent(demoUrl)}` : '')}
                                    className="w-full flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                                >
                                    <PlatformIcon slug={platform.slug} />
                                    Sign up with {platform.name}
                                </a>
                            ))}
                        </div>
                    </>
                )}

                <div className="mt-4 text-center">
                    <Link
                        href={route('login')}
                        className="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-flame-orange-500"
                    >
                        Already registered?
                    </Link>
                </div>
            </div>
        </GuestLayout>
    );
}
