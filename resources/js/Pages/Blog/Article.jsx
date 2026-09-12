import { Link } from '@inertiajs/react';
import PageTitle from '@/Components/PageTitle';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

const CATEGORY_COLORS = {
    'Getting Started': 'bg-green-100 text-green-700',
    'Platform':        'bg-violet-100 text-violet-800',
    'Google Ads':      'bg-blue-100 text-blue-700',
};

/*
 * No <Head> here — LandingController's sibling, HelpController, builds the
 * metadata and app.blade.php prints it server-side.
 *
 * This page used to emit its own, including a rel=canonical built from a
 * literal 'https://sitetospend.com'. Blade already emits one from url(), so
 * every article shipped two canonicals pointing at different hosts, and Google
 * discards all of them when there is more than one. The literal was also wrong
 * on the realpropertyads.com skin, where it pointed at another brand's site.
 */
export default function HelpArticle({ auth, article, relatedArticles = [] }) {
    return (
        <>
            <PageTitle />
            <div className="min-h-screen bg-gray-50 flex flex-col">
                <Header auth={auth} />

                <main className="flex-1">
                    {/* Breadcrumb + header */}
                    <div className="bg-white border-b border-gray-100">
                        <div className="mx-auto max-w-4xl px-4 py-8 sm:px-6 sm:py-10 lg:px-8">
                            <nav aria-label="Breadcrumb" className="mb-4 flex items-center gap-2 text-sm text-gray-500">
                                <Link href="/blog" className="inline-flex min-h-[44px] items-center font-medium text-brand-darker transition-colors hover:underline">Blog</Link>
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                </svg>
                                <span className="text-gray-600">{article.title}</span>
                            </nav>

                            <div className="flex items-center gap-3 mb-4">
                                <span className={`text-xs font-semibold rounded-full px-3 py-1 ${CATEGORY_COLORS[article.category] ?? 'bg-gray-100 text-gray-600'}`}>
                                    {article.category}
                                </span>
                                <span className="text-sm text-gray-500">{article.read_time}</span>
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
                                className="prose-article bg-white rounded-2xl border border-gray-100 p-5 sm:p-8 lg:p-10"
                                dangerouslySetInnerHTML={{ __html: article.content }}
                            />

                            {/* Sidebar */}
                            <aside className="mt-10 lg:mt-0 space-y-8">
                                {/* CTA */}
                                <div className="rounded-2xl bg-brand-darker p-6 text-white">
                                    <h3 className="font-bold text-lg mb-2">Try it yourself</h3>
                                    <p className="mb-4 text-sm leading-relaxed text-white/80">
                                        See all of this in action with a free sandbox — realistic campaigns, no real ad spend.
                                    </p>
                                    <a
                                        href="/register"
                                        className="block rounded-lg bg-white px-4 py-3 text-center text-sm font-semibold text-brand-darker transition hover:bg-gray-100"
                                    >
                                        Start Free
                                    </a>
                                </div>

                                {/* Related articles */}
                                {relatedArticles.length > 0 && (
                                    <div>
                                        <h3 className="text-xs font-bold uppercase tracking-widest text-gray-500 mb-4">
                                            More articles
                                        </h3>
                                        <div className="space-y-3">
                                            {relatedArticles.map(related => (
                                                <Link
                                                    key={related.slug}
                                                    href={`/blog/${related.slug}`}
                                                    className="group block bg-white rounded-xl border border-gray-100 p-4 hover:border-brand-dark hover:shadow-sm transition-all"
                                                >
                                                    <div className="text-xs text-gray-500 mb-1">{related.read_time}</div>
                                                    <div className="text-sm font-semibold text-gray-800 group-hover:text-brand-darker transition-colors leading-snug">
                                                        {related.title}
                                                    </div>
                                                </Link>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* Back link */}
                                <Link
                                    href="/blog"
                                    className="flex min-h-[44px] items-center gap-2 text-sm font-medium text-brand-darker transition-colors hover:underline"
                                >
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                                    </svg>
                                    All articles
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
