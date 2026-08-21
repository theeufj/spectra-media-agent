import { Bar } from 'react-chartjs-2';
import { seriesColor, INK } from '@/Components/Charts/palette';

/**
 * The activation funnel.
 *
 * A horizontal bar, never a doughnut: funnel stages are ORDERED, and a pie
 * destroys the one property that matters. Bars are horizontal because the stage
 * labels are sentences ("Generated a strategy"), and vertical bars would either
 * truncate them or rotate them 45°.
 *
 * Each row is labelled with its count AND its step-to-step rate. Percent-of-top
 * alone is the classic funnel mistake — it hides a 90% collapse at step 4 behind
 * a small absolute number, which is exactly the drop you most need to see.
 *
 * The table underneath is not redundant. Three palette slots sit below 3:1
 * contrast on white, and the accessibility relief for that is a visible label or
 * a table view; this is that table. It is also the only place the two different
 * percentages can be read side by side without a tooltip.
 */
export default function FunnelChart({ steps }) {
    if (!steps?.length) return null;

    const brand = seriesColor(0);
    const labels = steps.map((s) => s.label);

    const data = {
        labels,
        datasets: [
            {
                label: 'Users',
                data: steps.map((s) => s.count),
                backgroundColor: brand,
                // A 2px surface gap so adjacent bars read as separate marks
                // rather than one continuous block.
                borderColor: INK.light.surface,
                borderWidth: 2,
                borderRadius: 4,
                borderSkipped: false,
            },
        ],
    };

    const options = {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            // One series — the title names it, so a legend box would be noise.
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: (ctx) => {
                        const step = steps[ctx.dataIndex];
                        const parts = [`${step.count.toLocaleString()} users`];
                        if (step.pct_of_previous != null) {
                            parts.push(`${step.pct_of_previous}% of previous step`);
                        }
                        parts.push(`${step.pct_of_start}% of signups`);
                        if (step.means) parts.push(step.means);
                        return parts;
                    },
                },
            },
        },
        scales: {
            x: {
                beginAtZero: true,
                grid: { color: INK.light.grid, drawTicks: false },
                border: { display: false },
                ticks: { color: INK.light.muted, precision: 0 },
            },
            y: {
                grid: { display: false },
                border: { color: INK.light.axis },
                ticks: { color: INK.light.secondary },
            },
        },
    };

    return (
        <div className="bg-white rounded-lg shadow">
            <div className="px-6 py-4 border-b border-gray-200">
                <h3 className="text-base font-semibold text-gray-900">Activation funnel</h3>
                <p className="text-xs text-gray-500 mt-1">
                    Of the people who signed up in this window, how many reached each step.
                    Every step is a subset of the one above it. Sandbox and deleted accounts excluded.
                </p>
            </div>

            <div className="p-6">
                <div style={{ height: `${Math.max(220, steps.length * 46)}px` }}>
                    <Bar data={data} options={options} />
                </div>
            </div>

            <div className="overflow-x-auto border-t border-gray-100">
                <table className="min-w-full">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-6 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Step</th>
                            <th className="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Users</th>
                            <th className="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">From previous</th>
                            <th className="px-6 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Of signups</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {steps.map((step) => {
                            // Flag the worst leak rather than making the reader
                            // scan for it.
                            const leaky = step.pct_of_previous != null && step.pct_of_previous < 50;

                            return (
                                <tr key={step.key} className="hover:bg-gray-50 align-top">
                                    <td className="px-6 py-2 text-sm text-gray-700">
                                        <div>{step.label}</div>
                                        {/* Each step's definition, because the
                                            labels are necessarily short and
                                            "Has an ad account" does not say
                                            whether being invited counts. */}
                                        {step.means && (
                                            <div className="mt-0.5 max-w-md text-xs font-normal text-gray-400">
                                                {step.means}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-4 py-2 text-sm text-right font-semibold text-gray-900 tabular-nums">
                                        {step.count.toLocaleString()}
                                    </td>
                                    <td className={`px-4 py-2 text-sm text-right tabular-nums ${leaky ? 'text-red-600 font-semibold' : 'text-gray-500'}`}>
                                        {step.pct_of_previous != null ? `${step.pct_of_previous}%` : '—'}
                                    </td>
                                    <td className="px-6 py-2 text-sm text-right text-gray-500 tabular-nums">
                                        {step.pct_of_start}%
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
