import React, { useState } from 'react';
import { GoogleSearchPreview } from '@/Components/AdPreview';

const inclusions = [
    ['Google Ads account', 'An account set up for your business, with administrator access handed over to you.'],
    ['Campaign structure and targeting', 'A campaign organised around your offer, audience and locations, with a daily budget for you to approve.'],
    ['Keyword research', 'Relevant search terms chosen for what you sell and the customers you want to reach.'],
    ['Ad copy', 'Headlines and descriptions written for your business, ready for you to review.'],
    ['Suitable creative', 'Creative matched to the approved campaign and its supported Google Ads formats.'],
    ['Conversion goals and tracking setup', 'Configuration for your chosen customer action. You help install the website tracking and test a conversion.'],
    ['Paused campaign and handover', 'Your reviewed campaign is created paused. Accept your invitation, add Google billing and finish the launch checks before switching it on.'],
];

const keywords = ['home cleaning service', 'regular house cleaning', 'one-off home cleaning'];

function MediaUnavailable({ children }) {
    return (
        <div className="flex aspect-video items-center justify-center bg-gray-100 px-6 text-center">
            <p role="status" className="text-sm text-gray-600">{children}</p>
        </div>
    );
}

export default function SetupExpectations() {
    const [imageUnavailable, setImageUnavailable] = useState(false);
    const [videoUnavailable, setVideoUnavailable] = useState(false);

    return (
        <section aria-labelledby="setup-expectations-title" className="mt-8 border-t border-gray-100 pt-8">
            <h2 id="setup-expectations-title" className="text-xl font-semibold text-gray-900">What your setup includes</h2>
            <p className="mt-2 text-sm leading-relaxed text-gray-600">We prepare the account and campaign; you review the work and decide when to launch.</p>
            <dl className="mt-5 grid gap-x-8 gap-y-5 sm:grid-cols-2">
                {inclusions.map(([title, description]) => (
                    <div key={title}>
                        <dt className="text-sm font-semibold text-gray-900">{title}</dt>
                        <dd className="mt-1 text-sm leading-relaxed text-gray-600">{description}</dd>
                    </div>
                ))}
            </dl>

            <div className="mt-8 rounded-xl border border-gray-200 bg-gray-50 p-4 sm:p-5">
                <h3 className="text-lg font-semibold text-gray-900">See what the ads could look like</h3>
                <p className="mt-1 text-sm text-gray-600">Illustrative examples for a fictional business.</p>
                <p className="mt-2 text-sm leading-relaxed text-gray-600">These samples use Example Home Cleaning at example.com. Your own ads are prepared for your business after payment and reviewed with you.</p>

                <div className="mt-5 grid min-w-0 gap-5 sm:grid-cols-2">
                    <figure className="min-w-0">
                        <figcaption className="mb-3 text-sm font-semibold text-gray-900">Google Search ad example</figcaption>
                        <GoogleSearchPreview
                            url="example.com"
                            headlines={['Example Home Cleaning', 'Come Home to a Fresh Start', 'Explore Cleaning Services']}
                            descriptions={['Explore regular and one-off home cleaning. Find a visit that fits your household.']}
                        />
                        <p className="mt-2 text-xs leading-relaxed text-gray-500">Illustrative appearance. Google may combine the headlines and descriptions differently.</p>
                    </figure>

                    <div className="min-w-0 rounded-lg border border-gray-200 bg-white p-4">
                        <h4 className="text-sm font-semibold text-gray-900">Example keyword group</h4>
                        <p className="mt-2 text-sm leading-relaxed text-gray-600">Search terms for the same fictional home-cleaning campaign:</p>
                        <ul className="mt-3 flex flex-wrap gap-2">
                            {keywords.map(keyword => <li key={keyword} className="rounded-full bg-teal-50 px-3 py-1.5 text-xs font-medium text-teal-800">{keyword}</li>)}
                        </ul>
                        <p className="mt-3 text-xs leading-relaxed text-gray-500">Your keywords depend on your offer and market research.</p>
                    </div>
                </div>

                <div className="mt-5 rounded-lg border border-teal-100 bg-teal-50 p-3 text-sm leading-relaxed text-teal-900">
                    Image and video formats depend on the approved campaign. Search campaigns do not use video ads; these visual samples illustrate other supported Google Ads formats and are not included in every setup.
                </div>

                <div className="mt-4 grid min-w-0 gap-5 sm:grid-cols-2">
                    <figure className="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white">
                        {imageUnavailable ? <MediaUnavailable>Image example unavailable. You can still review the setup details and continue below.</MediaUnavailable> : (
                            <img
                                src="/setup-examples/home-cleaning.webp"
                                alt="Example Home Cleaning: Come home to a fresh start. Explore cleaning services at example.com. A cleaner in teal wipes a table in a bright living room."
                                width="1200"
                                height="675"
                                loading="lazy"
                                decoding="async"
                                onError={() => setImageUnavailable(true)}
                                className="aspect-video w-full object-cover"
                            />
                        )}
                        <figcaption className="p-4">
                            <p className="text-sm font-semibold text-gray-900">Image creative example</p>
                            <p className="mt-1 text-xs leading-relaxed text-gray-600">A landscape visual for a suitable Google Ads placement.</p>
                        </figcaption>
                    </figure>

                    <figure className="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white">
                        {videoUnavailable ? <MediaUnavailable>Video example unavailable. You can still review the setup details and continue below.</MediaUnavailable> : (
                            <video
                                src="/setup-examples/home-cleaning.mp4"
                                poster="/setup-examples/home-cleaning-poster.webp"
                                width="1280"
                                height="720"
                                controls
                                playsInline
                                preload="none"
                                aria-label="Example Home Cleaning video ad sample"
                                aria-describedby="setup-video-description"
                                onError={() => setVideoUnavailable(true)}
                                className="aspect-video w-full bg-gray-900 object-cover"
                            >
                                Your browser does not support this video. Read its description below.
                            </video>
                        )}
                        <figcaption className="p-4">
                            <p className="text-sm font-semibold text-gray-900">Video creative example</p>
                            <p id="setup-video-description" className="mt-1 text-xs leading-relaxed text-gray-600">A silent motion ad with a gentle zoom across a cleaner in a bright living room and the message “Come home to a fresh start.” Press play to view the example.</p>
                        </figcaption>
                    </figure>
                </div>
            </div>
        </section>
    );
}
