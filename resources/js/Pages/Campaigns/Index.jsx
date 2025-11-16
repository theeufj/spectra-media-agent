import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ auth, campaigns }) {
    const handleDelete = (campaignId) => {
        if (window.confirm('Are you sure you want to delete this campaign? This action cannot be undone.')) {
            router.delete(route('campaigns.destroy', campaignId));
        }
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Campaigns</h2>}
        >
            <Head title="Campaigns" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    {campaigns.length > 0 ? (
                        campaigns.map(campaign => {
                            const firstStrategy = campaign.strategies[0];
                            return (
                                <div key={campaign.id} className="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                                    <div className="p-6 text-gray-900">
                                        <div className="flex justify-between items-center">
                                            <h3 className="text-lg font-bold">{campaign.name}</h3>
                                            <div className="flex space-x-2">
                                                {firstStrategy && (
                                                    <Link href={route('campaigns.collateral.show', { campaign: campaign.id, strategy: firstStrategy.id })} className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                                        Edit
                                                    </Link>
                                                )}
                                                <button onClick={() => handleDelete(campaign.id)} className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                                                    Delete
                                                </button>
                                            </div>
                                        </div>
                                        <p className="text-sm text-gray-500 mt-2">{campaign.description}</p>

                                        {campaign.strategies.map(strategy => (
                                            <div key={strategy.id} className="mt-4">
                                                <h4 className="font-semibold">{strategy.name}</h4>
                                                {strategy.ad_copies.length > 0 && (
                                                    <div className="mt-2">
                                                        <h5 className="font-semibold">Ad Copies</h5>
                                                        <ul>
                                                            {strategy.ad_copies.map(adCopy => (
                                                                <li key={adCopy.id}>
                                                                    <p><strong>Headlines:</strong> {adCopy.headlines.join(', ')}</p>
                                                                    <p><strong>Descriptions:</strong> {adCopy.descriptions.join(', ')}</p>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                )}
                                                {strategy.image_collaterals.length > 0 && (
                                                    <div className="mt-2">
                                                        <h5 className="font-semibold">Image Collaterals</h5>
                                                        <div className="flex space-x-4">
                                                            {strategy.image_collaterals.map(image => (
                                                                <div key={image.id}>
                                                                    <img src={image.cloudfront_url} alt={image.prompt} className="w-32 h-32 object-cover" />
                                                                    <p>{image.prompt}</p>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    </div>
                                                )}
                                                {strategy.video_collaterals.length > 0 && (
                                                    <div className="mt-2">
                                                        <h5 className="font-semibold">Video Collaterals</h5>
                                                        <ul>
                                                            {strategy.video_collaterals.map(video => (
                                                                <li key={video.id}>
                                                                    <video src={video.cloudfront_url} controls className="w-full"></video>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            );
                        })
                    ) : (
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6 text-center text-gray-900">
                                <h3 className="text-lg font-bold">No campaigns yet!</h3>
                                <p className="mt-2">Get started by creating your first campaign.</p>
                                <Link
                                    href="/campaigns/create"
                                    className="mt-4 inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 active:bg-gray-900 focus:outline-none focus:border-gray-900 focus:ring ring-gray-300 disabled:opacity-25 transition ease-in-out duration-150"
                                >
                                    Create Your First Campaign
                                </Link>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}