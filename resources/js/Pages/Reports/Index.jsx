import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { money as formatMoney, count as formatCount, date as formatDate } from '@/utils/format';
import { useCurrency } from '@/hooks/useCurrency';
import { useMemo, useState } from 'react';
import {
    ArrowDownTrayIcon,
    ArrowPathIcon,
    DocumentTextIcon,
    SparklesIcon,
} from '@heroicons/react/24/outline';
import { brandTint } from '@/Components/Marketing/Hero';

/*
 * Reports listing.
 *
 * The job on this page is comparing the same four measures across a run of
 * periods — "was last week better than the one before?" — and it was rendered
 * as a stack of full-width cards with the numbers as inline prose
 * ("$592.8 spend  330 clicks  30 conversions  $19.76 CPA"). Nothing lined up,
 * so the comparison the page exists for had to be done by reading. Twelve
 * periods filled about four screens.
 *
 * A table, because that is what this is: one row per period, numbers
 * right-aligned in fixed columns, tabular figures so the digits sit under each
 * other. Cards remain below `sm`, where columns cannot fit.
 *
 * The duplicate rows this page used to show were not a display bug — both
 * generator jobs appended to the history cache without checking whether the
 * period was already in it. Fixed in App\Jobs\Concerns\RecordsReportHistory.
 */

const count = (n) => (n === null || n === undefined ? '—' : formatCount(n));

const PERIOD = {
    monthly: { label: 'Monthly', className: 'bg-violet-100 text-violet-800' },
    weekly: { label: 'Weekly', className: 'bg-sky-100 text-sky-800' },
};

