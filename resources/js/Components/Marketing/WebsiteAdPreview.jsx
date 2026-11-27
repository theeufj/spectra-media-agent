import { useSignupFunnel } from '@/hooks/useSignupFunnel';
import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import DemoResultsPanel, { hasUsableAd } from '@/Components/DemoResultsPanel';
import Hero, { brandTint, CTA_PRIMARY, CTA_SIZE_COMPACT } from '@/Components/Marketing/Hero';
import { trackConversion } from '@/utils/conversions';
import { fetchJson, HttpError } from '@/utils/http';

function previewFailureReason(error) {
    if (error.name === 'AbortError') return 'timeout';
    if (error instanceof TypeError) return 'network';
    if (error.status === 422) return 'validation';
    if (error.status === 429) return 'rate_limited';
    return 'unavailable';
}

export default function WebsiteAdPreview({ measureFunnel = false, ...hero }) {
    const [url, setUrl] = useState('');
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const recordStage = useSignupFunnel(measureFunnel, {
        landing: true,
        previewDisplayed: typeof result?.preview_token === 'string' && result.preview_token.trim().length > 0,
    });
    const demoRequest = useRef(null);
    const previewCard = useRef(null);
    useEffect(() => {
        // On a direct ad visit the fragment arrives before React mounts its target.
        if (window.location.hash === '#build-my-ads') {
            previewCard.current?.scrollIntoView({ block: 'start' });
        }
    }, []);
    useEffect(() => () => {
        const pending = demoRequest.current;
        demoRequest.current = null;
        pending?.abort();
    }, []);
    useEffect(() => {
        if (result) {
            previewCard.current?.focus({ preventScroll: true });
            previewCard.current?.scrollIntoView?.({ block: 'start' });
        }
    }, [result]);

    const handleDemoSubmit = async e => {
        e.preventDefault();
        if (demoRequest.current) return;
        recordStage('preview_started');
        if (!url.trim()) {
            recordStage('preview_failed', 'validation');
            setError('Enter your website address to build a preview.');
            return;
        }
        recordStage('preview_submitted');
        const website = /^https?:\/\//i.test(url.trim()) ? url.trim() : 'https://' + url.trim();
        const controller = new AbortController();
        demoRequest.current = controller;
        const timeout = setTimeout(() => controller.abort(), 180000);
        setError(null);
        setLoading(true);
        try {
            const data = await fetchJson('/api/demo/generate-full', {
                method: 'POST', signal: controller.signal,
                json: { url: website },
            });
            if (demoRequest.current !== controller) return;
            if (data?.success !== true || !data.url || !hasUsableAd(data.ad_copy)) {
                recordStage('preview_failed', 'unavailable');
                setError('We could not create a complete ad from this page. Try the page that describes your product or service.');
                return;
            }
            setResult(data);
            trackConversion('try_now');
        } catch (err) {
            if (demoRequest.current !== controller) return;
            recordStage('preview_failed', previewFailureReason(err));
            const validation = err instanceof HttpError ? Object.values(err.body?.errors ?? {}).flat()[0] : null;
            const message = err.status === 429 ? 'Too many previews were requested. Please wait a minute and retry.'
                : validation || (err.name === 'AbortError' ? 'The preview took longer than expected. Please retry.' : 'We could not finish your preview. Please retry.');
            setError(message);
        } finally {
            clearTimeout(timeout);
            if (demoRequest.current === controller) {
                demoRequest.current = null;
                setLoading(false);
            }
        }
    };

    if (result) return (
        <section id="build-my-ads" ref={previewCard} tabIndex={-1} aria-label="Your ad preview" className="scroll-mt-20 bg-white px-4 py-10 sm:px-6 lg:px-8"
            style={{ backgroundImage: `linear-gradient(to bottom, ${brandTint(10)}, #fff)` }}>
            <DemoResultsPanel result={result} onSaveClick={() => recordStage('save_clicked')} />
        </section>
    );

    return (
        <Hero {...hero}>
            <div id="build-my-ads" ref={previewCard} className="scroll-mt-20 rounded-xl border border-gray-200 bg-white p-4 text-left shadow-lg">
                <p className="mb-3 text-sm text-gray-600">Enter your website to see a Google Search ad written for your business. You can save the draft after you read it.</p>
                <form aria-label="Preview my Google ad" aria-busy={loading}
                    onChange={() => recordStage('preview_started')}
                    onInvalidCapture={() => {
                        recordStage('preview_started');
                        recordStage('preview_failed', 'validation');
                    }}
                    onSubmit={handleDemoSubmit} className="flex flex-col gap-3">
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
                            {loading ? 'Building your preview…' : 'Preview my Google ad'}
                        </button>
                    </div>
                </form>
                {error && <p role="alert" className="mt-2 text-sm text-red-600">{error}</p>}
                {loading && (
                    <div className="mt-4">
                        <p role="status" className="text-center text-sm font-medium text-brand-darker">Reading your site and preparing an ad preview. This can take a few minutes.</p>
                    </div>
                )}
                <Link href="/register" className="mt-4 inline-block text-sm font-medium text-brand-darker underline underline-offset-2">
                    Create a free account without a preview
                </Link>
            </div>
        </Hero>
    );
}
