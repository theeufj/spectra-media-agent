import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';

export default function CreativeUsage({ auth }) {
    const { creativeUsage, boostConfig, purchases, flash } = usePage().props;
    const boost = boostConfig || { price_cents: 2900, image_generations: 25, video_generations: 5, refinements: 25 };

    const handleBuyBoost = () => {
        router.post(route('creative-boost.checkout'));
    };

    const queryParams = new URLSearchParams(window.location.search);
    const boostStatus = queryParams.get('boost');

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Creative Usage" />

            <div className="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                <h1 className="text-2xl font-bold text-gray-900 mb-2">Creative Usage</h1>
                <p className="text-gray-500 mb-8">
                    Track your monthly AI generation usage and purchase additional credits.
                    {!creativeUsage.is_unlimited && (
                        <span className="ml-1 text-sm text-gray-400">
                            Resets at the start of each month.
                        </span>
                    )}
                </p>

                {/* Flash messages for boost purchase */}
                {boostStatus === 'success' && (
                    <div className="mb-6 p-4 rounded-lg bg-green-50 border border-green-200 text-green-800">
                        Creative Boost applied! Your bonus credits are now available.
                    </div>
                )}
                {boostStatus === 'cancelled' && (
                    <div className="mb-6 p-4 rounded-lg bg-yellow-50 border border-yellow-200 text-yellow-800">
                        Boost purchase was cancelled. No charges were made.
                    </div>
                )}

                {/* Current Plan Badge */}
                <div className="mb-6 flex items-center gap-3">
                    <span className="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-indigo-100 text-indigo-800">
                        {creativeUsage.plan_name} Plan
                    </span>
                    {creativeUsage.is_unlimited && (
                        <span className="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                            Unlimited
                        </span>
                    )}
                    <span className="text-sm text-gray-400">
                        Period: {creativeUsage.period}
                    </span>
                </div>

                {/* Usage Bars */}
                {!creativeUsage.is_unlimited ? (
                    <div className="grid gap-6 mb-8">
                        <UsageBar
                            label="Image Generations"
                            icon="🖼️"
                            used={creativeUsage.image_generations.used}
                            limit={creativeUsage.image_generations.limit}
                            bonus={creativeUsage.image_generations.bonus}
                            remaining={creativeUsage.image_generations.remaining}
                        />
                        <UsageBar
                            label="Video Generations"
                            icon="🎬"
                            used={creativeUsage.video_generations.used}
                            limit={creativeUsage.video_generations.limit}
                            bonus={creativeUsage.video_generations.bonus}
                            remaining={creativeUsage.video_generations.remaining}
                        />
                        <UsageBar
                            label="Refinements"
                            icon="✏️"
                            used={creativeUsage.refinements.used}
                            limit={creativeUsage.refinements.limit}
                            bonus={creativeUsage.refinements.bonus}
                            remaining={creativeUsage.refinements.remaining}
                        />
                    </div>
                ) : (
                    <div className="mb-8 p-6 bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl border border-green-200">
                        <p className="text-green-800 font-medium text-lg">Unlimited creative generations included with your plan.</p>
                        <p className="text-green-600 text-sm mt-1">Per-item limits still apply: {creativeUsage.max_refinements_per_item} refinements per image, {creativeUsage.max_extensions_per_video} extensions per video.</p>
                    </div>
                )}

                {/* Creative Boost Pack */}
                {!creativeUsage.is_unlimited && (
                    <div className="mb-8 bg-gradient-to-r from-flame-orange-50 to-amber-50 rounded-xl border border-flame-orange-200 p-6">
                        <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-bold text-gray-900 flex items-center gap-2">
                                    🚀 Creative Boost Pack
                                    <span className="text-flame-orange-600 text-xl font-black">${(boost.price_cents / 100).toFixed(0)}</span>
                                </h2>
                                <p className="text-gray-600 text-sm mt-1">
                                    Instantly add more credits to your current billing period.
                                </p>
                                <div className="flex flex-wrap gap-3 mt-3">
                                    <span className="inline-flex items-center gap-1 px-2.5 py-1 bg-white rounded-lg text-sm font-medium text-gray-700 shadow-sm">
                                        🖼️ {boost.image_generations} images
                                    </span>
                                    <span className="inline-flex items-center gap-1 px-2.5 py-1 bg-white rounded-lg text-sm font-medium text-gray-700 shadow-sm">
                                        🎬 {boost.video_generations} videos
                                    </span>
                                    <span className="inline-flex items-center gap-1 px-2.5 py-1 bg-white rounded-lg text-sm font-medium text-gray-700 shadow-sm">
                                        ✏️ {boost.refinements} refinements
                                    </span>
                                </div>
                            </div>
                            <button
                                onClick={handleBuyBoost}
                                className="px-6 py-3 bg-flame-orange-600 text-white font-semibold rounded-lg hover:bg-flame-orange-700 transition-colors shadow-md hover:shadow-lg whitespace-nowrap"
                            >
                                Buy Boost Pack
                            </button>
                        </div>
                    </div>
                )}

                {/* Per-Item Limits */}
                <div className="mb-8 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div className="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-1">Max Refinements per Image</h3>
                        <p className="text-3xl font-bold text-gray-900">{creativeUsage.max_refinements_per_item}</p>
                        <p className="text-sm text-gray-500 mt-1">Each image can be edited up to {creativeUsage.max_refinements_per_item} times</p>
                    </div>
                    <div className="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-1">Max Extensions per Video</h3>
                        <p className="text-3xl font-bold text-gray-900">{creativeUsage.max_extensions_per_video}</p>
                        <p className="text-sm text-gray-500 mt-1">Each video can be extended up to {creativeUsage.max_extensions_per_video} times</p>
                    </div>
                </div>

                {/* Purchase History */}
                {purchases && purchases.length > 0 && (
                    <div>
                        <h2 className="text-lg font-semibold text-gray-900 mb-4">Boost Purchase History</h2>
                        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Period</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contents</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200">
                                    {purchases.map((purchase) => (
                                        <tr key={purchase.id}>
                                            <td className="px-4 py-3 text-sm text-gray-700">
                                                {new Date(purchase.created_at).toLocaleDateString()}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-gray-500">{purchase.period}</td>
                                            <td className="px-4 py-3 text-sm text-gray-600">
                                                {purchase.image_generations} images, {purchase.video_generations} videos, {purchase.refinements} refinements
                                            </td>
                                            <td className="px-4 py-3 text-sm text-gray-700 text-right font-medium">
                                                ${(purchase.amount_cents / 100).toFixed(2)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function UsageBar({ label, icon, used, limit, bonus, remaining }) {
    const total = limit + bonus;
    const percentage = total > 0 ? Math.min((used / total) * 100, 100) : 0;
    const isExhausted = remaining <= 0;

    let barColor = 'bg-green-500';
    if (percentage >= 80) barColor = 'bg-red-500';
    else if (percentage >= 50) barColor = 'bg-yellow-500';

    return (
        <div className="bg-white rounded-xl border border-gray-200 p-5">
            <div className="flex items-center justify-between mb-2">
                <h3 className="font-semibold text-gray-900 flex items-center gap-2">
                    <span>{icon}</span> {label}
                </h3>
                <div className="text-right">
                    <span className={`text-lg font-bold ${isExhausted ? 'text-red-600' : 'text-gray-900'}`}>
                        {used}
                    </span>
                    <span className="text-gray-400 text-sm"> / {total}</span>
                    {bonus > 0 && (
                        <span className="ml-2 text-xs text-flame-orange-600 font-medium">
                            (+{bonus} bonus)
                        </span>
                    )}
                </div>
            </div>
            <div className="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
                <div
                    className={`h-full rounded-full transition-all duration-500 ${barColor}`}
                    style={{ width: `${percentage}%` }}
                />
            </div>
            <div className="flex justify-between mt-1.5">
                <span className="text-xs text-gray-400">
                    {remaining} remaining
                </span>
                {isExhausted && (
                    <span className="text-xs text-red-500 font-medium">Limit reached</span>
                )}
            </div>
        </div>
    );
}
