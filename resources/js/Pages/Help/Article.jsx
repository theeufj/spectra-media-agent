import { Head, Link } from '@inertiajs/react';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

const CATEGORY_COLORS = {
    'Getting Started': 'bg-green-100 text-green-700',
    'Platform':        'bg-violet-100 text-violet-700',
    'Google Ads':      'bg-blue-100 text-blue-700',
};

export default function HelpArticle({ auth, article, relatedArticles = [] }) {
    const schema = {
        '@context': 'https://schema.org',
        '@type': 'Article',
        headline: article.title,
        description: article.description,
        datePublished: article.published,
        publisher: {
            '@type': 'Organization',
            name: 'sitetospend.com',
            url: 'https://sitetospend.com',
        },
    };

    return (
        <>
            <Head>
                <title>{`${article.title} — sitetospend.com Help`}</title>
                <meta name="description" content={article.description} />
                <meta property="og:title" content={`${article.title} — sitetospend.com`} />
                <meta property="og:description" content={article.description} />
                <meta property="og:type" content="article" />
                <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(schema) }} />
            </Head>

            <div className="min-h-screen bg-gray-50 flex flex-col">
                <Header auth={auth} />

                <main className="flex-1">
                    {/* Breadcrumb + header */}
                    <div className="bg-white border-b border-gray-100">
                        <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
                            <nav className="flex items-center gap-2 text-sm text-gray-400 mb-6">
                                <Link href="/help" className="hover:text-gray-600 transition-colors">Help Center</Link>
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                </svg>
                                <span className="text-gray-600">{article.title}</span>
                            </nav>

                            <div className="flex items-center gap-3 mb-4">
                                <span className={`text-xs font-semibold rounded-full px-3 py-1 ${CATEGORY_COLORS[article.category] ?? 'bg-gray-100 text-gray-600'}`}>
                                    {article.category}
                                </span>
                                <span className="text-sm text-gray-400">{article.read_time}</span>
                            </div>

                            <h1 className="text-3xl sm:text-4xl font-extrabold text-gray-900 leading-tight">
                                {article.title}
                            </h1>
                            <p className="mt-4 text-lg text-gray-500 leading-relaxed max-w-2xl">
                                {article.description}
                            </p>
                        </div>
                    </div>

                    <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
                        <div className="lg:grid lg:grid-cols-[1fr_280px] lg:gap-12">

                            {/* Article body */}
                            <article
                                className="prose-article bg-white rounded-2xl border border-gray-100 p-8 sm:p-10"
                                dangerouslySetInnerHTML={{ __html: article.content }}
                            />

                            {/* Sidebar */}
                            <aside className="mt-10 lg:mt-0 space-y-8">
                                {/* CTA */}
                                <div className="bg-gradient-to-br from-violet-600 to-indigo-600 rounded-2xl p-6 text-white">
                                    <h3 className="font-bold text-lg mb-2">Try it yourself</h3>
                                    <p className="text-violet-100 text-sm mb-4 leading-relaxed">
                                        See all of this in action with a free sandbox — realistic campaigns, no real ad spend.
                                    </p>
                                    <a
                                        href="/register"
                                        className="block text-center px-4 py-2.5 bg-white text-violet-700 font-semibold text-sm rounded-lg hover:bg-violet-50 transition"
                                    >
                                        Start Free
                                    </a>
                                </div>

                                {/* Related articles */}
                                {relatedArticles.length > 0 && (
                                    <div>
                                        <h3 className="text-xs font-bold uppercase tracking-widest text-gray-400 mb-4">
                                            More articles
                                        </h3>
                                        <div className="space-y-3">
                                            {relatedArticles.map(related => (
                                                <Link
                                                    key={related.slug}
                                                    href={`/help/${related.slug}`}
                                                    className="group block bg-white rounded-xl border border-gray-100 p-4 hover:border-violet-200 hover:shadow-sm transition-all"
                                                >
                                                    <div className="text-xs text-gray-400 mb-1">{related.read_time}</div>
                                                    <div className="text-sm font-semibold text-gray-800 group-hover:text-violet-700 transition-colors leading-snug">
                                                        {related.title}
                                                    </div>
                                                </Link>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* Back link */}
                                <Link
                                    href="/help"
                                    className="flex items-center gap-2 text-sm text-gray-400 hover:text-gray-600 transition-colors"
                                >
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                                    </svg>
                                    All help articles
                                </Link>
                            </aside>
                        </div>
                    </div>
                </main>

                <Footer />
            </div>
        </>
    );
}
