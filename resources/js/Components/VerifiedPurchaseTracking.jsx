import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { usePolling } from '@/hooks/usePolling';
import { invokeVerifiedPurchases } from '@/utils/verifiedPurchases';

export default function VerifiedPurchaseTracking() {
    const { purchaseTracking } = usePage().props;
    const [watching, setWatching] = useState(false);
    useEffect(() => {
        if (!purchaseTracking?.installed || !purchaseTracking?.watch_for_payment) {
            setWatching(false);
            return;
        }
        setWatching(true);
        const timeout = setTimeout(() => setWatching(false), 60000);
        return () => clearTimeout(timeout);
    }, [purchaseTracking?.installed, purchaseTracking?.watch_for_payment]);
    const { data } = usePolling(purchaseTracking?.pending_url, {
        interval: 3000,
        enabled: Boolean(purchaseTracking?.installed && watching),
        until: result => Array.isArray(result?.pending) && result.pending.length > 0,
    });
    useEffect(() => {
        if (purchaseTracking?.installed) void invokeVerifiedPurchases(purchaseTracking.pending);
    }, [purchaseTracking]);
    useEffect(() => {
        if (data?.installed) void invokeVerifiedPurchases(data.pending);
    }, [data]);
    return null;
}
