
import React from 'react';
import { useForm, usePage } from '@inertiajs/react';
import Card from './Card';
import PrimaryButton from './PrimaryButton';
import SecondaryButton from './SecondaryButton';

const SubscriptionTierSelector = () => {
    const { post, processing } = useForm();
    const { auth } = usePage().props;
    const currentPlan = auth.user.subscription_plan;

    const plans = [
        {
            id: 'free',
            name: 'Free',
            price: '$0 / month',
            description: 'For individuals and small projects to explore our features.',
            features: [
                'Create Marketing Strategies',
                'Generate Ad Collateral',
                'Manual Campaign Asset Downloads',
            ],
        },
        {
            id: import.meta.env.VITE_STRIPE_SUBSCRIPTION_PRICE_ID,
            name: 'Spectra Pro',
            price: '$200 / month',
            description: 'For businesses who want to automate and optimize their advertising.',
            features: [
                'Everything in Free',
                'Automated Campaign Publishing',
                'Performance Analytics & Optimization',
                'Daily Ad Spend Billing',
            ],
            cta: 'Upgrade to Pro',
        },
    ];

    const handleSubmit = (e, priceId) => {
        e.preventDefault();
        post(route('subscription.checkout', { price_id: priceId }));
    };

    return (
        <div>
            <h2 className="text-2xl font-bold text-center mb-6">Our Plans</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl mx-auto">
                {plans.map(plan => (
                    <Card key={plan.id} className="flex flex-col">
                        <h3 className="text-xl font-semibold">{plan.name}</h3>
                        <p className="text-3xl font-bold my-4">{plan.price}</p>
                        <p className="text-gray-600 h-16">{plan.description}</p>
                        <ul className="space-y-2 my-6">
                            {plan.features.map((feature, index) => (
                                <li key={index} className="flex items-center">
                                    <svg className="w-4 h-4 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    {feature}
                                </li>
                            ))}
                        </ul>
                        <div className="mt-auto text-center">
                            {plan.id === 'free' ? (
                                <SecondaryButton disabled={true} className="w-full justify-center">
                                    {currentPlan === plan.name ? 'Your Current Plan' : 'Included'}
                                </SecondaryButton>
                            ) : (
                                <form onSubmit={(e) => handleSubmit(e, plan.id)} className="w-full">
                                    {currentPlan === plan.name ? (
                                        <PrimaryButton disabled={true} className="w-full justify-center">Your Current Plan</PrimaryButton>
                                    ) : (
                                        <PrimaryButton disabled={processing} className="w-full justify-center">
                                            {plan.cta}
                                        </PrimaryButton>
                                    )}
                                </form>
                            )}
                        </div>
                    </Card>
                ))}
            </div>
        </div>
    );
};

export default SubscriptionTierSelector;
