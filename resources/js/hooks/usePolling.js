import { useEffect, useRef, useState } from 'react';
import { fetchJson } from '@/utils/http';

/**
 * Poll a JSON endpoint until a stop condition is met.
 *
 * Five pages each hand-rolled this with setInterval + an async callback. Two
 * problems recur in those versions: the interval keeps firing while a slow
 * request is still in flight (overlapping requests), and an unmounted component
 * still calls setState from the in-flight response.
 *
 * @param {string|null} url            endpoint to poll; null disables polling
 * @param {object}      options
 * @param {number}      options.interval  ms between polls (default 5000)
 * @param {boolean}     options.enabled   poll only while true (default true)
 * @param {(data: any) => boolean} options.until  return true to stop polling
 * @param {boolean}     options.immediate fetch once on mount (default true)
 */
export function usePolling(url, options = {}) {
    const {
        interval = 5000,
        enabled = true,
        until = () => false,
        immediate = true,
    } = options;

    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [isPolling, setIsPolling] = useState(enabled);
    // Consecutive failed ticks, reset by any success. A monotonically
    // changing number, unlike `error`, which can be the same instance twice
    // and bail out of re-renders — callers watching for sustained failure
    // (useJobWatch's disconnected phase) count on this.
    const [failureStreak, setFailureStreak] = useState(0);

    // Keep the stop predicate in a ref so callers can pass an inline arrow
    // without restarting the interval on every render.
    const untilRef = useRef(until);
    untilRef.current = until;

    useEffect(() => {
        if (!url || !enabled) {
            setIsPolling(false);
            return undefined;
        }

        let cancelled = false;
        let inFlight = false;
        let timer = null;

        setIsPolling(true);

        const stop = () => {
            if (timer) clearInterval(timer);
            timer = null;
            if (!cancelled) setIsPolling(false);
        };

        const tick = async () => {
            // Skip rather than stack up if the previous request hasn't returned.
            if (inFlight) return;
            inFlight = true;
            try {
                const result = await fetchJson(url);
                if (cancelled) return;
                setData(result);
                setError(null);
                setFailureStreak(0);
                if (untilRef.current(result)) stop();
            } catch (e) {
                if (!cancelled) {
                    setError(e);
                    setFailureStreak((n) => n + 1);
                }
            } finally {
                inFlight = false;
            }
        };

        if (immediate) tick();
        timer = setInterval(tick, interval);

        return () => {
            cancelled = true;
            if (timer) clearInterval(timer);
        };
    }, [url, interval, enabled, immediate]);

    return { data, error, isPolling, failureStreak };
}
