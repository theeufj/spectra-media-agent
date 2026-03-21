import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function QuickStart({ auth }) {
    const { data, setData, post, processing, errors } = useForm({
        website_url: '',
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
        country: 'US',
    });

    const [urlFocused, setUrlFocused] = useState(false);

    function handleSubmit(e) {
        e.preventDefault();

        // Auto-prepend https:// if no protocol
        let url = data.website_url.trim();
        if (url && !url.match(/^https?:\/\//i)) {
            url = 'https://' + url;
            setData('website_url', url);
        }

        post(route('quick-start.process'));
    }

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                    Quick Start
                </h2>
            }
        >
            <Head title="Quick Start" />

            <div className="py-12">
                <div className="max-w-2xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-8 text-center">
                            <div className="text-5xl mb-4">🚀</div>
                            <h1 className="text-2xl font-bold text-gray-900 mb-2">
                                Get Started in 30 Seconds
                            </h1>
                            <p className="text-gray-600 mb-8 max-w-md mx-auto">
                                Paste your website URL and our AI will scan your site, learn your brand, and prepare everything for your first campaign.
                            </p>

                            <form onSubmit={handleSubmit} className="max-w-lg mx-auto">
                                <div className="relative">
                                    <div className={`
                                        flex items-center border-2 rounded-xl transition-all duration-200
                                        ${urlFocused ? 'border-indigo-500 shadow-lg shadow-indigo-100' : 'border-gray-200'}
                                        ${errors.website_url ? 'border-red-400' : ''}
                                    `}>
                                        <span className="pl-4 text-gray-400">
                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                                            </svg>
                                        </span>
                                        <input
                                            type="text"
                                            value={data.website_url}
                                            onChange={e => setData('website_url', e.target.value)}
                                            onFocus={() => setUrlFocused(true)}
                                            onBlur={() => setUrlFocused(false)}
                                            placeholder="yourwebsite.com"
                                            className="flex-1 px-3 py-4 text-lg border-0 focus:ring-0 focus:outline-none rounded-xl"
                                            autoFocus
                                        />
                                        <button
                                            type="submit"
                                            disabled={processing || !data.website_url.trim()}
                                            className={`
                                                mr-2 px-6 py-2.5 rounded-lg font-medium text-white transition-all duration-200
                                                ${processing || !data.website_url.trim()
                                                    ? 'bg-gray-300 cursor-not-allowed'
                                                    : 'bg-indigo-600 hover:bg-indigo-700 shadow-md hover:shadow-lg'
                                                }
                                            `}
                                        >
                                            {processing ? (
                                                <span className="flex items-center">
                                                    <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                                    </svg>
                                                    Scanning...
                                                </span>
                                            ) : 'Go'}
                                        </button>
                                    </div>
                                    {errors.website_url && (
                                        <p className="mt-2 text-sm text-red-600 text-left">{errors.website_url}</p>
                                    )}
                                </div>
                            </form>

                            <div className="mt-10 grid grid-cols-3 gap-4 max-w-lg mx-auto text-center">
                                <div className="p-3">
                                    <div className="text-2xl mb-1">📚</div>
                                    <p className="text-xs text-gray-500">Scans your website content</p>
                                </div>
                                <div className="p-3">
                                    <div className="text-2xl mb-1">🎨</div>
                                    <p className="text-xs text-gray-500">Extracts brand guidelines</p>
                                </div>
                                <div className="p-3">
                                    <div className="text-2xl mb-1">🚀</div>
                                    <p className="text-xs text-gray-500">Prepares your first campaign</p>
                                </div>
                            </div>

                            <p className="mt-6 text-xs text-gray-400">
                                Or{' '}
                                <a href={route('customers.create')} className="text-indigo-600 hover:text-indigo-800">
                                    set up manually
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
