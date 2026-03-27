import React from 'react';
import { Head } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

export default function FreeAudit({ auth, error }) {
    return (
        <>
            <Head title="Free Ad Account Audit - sitetospend" />
            <div className="min-h-screen bg-gradient-to-b from-flame-orange-50 via-white to-gray-50 text-gray-800">
                <Header auth={auth} />

                <main>
                    {/* Hero */}
                    <div className="pt-16 pb-20 px-4 sm:px-6 lg:px-8">
                        <div className="mx-auto max-w-3xl text-center">
                            <p className="text-sm font-semibold text-flame-orange-600 uppercase tracking-wider">Free — No Credit Card Required</p>
                            <h1 className="mt-4 text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight text-gray-900">
                                Find out what's <span className="text-red-500">wrong</span> with your ads
                            </h1>
                            <p className="mt-6 text-lg sm:text-xl text-gray-500 max-w-2xl mx-auto">
                                Connect your Google or Facebook ad account and our AI will analyze your campaigns in 60 seconds. 
                                Get a personalized report with exactly what to fix — and how much you could save.
                            </p>

                            {error && (
                                <div className="mt-6 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                                    {error}
                                </div>
                            )}
                        </div>

                        {/* OAuth Buttons */}
                        <div className="mt-12 mx-auto max-w-md space-y-4">
                            <a
                                href={route('google.audit.redirect')}
                                className="flex items-center justify-center w-full px-6 py-4 border-2 border-gray-200 rounded-xl shadow-sm bg-white text-gray-700 font-semibold hover:border-flame-orange-300 hover:shadow-md transition-all group"
                            >
                                <svg className="w-6 h-6 mr-3" viewBox="0 0 24 24">
                                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" />
                                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                                </svg>
                                Audit My Google Ads
                                <svg className="w-5 h-5 ml-auto text-gray-400 group-hover:text-flame-orange-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" /></svg>
                            </a>

                            <a
                                href={route('facebook.audit.redirect')}
                                className="flex items-center justify-center w-full px-6 py-4 border-2 border-gray-200 rounded-xl shadow-sm bg-white text-gray-700 font-semibold hover:border-blue-300 hover:shadow-md transition-all group"
                            >
                                <svg className="w-6 h-6 mr-3" viewBox="0 0 24 24" fill="#1877F2">
                                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
                                </svg>
                                Audit My Facebook Ads
                                <svg className="w-5 h-5 ml-auto text-gray-400 group-hover:text-blue-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" /></svg>
                            </a>

                            <p className="text-center text-xs text-gray-400 mt-4">
                                We only request <strong>read-only</strong> access. We can't change anything in your account.
                            </p>
                        </div>
                    </div>

                    {/* What We Check */}
                    <div className="bg-white py-16 border-t border-b border-gray-200">
                        <div className="mx-auto max-w-5xl px-6 lg:px-8">
                            <h2 className="text-2xl sm:text-3xl font-bold text-center text-gray-900">What We Analyze</h2>
                            <p className="mt-3 text-center text-gray-500 max-w-2xl mx-auto">Our AI checks over a dozen critical areas that impact your ad performance and ROI.</p>

                            <div className="mt-12 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
                                {[
                                    { icon: '💸', title: 'Wasted Spend', desc: 'Campaigns spending money with zero conversions' },
                                    { icon: '🚫', title: 'Disapproved Ads', desc: 'Ads rejected by Google or Facebook policies' },
                                    { icon: '📊', title: 'Conversion Tracking', desc: 'Whether your ROI measurement is working correctly' },
                                    { icon: '🔑', title: 'Keyword Health', desc: 'Broad match waste and missing negative keywords' },
                                    { icon: '🔗', title: 'Ad Extensions', desc: 'Missing sitelinks, callouts, and structured snippets' },
                                    { icon: '📉', title: 'Ad Fatigue', desc: 'Audiences seeing your ads too many times' },
                                    { icon: '🎯', title: 'Audience Targeting', desc: 'Overly broad audiences wasting your budget' },
                                    { icon: '🧪', title: 'Creative Testing', desc: 'Ad groups running only one ad (no A/B testing)' },
                                    { icon: '📱', title: 'Pixel Health', desc: 'Whether your tracking pixels are firing correctly' },
                                ].map((item, i) => (
                                    <div key={i} className="flex items-start gap-4">
                                        <span className="text-2xl flex-shrink-0">{item.icon}</span>
                                        <div>
                                            <h3 className="font-semibold text-gray-900">{item.title}</h3>
                                            <p className="text-sm text-gray-500 mt-1">{item.desc}</p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Trust Section */}
                    <div className="py-16 px-6">
                        <div className="mx-auto max-w-3xl text-center">
                            <h2 className="text-2xl font-bold text-gray-900">100% Safe & Secure</h2>
                            <div className="mt-8 grid grid-cols-1 sm:grid-cols-3 gap-8">
                                <div>
                                    <div className="text-3xl">🔒</div>
                                    <h3 className="mt-3 font-semibold text-gray-900">Read-Only Access</h3>
                                    <p className="mt-1 text-sm text-gray-500">We can never make changes to your ad account</p>
                                </div>
                                <div>
                                    <div className="text-3xl">⚡</div>
                                    <h3 className="mt-3 font-semibold text-gray-900">60 Second Results</h3>
                                    <p className="mt-1 text-sm text-gray-500">AI-powered analysis delivers instant insights</p>
                                </div>
                                <div>
                                    <div className="text-3xl">🎯</div>
                                    <h3 className="mt-3 font-semibold text-gray-900">Actionable Fixes</h3>
                                    <p className="mt-1 text-sm text-gray-500">Not just problems — specific steps to fix them</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>

                <Footer />
            </div>
        </>
    );
}
