import { useCallback, useEffect, useRef } from 'react';
import { fetchJson } from '@/utils/http';

// The server identifies the journey from its own session. Form values, the
// referring URL, click identifiers and the optional ad consent stay out of this POST.
export function useSignupFunnel(enabled, { landing = false } = {}) {
    const reported = useRef(new Set());
    const recordStage = useCallback((stage, reason) => {
        const key = reason ? stage + ':' + reason : stage;
        if (!enabled || reported.current.has(key)) return;
        reported.current.add(key);
        void fetchJson('/funnel/stages', {
            method: 'POST',
            json: reason ? { stage, reason } : { stage },
            keepalive: true,
            referrerPolicy: 'no-referrer',
        }).catch(() => {});
    }, [enabled]);

    useEffect(() => {
        if (!enabled || !landing) return;
        const recordVisibleLanding = () => {
            if (document.visibilityState !== 'visible') return;
            recordStage('landing_visible');
            document.removeEventListener('visibilitychange', recordVisibleLanding);
        };
        document.addEventListener('visibilitychange', recordVisibleLanding);
        recordVisibleLanding();
        return () => document.removeEventListener('visibilitychange', recordVisibleLanding);
    }, [enabled, landing, recordStage]);

    return recordStage;
}
