/**
 * The stat tile used across the admin analytics pages.
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
 * `variant` preserves the two layouts rather than picking a winner:
 *   'badge' — icon in a coloured tile on the left (AiCosts, Revenue)
 *   'plain' — larger value, icon floated right (ExecutionMetrics)
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
}) {
    const hasTrend = trend != null;
    const up = hasTrend && trend >= 0;
    const good = up === higherIsBetter;
    const trendColor = good ? 'text-green-600' : 'text-red-600';

    if (variant === 'plain') {
        return (
            <div className="bg-white rounded-lg shadow p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <p className="text-sm font-medium text-gray-600">{title}</p>
                        <p className="text-3xl font-bold text-gray-900 mt-2">{value}</p>
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
            </div>
        );
    }

    return (
        <div className="bg-white rounded-lg shadow p-6">
            <div className="flex items-center">
                {icon && (
                    <div className={`flex-shrink-0 p-3 rounded-lg ${BADGE_COLORS[color] ?? BADGE_COLORS.flame}`}>
                        <span className="text-white text-xl">{icon}</span>
                    </div>
                )}
                <div className={`flex-1 min-w-0 ${icon ? 'ml-4' : ''}`}>
                    <p className="text-sm font-medium text-gray-500 truncate">{title}</p>
                    <p className="text-2xl font-bold text-gray-900">{value}</p>
                    {subtitle && <p className="text-xs text-gray-400">{subtitle}</p>}
                </div>
                {hasTrend && (
                    <div className={`text-sm font-semibold ml-2 ${trendColor}`}>
                        {up ? '↑' : '↓'} {Math.abs(trend)}%
                    </div>
                )}
            </div>
        </div>
    );
}
