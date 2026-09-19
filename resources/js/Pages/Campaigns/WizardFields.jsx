import React, { useState } from 'react';

// TextArea Component
export const TextArea = ({ className = '', ...props }) => (
    <textarea
        {...props}
        className={`border-gray-300 focus:border-brand-primary focus:ring-brand-primary rounded-md shadow-sm w-full ${className}`}
        rows={3}
    />
);

// Tooltip Component
export const Tooltip = ({ children, text }) => (
    <div className="group relative inline-block">
        {children}
        <div className="invisible group-hover:visible absolute z-10 w-64 p-2 mt-1 text-sm text-white bg-gray-900 rounded-lg shadow-lg -left-1/2 transform">
            {text}
        </div>
    </div>
);

// Help Text Component
export const HelpText = ({ text }) => (
    <p className="mt-1 text-sm text-gray-500">{text}</p>
);

// Pre-flight Banner Component
export const PreflightBanner = ({ brandGuideline, pages, selectablePlatforms, configuredPlatforms, allowedPlatforms }) => {
    const [dismissed, setDismissed] = useState(false);
    if (dismissed) return null;

    const warnings = [];

    if (!brandGuideline) {
        warnings.push({
            key: 'brand',
            message: "Your brand hasn't been extracted yet.",
            action: 'Extract brand guidelines',
            href: '/brand-guidelines',
        });
    }

    if (!pages || pages.length === 0) {
        warnings.push({
            key: 'pages',
            message: 'No website pages have been crawled.',
            action: 'Add pages to your knowledge base',
            href: '/knowledge-base',
        });
    }

    /*
       No warning about unconfigured sub-accounts.

       This said "No ad platform sub-accounts are set up yet. Contact us to get
       your account configured", and it fired for every customer who had not
       subscribed yet — which is all of them at this point in the funnel. The
       accounts are created on deploy intent, deliberately, so the state it was
       reporting is the normal one. It presented that as a fault and offered
       "contact us" as the remedy, which is nobody's job and no kind of
       instruction.

       The platform step itself now says the accounts are created for them and
       prices whichever platforms they choose. That is the right place for it:
       next to the choice, phrased as how this works rather than as what is
       wrong with their account.
    */

    if (warnings.length === 0) return null;

    return (
        <div className="mb-6 bg-amber-50 border border-amber-200 rounded-lg p-4">
            <div className="flex items-start justify-between">
                <div className="flex items-start gap-3">
                    <svg className="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                    </svg>
                    <div>
                        <p className="text-sm font-semibold text-amber-800 mb-1">
                            A few things will help your campaign perform better:
                        </p>
                        <ul className="space-y-1">
                            {warnings.map(w => (
                                <li key={w.key} className="text-sm text-amber-700 flex items-center gap-2">
                                    <span className="w-1.5 h-1.5 rounded-full bg-amber-400 flex-shrink-0" />
                                    {w.message}
                                    {w.action && w.href && (
                                        <>
                                            {' '}
                                            <a href={w.href} className="font-medium underline hover:text-amber-900">
                                                {w.action} →
                                            </a>
                                        </>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <p className="text-xs text-amber-600 mt-2">You can still create the campaign — these just improve AI output quality.</p>
                    </div>
                </div>
                <button
                    type="button"
                    onClick={() => setDismissed(true)}
                    className="text-amber-400 hover:text-amber-600 flex-shrink-0 ml-4"
                    aria-label="Dismiss"
                >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    );
};
