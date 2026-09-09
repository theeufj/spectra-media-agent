import Card from '@/Components/Card';

/**
 * The stat tile. There is one of these; do not write a thirteenth.
 *
 * Three near-identical copies of this existed (ExecutionMetrics, AiCosts,
 * Revenue) and had already drifted apart in ways that mattered:
 *
 *  - AiCosts coloured a rising trend RED, because rising spend is bad news.
 *    Revenue coloured the same rise GREEN. That is a real distinction, not
 *    drift, so it survives here as `higherIsBetter`.
 *  - Revenue rendered the trend row for `trend === null` ("↑ 0%", implying a
 *    flat period when the truth was "no comparable prior period"). Both now
 *    use `!= null`, so a null trend renders nothing.
 *
 * Eleven MORE local tiles were written anyway, across six card treatments,
 * three value sizes, two weights and three label treatments — so the Dashboard
 * KPI row and the same four numbers one click away on Analytics → ROI did not
 * look like the same product. `variant` is what lets those eleven collapse into
 * this file instead of a twelfth copy:
 *
 *   'badge'   — icon in a coloured tile on the left (AiCosts, Revenue)
 *   'plain'   — larger value, icon floated right (ExecutionMetrics)
 *   'compact' — uppercase micro-label over the value, no icon. This is the
 *               Dashboard KPI recipe, and the shape the eleven local tiles were
 *               all reaching for.
 *
 * The surface comes from `Card` so a tile cannot drift away from every other
 * panel on its page.
 */

const BADGE_COLORS = {
    flame: 'bg-brand-primary',
    green: 'bg-green-500',
    blue: 'bg-blue-500',
    purple: 'bg-purple-500',
    orange: 'bg-orange-500',
    red: 'bg-red-500',
};

const TrendArrow = ({ up }) => (
    <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
        {up ? (
            <path fillRule="evenodd" d="M5.293 9.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 7.414V15a1 1 0 11-2 0V7.414L6.707 9.707a1 1 0 01-1.414 0z" clipRule="evenodd" />
        ) : (
            <path fillRule="evenodd" d="M14.707 10.293a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L9 12.586V5a1 1 0 012 0v7.586l2.293-2.293a1 1 0 011.414 0z" clipRule="evenodd" />
        )}
    </svg>
);

export default function MetricCard({
    title,
    value,
    subtitle,
    icon,
    trend,
    trendLabel = 'vs last period',
    higherIsBetter = true,
    color = 'flame',
    variant = 'badge',
    valueClassName = '',
    className = '',
}) {
    const hasTrend = trend != null;
    const up = hasTrend && trend >= 0;
    const good = up === higherIsBetter;
    // green-600 is 3.30:1 on white and the trend line is 12–14px, so it failed AA
    // on the one number that tells you whether things are getting better.
    // green-700 is 5.02:1 and sits at the same visual weight as red-600 (4.83:1).
    const trendColor = good ? 'text-green-700' : 'text-red-600';

    if (variant === 'compact') {
        return (
            <Card padding="p-5" className={className}>
                <p className="text-xs text-gray-500 uppercase tracking-wide">{title}</p>
                <p className={`text-2xl font-bold mt-1 ${valueClassName || 'text-gray-900'}`}>{value}</p>
                {subtitle && <p className="text-xs text-gray-500 mt-1">{subtitle}</p>}
                {hasTrend && (
                    <p className={`text-xs mt-1 font-medium ${trendColor}`}>
                        {up ? '↑' : '↓'} {Math.abs(trend)}% {trendLabel}
                    </p>
                )}
            </Card>
        );
    }

    if (variant === 'plain') {
        return (
            <Card className={className}>
                <div className="flex items-center justify-between">
                    <div>
                        <p className="text-sm font-medium text-gray-600">{title}</p>
                        <p className={`text-3xl font-bold mt-2 ${valueClassName || 'text-gray-900'}`}>{value}</p>
                        {subtitle && <p className="text-sm text-gray-500 mt-1">{subtitle}</p>}
                    </div>
                    {icon && <div className="text-brand-dark">{icon}</div>}
                </div>
                {hasTrend && (
                    <div className={`mt-4 flex items-center text-sm ${trendColor}`}>
                        <TrendArrow up={up} />
                        {Math.abs(trend)}% {trendLabel}
                    </div>
                )}
            </Card>
        );
    }

    return (
        <Card className={className}>
            <div className="flex items-center">
                {icon && (
                    <div className={`flex-shrink-0 p-3 rounded-lg ${BADGE_COLORS[color] ?? BADGE_COLORS.flame}`}>
                        <span className="text-white text-xl">{icon}</span>
                    </div>
                )}
                <div className={`flex-1 min-w-0 ${icon ? 'ml-4' : ''}`}>
                    <p className="text-sm font-medium text-gray-500 truncate">{title}</p>
                    <p className={`text-2xl font-bold ${valueClassName || 'text-gray-900'}`}>{value}</p>
                    {/* text-gray-500 is 2.54:1 on white — under half the AA floor for
                        a 12px line. gray-500 is 4.83:1 and reads as the same tier. */}
                    {subtitle && <p className="text-xs text-gray-500">{subtitle}</p>}
                </div>
                {hasTrend && (
                    <div className={`text-sm font-semibold ml-2 ${trendColor}`}>
                        {up ? '↑' : '↓'} {Math.abs(trend)}%
                    </div>
                )}
            </div>
        </Card>
    );
}
