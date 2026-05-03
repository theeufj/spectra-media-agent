import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

const CATEGORY_COLORS = {
    'Getting Started': 'bg-green-100 text-green-700',
    'Platform':        'bg-violet-100 text-violet-700',
    'Google Ads':      'bg-blue-100 text-blue-700',
};

const PER_PAGE = 9;

export default function HelpIndex({ auth, articles = [] }) {
    const categories = ['All', ...new Set(articles.map(a => a.category))];
    const [activeCategory, setActiveCategory] = useState('All');
    const [page, setPage] = useState(1);

    const filtered = activeCategory === 'All' ? articles : articles.filter(a => a.category === activeCategory);
    const totalPages = Math.ceil(filtered.length / PER_PAGE);
    const visible = filtered.slice((page - 1) * PER_PAGE, page * PER_PAGE);

    function handleCategory(cat) {
        setActiveCategory(cat);
        setPage(1);
    }

    return (
        <>
            <Head>
                <title>Blog — sitetospend.com</title>
                <meta name="description" content="Plain-English guides on Google Ads, Facebook Ads, AI campaign management, and how sitetospend.com works." />
                <meta property="og:title" content="Blog — sitetospend.com" />
                <meta property="og:description" content="Plain-English guides on Google Ads, Facebook Ads, AI campaign management, and how sitetospend.com works." />
            </Head>

            <div className="min-h-screen bg-gray-50 flex flex-col">
                <Header auth={auth} />

                <main className="flex-1">
                    {/* Hero */}
                    <div className="bg-white border-b border-gray-100">
                        <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-16 text-center">
                            <p className="text-sm font-semibold text-flame-orange-600 uppercase tracking-wider mb-3">Blog</p>
                            <h1 className="text-4xl font-extrabold text-gray-900 mb-4">
                                Google Ads & Digital Advertising Guides
                            </h1>
                            <p className="text-lg text-gray-500 max-w-2xl mx-auto">
                                Plain-English guides to Google Ads, Smart Bidding, AI campaign management, and everything in between.
                            </p>
                        </div>
                    </div>

                    <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12">

                        {/* Category filter tabs */}
                        <div className="flex flex-wrap gap-2 mb-10">
                            {categories.map(cat => (
                                <button
                                    key={cat}
                                    onClick={() => handleCategory(cat)}
                                    className={`px-4 py-2 rounded-full text-sm font-semibold transition-colors ${
                                        activeCategory === cat
                                            ? 'bg-violet-600 text-white'
                                            : 'bg-white text-gray-600 border border-gray-200 hover:border-violet-300 hover:text-violet-700'
                                    }`}
                                >
                                    {cat}
                                    <span className={`ml-1.5 text-xs ${activeCategory === cat ? 'text-violet-200' : 'text-gray-400'}`}>
                                        {cat === 'All' ? articles.length : articles.filter(a => a.category === cat).length}
                                    </span>
                                </button>
                            ))}
                        </div>

                        {/* Article grid */}
                        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {visible.map(article => (
                                <Link
                                    key={article.slug}
                                    href={`/blog/${article.slug}`}
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

                        {/* Pagination */}
                        {totalPages > 1 && (
                            <div className="flex items-center justify-center gap-2 mt-12">
                                <button
                                    onClick={() => setPage(p => Math.max(1, p - 1))}
                                    disabled={page === 1}
                                    className="px-4 py-2 rounded-lg border border-gray-200 text-sm font-medium text-gray-600 hover:border-violet-300 hover:text-violet-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                >
                                    ← Previous
                                </button>
                                {Array.from({ length: totalPages }, (_, i) => i + 1).map(p => (
                                    <button
                                        key={p}
                                        onClick={() => setPage(p)}
                                        className={`w-9 h-9 rounded-lg text-sm font-semibold transition-colors ${
                                            p === page
                                                ? 'bg-violet-600 text-white'
                                                : 'border border-gray-200 text-gray-600 hover:border-violet-300 hover:text-violet-700'
                                        }`}
                                    >
                                        {p}
                                    </button>
                                ))}
                                <button
                                    onClick={() => setPage(p => Math.min(totalPages, p + 1))}
                                    disabled={page === totalPages}
                                    className="px-4 py-2 rounded-lg border border-gray-200 text-sm font-medium text-gray-600 hover:border-violet-300 hover:text-violet-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                >
                                    Next →
                                </button>
                            </div>
                        )}
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
