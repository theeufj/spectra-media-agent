import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import { useState } from 'react';

const PLATFORM_OPTIONS = [
    'Google Ads',
    'Facebook & Instagram',
    'Microsoft Ads',
    'LinkedIn Ads',
    'TikTok Ads',
];

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        client_name: '',
        industry: '',
        website_url: '',
        budget: '',
        goals: '',
        platforms: ['Google Ads'],
    });

    const togglePlatform = (platform) => {
        const current = data.platforms;
        if (current.includes(platform)) {
            if (current.length > 1) {
                setData('platforms', current.filter(p => p !== platform));
            }
        } else {
            setData('platforms', [...current, platform]);
        }
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('proposals.store'));
    };

    return (
        <AuthenticatedLayout>
            <Head title="Create Proposal" />

            <div className="max-w-3xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                <Link
                    href={route('proposals.index')}
                    className="text-indigo-600 hover:text-indigo-800 text-sm font-medium inline-flex items-center mb-6"
                >
                    <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                    </svg>
                    Back to Proposals
                </Link>

                <div className="bg-white rounded-lg shadow-md overflow-hidden">
                    <div className="bg-gradient-to-r from-indigo-600 to-indigo-700 px-6 py-5">
                        <h1 className="text-2xl font-bold text-white">Generate a Proposal</h1>
                        <p className="text-indigo-200 mt-1">
                            Our AI will create a professional advertising proposal with platform strategies, projected metrics, and sample ad concepts.
                        </p>
                    </div>

                    <form onSubmit={handleSubmit} className="p-6 space-y-6">
                        {/* Client Name */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Client / Company Name <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={data.client_name}
                                onChange={e => setData('client_name', e.target.value)}
                                className="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="e.g. Acme Corp"
                            />
                            {errors.client_name && <p className="mt-1 text-sm text-red-600">{errors.client_name}</p>}
                        </div>

                        {/* Industry */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Industry</label>
                            <input
                                type="text"
                                value={data.industry}
                                onChange={e => setData('industry', e.target.value)}
                                className="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="e.g. E-commerce, Healthcare, Real Estate"
                            />
                            {errors.industry && <p className="mt-1 text-sm text-red-600">{errors.industry}</p>}
                        </div>

                        {/* Website URL */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Client Website</label>
                            <input
                                type="url"
                                value={data.website_url}
                                onChange={e => setData('website_url', e.target.value)}
                                className="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="https://example.com"
                            />
                            <p className="mt-1 text-xs text-gray-400">We'll analyze the website to personalize the proposal.</p>
                            {errors.website_url && <p className="mt-1 text-sm text-red-600">{errors.website_url}</p>}
                        </div>

                        {/* Monthly Budget */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                Monthly Ad Budget (USD) <span className="text-red-500">*</span>
                            </label>
                            <div className="relative">
                                <span className="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500">$</span>
                                <input
                                    type="number"
                                    value={data.budget}
                                    onChange={e => setData('budget', e.target.value)}
                                    className="w-full pl-8 rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="5000"
                                    min="100"
                                    step="100"
                                />
                            </div>
                            {errors.budget && <p className="mt-1 text-sm text-red-600">{errors.budget}</p>}
                        </div>

                        {/* Platforms */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Advertising Platforms <span className="text-red-500">*</span>
                            </label>
                            <div className="flex flex-wrap gap-2">
                                {PLATFORM_OPTIONS.map((platform) => (
                                    <button
                                        key={platform}
                                        type="button"
                                        onClick={() => togglePlatform(platform)}
                                        className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors border ${
                                            data.platforms.includes(platform)
                                                ? 'bg-indigo-600 text-white border-indigo-600 shadow-md'
                                                : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                        }`}
                                    >
                                        {platform}
                                    </button>
                                ))}
                            </div>
                            {errors.platforms && <p className="mt-1 text-sm text-red-600">{errors.platforms}</p>}
                        </div>

                        {/* Goals */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Client Goals & Notes</label>
                            <textarea
                                value={data.goals}
                                onChange={e => setData('goals', e.target.value)}
                                rows={4}
                                className="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="e.g. Increase online sales by 30%, generate 200 leads/month, build brand awareness in the Southeast market..."
                            />
                            {errors.goals && <p className="mt-1 text-sm text-red-600">{errors.goals}</p>}
                        </div>

                        {/* Submit */}
                        <div className="pt-4 border-t border-gray-200">
                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full inline-flex justify-center items-center px-6 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition font-semibold shadow-md disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {processing ? (
                                    <>
                                        <svg className="animate-spin -ml-1 mr-3 h-5 w-5" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                        </svg>
                                        Generating...
                                    </>
                                ) : (
                                    <>
                                        <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
                                        </svg>
                                        Generate Proposal with AI
                                    </>
                                )}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
