import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function Pricing({ auth }) {
    // In a real application, you would fetch these from your Stripe dashboard
    // or a configuration file. For simplicity, we'll hardcode them here.
    const plans = [
        {
            name: 'Free Tier',
            price: '$0',
            price_id: null, // No checkout for the free tier
            features: [
                'Create 1 Marketing Strategy',
                'Generate Collateral',
                'Manual Campaign Publishing',
            ],
            cta: 'Included',
        },
        {
            name: 'Meta Publishing',
            price: '$100 / month',
            price_id: 'YOUR_META_PRICE_ID', // IMPORTANT: Replace with your actual Stripe Price ID
            features: [
                'All features from Free Tier',
                'Automated Publishing to Meta',
                'Performance Analytics',
            ],
            cta: 'Choose Plan',
        },
        {
            name: 'Meta & Google Publishing',
            price: '$250 / month',
            price_id: 'YOUR_META_GOOGLE_PRICE_ID', // IMPORTANT: Replace with your actual Stripe Price ID
            features: [
                'All features from Meta Publishing',
                'Automated Publishing to Google Ads',
                'Advanced Analytics & Reporting',
            ],
            cta: 'Choose Plan',
        },
    ];

    const handleCheckout = (priceId) => {
        // This would typically be a form submission, but for simplicity,
        // we'll use a GET request with the price_id in the query string.
        // A POST request is better practice to prevent CSRF.
        window.location.href = `/subscription/checkout?price_id=${priceId}`;
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Subscription Plans</h2>}
        >
            <Head title="Pricing" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
                                {plans.map((plan) => (
                                    <div key={plan.name} className="border rounded-lg p-6 flex flex-col">
                                        <h3 className="text-2xl font-bold">{plan.name}</h3>
                                        <p className="text-4xl font-bold my-4">{plan.price}</p>
                                        <ul className="space-y-2 mb-6">
                                            {plan.features.map((feature) => (
                                                <li key={feature} className="flex items-center">
                                                    <svg className="w-4 h-4 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"></path></svg>
                                                    {feature}
                                                </li>
                                            ))}
                                        </ul>
                                        <div className="mt-auto">
                                            {plan.price_id ? (
                                                <form action={route('subscription.checkout')} method="POST" className="w-full">
                                                    <input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]').content} />
                                                    <input type="hidden" name="price_id" value={plan.price_id} />
                                                    <button
                                                        type="submit"
                                                        className="w-full bg-indigo-600 text-white py-2 rounded-lg hover:bg-indigo-700"
                                                    >
                                                        {plan.cta}
                                                    </button>
                                                </form>
                                            ) : (
                                                <div className="w-full bg-gray-200 text-gray-600 text-center py-2 rounded-lg">
                                                    {plan.cta}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
