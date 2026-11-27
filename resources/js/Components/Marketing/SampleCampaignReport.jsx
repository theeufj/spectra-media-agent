import { useEffect, useRef } from 'react';

export default function SampleCampaignReport() {
    const report = useRef(null);
    useEffect(() => {
        if (window.location.hash === '#sample-report') {
            report.current?.scrollIntoView({ block: 'start' });
        }
    }, []);

    return (
        <section ref={report} id="sample-report" aria-labelledby="sample-report-title" className="scroll-mt-16 border-y border-gray-200 bg-gray-50 py-12 sm:py-16">
            <div className="mx-auto grid max-w-6xl items-center gap-8 px-4 sm:px-6 lg:grid-cols-[2fr_3fr] lg:gap-14 lg:px-8">
                <div>
                    <h2 id="sample-report-title" className="text-3xl font-bold tracking-tight text-gray-900">What did the ads buy?</h2>
                    <p className="mt-4 leading-relaxed text-gray-700">A click tells you someone visited. A tracked enquiry tells you someone got in touch. Your booking records tell you which enquiries turned into work.</p>
                </div>
                <figure className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <figcaption className="bg-gray-900 px-5 py-4 text-white sm:px-7">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-300">Illustrative report · Fictional campaign</p>
                        <p className="mt-1 text-xl font-semibold">Residential leak repairs</p>
                        <p className="mt-1 text-sm text-gray-300">2–8 November 2026 · Google Search</p>
                    </figcaption>
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-5 px-5 py-5 sm:px-7">
                        {[
                            ['Google ad spend', 'US$280'],
                            ['Clicks', '56'],
                            ['Tracked enquiry forms', '7'],
                            ['Ad spend per enquiry', 'US$40'],
                        ].map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-sm text-gray-600">{label}</dt>
                                <dd className="text-2xl font-bold tabular-nums text-gray-900">{value}</dd>
                            </div>
                        ))}
                    </dl>
                    <p className="border-t border-gray-200 px-5 py-4 text-sm leading-relaxed text-gray-600 sm:px-7">
                        US$280 in media spend ÷ 7 tracked forms = US$40 per enquiry. The monthly management fee is separate. Check the seven forms against your service area and jobs booked before increasing the budget.
                    </p>
                    <div className="border-t border-gray-200 bg-gray-50 px-5 py-4 text-sm sm:px-7">
                        <p className="font-semibold text-gray-900">Example change · 6 November</p>
                        <p className="mt-1 leading-relaxed text-gray-700">Added “plumbing courses” as a negative keyword after reviewing search terms. Training falls outside the residential repair service advertised.</p>
                    </div>
                </figure>
            </div>
        </section>
    );
}
