import { useEffect, useRef } from 'react';

/** Persistent submit feedback for forms whose field errors would otherwise be invisible. */
export default function FormErrorSummary({ errors = {}, labels = {}, fieldIds = {}, className = '' }) {
    const summary = useRef(null);
    const entries = Object.entries(errors).filter(([, message]) => Boolean(message));
    const signature = JSON.stringify(entries);

    useEffect(() => {
        if (entries.length) summary.current?.focus();
    }, [signature]);

    if (!entries.length) return null;

    return (
        <div ref={summary} tabIndex={-1} role="alert" className={`rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 focus:outline-none focus:ring-2 focus:ring-red-500 ${className}`}>
            <p className="font-semibold">Please check the following before saving:</p>
            <ul className="mt-2 list-inside list-disc space-y-1">
                {entries.map(([field, message]) => (
                    <li key={field}>
                        <button type="button" className="text-left underline" onClick={() => (document.getElementById(fieldIds[field] || field) || document.getElementById(field.split('.')[0]))?.focus()}>
                            {labels[field] || labels[field.split('.')[0]] ? `${labels[field] || labels[field.split('.')[0]]}: ` : ''}{Array.isArray(message) ? message.join(' ') : message}
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}
