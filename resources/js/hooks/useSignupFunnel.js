import { useCallback, useEffect, useRef } from 'react';
import { fetchJson } from '@/utils/http';

export function isFunnelGuest(tenant, auth) {
    return tenant?.key === 'sitetospend' && !auth?.user;
}

function useVisibleStage(recordStage, stage, active) {
    useEffect(() => {
        if (!active) return;
        const recordWhenVisible = () => {
            if (document.visibilityState !== 'visible') return;
            recordStage(stage);
            document.removeEventListener('visibilitychange', recordWhenVisible);
        };
        document.addEventListener('visibilitychange', recordWhenVisible);
        recordWhenVisible();
        return () => document.removeEventListener('visibilitychange', recordWhenVisible);
    }, [recordStage, stage, active]);
}

export function useSignupFunnel(enabled, { landing = false, previewDisplayed = false } = {}) {
    const reported = useRef(new Set());
    const recordStage = useCallback((stage, reason) => {
        const key = reason ? stage + ':' + reason : stage;
        if (!enabled || reported.current.has(key)) return;
        reported.current.add(key);
        // The server identifies the journey from its own session. Never send form values or identifiers.
        void fetchJson('/funnel/stages', {
            method: 'POST',
            json: reason ? { stage, reason } : { stage },
            keepalive: true,
            referrerPolicy: 'no-referrer',
        }).catch(() => {});
    }, [enabled]);

    useVisibleStage(recordStage, 'landing_visible', enabled && landing);
    useVisibleStage(recordStage, 'preview_succeeded', enabled && previewDisplayed);

    useEffect(() => {
        if (!enabled || !landing) return;
        // Covers regular links and Inertia links in the header, page and footer.
        // The personal draft has its own Save event and server-side handoff.
        const recordSignupLink = event => {
            const link = event.target.closest?.('a[href]');
            if (link?.origin === window.location.origin && link.pathname === '/register' && !link.search) {
                recordStage('signup_cta_clicked');
            }
        };
        document.addEventListener('click', recordSignupLink, true);
        return () => document.removeEventListener('click', recordSignupLink, true);
    }, [enabled, landing, recordStage]);

    return recordStage;
}
