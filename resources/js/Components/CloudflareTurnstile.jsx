import { useEffect, useRef } from 'react';

export default function CloudflareTurnstile({ siteKey, onVerify, onError, onExpire, theme = 'light' }) {
    const containerRef = useRef(null);
    const widgetIdRef = useRef(null);

    useEffect(() => {
        // Load the Turnstile script if not already loaded
        if (!window.turnstile) {
            const script = document.createElement('script');
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            script.defer = true;
            document.head.appendChild(script);

            script.onload = () => {
                renderWidget();
            };
        } else {
            renderWidget();
        }

        return () => {
            // Cleanup widget on unmount
            if (widgetIdRef.current !== null && window.turnstile) {
                window.turnstile.remove(widgetIdRef.current);
            }
        };
    }, [siteKey]);

    const renderWidget = () => {
        if (containerRef.current && window.turnstile && widgetIdRef.current === null) {
            widgetIdRef.current = window.turnstile.render(containerRef.current, {
                sitekey: siteKey,
                theme: theme,
                callback: (token) => {
                    if (onVerify) onVerify(token);
                },
                'error-callback': () => {
                    if (onError) onError();
                },
                'expired-callback': () => {
                    if (onExpire) onExpire();
                },
            });
        }
    };

    return (
        <div ref={containerRef} className="cf-turnstile" />
    );
}
