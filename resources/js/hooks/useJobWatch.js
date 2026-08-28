import { useEffect, useRef, useState } from 'react';
import { usePolling } from '@/hooks/usePolling';

/**
 * Watch a long-running server job (site scan, brand extraction, collateral
 * generation…) through a JSON status endpoint until it lands somewhere.
 *
 * The recurring failure this exists to prevent: a button dispatches a queued
 * job, the POST returns in milliseconds, the spinner disappears — and the
 * actual work runs for minutes with the page communicating nothing. This
 * hook keeps a phase the UI can narrate.
 *
 * Phases: idle → watching → done | failed | timeout. Terminal phases stick
 * until `enabled` goes false and true again (a new watch).
 *
 * @param {string} url                       status endpoint
 * @param {object}  options
 * @param {boolean} options.enabled          start/stop the watch
 * @param {(data: any) => boolean} options.isDone    job finished successfully
 * @param {(data: any) => boolean} [options.isFailed] job failed (checked first)
 * @param {(data: any) => void} [options.onDone]     fired once on completion
 * @param {(data: any) => void} [options.onFailed]   fired once on failure
 * @param {number}  [options.interval=5000]  poll cadence in ms
 * @param {number}  [options.timeoutMs=600000] give up after this long
 */
export function useJobWatch(url, {
    enabled,
    isDone,
    isFailed = () => false,
    onDone,
    onFailed,
    interval = 5000,
    timeoutMs = 10 * 60 * 1000,
} = {}) {
    const [phase, setPhase] = useState(enabled ? 'watching' : 'idle');

    const startRef = useRef(Date.now());
    const settledRef = useRef(false);

    // Callbacks and predicates live in refs so inline arrows don't restart
    // the underlying poll every render.
    const cbRef = useRef({ isDone, isFailed, onDone, onFailed });
    cbRef.current = { isDone, isFailed, onDone, onFailed };

    useEffect(() => {
        if (enabled) {
            startRef.current = Date.now();
            settledRef.current = false;
            setPhase('watching');
        } else {
            setPhase((p) => (p === 'watching' ? 'idle' : p));
        }
    }, [enabled]);

    const { data, error } = usePolling(enabled ? url : null, {
        interval,
        until: (result) => {
            if (settledRef.current) return true;

            const { isDone, isFailed, onDone, onFailed } = cbRef.current;

            if (isFailed(result)) {
                settledRef.current = true;
                setPhase('failed');
                onFailed?.(result);

                return true;
            }
            if (isDone(result)) {
                settledRef.current = true;
                setPhase('done');
                onDone?.(result);

                return true;
            }
            if (Date.now() - startRef.current > timeoutMs) {
                settledRef.current = true;
                setPhase('timeout');

                return true;
            }

            return false;
        },
    });

    return { phase, data, error };
}
