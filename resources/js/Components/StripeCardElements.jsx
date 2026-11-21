import React, { useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import { loadStripe } from '@stripe/stripe-js/pure';
import { Elements } from '@stripe/react-stripe-js';

export default function StripeCardElements({ children }) {
    const runtimeKey = usePage().props.stripePublishableKey;
    const key = [runtimeKey, import.meta.env.VITE_STRIPE_KEY].find(value => typeof value === 'string' && /^pk_(live|test)_/.test(value));
    const stripe = useMemo(() => key ? loadStripe(key) : null, [key]);

    if (!key) {
        return <p role="status" className="text-sm text-gray-700">Card entry is currently unavailable. Return to billing to try again later.</p>;
    }

    return <Elements stripe={stripe}>{children}</Elements>;
}
