import { useState, useEffect, useCallback, useRef } from 'react';
import { router } from '@inertiajs/react';

const TOUR_STEPS = [
    {
        target: '[data-tour="dashboard"]',
        title: 'Welcome to Spectra',
        body: 'This is your dashboard — a quick overview of campaign performance, tasks, and AI agent activity.',
        placement: 'bottom',
    },
    {
        target: '[data-tour="campaigns"]',
        title: 'Campaigns',
        body: 'Create and manage your ad campaigns across Google, Facebook, Microsoft, and LinkedIn from one place.',
        placement: 'bottom',
    },
    {
        target: '[data-tour="content"]',
        title: 'Content',
        body: 'Add your website content to the Knowledge Base and set Brand Guidelines so AI writes ads in your voice.',
        placement: 'bottom',
    },
    {
        target: '[data-tour="insights"]',
        title: 'Insights',
        body: 'Keyword research, SEO audits, budget allocation, reports, and analytics — all your data in one menu.',
        placement: 'bottom',
    },
    {
        target: '[data-tour="strategy"]',
        title: 'Strategy',
        body: 'The War Room gives you a real-time strategic command center. Proposals help you plan new initiatives.',
        placement: 'bottom',
    },
    {
        target: '[data-tour="new-campaign"]',
        title: 'Ready to start?',
        body: 'Click here to create your first campaign. Our AI will guide you through setup step by step.',
        placement: 'bottom-end',
    },
];

const STORAGE_KEY = 'spectra_tour_completed';

function getTooltipPosition(targetEl, placement) {
    const rect = targetEl.getBoundingClientRect();
    const scrollY = window.scrollY;
    const scrollX = window.scrollX;
    const gap = 12;

    switch (placement) {
        case 'bottom':
            return {
                top: rect.bottom + scrollY + gap,
                left: rect.left + scrollX + rect.width / 2,
                transform: 'translateX(-50%)',
                arrowClass: 'bottom',
            };
        case 'bottom-end':
            return {
                top: rect.bottom + scrollY + gap,
                left: rect.right + scrollX,
                transform: 'translateX(-100%)',
                arrowClass: 'bottom-end',
            };
        default:
            return {
                top: rect.bottom + scrollY + gap,
                left: rect.left + scrollX + rect.width / 2,
                transform: 'translateX(-50%)',
                arrowClass: 'bottom',
            };
    }
}

export default function OnboardingTour({ forceShow = false }) {
    const [active, setActive] = useState(false);
    const [step, setStep] = useState(0);
    const [pos, setPos] = useState(null);
    const tooltipRef = useRef(null);

    // Determine if the tour should show
    useEffect(() => {
        if (forceShow) {
            setActive(true);
            setStep(0);
            return;
        }
        try {
            const completed = localStorage.getItem(STORAGE_KEY);
            if (!completed) {
                setActive(true);
                setStep(0);
            }
        } catch {
            // localStorage not available
        }
    }, [forceShow]);

    const positionTooltip = useCallback(() => {
        if (!active) return;
        const current = TOUR_STEPS[step];
        if (!current) return;
        const el = document.querySelector(current.target);
        if (!el) return;
        setPos(getTooltipPosition(el, current.placement));
    }, [active, step]);

    useEffect(() => {
        positionTooltip();
        window.addEventListener('resize', positionTooltip);
        window.addEventListener('scroll', positionTooltip);
        return () => {
            window.removeEventListener('resize', positionTooltip);
            window.removeEventListener('scroll', positionTooltip);
        };
    }, [positionTooltip]);

    // Highlight current target element
    useEffect(() => {
        if (!active) return;
        const current = TOUR_STEPS[step];
        if (!current) return;
        const el = document.querySelector(current.target);
        if (!el) return;

        el.style.position = 'relative';
        el.style.zIndex = '60';
        el.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });

        return () => {
            el.style.position = '';
            el.style.zIndex = '';
        };
    }, [active, step]);

    const finish = useCallback(() => {
        setActive(false);
        try {
            localStorage.setItem(STORAGE_KEY, '1');
        } catch {
            // ignore
        }
    }, []);

    const next = useCallback(() => {
        if (step < TOUR_STEPS.length - 1) {
            setStep(s => s + 1);
        } else {
            finish();
        }
    }, [step, finish]);

    const prev = useCallback(() => {
        if (step > 0) setStep(s => s - 1);
    }, [step]);

    // Close on navigation
    useEffect(() => {
        const remove = router.on('navigate', finish);
        return remove;
    }, [finish]);

    // Close on escape
    useEffect(() => {
        const handleKey = (e) => { if (e.key === 'Escape') finish(); };
        document.addEventListener('keydown', handleKey);
        return () => document.removeEventListener('keydown', handleKey);
    }, [finish]);

    if (!active || !pos) return null;

    const current = TOUR_STEPS[step];
    const isLast = step === TOUR_STEPS.length - 1;

    return (
        <>
            {/* Backdrop overlay */}
            <div
                className="fixed inset-0 z-50 bg-black/40 transition-opacity duration-300"
                onClick={finish}
            />

            {/* Tooltip */}
            <div
                ref={tooltipRef}
                className="absolute z-[70] w-80 max-w-[calc(100vw-2rem)] bg-white rounded-xl shadow-2xl border border-gray-200 p-5 animate-in fade-in"
                style={{
                    top: pos.top,
                    left: pos.left,
                    transform: pos.transform,
                }}
            >
                {/* Arrow */}
                <div
                    className={`absolute -top-2 w-4 h-4 bg-white border-l border-t border-gray-200 rotate-45 ${
                        pos.arrowClass === 'bottom-end' ? 'right-6' : 'left-1/2 -translate-x-1/2'
                    }`}
                />

                {/* Step counter */}
                <div className="flex items-center justify-between mb-2">
                    <span className="text-[11px] font-medium text-flame-orange-600 uppercase tracking-wider">
                        Step {step + 1} of {TOUR_STEPS.length}
                    </span>
                    <button
                        onClick={finish}
                        className="text-gray-400 hover:text-gray-600 transition-colors"
                        aria-label="Close tour"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <h4 className="text-sm font-semibold text-gray-900 mb-1">{current.title}</h4>
                <p className="text-sm text-gray-600 leading-relaxed mb-4">{current.body}</p>

                {/* Progress dots */}
                <div className="flex items-center justify-between">
                    <div className="flex gap-1.5">
                        {TOUR_STEPS.map((_, i) => (
                            <div
                                key={i}
                                className={`w-1.5 h-1.5 rounded-full transition-colors ${
                                    i === step ? 'bg-flame-orange-500' : i < step ? 'bg-flame-orange-200' : 'bg-gray-200'
                                }`}
                            />
                        ))}
                    </div>

                    <div className="flex items-center gap-2">
                        {step > 0 && (
                            <button
                                onClick={prev}
                                className="px-3 py-1.5 text-xs font-medium text-gray-600 hover:text-gray-900 transition-colors"
                            >
                                Back
                            </button>
                        )}
                        <button
                            onClick={next}
                            className="px-4 py-1.5 text-xs font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 transition-colors"
                        >
                            {isLast ? 'Get Started' : 'Next'}
                        </button>
                    </div>
                </div>
            </div>
        </>
    );
}
