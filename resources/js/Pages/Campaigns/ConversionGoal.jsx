import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { FIELD, Field } from '@/Components/Forms';
import FormErrorSummary from '@/Components/FormErrorSummary';
import { usePolling } from '@/hooks/usePolling';

const objectives = [
    { value: 'lead', label: 'Enquiries', detail: 'Someone successfully sends an enquiry or lead form.' },
    { value: 'signup', label: 'Signups', detail: 'Someone finishes creating an account or registering.' },
    { value: 'purchase', label: 'Purchases', detail: 'Someone completes a purchase.' },
];

function StatusItem({ title, state, children }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
            <h3 className="font-medium text-gray-900">{title}</h3>
            <p className="mt-1 text-sm font-semibold text-brand-dark">{state}</p>
            <p className="mt-2 text-sm text-gray-600">{children}</p>
        </div>
    );
}

export default function ConversionGoal({ auth, campaign, customer, goal = {}, existingActions = [], configuration = {}, installation = {}, nativeTracking = {}, conversionVerification = {}, statusUrl = null, canConfigure = true, availableActionsError = null, strategies = [], selectedStrategyUuid = null }) {
    const form = useForm({
        strategy: selectedStrategyUuid || strategies[0]?.uuid || '',
        objective: goal.objective || '',
        trigger: goal.trigger || '',
        thank_you_url: goal.thank_you_url || '',
        event_name: goal.event_name || '',
        action_resource: goal.action_resource || '',
    });
    const [watchAttempt, setWatchAttempt] = useState(0);
    const { data: polled, error: pollingError } = usePolling(statusUrl, {
        interval: 5000,
        enabled: Boolean(statusUrl) && configuration.status === 'configuring',
        restartKey: `${configuration.request_id || ''}:${watchAttempt}`,
        parse: data => {
            if (!data || typeof data.configuration?.status !== 'string') throw new Error('Invalid goal setup status');
            return data;
        },
        until: data => data.configuration?.request_id === configuration.request_id && data.configuration?.status !== 'configuring',
    });
    const current = configuration.status === 'configuring' && polled?.configuration?.request_id === configuration.request_id ? polled : null;
    const config = current?.configuration || configuration;
    const tag = current?.installation || installation;
    const conversion = current?.conversionVerification || conversionVerification;
    const native = current?.nativeTracking || nativeTracking;
    const configuring = config.status === 'configuring';
    const configured = config.status === 'configured';
    const nativeConnected = configured && native.connected === true;
    const preparedOnly = goal.trigger === 'prepare_only' && !nativeConnected;
    const preparingOnly = form.data.trigger === 'prepare_only';
    const waitingForCampaign = config.status === 'waiting_for_campaign';
    const needsAttention = ['needs_review', 'unknown'].includes(config.status);
    const formDisabled = form.processing || configuring || !canConfigure;
    const actions = existingActions.filter(action => action.objective === form.data.objective);
    const issues = config.issues || [];
    const submit = event => {
        event.preventDefault();
        if (formDisabled) return;
        form.post(route('campaigns.conversion-goal.update', { campaign: campaign.uuid }), { preserveScroll: true });
    };
    const setObjective = value => form.setData({ ...form.data, objective: value, action_resource: '' });
    const changeTrigger = value => form.setData({ ...form.data, trigger: value, thank_you_url: '', event_name: '', action_resource: '' });

    return (
        <AuthenticatedLayout user={auth?.user} header={<h2 className="text-xl font-semibold text-gray-900">Set your campaign goal</h2>}>
            <Head title={`Campaign goal — ${campaign.name}`} />
            <div className="mx-auto max-w-4xl px-4 py-8 sm:px-6">
                <Link href={route('dashboard')} className="text-sm font-medium text-brand-dark underline">Back to your handover checklist</Link>
                <h1 className="mt-5 text-2xl font-semibold text-gray-900">Choose what success looks like</h1>
                <p className="mt-2 max-w-2xl text-sm text-gray-600">Tell us what a completed enquiry, signup or purchase looks like. We’ll configure the matching Google Ads goal. Connect website tracking now, or prepare the goal first and connect it later. Your campaign stays paused.</p>
                {strategies.length > 1 && <div className="mt-6 rounded-xl border border-gray-200 bg-white p-5">
                    <label htmlFor="goal-strategy" className="block text-sm font-semibold text-gray-900">Which Search campaign is this goal for?</label>
                    <select id="goal-strategy" value={selectedStrategyUuid || strategies[0]?.uuid || ''} disabled={form.processing} className={`mt-2 ${FIELD}`} aria-invalid={Boolean(form.errors.strategy)} aria-describedby={form.errors.strategy ? 'strategy-error' : undefined} onChange={event => router.visit(route('campaigns.conversion-goal.edit', { campaign: campaign.uuid, strategy: event.target.value }), { preserveState: false })}>
                        {strategies.map((strategy, index) => <option key={strategy.uuid} value={strategy.uuid}>{strategy.label || `${strategy.platform || 'Search'} campaign ${index + 1}`}</option>)}
                    </select>
                    <p className="mt-2 text-sm text-gray-600">Each Search campaign has its own goal. Saving applies only to the one selected here.</p>
                    {form.errors.strategy && <p id="strategy-error" role="alert" className="mt-2 text-sm text-red-700">{form.errors.strategy}</p>}
                </div>}

                <section aria-label="Goal setup progress" className="mt-6 grid gap-3 sm:grid-cols-3">
                    <StatusItem title="Google Ads settings" state={configured ? 'Configuration checked' : configuring ? 'Configuring…' : waitingForCampaign ? 'Goal saved' : needsAttention ? 'Needs attention' : 'Choose your goal'}>
                        {configured ? preparedOnly ? 'Your Google Ads goal is configured. Its website event still needs connecting before conversions can be recorded.' : 'Your selected goal is configured. Website installation and a real conversion still need their own checks.' : configuring ? 'We’re saving and checking the goal in your account. Your ads remain paused.' : waitingForCampaign ? 'Configuration will finish after your paused campaign is created.' : 'We’ll set up the Google Ads goal after you save the choices below.'}
                    </StatusItem>
                    <StatusItem title="Website installation" state={nativeConnected ? 'Paid checkout connected' : preparedOnly ? 'Website event not connected' : tag.tag_detected === true ? 'Website tag detected' : 'Installation not verified'}>
                        {nativeConnected ? 'Verified paid checkout receipts send the actual amount, currency and unique transaction ID to this Purchase goal. Free checkouts and renewals do not count.' : preparedOnly ? 'Connect a success event or dedicated confirmation page to this goal, then install or update your website tag. Preparing the Google Ads goal does not add website tracking.' : tag.tag_detected === true ? 'A tag was detected. That alone does not prove this conversion fires.' : 'Install your assigned website tag so the chosen action can be sent to Google Ads.'}
                    </StatusItem>
                    <StatusItem title="Real conversion" state={conversion.verified === true ? 'Google conversions observed' : nativeConnected ? 'Not yet verified' : 'Test conversion needed'}>
                        {conversion.verified === true ? 'Google reports conversions for this campaign’s selected action.' : nativeConnected ? 'The checkout connection can be checked without making a payment. Browser invocation and Google attribution remain separate; only a genuine qualifying purchase can establish a conversion.' : 'Complete the action on your website, then confirm Google Ads receives it before switching on ads.'}
                    </StatusItem>
                </section>

                {(configuring || configured || waitingForCampaign || needsAttention || issues.length > 0) && (
                    <div role="status" aria-live="polite" className={`mt-6 rounded-lg border p-4 ${configured ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50'}`}>
                        <p className="text-sm font-medium text-gray-900">{nativeConnected ? native.message : config.message || (configured ? 'Your goal settings are configured. Your campaign remains paused.' : configuring ? 'Configuring your selected goal. You can leave this page and return to check progress.' : waitingForCampaign ? 'Your goal is saved. Create your paused campaign to finish its configuration.' : 'We could not finish configuring this goal. Review the details and try again.')}</p>
                        {issues.length > 0 && <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700">{issues.map((issue, index) => <li key={index}>{typeof issue === 'string' ? issue : issue.message}</li>)}</ul>}
                    </div>
                )}
                {!canConfigure && <p role="status" className="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">Your campaign draft is being prepared. Return here once it is ready to choose and configure its goal.</p>}
                {availableActionsError && <div role="alert" className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-gray-800">
                    <p>{availableActionsError}</p>
                    <button type="button" onClick={() => router.reload({ only: ['existingActions', 'availableActionsError'] })} className="mt-2 min-h-[44px] font-semibold text-brand-dark underline">Reload existing actions</button>
                </div>}
                {pollingError && (
                    <div role="alert" className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-gray-800">
                        <p>We cannot confirm the latest setup status. Your saved request may still be processing.</p>
                        <button type="button" onClick={() => setWatchAttempt(value => value + 1)} className="mt-2 min-h-[44px] font-semibold text-brand-dark underline">Check status again</button>
                    </div>
                )}

                <form onSubmit={submit} className="mt-6 space-y-6 rounded-xl border border-gray-200 bg-white p-6">
                    <FormErrorSummary errors={form.errors} labels={{ strategy: 'Search campaign', objective: 'Campaign goal', trigger: 'How the action is completed', thank_you_url: 'Confirmation page URL', event_name: 'Website event name', action_resource: 'Existing action' }} fieldIds={{ strategy: 'goal-strategy' }} />
                    <fieldset id="objective" tabIndex={-1} disabled={formDisabled} aria-describedby={form.errors.objective ? 'objective-error' : undefined}>
                        <legend className="font-semibold text-gray-900">What should this campaign count?</legend>
                        <div className="mt-3 grid gap-3 sm:grid-cols-3">
                            {objectives.map(objective => <label key={objective.value} className={`cursor-pointer rounded-lg border p-4 ${form.data.objective === objective.value ? 'border-brand-dark bg-gray-50' : 'border-gray-200'}`}>
                                <input type="radio" name="objective" value={objective.value} checked={form.data.objective === objective.value} onChange={() => setObjective(objective.value)} required className="mr-2 text-brand-dark focus:ring-brand-dark" />
                                <span className="text-sm font-medium text-gray-900">{objective.label}</span>
                                <span className="mt-2 block text-sm text-gray-600">{objective.detail}</span>
                            </label>)}
                        </div>
                        {form.errors.objective && <p id="objective-error" role="alert" className="mt-2 text-sm text-red-700">{form.errors.objective}</p>}
                    </fieldset>
                    <div>
                        <label htmlFor="trigger" className="block text-sm font-medium text-gray-900">How do you know the action is complete?</label>
                        <select id="trigger" name="trigger" value={form.data.trigger} onChange={event => changeTrigger(event.target.value)} required disabled={formDisabled} className={`mt-2 ${FIELD}`} aria-invalid={Boolean(form.errors.trigger)} aria-describedby={form.errors.trigger ? 'trigger-error' : undefined}>
                            <option value="">Choose how to track it</option>
                            <option value="thank_you_url">A dedicated confirmation or thank-you page</option>
                            <option value="event">A success event sent by my website</option>
                            <option value="existing_action" disabled={actions.length === 0}>Use an action already set up in Google Ads</option>
                            <option value="prepare_only">Prepare my goal now; connect website tracking later</option>
                        </select>
                        {form.errors.trigger && <p id="trigger-error" role="alert" className="mt-2 text-sm text-red-700">{form.errors.trigger}</p>}
                    </div>

                    {preparingOnly && !nativeConnected && <p className="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">We’ll prepare the Google Ads goal without creating a website trigger or tag. You can return here to connect a real success event or confirmation page. Your ads stay paused until you decide to launch.</p>}
                    {nativeConnected && <p className="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">The paid checkout connection is installed for this reviewed goal. Saving a different goal requires its tracking connection to be reviewed again; it will not reuse the previous destination.</p>}

                    {form.data.trigger === 'thank_you_url' && <Field id="thank_you_url" label="Confirmation page URL" type="url" value={form.data.thank_you_url} onChange={event => form.setData('thank_you_url', event.target.value)} required disabled={formDisabled} error={form.errors.thank_you_url} hint={`Use the full URL on ${customer?.website || 'your website'} that only appears after a successful action. Do not use your homepage or form page.`} />}
                    {form.data.trigger === 'event' && <Field id="event_name" label="Website event name" value={form.data.event_name} onChange={event => form.setData('event_name', event.target.value)} required disabled={formDisabled} error={form.errors.event_name} hint="Enter the exact event your website sends after success, such as lead_submitted. We configure the tracking trigger; your website must send this event." />}
                    {form.data.objective === 'purchase' && ['event', 'thank_you_url'].includes(form.data.trigger) && <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                        <p>After payment succeeds, your website must supply the actual amount, ISO currency and a unique order ID. Never send this on a checkout visit, payment failure or free order. The tag requires a positive value and transaction ID.</p>
                        <pre className="mt-3 overflow-x-auto rounded bg-white p-3 text-xs">{`window.dataLayer = window.dataLayer || [];\nwindow.dataLayer.push({\n  event: '${form.data.event_name || 'purchase_completed'}',\n  ecommerce: {\n    transaction_id: order.id,\n    value: order.paidAmount,\n    currency: order.currency\n  }\n});`}</pre>
                        <p className="mt-2">Use values from your completed-payment system. A confirmation-page trigger also needs these values in the data layer before the page tag runs.</p>
                    </div>}
                    {form.data.trigger === 'existing_action' && <div>
                        <label htmlFor="action_resource" className="block text-sm font-medium text-gray-900">Existing action for this goal</label>
                        <select id="action_resource" name="action_resource" value={form.data.action_resource} onChange={event => form.setData('action_resource', event.target.value)} required disabled={formDisabled} className={`mt-2 ${FIELD}`} aria-invalid={Boolean(form.errors.action_resource)} aria-describedby={form.errors.action_resource ? 'action_resource-error' : undefined}>
                            <option value="">Choose an action</option>
                            {actions.map(action => <option key={action.resource} value={action.resource}>{action.name}</option>)}
                        </select>
                        <p className="mt-2 text-sm text-gray-600">Only actions available in your account for the selected goal are shown.</p>
                        {form.errors.action_resource && <p id="action_resource-error" role="alert" className="mt-2 text-sm text-red-700">{form.errors.action_resource}</p>}
                    </div>}

                    <p className="text-sm text-gray-600">{preparingOnly ? 'Saving prepares only the Google Ads goal. Website tracking still needs connecting.' : 'Saving changes the goal and its tracking settings.'} It does not switch on ads, change your daily budget, or confirm a real conversion.</p>
                    <div className="flex flex-wrap items-center gap-4">
                        <button type="submit" disabled={formDisabled} className="min-h-[44px] rounded-lg bg-brand-dark px-5 py-3 text-sm font-semibold text-white hover:bg-brand-darker disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-600">{form.processing ? 'Saving…' : configuring ? 'Configuring…' : 'Save and configure goal'}</button>
                        {tag.setup_url && !nativeConnected && <Link href={tag.setup_url} className="text-sm font-semibold text-brand-dark underline">Set up your website tag</Link>}
                        {waitingForCampaign && <Link href={route('campaigns.show', { campaign: campaign.uuid })} className="text-sm font-semibold text-brand-dark underline">Review and create your campaign</Link>}
                        <button type="button" onClick={() => router.reload({ only: ['configuration', 'installation', 'nativeTracking', 'conversionVerification'] })} className="min-h-[44px] text-sm font-medium text-gray-600 underline">Refresh status</button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
