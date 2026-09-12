import { useState } from 'react';
import { Link } from '@inertiajs/react';
import PageTitle from '@/Components/PageTitle';
import Header from '@/Components/Header';
import Hero from '@/Components/Marketing/Hero';
import CtaBand from '@/Components/Marketing/CtaBand';
import Footer from '@/Components/Footer';

const CATEGORY_COLORS = {
    'Getting Started': 'bg-green-100 text-green-700',
    'Platform':        'bg-violet-100 text-violet-800',
    'Google Ads':      'bg-blue-100 text-blue-700',
};

const PER_PAGE = 9;

/*
 * No <Head> here. HelpController owns this page's metadata; see the note in
 * Blog/Article.jsx. The title written here was "Blog — sitetospend.com", 22
 * characters against the controller's 47, and it was the one Google saw.
 */
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
            <PageTitle />
            <div className="min-h-screen bg-gray-50 flex flex-col">
                <Header auth={auth} />

                <main className="flex-1">
                    <Hero
                        eyebrow="Blog"
                        headline="Google Ads & digital advertising guides"
                        sub="Plain-English guides to Google Ads, Smart Bidding, AI campaign management and everything in between."
                    />

                    <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-12">

                        {/* Category filter tabs */}
                        <div className="flex flex-wrap gap-2 mb-8 sm:mb-10">
                            {categories.map(cat => (
                                <button
                                    key={cat}
                                    onClick={() => handleCategory(cat)}
                                    className={`min-h-[44px] rounded-full px-4 py-2.5 text-sm font-semibold transition-colors ${
                                        activeCategory === cat
                                            ? 'bg-brand-dark text-white'
                                            : 'bg-white text-gray-600 border border-gray-200 hover:border-brand-dark hover:text-brand-darker'
                                    }`}
                                >
                                    {cat}
                                    <span className={`ml-1.5 text-xs ${activeCategory === cat ? 'text-white/80' : 'text-gray-500'}`}>
                                        {cat === 'All' ? articles.length : articles.filter(a => a.category === cat).length}
                                    </span>
                                </button>
                            ))}
                        </div>

                        {/* Article grid */}
                        <div className="grid gap-4 sm:gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {visible.map(article => (
                                <Link
                                    key={article.slug}
                                    href={`/blog/${article.slug}`}
                                    className="group bg-white rounded-2xl border border-gray-100 p-5 sm:p-6 hover:border-brand-dark hover:shadow-md transition-all duration-200"
                                >
                                    <div className="flex items-center justify-between mb-4">
                                        <span className={`text-xs font-semibold rounded-full px-3 py-1 ${CATEGORY_COLORS[article.category] ?? 'bg-gray-100 text-gray-600'}`}>
                                            {article.category}
                                        </span>
                                        <span className="text-xs text-gray-500">{article.read_time}</span>
                                    </div>
                                    <h3 className="font-bold text-gray-900 group-hover:text-brand-darker transition-colors mb-2 leading-snug">
                                        {article.title}
                                    </h3>
                                    <p className="text-sm text-gray-500 leading-relaxed">
                                        {article.description}
                                    </p>
                                    <div className="mt-4 text-sm font-semibold text-brand-darker flex items-center gap-1">
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
                                    className="min-h-[44px] px-4 py-2 rounded-lg border border-gray-200 text-sm font-medium text-gray-600 hover:border-brand-dark hover:text-brand-darker disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                >
                                    ← Previous
                                </button>
                                {Array.from({ length: totalPages }, (_, i) => i + 1).map(p => (
                                    <button
                                        key={p}
                                        onClick={() => setPage(p)}
                                        className={`h-11 w-11 rounded-lg text-sm font-semibold transition-colors ${
                                            p === page
                                                ? 'bg-brand-dark text-white'
                                                : 'border border-gray-200 text-gray-600 hover:border-brand-dark hover:text-brand-darker'
                                        }`}
                                    >
                                        {p}
                                    </button>
                                ))}
                                <button
                                    onClick={() => setPage(p => Math.min(totalPages, p + 1))}
                                    disabled={page === totalPages}
                                    className="min-h-[44px] px-4 py-2 rounded-lg border border-gray-200 text-sm font-medium text-gray-600 hover:border-brand-dark hover:text-brand-darker disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                >
                                    Next →
                                </button>
                            </div>
                        )}
                    </div>

                    {/*
                        The shared band, not a violet-to-indigo gradient. Violet
                        is not a brand token, so it could not follow the tenant
                        skin — the realpropertyads blog closed with a violet
                        panel under a navy header. The old line also promised
                        "no setup fees", which stopped being true the day the
                        one-time setup went on sale.
                    */}
                    <CtaBand
                        title="Ready to put this into practice?"
                        body="Launch your first AI-managed campaign. No long-term contract, live in minutes."
                        primaryCta={{ href: '/register', label: 'Start free' }}
                        secondaryCta={{ href: '/pricing', label: 'See pricing' }}
                    />
                </main>

                <Footer />
            </div>
        </>
    );
}
