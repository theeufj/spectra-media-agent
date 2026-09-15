import { useCallback, useEffect, useState } from 'react';
import Modal from '@/Components/Modal';
import { FORMAT_LABELS } from '@/utils/collateral';

/**
 * The same photograph at each size it will run at.
 *
 * One concept is generated as a square for feeds, 1200x628 for landscape
 * placements and 300x250 for display, and the collateral page shows only the
 * square — the other two existed but there was no way to look at them. The
 * card said "3 sizes" without ever being able to show them, which is a promise
 * the page could not keep.
 *
 * Worth seeing rather than trusting: the three are generated separately at
 * their own aspect ratios, so they are not crops of one another, and the
 * headline is composited per size. Whether a 300x250 still reads is a question
 * only looking at it answers.
 */

/** Square first, because it is the one the scene was composed for. */
const ORDER = ['square', 'landscape', 'mrec'];

function ordered(images) {
    return [...(images || [])].sort(
        (a, b) => ORDER.indexOf(a.format) - ORDER.indexOf(b.format)
    );
}

export default function CreativeSizesModal({ concept, show, onClose }) {
    const images = ordered(concept?.images);
    const [index, setIndex] = useState(0);

    // A different card opening must not inherit the last one's position.
    useEffect(() => {
        setIndex(0);
    }, [concept?.key]);

    const step = useCallback(
        (by) => {
            if (images.length === 0) return;

            setIndex((i) => (i + by + images.length) % images.length);
        },
        [images.length]
    );

    useEffect(() => {
        if (! show) return undefined;

        const onKey = (e) => {
            if (e.key === 'ArrowRight') step(1);
            if (e.key === 'ArrowLeft') step(-1);
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [show, step]);

    if (! concept || images.length === 0) return null;

    const current = images[Math.min(index, images.length - 1)];

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <div className="p-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-semibold text-jet">This creative, at every size it runs at</h2>
                        <p className="mt-1 text-sm text-gray-600">
                            Each size is generated at its own shape rather than cropped from the others, so
                            the framing differs. Approving the card approves all {images.length}.
                        </p>
                    </div>
                    <button
                        onClick={onClose}
                        className="shrink-0 rounded-md p-1 text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-brand-primary"
                        aria-label="Close"
                    >
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {/* Checkered ground, so a 300x250 sitting in a wide frame reads
                    as a small image rather than as a broken one. */}
                <div
                    className="mt-5 flex min-h-[320px] items-center justify-center rounded-lg border border-gray-200 p-4"
                    style={{
                        backgroundImage:
                            'linear-gradient(45deg, #f3f4f6 25%, transparent 25%), linear-gradient(-45deg, #f3f4f6 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #f3f4f6 75%), linear-gradient(-45deg, transparent 75%, #f3f4f6 75%)',
                        backgroundSize: '16px 16px',
                        backgroundPosition: '0 0, 0 8px, 8px -8px, -8px 0',
                    }}
                >
                    <img
                        src={current.cloudfront_url}
                        alt={`${current.format} version of this creative`}
                        className="max-h-[60vh] max-w-full object-contain shadow-sm"
                    />
                </div>

                <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap gap-2">
                        {images.map((image, i) => (
                            <button
                                key={image.id}
                                onClick={() => setIndex(i)}
                                aria-current={i === index}
                                className={`rounded-full px-3 py-1 text-xs font-medium transition ${
                                    i === index
                                        ? 'bg-brand-dark text-white'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                }`}
                            >
                                {FORMAT_LABELS[image.format] || image.format}
                            </button>
                        ))}
                    </div>

                    {images.length > 1 && (
                        <div className="flex items-center gap-1">
                            <button
                                onClick={() => step(-1)}
                                className="rounded-md p-2 text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-primary"
                                aria-label="Previous size"
                            >
                                <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                                </svg>
                            </button>
                            <span className="px-1 text-xs tabular-nums text-gray-500">
                                {index + 1} / {images.length}
                            </span>
                            <button
                                onClick={() => step(1)}
                                className="rounded-md p-2 text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-primary"
                                aria-label="Next size"
                            >
                                <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                </svg>
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </Modal>
    );
}
