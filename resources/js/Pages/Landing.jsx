import React from 'react';
import { Head, Link } from '@inertiajs/react';

export default function Landing({ auth }) {
    return (
        <>
            <Head title="Spectra Media Agent - AI-Powered Digital Marketing" />
            <div className="min-h-screen bg-gray-50 text-gray-800">
                {/* Header */}
                <header className="bg-white shadow-sm">
                    <div className="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8 flex justify-between items-center">
                        <h1 className="text-2xl font-bold text-indigo-600">Spectra Media Agent</h1>
                        <div>
                            {auth && auth.user ? (
                                <Link href={route('dashboard')} className="text-base font-medium text-gray-600 hover:text-gray-900">
                                    Dashboard
                                </Link>
                            ) : (
                                <>
                                    <a href="/login" className="text-base font-medium text-gray-600 hover:text-gray-900">
                                        Log in
                                    </a>
                                    <a
                                        href="/register"
                                        className="ml-8 inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-base font-medium text-white bg-indigo-600 hover:bg-indigo-700"
                                    >
                                        Sign up
                                    </a>
                                </>
                            )}
                        </div>
                    </div>
                </header>

                <main>
                    {/* Hero Section */}
                    <div className="pt-10 bg-white sm:pt-16 lg:pt-8 lg:pb-14 lg:overflow-hidden">
                        <div className="mx-auto max-w-7xl lg:px-8">
                            <div className="lg:grid lg:grid-cols-2 lg:gap-8">
                                <div className="mx-auto max-w-md px-4 sm:max-w-2xl sm:px-6 sm:text-center lg:px-0 lg:text-left lg:flex lg:items-center">
                                    <div className="lg:py-24">
                                        <h1 className="mt-4 text-4xl tracking-tight font-extrabold text-gray-900 sm:mt-5 sm:text-6xl lg:mt-6 xl:text-6xl">
                                            <span className="block">Agentic Digital Marketing</span>
                                            <span className="block text-indigo-600">Powered by AI</span>
                                        </h1>
                                        <p className="mt-3 text-base text-gray-500 sm:mt-5 sm:text-xl lg:text-lg xl:text-xl">
                                            Generate ad copy, images, and videos for free. When you're ready to launch, our AI agents will deploy and manage your campaigns for a flat monthly fee. Stop paying thousands for ad management.
                                        </p>
                                        <div className="mt-10 sm:mt-12">
                                            <a
                                                href="/register"
                                                className="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700"
                                            >
                                                Start Generating for Free
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <div className="mt-12 -mb-16 sm:-mb-48 lg:m-0 lg:relative">
                                    <div className="mx-auto max-w-md px-4 sm:max-w-2xl sm:px-6 lg:max-w-none lg:px-0">
                                        {/* Replace with a relevant image or illustration */}
                                        <img className="w-full lg:absolute lg:inset-y-0 lg:left-0 lg:h-full lg:w-auto lg:max-w-none" src="https://tailwindui.com/img/component-images/cloud-illustration-indigo-400.svg" alt="" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Platforms Section */}
                    <div className="bg-gray-50 py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center">
                                <h2 className="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">One-Click Deployment to All Your Platforms</h2>
                                <p className="mt-6 text-lg leading-8 text-gray-600">
                                    Connect your accounts and let our AI agents handle the rest. We're constantly adding new platforms to our roster.
                                </p>
                            </div>
                            <div className="mt-16 flex justify-center">
                                <div className="flex flex-wrap justify-center gap-8">
                                    {/* Google Ads - Active */}
                                    <div className="flex flex-col items-center text-center">
                                        <div className="flex h-24 w-24 items-center justify-center rounded-full bg-green-100">
                                            {/* Placeholder for Google Ads Logo */}
                                            <svg className="h-12 w-12 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                                        </div>
                                        <p className="mt-4 font-semibold text-gray-900">Google Ads</p>
                                    </div>
                                    {/* Facebook Ads - Coming Soon */}
                                    <div className="flex flex-col items-center text-center grayscale">
                                        <div className="relative flex h-24 w-24 items-center justify-center rounded-full bg-gray-100">
                                            <span className="absolute -top-1 -right-1 inline-flex items-center rounded-full bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">Coming Soon</span>
                                            {/* Placeholder for Facebook Ads Logo */}
                                            <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm0 2c-2.21 0-4 1.79-4 4v1h8v-1c0-2.21-1.79-4-4-4z" /></svg>
                                        </div>
                                        <p className="mt-4 font-semibold text-gray-900">Facebook Ads</p>
                                    </div>
                                    {/* Instagram Ads - Coming Soon */}
                                    <div className="flex flex-col items-center text-center grayscale">
                                        <div className="relative flex h-24 w-24 items-center justify-center rounded-full bg-gray-100">
                                            <span className="absolute -top-1 -right-1 inline-flex items-center rounded-full bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">Coming Soon</span>
                                            {/* Placeholder for Instagram Ads Logo */}
                                            <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l-1.586-1.586a2 2 0 00-2.828 0L6 14m6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                        </div>
                                        <p className="mt-4 font-semibold text-gray-900">Instagram Ads</p>
                                    </div>
                                    {/* Reddit Ads - Coming Soon */}
                                    <div className="flex flex-col items-center text-center grayscale">
                                        <div className="relative flex h-24 w-24 items-center justify-center rounded-full bg-gray-100">
                                            <span className="absolute -top-1 -right-1 inline-flex items-center rounded-full bg-yellow-50 px-2 py-1 text-xs font-medium text-yellow-800 ring-1 ring-inset ring-yellow-600/20">Coming Soon</span>
                                            {/* Placeholder for Reddit Ads Logo */}
                                            <svg className="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15a3 3 0 100-6 3 3 0 000 6z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                        </div>
                                        <p className="mt-4 font-semibold text-gray-900">Reddit Ads</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Features Section */}
                    <div className="bg-white py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center">
                                <h2 className="text-base font-semibold leading-7 text-indigo-600">Deploy with Confidence</h2>
                                <p className="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                                    Everything you need to launch and optimize your ad campaigns
                                </p>
                                <p className="mt-6 text-lg leading-8 text-gray-600">
                                    Our AI agents handle the heavy lifting, from creative generation to performance analysis, so you can focus on your business.
                                </p>
                            </div>
                            <div className="mx-auto mt-16 max-w-2xl sm:mt-20 lg:mt-24 lg:max-w-4xl">
                                <dl className="grid max-w-xl grid-cols-1 gap-x-8 gap-y-10 lg:max-w-none lg:grid-cols-2 lg:gap-y-16">
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            AI-Powered Optimization
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">Our agents continuously tweak and improve your ad copy, images, and video content to maximize sales and ROI.</dd>
                                    </div>
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            Multi-Platform Support
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">Deploy to Google Ads instantly. Facebook, Instagram, and Reddit support is just around the corner, giving you a unified view of your marketing efforts.</dd>
                                    </div>
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            Transparent Pricing
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">A flat fee of $200 a month, plus your ad spend. No hidden costs, no surprises. Your ad spend is billed directly to your card on file daily.</dd>
                                    </div>
                                    <div className="relative pl-16">
                                        <dt className="text-base font-semibold leading-7 text-gray-900">
                                            Generate for Free
                                        </dt>
                                        <dd className="mt-2 text-base leading-7 text-gray-600">Sign up for free and generate unlimited ad copy, images, and videos. Only pay when you're ready to deploy and have our AI manage your campaigns.</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>

                    {/* Case Studies Section */}
                    <div className="bg-gray-50 py-16 sm:py-24">
                        <div className="mx-auto max-w-7xl px-6 lg:px-8">
                            <div className="mx-auto max-w-2xl lg:text-center">
                                <h2 className="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">From Our Customers</h2>
                                <p className="mt-6 text-lg leading-8 text-gray-600">
                                    See how businesses like yours are succeeding with Spectra Media Agent.
                                </p>
                            </div>
                            <div className="mx-auto mt-16 grid max-w-2xl grid-cols-1 gap-8 text-sm leading-6 text-gray-900 sm:mt-20 sm:grid-cols-2 xl:mx-0 xl:max-w-none xl:grid-flow-col xl:grid-cols-3">
                                <div className="relative rounded-2xl bg-white p-6 shadow-xl ring-1 ring-gray-900/5">
                                    <div className="text-lg">
                                        <p>"Spectra has been a game-changer for our marketing. We've seen a 30% increase in conversions since we started using their AI agents."</p>
                                    </div>
                                    <div className="mt-6 font-semibold">- Sarah L., CEO of a growing e-commerce brand</div>
                                </div>
                                <div className="relative rounded-2xl bg-white p-6 shadow-xl ring-1 ring-gray-900/5">
                                    <div className="text-lg">
                                        <p>"The ability to generate and test so many different creatives so quickly is incredible. We're getting much better results for a fraction of the cost."</p>
                                    </div>
                                    <div className="mt-6 font-semibold">- Mike R., Founder of a SaaS startup</div>
                                </div>
                                <div className="relative rounded-2xl bg-white p-6 shadow-xl ring-1 ring-gray-900/5">
                                    <div className="text-lg">
                                        <p>"As a small business owner, I don't have the time or expertise to manage ad campaigns. Spectra's AI agents do it all for me, and the results have been fantastic."</p>
                                    </div>
                                    <div className="mt-6 font-semibold">- Jessica B., Owner of a local service business</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Pricing Section */}
                    <div className="bg-white">
                        <div className="max-w-7xl mx-auto py-12 px-4 sm:px-6 lg:py-24 lg:px-8">
                            <div className="text-center">
                                <h2 className="text-3xl font-extrabold text-gray-900 sm:text-4xl">
                                    Create for Free, Pay to Deploy
                                </h2>
                                <p className="mt-4 text-xl text-gray-500">
                                    Generate unlimited collateral. Only pay when you're ready to publish.
                                </p>
                            </div>

                            <div className="mt-16 flex justify-center">
                                <div className="w-full max-w-md bg-white rounded-lg shadow-lg p-8 border border-gray-200">
                                    <h3 className="text-2xl font-bold text-center">Pro Plan</h3>
                                    <div className="mt-4 text-center text-gray-900">
                                        <span className="text-5xl font-extrabold">$200</span>
                                        <span className="text-xl font-medium">/month</span>
                                    </div>
                                    <p className="mt-4 text-sm text-gray-500 text-center">+ ad spend, billed daily.</p>
                                    <ul className="mt-8 space-y-4">
                                        <li className="flex items-start">
                                            <div className="flex-shrink-0">
                                                <svg className="h-6 w-6 text-green-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                                </svg>
                                            </div>
                                            <p className="ml-3 text-base text-gray-700">Automated campaign setup on Google Ads</p>
                                        </li>
                                        <li className="flex items-start">
                                            <div className="flex-shrink-0">
                                                <svg className="h-6 w-6 text-green-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                                </svg>
                                            </div>
                                            <p className="ml-3 text-base text-gray-700">Continuous AI-driven optimization of copy, images, and video</p>
                                        </li>
                                        <li className="flex items-start">
                                            <div className="flex-shrink-0">
                                                <svg className="h-6 w-6 text-green-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                                </svg>
                                            </div>
                                            <p className="ml-3 text-base text-gray-700">Daily billing of ad spend directly to your card on file</p>
                                        </li>
                                        <li className="flex items-start">
                                            <div className="flex-shrink-0">
                                                <svg className="h-6 w-6 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                                </svg>
                                            </div>
                                            <p className="ml-3 text-base text-gray-700">Facebook, Instagram, and Reddit support (coming soon)</p>
                                        </li>
                                    </ul>
                                    <div className="mt-10">
                                        <a
                                            href="/register"
                                            className="block w-full text-center rounded-lg border border-transparent bg-indigo-600 px-6 py-3 text-base font-medium text-white shadow hover:bg-indigo-700"
                                        >
                                            Deploy Your Campaign
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>

                {/* Footer */}
                <footer className="bg-white">
                    <div className="max-w-7xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
                        <div className="flex justify-center space-x-6">
                            <Link href={route('terms')} className="text-base text-gray-500 hover:text-gray-900">
                                Terms of Service
                            </Link>
                            <Link href={route('privacy')} className="text-base text-gray-500 hover:text-gray-900">
                                Privacy Policy
                            </Link>
                        </div>
                        <p className="mt-8 text-center text-base text-gray-400">&copy; 2025 Spectra Media Agent. All rights reserved.</p>
                    </div>
                </footer>
            </div>
        </>
    );
}
