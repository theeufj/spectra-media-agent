import { useJobWatch } from '@/hooks/useJobWatch';

export function useCampaignStrategyWatch(url, enabled) {
    return useJobWatch(url, {
        enabled,
        interval: 10000,
        // GenerateStrategy allows twelve minutes for research and generation.
        // Keep receiving the result beyond that window, including queue delay.
        timeoutMs: 15 * 60 * 1000,
        isFailed: (data) => Boolean(data?.strategy_generation_error),
        isDone: (data) => Boolean(data?.strategies?.length) && !data?.is_generating_strategies,
    });
}
