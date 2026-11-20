import { useEffect, useRef, useState } from 'react';
import DemoResultsPanel from '@/Components/DemoResultsPanel';
import Hero, { brandTint, CTA_PRIMARY, CTA_SIZE_COMPACT } from '@/Components/Marketing/Hero';
import { trackConversion } from '@/utils/conversions';
import { fetchJson, HttpError } from '@/utils/http';
import { rememberPreviewContact } from '@/utils/previewContact';

export default function WebsiteAdPreview(hero) {
    const [url, setUrl] = useState('');
    const [firstName, setFirstName] = useState('');
    const [email, setEmail] = useState('');
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const demoRequest = useRef(null);
    const previewCard = useRef(null);
    useEffect(() => {
        // On a direct ad visit the fragment arrives before React mounts its target.
        if (window.location.hash === '#build-my-ads') {
            previewCard.current?.scrollIntoView({ block: 'start' });
        }
    }, []);
    useEffect(() => () => demoRequest.current?.abort(), []);

    const handleDemoSubmit = async e => {
        e.preventDefault();
        if (demoRequest.current || !url.trim()) return;
        const website = /^https?:\/\//i.test(url.trim()) ? url.trim() : 'https://' + url.trim();
        const controller = new AbortController();
        demoRequest.current = controller;
        const timeout = setTimeout(() => controller.abort(), 180000);
        setError(null);
        setLoading(true);
        try {
            const data = await fetchJson('/api/demo/generate-full', {
                method: 'POST', signal: controller.signal,
                json: { url: website, first_name: firstName.trim(), email: email.trim() },
            });
            if (data?.success !== true || !data.url) throw new Error('Missing preview');
            rememberPreviewContact(data.url, firstName.trim(), email.trim());
            setResult(data);
            trackConversion('try_now');
        } catch (err) {
            const validation = err instanceof HttpError ? Object.values(err.body?.errors ?? {}).flat()[0] : null;
            const message = err.status === 429 ? 'Too many previews were requested. Please wait a minute and retry.'
                : validation || (err.name === 'AbortError' ? 'The preview took longer than expected. Please retry.' : 'We could not finish your preview. Please retry.');
            setError(message);
        } finally {
            clearTimeout(timeout);
            demoRequest.current = null;
            setLoading(false);
        }
    };

    if (result) return (
        <section id="build-my-ads" aria-label="Your ad preview" className="scroll-mt-20 bg-white px-4 py-10 sm:px-6 lg:px-8"
            style={{ backgroundImage: `linear-gradient(to bottom, ${brandTint(10)}, #fff)` }}>
            <DemoResultsPanel result={result} />
        </section>
    );

    return (
        <Hero {...hero}>
            <div id="build-my-ads" ref={previewCard} className="scroll-mt-20 rounded-xl border border-gray-200 bg-white p-4 text-left shadow-lg">
                <p className="mb-3 text-sm text-gray-600">See sample ad copy for your website before you create an account. We use your email to follow up about the preview.</p>
                <form aria-label="Build my ads" aria-busy={loading} onSubmit={handleDemoSubmit} className="flex flex-col gap-3">
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <label htmlFor="demo-first-name" className="sr-only">First name</label>
                        <input
                            autoComplete="given-name" maxLength={100}
                            id="demo-first-name"
                            type="text"
                            required
                            value={firstName}
                            onChange={e => setFirstName(e.target.value)}
                            placeholder="First name"
                            className="flex-1 rounded-md border-gray-300 py-3 shadow-sm focus:border-brand-dark focus:ring-brand-dark"
                            disabled={loading}
                        />
                        <label htmlFor="demo-email" className="sr-only">Your email address</label>
                        <input
                            autoComplete="email" maxLength={255}
                            id="demo-email"
                            type="email"
                            required
                            value={email}
                            onChange={e => setEmail(e.target.value)}
                            placeholder="Your email address"
                            className="flex-1 rounded-md border-gray-300 py-3 shadow-sm focus:border-brand-dark focus:ring-brand-dark"
                            disabled={loading}
                        />
                    </div>
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <label htmlFor="demo-url" className="sr-only">Your website URL</label>
                        <input
                            autoComplete="url" inputMode="url" maxLength={255}
                            id="demo-url"
                            type="text"
                            required
                            value={url}
                            onChange={e => setUrl(e.target.value)}
                            placeholder="Enter your website URL..."
                            className="flex-1 rounded-md border-gray-300 py-3 shadow-sm focus:border-brand-dark focus:ring-brand-dark"
                            disabled={loading}
                        />
                        <button
                            type="submit"
                            disabled={loading}
                            className={`${CTA_PRIMARY} ${CTA_SIZE_COMPACT} w-full whitespace-nowrap disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-600 sm:w-auto`}
                        >
                            {loading ? 'Building your preview…' : 'Build my ads'}
                        </button>
                    </div>
                </form>
                {error && <p role="alert" className="mt-2 text-sm text-red-600">{error}</p>}
                {loading && (
                    <div className="mt-4">
                        <p role="status" className="text-center text-sm font-medium text-brand-darker">Reading your site and preparing an ad preview. This can take a few minutes.</p>
                    </div>
                )}
            </div>
        </Hero>
    );
}