function formatGenerated(value) {
    if (! value) return '';
    const d = new Date(value);

    return Number.isNaN(d.valueOf())
        ? ''
        : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function PeriodBadge({ period }) {
    const { label, className } = PERIOD[period] ?? PERIOD.weekly;

    return (
        <span className={`inline-flex rounded px-2 py-0.5 text-xs font-semibold ${className}`}>{label}</span>
    );
}

function PdfButton({ report, onDownload, className = '' }) {
    if (! report.pdf_path) {
        return <span className="text-xs text-gray-400">No PDF</span>;
    }

    return (
        <button
            type="button"
            onClick={() => onDownload(report)}
            className={`inline-flex min-h-[44px] items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-brand-darker transition-colors hover:bg-brand-tint-20 ${className}`}
            style={{ backgroundColor: brandTint(10) }}
        >
            <ArrowDownTrayIcon className="h-4 w-4" aria-hidden="true" />
            PDF
            <span className="sr-only">
                {' '}
                for the {report.period} report covering {formatDate(report.start)} to {formatDate(report.end)}
            </span>
        </button>
    );
}

/**
 * One row per period, newest kept.
 *
 * RecordsReportHistory stops new duplicates being written, but the listing is a
 * cache entry with a 365-day TTL — every customer who already has doubled
 * history keeps it until their entry expires or every period is regenerated.
 * Collapsing here means they see the fix now rather than next year, and it also
 * keeps React from being handed two rows with the same key.
 */
function dedupeByPeriod(reports) {
    const seen = new Map();

    for (const report of reports) {
        const key = `${report.period}-${report.start}`;
        if (! seen.has(key)) seen.set(key, report);
    }

    return [...seen.values()];
}

export default function Index({ reports = [], canWhiteLabel }) {
    const currency = useCurrency();

    // Was a module-level helper hardcoding '$'. A report is the customer's own
    // spend, so it follows the customer's currency like every other figure.
    const money = (n) => (n === null || n === undefined ? '—' : formatMoney(n, currency));

    const [generating, setGenerating] = useState(false);
    const rows = useMemo(() => dedupeByPeriod(reports), [reports]);

    const handleGenerate = (period) => {
        setGenerating(true);
        router.post(route('reports.generate'), { period }, {
            preserveScroll: true,
            onFinish: () => setGenerating(false),
        });
    };

    const handleDownload = (report) => {
        const date = report.end || report.start;
        window.location.href = route('reports.download', { period: report.period, date });
    };

    return (
        <AuthenticatedLayout>
            <Head title="Reports" />

            <div className="py-8">
                {/* `max-w-5xl sm:` — that trailing `sm:` was a truncated utility. */}
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight text-gray-900">Performance reports</h1>
                            <p className="mt-1 text-sm text-gray-600">
                                Generated every Monday and on the 1st of each month, with the AI's read on what changed.
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => handleGenerate('weekly')}
                                disabled={generating}
                                className="inline-flex min-h-[44px] items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500"
                            >
                                <ArrowPathIcon className={`h-4 w-4 ${generating ? 'animate-spin' : ''}`} aria-hidden="true" />
                                Weekly
                            </button>
                            <button
                                type="button"
                                onClick={() => handleGenerate('monthly')}
                                disabled={generating}
                                className="inline-flex min-h-[44px] items-center gap-1.5 rounded-lg bg-brand-dark px-4 text-sm font-medium text-white transition-colors hover:bg-brand-darker disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-600"
                            >
                                <DocumentTextIcon className="h-4 w-4" aria-hidden="true" />
                                Monthly
                            </button>
                        </div>
                    </div>

                    {canWhiteLabel && (
                        <div
                            className="mb-6 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border p-4"
                            style={{ backgroundColor: brandTint(8), borderColor: brandTint(30) }}
                        >
                            <SparklesIcon className="h-5 w-5 shrink-0 text-brand-darker" aria-hidden="true" />
                            <span className="text-sm font-medium text-gray-900">
                                Agency plan — reports can carry your own branding.
                            </span>
                            <a
                                href={route('reports.settings')}
                                className="text-sm font-medium text-brand-darker hover:underline sm:ml-auto"
                            >
                                Configure branding →
                            </a>
                        </div>
                    )}

                    {rows.length > 0 ? (
                        <>
                            {/* Table from sm up: the numbers only compare when they line up. */}
                            <div className="hidden overflow-hidden rounded-xl border border-gray-200 bg-white sm:block">
                                <table className="w-full text-sm">
                                    <caption className="sr-only">
                                        Performance reports, newest first
                                    </caption>
                                    <thead>
                                        <tr className="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                            <th scope="col" className="px-4 py-2.5 text-left font-medium">Period</th>
                                            <th scope="col" className="px-4 py-2.5 text-right font-medium">Spend</th>
                                            <th scope="col" className="px-4 py-2.5 text-right font-medium">Clicks</th>
                                            <th scope="col" className="px-4 py-2.5 text-right font-medium">Conv.</th>
                                            <th scope="col" className="px-4 py-2.5 text-right font-medium">CPA</th>
                                            <th scope="col" className="px-4 py-2.5 text-right font-medium">
                                                <span className="sr-only">Download</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {rows.map((report) => {
                                            const quiet = ! report.summary?.total_clicks;

                                            return (
                                                <tr key={`${report.period}-${report.start}`} className="hover:bg-gray-50">
                                                    <td className="px-4 py-3">
                                                        <div className="flex items-center gap-2">
                                                            <PeriodBadge period={report.period} />
                                                            <span className="font-medium text-gray-900">
                                                                {formatDate(report.start)} — {formatDate(report.end)}
                                                            </span>
                                                        </div>
                                                        <span className="text-xs text-gray-500">
                                                            generated {formatGenerated(report.generated_at)}
                                                            {/*
                                                                A period with no clicks is not the
                                                                same as a period that performed
                                                                badly, and rendering them
                                                                identically hid a fortnight of
                                                                stopped campaigns in this account.
                                                            */}
                                                            {quiet && ' · no activity'}
                                                        </span>
                                                    </td>
                                                    <td className={`px-4 py-3 text-right tabular-nums ${quiet ? 'text-gray-400' : 'font-medium text-gray-900'}`}>
                                                        {money(report.summary?.total_cost)}
                                                    </td>
                                                    <td className={`px-4 py-3 text-right tabular-nums ${quiet ? 'text-gray-400' : 'text-gray-700'}`}>
                                                        {count(report.summary?.total_clicks)}
                                                    </td>
                                                    <td className={`px-4 py-3 text-right tabular-nums ${quiet ? 'text-gray-400' : 'text-gray-700'}`}>
                                                        {count(report.summary?.total_conversions)}
                                                    </td>
                                                    <td className={`px-4 py-3 text-right tabular-nums ${quiet ? 'text-gray-400' : 'text-gray-700'}`}>
                                                        {report.summary?.total_conversions ? money(report.summary?.blended_cpa) : '—'}
                                                    </td>
                                                    <td className="px-4 py-3 text-right">
                                                        <PdfButton report={report} onDownload={handleDownload} />
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>

                            {/* Below sm, one card per period. */}
                            <ul className="space-y-3 sm:hidden">
                                {rows.map((report) => (
                                    <li
                                        key={`${report.period}-${report.start}`}
                                        className="rounded-xl border border-gray-200 bg-white p-4"
                                    >
                                        <div className="flex items-center gap-2">
                                            <PeriodBadge period={report.period} />
                                            <span className="text-sm font-medium text-gray-900">
                                                {formatDate(report.start)} — {formatDate(report.end)}
                                            </span>
                                        </div>
                                        <dl className="mt-3 grid grid-cols-4 gap-2 text-center">
                                            {[
                                                ['Spend', money(report.summary?.total_cost)],
                                                ['Clicks', count(report.summary?.total_clicks)],
                                                ['Conv.', count(report.summary?.total_conversions)],
                                                ['CPA', report.summary?.total_conversions ? money(report.summary?.blended_cpa) : '—'],
                                            ].map(([label, value]) => (
                                                <div key={label}>
                                                    <dt className="text-xs text-gray-500">{label}</dt>
                                                    <dd className="text-sm font-medium tabular-nums text-gray-900">{value}</dd>
                                                </div>
                                            ))}
                                        </dl>
                                        <div className="mt-3">
                                            <PdfButton report={report} onDownload={handleDownload} className="w-full justify-center" />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </>
                    ) : (
                        <div className="rounded-xl border border-gray-200 bg-white py-16 text-center">
                            <DocumentTextIcon className="mx-auto h-12 w-12 text-gray-300" aria-hidden="true" />
                            <h2 className="mt-4 text-sm font-medium text-gray-900">No reports yet</h2>
                            <p className="mx-auto mt-1 max-w-sm text-sm text-gray-600">
                                One is generated for you every Monday. You can also make one now from whatever data
                                has come in so far.
                            </p>
                            <button
                                type="button"
                                onClick={() => handleGenerate('weekly')}
                                disabled={generating}
                                className="mt-6 inline-flex min-h-[44px] items-center gap-1.5 rounded-lg bg-brand-dark px-4 text-sm font-medium text-white transition-colors hover:bg-brand-darker disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-600"
                            >
                                Generate the first one
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
