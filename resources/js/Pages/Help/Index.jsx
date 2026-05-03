import { Head, Link } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

const CATEGORY_COLORS = {
    'Getting Started': 'bg-green-100 text-green-700',
    'Platform':        'bg-violet-100 text-violet-700',
    'Google Ads':      'bg-blue-100 text-blue-700',
};

export default function HelpIndex({ auth, articles = [] }) {
    const categories = [...new Set(articles.map(a => a.category))];

    return (
        <>
            <Head>
                <title>Help Center — sitetospend.com</title>
                <meta name="description" content="Guides and articles to help you understand how sitetospend.com manages your ad campaigns, tracks conversions, and uses AI to optimise your results." />
                <meta property="og:title" content="Help Center — sitetospend.com" />
                <meta property="og:description" content="Guides and articles on conversion tracking, AI agents, Smart Bidding, and getting started with sitetospend.com." />
            </Head>

            <div className="min-h-screen bg-gray-50 flex flex-col">
                <Header auth={auth} />

                <main className="flex-1">
                    {/* Hero */}
                    <div className="bg-white border-b border-gray-100">
                        <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-16 text-center">
                            <p className="text-sm font-semibold text-flame-orange-600 uppercase tracking-wider mb-3">Help Center</p>
                            <h1 className="text-4xl font-extrabold text-gray-900 mb-4">
                                Learn how sitetospend.com works
                            </h1>
                            <p className="text-lg text-gray-500 max-w-2xl mx-auto">
                                Plain-English guides to conversion tracking, AI agents, Smart Bidding, and everything in between.
                            </p>
                        </div>
                    </div>

                    <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
                        {categories.map(category => (
                            <div key={category} className="mb-14">
                                <h2 className="text-xs font-bold uppercase tracking-widest text-gray-400 mb-6">
                                    {category}
                                </h2>
                                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                                    {articles.filter(a => a.category === category).map(article => (
                                        <Link
                                            key={article.slug}
                                            href={`/help/${article.slug}`}
                                            className="group bg-white rounded-2xl border border-gray-100 p-6 hover:border-violet-200 hover:shadow-md transition-all duration-200"
                                        >
                                            <div className="flex items-center justify-between mb-4">
                                                <span className={`text-xs font-semibold rounded-full px-3 py-1 ${CATEGORY_COLORS[article.category] ?? 'bg-gray-100 text-gray-600'}`}>
                                                    {article.category}
                                                </span>
                                                <span className="text-xs text-gray-400">{article.read_time}</span>
                                            </div>
                                            <h3 className="font-bold text-gray-900 group-hover:text-violet-700 transition-colors mb-2 leading-snug">
                                                {article.title}
                                            </h3>
                                            <p className="text-sm text-gray-500 leading-relaxed">
                                                {article.description}
                                            </p>
                                            <div className="mt-4 text-sm font-semibold text-violet-600 group-hover:text-violet-700 flex items-center gap-1">
                                                Read article
                                                <svg className="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                                </svg>
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* CTA */}
                    <div className="bg-gradient-to-r from-violet-600 to-indigo-600 py-16">
                        <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                            <h2 className="text-2xl font-bold text-white mb-3">Ready to put this into practice?</h2>
                            <p className="text-violet-100 mb-8">Launch your first AI-managed campaign. No long-term contract, no setup fees.</p>
                            <a
                                href="/register"
                                className="inline-flex items-center px-7 py-3 bg-white text-violet-700 font-semibold rounded-xl hover:bg-violet-50 transition"
                            >
                                Start Free
                            </a>
                        </div>
                    </div>
                </main>

                <Footer />
            </div>
        </>
    );
}
