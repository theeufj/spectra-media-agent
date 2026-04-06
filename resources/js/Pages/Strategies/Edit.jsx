import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import InputError from '@/Components/InputError';

export default function Edit({ auth, strategy, campaign }) {
    const { data, setData, put, processing, errors } = useForm({
        ad_copy_strategy: strategy.ad_copy_strategy || '',
        imagery_strategy: strategy.imagery_strategy || '',
        video_strategy: strategy.video_strategy || '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('strategies.update', strategy.id));
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center gap-4">
                    <Link
                        href={route('campaigns.show', campaign.id)}
                        className="text-gray-500 hover:text-gray-700"
                    >
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                    </Link>
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                        Edit Strategy — {strategy.platform} {strategy.campaign_type}
                    </h2>
                </div>
            }
        >
            <Head title={`Edit Strategy — ${strategy.platform}`} />

            <div className="py-12">
                <div className="max-w-4xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <form onSubmit={submit} className="p-6 space-y-6">
                            <div>
                                <div className="mb-4 text-sm text-gray-500">
                                    Campaign: <span className="font-medium text-gray-700">{campaign.name}</span>
                                    {' · '}
                                    Platform: <span className="font-medium text-gray-700 capitalize">{strategy.platform}</span>
                                    {' · '}
                                    Type: <span className="font-medium text-gray-700 capitalize">{strategy.campaign_type?.replace('_', ' ')}</span>
                                </div>
                            </div>

                            <div>
                                <InputLabel htmlFor="ad_copy_strategy" value="Ad Copy Strategy" />
                                <textarea
                                    id="ad_copy_strategy"
                                    className="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                                    rows={8}
                                    value={data.ad_copy_strategy}
                                    onChange={(e) => setData('ad_copy_strategy', e.target.value)}
                                />
                                <InputError message={errors.ad_copy_strategy} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="imagery_strategy" value="Imagery Strategy" />
                                <textarea
                                    id="imagery_strategy"
                                    className="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                                    rows={8}
                                    value={data.imagery_strategy}
                                    onChange={(e) => setData('imagery_strategy', e.target.value)}
                                />
                                <InputError message={errors.imagery_strategy} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="video_strategy" value="Video Strategy" />
                                <textarea
                                    id="video_strategy"
                                    className="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                                    rows={8}
                                    value={data.video_strategy}
                                    onChange={(e) => setData('video_strategy', e.target.value)}
                                />
                                <InputError message={errors.video_strategy} className="mt-2" />
                            </div>

                            <div className="flex items-center justify-end gap-4">
                                <Link
                                    href={route('campaigns.show', campaign.id)}
                                    className="text-sm text-gray-600 hover:text-gray-900"
                                >
                                    Cancel
                                </Link>
                                <PrimaryButton disabled={processing}>
                                    Save Changes
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
