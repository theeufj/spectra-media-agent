import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { useJobWatch } from '@/hooks/useJobWatch';

/** A real persisted queue state; completion reloads the result, failures keep the original input. */
export default function WorkStatusBanner({ initialRun, url, task, label, reloadOnly, onBusyChange }) {
    const [run, setRun] = useState(initialRun);
    const seen = useRef(['completed', 'failed'].includes(initialRun?.status) ? initialRun.id : null);
    useEffect(() => { setRun(initialRun); }, [initialRun?.id, initialRun?.status]);
    const active = ['queued', 'running'].includes(run?.status);
    const { phase, data } = useJobWatch(url, {
        enabled: active,
        timeoutMs: 20 * 60 * 1000,
        isDone: result => result?.runs?.[task]?.status === 'completed',
        isFailed: result => result?.runs?.[task]?.status === 'failed',
        onDone: result => setRun(result.runs[task]),
        onFailed: result => setRun(result.runs[task]),
    });
    const current = data?.runs?.[task] && data.runs[task].id === run?.id ? data.runs[task] : run;
    useEffect(() => {
        onBusyChange?.(active && !['timeout', 'disconnected', 'failed', 'done'].includes(phase));
    }, [active, phase, onBusyChange]);
    useEffect(() => {
        if (['completed', 'failed'].includes(current?.status) && seen.current !== current.id) {
            seen.current = current.id;
            router.reload({ only: reloadOnly, preserveScroll: true });
        }
    }, [current?.id, current?.status]);
    if (!current) return null;
    const interrupted = ['timeout', 'disconnected'].includes(phase);
    const failed = current.status === 'failed' || interrupted;
    const completed = current.status === 'completed';
    return (
        <div role={failed ? 'alert' : 'status'} className={`mb-6 rounded-lg border p-4 text-sm ${failed ? 'border-red-200 bg-red-50 text-red-800' : completed ? 'border-green-200 bg-green-50 text-green-800' : 'border-blue-200 bg-blue-50 text-blue-800'}`}>
            <p className="font-medium">{label}: {failed ? 'needs attention' : completed ? 'completed' : current.status === 'queued' ? 'queued' : 'running'}</p>
            <p className="mt-1">{interrupted ? 'Live updates stopped. Refresh to check this run before starting another.' : current.message || (completed ? 'The latest results are shown below.' : 'Results will update here when the work finishes.')}</p>
            <p className="mt-1 text-xs">Last update: {new Date(current.updated_at).toLocaleString()}</p>
            {failed && <button type="button" onClick={() => router.reload({ preserveScroll: true })} className="mt-2 underline">Refresh status</button>}
        </div>
    );
}
