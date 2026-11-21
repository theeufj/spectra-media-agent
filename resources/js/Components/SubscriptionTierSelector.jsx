
import React, { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import Card from './Card';
import PrimaryButton from './PrimaryButton';
import SecondaryButton from './SecondaryButton';

const SubscriptionTierSelector = ({ plans = [], availableChannels: offeredChannels, prequalification: storedReadiness }) => {
    const { post, processing } = useForm();
    const { auth } = usePage().props;
    const availableChannels = offeredChannels ?? ['google', 'facebook'];
    const prequalification = storedReadiness;
    const [channels, setChannels] = useState(availableChannels);
    const currentPlan = auth.user.subscription_plan;

    const handleSubmit = (e, plan) => {
        e.preventDefault();
        post(route('subscription.checkout', { price_id: plan.stripe_price_id, platforms: plan.slug === 'starter' ? ['google'] : channels }));
    };

    return (
        <div>
            <h2 className="text-2xl font-bold text-center mb-6">Our Plans</h2>
            <fieldset className="mx-auto mb-6 max-w-4xl">
                <legend>Where do you want to advertise?</legend>
                {availableChannels.map(channel => <label key={channel} className="mr-5"><input type="checkbox" checked={channels.includes(channel)} onChange={event => setChannels(event.target.checked ? [...channels, channel] : channels.filter(item => item !== channel))} /> {channel === 'google' ? 'Google Ads' : 'Meta Ads'}</label>)}
                {prequalification && <ul aria-live="polite">{Object.values(prequalification.channels).map(channel => <li key={channel.platform}>{channel.platform === 'google' ? 'Google' : 'Meta'} — {channel.message}</li>)}</ul>}
            </fieldset>
            <p className="mx-auto mb-4 max-w-4xl text-sm text-gray-700">We save your checkout card for ad spend you approve separately. Each ready channel reserves up to seven days of its approved budget.</p>
            <div className={`grid grid-cols-1 ${plans.length > 1 ? 'md:grid-cols-2' : ''} ${plans.length > 2 ? 'lg:grid-cols-3' : ''} gap-8 max-w-4xl mx-auto`}>
                {plans.map(plan => (
                    <Card key={plan.id} className="flex flex-col">
                        <h3 className="text-xl font-semibold">{plan.name}</h3>
                        <p className="text-3xl font-bold my-4">{plan.price_cents > 0 ? 'US' : ''}{plan.formatted_price}</p>
                        <p className="text-gray-600 h-16">{plan.description}</p>
                        <ul className="space-y-2 my-6">
                            {(plan.features || []).map((feature, index) => (
                                <li key={index} className="flex items-center">
                                    <svg className="w-4 h-4 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                    {feature}
                                </li>
                            ))}
                        </ul>
                        <div className="mt-auto text-center">
                            {plan.is_free ? (
                                <SecondaryButton disabled={true} className="w-full justify-center">
                                    {currentPlan === plan.name ? 'Your Current Plan' : 'Included'}
                                </SecondaryButton>
                            ) : plan.cta_text === 'Contact Sales' ? (
                                <a href="mailto:hello@sitetospend.com?subject=Agency Plan Inquiry" className="w-full">
                                    <PrimaryButton className="w-full justify-center">
                                        Contact Sales
                                    </PrimaryButton>
                                </a>
                            ) : (
                                <form onSubmit={(e) => handleSubmit(e, plan)} className="w-full">
                                    {currentPlan === plan.name ? (
                                        <PrimaryButton disabled={true} className="w-full justify-center">Your Current Plan</PrimaryButton>
                                    ) : (
                                        <PrimaryButton disabled={processing} className="w-full justify-center">
                                            Upgrade to {plan.name}
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
