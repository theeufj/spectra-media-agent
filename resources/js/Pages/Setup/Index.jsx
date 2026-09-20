import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { SetupStages, HandoverChecklist } from '@/Components/SetupJourney';
import { usePolling } from '@/hooks/usePolling';

export default function SetupHome({ journey: initialJourney }) {
    const { data, error } = usePolling(route('api.setup-progress.index'), {
        interval: 8000, until: result => !result.is_working,
    });
    const journey = data?.setup_only ? data : initialJourney;
    const current = journey.current_step;
    const stage = Math.max(0, journey.steps.findIndex(step => step.key === current.key));
    const review = journey.campaign?.review_url;

    return (
        <AuthenticatedLayout>
            <Head title="Your Google Ads setup" />
            <main className="mx-auto max-w-5xl px-4 py-10">
                <SetupStages stage={stage} />
                <p className="text-sm font-medium text-brand-dark">{journey.business.name} · One-time Google Ads setup</p>
                <h1 className="mt-2 text-3xl font-semibold tracking-tight text-gray-900">{current.title}</h1>
                <p className="mt-3 max-w-2xl text-gray-600" role="status">{current.description}</p>
                {error && <p role="alert" className="mt-3 text-sm text-amber-800">We could not refresh the status. These are the last confirmed details. Refresh to try again.</p>}
                <div className="my-6 flex flex-wrap gap-3">
                    {current.action_url && current.key !== 'handover' && <Link href={current.action_url} className="rounded-lg bg-brand-dark px-5 py-3 font-medium text-white">{current.action_text}</Link>}
                    {review && current.key === 'handover' && <Link href={review} className="rounded-lg border border-gray-300 bg-white px-5 py-3 font-medium text-gray-800">View your ads</Link>}
                    {journey.paid && <span className="self-center text-sm text-green-800">Setup fee paid · No subscription</span>}
                </div>
                {current.status === 'failed' && <p className="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Your setup is saved. Please contact support so we can resume the build. You do not need to start again. <Link href={route('support-tickets.create')} className="font-semibold underline">Contact support</Link></p>}
                {journey.paid && <HandoverChecklist items={journey.checklist} />}
                {current.key === 'handover' && <a href="https://ads.google.com/" target="_blank" rel="noreferrer" className="mt-6 inline-flex rounded-lg bg-brand-dark px-5 py-3 font-medium text-white">Open Google Ads</a>}
            </main>
        </AuthenticatedLayout>
    );
}
