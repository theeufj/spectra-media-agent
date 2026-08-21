/**
 * Central Chart.js registration for the admin analytics pages.
 *
 * Import this once per page for its side effect:
 *
 *     import '@/Components/Charts/registerCharts';
 *
 * The six existing chart pages each register their own components inline. That
 * is harmless — registration is idempotent — so they are deliberately left
 * alone; converting them is unrelated risk for no behaviour change.
 *
 * Defaults set here are the recessive-chrome rules from the dataviz method:
 * grid and axes stay behind the data, marks are thin, tooltips are on by
 * default because an HTML chart that cannot be interrogated is a picture.
 */
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';
import { INK } from './palette';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler,
);

ChartJS.defaults.font.family =
    'system-ui, -apple-system, "Segoe UI", sans-serif';
ChartJS.defaults.color = INK.light.secondary;

// 2px lines, >=8px hit targets. Thin marks; the data is the ink.
ChartJS.defaults.elements.line.borderWidth = 2;
ChartJS.defaults.elements.point.radius = 3;
ChartJS.defaults.elements.point.hoverRadius = 6;
ChartJS.defaults.elements.point.hitRadius = 8;
// A 4px rounded data-end, anchored to the baseline rather than floating.
ChartJS.defaults.elements.bar.borderRadius = 4;
ChartJS.defaults.elements.bar.borderSkipped = 'start';

ChartJS.defaults.plugins.legend.labels.usePointStyle = true;
ChartJS.defaults.plugins.legend.labels.boxWidth = 8;
ChartJS.defaults.plugins.legend.labels.padding = 16;
ChartJS.defaults.plugins.tooltip.padding = 10;
ChartJS.defaults.plugins.tooltip.displayColors = true;
ChartJS.defaults.plugins.tooltip.backgroundColor = 'rgba(11,11,11,0.92)';

/**
 * Axis options for a recessive grid: horizontal rules only, no vertical
 * ladder, no axis border competing with the bars.
 */
export const recessiveScales = (mode = 'light') => ({
    x: {
        grid: { display: false },
        border: { color: INK[mode].axis },
        ticks: { color: INK[mode].muted },
    },
    y: {
        grid: { color: INK[mode].grid, drawTicks: false },
        border: { display: false },
        ticks: { color: INK[mode].muted },
    },
});

export { ChartJS };
