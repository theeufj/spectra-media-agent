import { useEffect, useRef, useState } from 'react';
import { fetchJson } from '@/utils/http';

/**
 * The email as it will actually arrive.
 *
 * Rendered server-side from the real mailable and dropped into a sandboxed
 * iframe, so what is on screen is the same HTML the recipient's client gets —
 * shell, signature spacing, unsubscribe footer and all. Re-implementing the
 * layout in React would look right until either side changed, and then it
 * would quietly lie.
 *
 * The iframe carries no `allow-scripts`. The body is sanitised before it gets
 * here, but the preview is the one place admin-authored markup is rendered
 * inside the app's own origin, so it does not get to run anything even if the
 * sanitiser is one day wrong.
 */

const WIDTHS = {
    desktop: { label: 'Desktop', width: '100%' },
    mobile: { label: 'Mobile', width: '390px' },
};

export default function EmailPreview({ step, draft }) {
    const [html, setHtml] = useState(null);
    const [error, setError] = useState(null);
    const [device, setDevice] = useState('desktop');
    const [meta, setMeta] = useState({ subject: '', from: '' });
    const latest = useRef(0);

    const { subject, body, format } = draft;

    useEffect(() => {
        // Debounced: this re-renders a Blade view, and firing on every
        // keystroke would put a request per character on the admin throttle.
        const timer = setTimeout(async () => {
            const token = ++latest.current;

            try {
                const result = await fetchJson(route('admin.email-sequence-steps.preview', step.id), {
                    method: 'POST',
                    json: { subject, body, format },
                });

                // A slower earlier request must not overwrite a newer one.
                if (token !== latest.current) return;

                setHtml(result.html);
                setMeta({ subject: result.subject, from: result.from });
                setError(null);
            } catch (e) {
                if (token !== latest.current) return;
                setError(e.message ?? 'Could not render the preview.');
            }
        }, 400);

        return () => clearTimeout(timer);
    }, [step.id, subject, body, format]);

    return (
        <div className="rounded-lg border border-gray-200 bg-gray-50">
            <div className="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-2">
                <div className="min-w-0">
                    <p className="truncate text-xs font-medium text-gray-900">{meta.subject || subject}</p>
                    <p className="truncate text-xs text-gray-500">{meta.from}</p>
                </div>
                <div className="flex shrink-0 rounded-md border border-gray-300 bg-white p-0.5">
                    {Object.entries(WIDTHS).map(([key, { label }]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setDevice(key)}
                            className={`rounded px-2 py-1 text-xs transition ${
                                device === key ? 'bg-flame-orange-500 text-white' : 'text-gray-600 hover:bg-gray-100'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="flex justify-center p-4">
                {error ? (
                    <p className="py-8 text-sm text-red-600">{error}</p>
                ) : html === null ? (
                    <p className="py-8 text-sm text-gray-400">Rendering…</p>
                ) : (
                    <iframe
                        title="Email preview"
                        srcDoc={html}
                        sandbox=""
                        className="h-[620px] rounded border border-gray-200 bg-white shadow-sm transition-all"
                        style={{ width: WIDTHS[device].width, maxWidth: '100%' }}
                    />
                )}
            </div>
        </div>
    );
}
