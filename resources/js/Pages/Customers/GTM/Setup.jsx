import React, { useState, useEffect, useCallback, useRef } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { fetchJson } from '@/utils/http';
import { usePolling } from '@/hooks/usePolling';

function CopyBlock({ label, code }) {
    const [copied, setCopied] = useState(false);

    const copy = () => {
        navigator.clipboard.writeText(code).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    };

    return (
        <div className="mb-4">
            <div className="flex items-center justify-between mb-1">
                <span className="text-sm font-medium text-gray-700">{label}</span>
                <button
                    onClick={copy}
                    className="text-xs text-brand-dark hover:text-brand-darker font-medium"
                >
                    {copied ? '✓ Copied' : 'Copy'}
                </button>
            </div>
            <pre className="bg-gray-900 text-green-300 text-xs rounded-lg p-4 overflow-x-auto whitespace-pre-wrap break-all">
                {code}
            </pre>
        </div>
    );
}

function ExistingTagsInfo() {
    const [expanded, setExpanded] = useState(false);

    return (
        <div className="bg-blue-50 border border-blue-200 shadow-sm sm:rounded-lg p-6">
            <button
                onClick={() => setExpanded(!expanded)}
                className="flex items-center gap-2 w-full text-left"
            >
                <svg className="h-5 w-5 text-blue-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                </svg>
                <span className="text-sm font-semibold text-blue-900">
                    Already have Google tags on your website?
                </span>
                <svg className={`h-4 w-4 text-blue-500 ml-auto transition-transform ${expanded ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" strokeWidth="2" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>

            {expanded && (
                <div className="mt-4 space-y-3 text-sm text-blue-800">
                    <p>
                        If your website already has Google tags installed — such as Google Tag Manager, Google Analytics, or Google Ads conversion tracking — that's completely fine. Here's how our tracking works with your existing setup:
                    </p>
                    <div className="space-y-2">
                        <p>
                            <strong>Google Tag Manager (GTM):</strong> Multiple GTM containers can run on the same page without conflict. Our container handles campaign conversion tracking independently from your existing container.
                        </p>
                        <p>
                            <strong>Google Analytics (GA4):</strong> Your existing analytics will continue working as normal. Our tracking is focused on ad conversion events and doesn't duplicate analytics data.
                        </p>
                        <p>
                            <strong>Google Ads conversion tags:</strong> If you have manually installed Google Ads conversion tags (e.g., a global site tag or gtag.js snippet), we recommend removing them after our tracking is verified. Running both could count conversions twice.
                        </p>
                        <p>
                            <strong>Facebook Pixel / other platforms:</strong> No changes needed — our container is completely separate.
                        </p>
                    </div>
                    <p className="text-blue-700 italic">
                        Just proceed with the steps below and add our snippet alongside your existing tags.
                    </p>
                </div>
            )}
        </div>
    );
}

/**
 * Send the instructions to whoever actually manages the website.
 *
 * The snippet step is the one thing on the critical path that a small business
 * owner usually cannot do themselves, and the page offered them no way out of
 * it — no developer hand-off, no "we'll do it", nothing. A customer who cannot
 * complete this never gets conversion data, which means the product can never
 * show them what it earned.
 */
function HandOffPanel({ customer }) {
    const [email, setEmail] = useState('');
    const [state, setState] = useState('idle');
    const [error, setError] = useState(null);

    const send = async () => {
        if (!email || state === 'sending') return;

        setState('sending');
        setError(null);

        try {
            await fetchJson(route('customers.gtm.handoff', { customer: customer.uuid }), {
                method: 'POST',
                json: { email },
            });
            setState('sent');
        } catch {
            setState('idle');
            setError('We could not send that. Check the address and try again.');
        }
    };

    if (state === 'sent') {
        return (
            <div className="mb-5 rounded-lg border border-green-200 bg-green-50 px-4 py-3">
                <p className="text-sm text-green-900">
                    Sent to <strong>{email}</strong>. They will get the snippets, your container ID and
                    step-by-step instructions. This page will show "installed" once it is live.
                </p>
            </div>
        );
    }

    return (
        <div className="mb-5 rounded-lg border border-gray-200 bg-gray-50 p-4">
            <label htmlFor="gtm-handoff-email" className="block text-sm font-medium text-gray-900">
                Don't manage your own website?
            </label>
            <p className="mt-1 text-xs text-gray-600">
                We'll email everything below to whoever does — your web developer, agency, or whoever
                built the site.
            </p>

            <div className="mt-3 flex flex-wrap items-start gap-2">
                <input
                    id="gtm-handoff-email"
                    type="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            e.stopPropagation();
                            send();
                        }
                    }}
                    placeholder="their@email.com"
                    className="w-64 rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-primary focus:ring-brand-primary"
                />
                <button
                    type="button"
                    onClick={send}
                    disabled={!email || state === 'sending'}
                    className="rounded-md bg-brand-dark px-4 py-2 text-sm font-semibold text-white hover:bg-brand-darker disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-600"
                >
                    {state === 'sending' ? 'Sending…' : 'Send instructions'}
                </button>
            </div>

            {error && <p className="mt-2 text-sm text-red-700">{error}</p>}
        </div>
    );
}

function CustomerOwnedTracking({ customer, snippet, existingTags, trackingOwnership, goalSetupUrl, identityError, processing, onVerify }) {
    const identity = customer.setup_handover?.google_identity;
    const identityVerified = Boolean(identity?.email && identity?.subject && identity?.verified_at && trackingOwnership?.status !== 'identity_required');
    const adsAccess = customer.setup_handover?.ads_access || {};
    const adsAccepted = identityVerified && adsAccess.status === 'accepted' && adsAccess.email === identity.email && adsAccess.subject === identity.subject
        && String(adsAccess.account_id || '').replace(/\D/g, '') === String(customer.google_ads_customer_id || '').replace(/\D/g, '')
        && Boolean(customer.google_ads_customer_id);
    const ownership = trackingOwnership || {};
    const owned = identityVerified && ownership.status === 'connected' && ownership.connected === true && ownership.email === identity.email
        && Boolean(ownership.public_id) && ownership.public_id === customer.gtm_container_id;
    const installed = owned && customer.gtm_installed === true && customer.gtm_container_id === ownership.public_id;
    const previousSelection = ['connected', 'access_unverified', 'identity_changed'].includes(ownership.status);
    const { data, setData, post, processing: connecting, errors } = useForm({
        account_id: previousSelection ? ownership.account_id || '' : '', container_id: previousSelection ? ownership.container_id || '' : '',
    });
    const [pendingAction, setPendingAction] = useState(null);
    const [actionError, setActionError] = useState(null);
    const customerId = customer.uuid || customer.id;
    const validSelection = /^\d{1,20}$/.test(data.account_id) && /^\d{1,20}$/.test(data.container_id);
    const request = (action) => {
        if (pendingAction) return;
        setPendingAction(action);
        setActionError(null);
        router.post(route(action, { customer: customerId }), {}, {
            preserveScroll: true,
            onError: errors => setActionError(Object.values(errors).flat().join(' ') || 'This check could not finish. Please try again.'),
            onFinish: () => setPendingAction(null),
        });
    };
    const connect = event => {
        event.preventDefault();
        if (!identityVerified || !ownership.platform_email || !validSelection || connecting) return;
        post(route('customers.gtm.connect', { customer: customerId }), { preserveScroll: true });
    };
    const adsMessage = adsAccepted ? `Google confirms that ${adsAccess.email} has administrator access to account ${adsAccess.account_id}.`
        : adsAccess.status === 'approval_pending' ? 'Google is waiting for an existing administrator to approve the invitation request. Your access is not ready yet.'
        : adsAccess.status === 'invitation_pending' ? 'Your Google Ads invitation is pending. Accept it using the Google account shown above, then check access again.'
        : adsAccess.status === 'error' ? 'We could not verify access with Google. Try the check again; an invitation alone does not confirm access.'
        : !customer.google_ads_customer_id ? 'Your Google Ads account is not ready yet. Its invitation and access check will appear here once the account is created.'
        : 'We have not yet confirmed that your Google account can administer this Google Ads account.';

    return <>
        <div className="rounded-lg border border-blue-200 bg-blue-50 p-6">
            <h3 className="text-lg font-semibold text-blue-900">Your accounts, your tracking</h3>
            <p className="mt-2 text-sm text-blue-900">Confirm that you control your Google Ads account and your own Google Tag Manager account before handover.</p>
            <p className="mt-2 text-sm text-blue-800">If you remove SiteToSpend’s access later, your Google accounts and published conversion tags remain in place. SiteToSpend’s monitoring and automated changes will stop.</p>
        </div>

        <section aria-label="Your Google identity" className="rounded-lg bg-white p-6 shadow-sm">
            <h3 className="text-lg font-semibold text-gray-900">1. Which Google account will manage your setup?</h3>
            <p className="mt-2 text-sm text-gray-600">Use the Google account you want to manage your ads and website tracking. Signing in confirms your identity; it does not connect an existing ads account or grant access to other customers’ accounts.</p>
            {identityVerified && <p className="mt-3 text-sm text-green-800">Google identity confirmed: <strong>{identity.email}</strong></p>}
            {!identityVerified && <p className="mt-3 text-sm text-amber-800">Your Google identity has not been confirmed yet.</p>}
            <PrimaryButton type="button" onClick={() => request('customers.setup.google-identity')} disabled={Boolean(pendingAction)} className="mt-4">
                {pendingAction === 'customers.setup.google-identity' ? 'Opening Google…' : identityVerified ? 'Use another Google account' : 'Continue with Google'}
            </PrimaryButton>
            {identityError && <p role="alert" className="mt-3 text-sm text-red-700">{identityError}</p>}
        </section>

        <section aria-label="Your Google Ads access" className="rounded-lg bg-white p-6 shadow-sm">
            <h3 className="text-lg font-semibold text-gray-900">2. Confirm that you can manage your Google Ads account</h3>
            <p className={`mt-2 text-sm ${adsAccepted ? 'text-green-800' : 'text-gray-700'}`}>{adsMessage}</p>
            <p className="mt-2 text-sm text-gray-600">Administrator access applies to your customer account, not SiteToSpend’s manager account or other customers.</p>
            <div className="mt-4 flex flex-wrap items-center gap-4">
                <PrimaryButton type="button" onClick={() => request('customers.setup.verify-access')}
                    disabled={!identityVerified || !customer.google_ads_customer_id || Boolean(pendingAction)}>
                    {pendingAction === 'customers.setup.verify-access' ? 'Checking access…' : 'Check Google Ads access'}
                </PrimaryButton>
                <a href="https://ads.google.com/" target="_blank" rel="noopener noreferrer" className="text-sm font-medium text-brand-dark underline">Open Google Ads</a>
            </div>
            {adsAccess.checked_at && <p className="mt-3 text-xs text-gray-500">Last checked: {new Date(adsAccess.checked_at).toLocaleString()}</p>}
        </section>
        {actionError && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{actionError}</p>}

        <section aria-label="Your tracking account" className="rounded-lg bg-white p-6 shadow-sm">
            <h3 className="text-lg font-semibold text-gray-900">3. Choose your own Tag Manager account</h3>
            <p className="mt-2 text-sm text-gray-600">Google Tag Manager holds your website’s conversion tags. Use an account where your confirmed Google identity is an administrator, so you retain control after handover.</p>
            <p className="mt-2 text-sm text-gray-600">Already use Tag Manager? Choose that account and its website container. If you do not have one, create an account and a Web container in Google first.</p>
            <a href="https://tagmanager.google.com/" target="_blank" rel="noopener noreferrer" className="mt-3 inline-block text-sm font-medium text-brand-dark underline">Open Google Tag Manager</a>
            {owned ? <p className="mt-3 text-sm text-green-800">The last ownership check confirmed administrator access for <strong>{ownership.email}</strong>. Container: <strong>{ownership.public_id}</strong>.</p>
                : <p className="mt-3 text-sm text-amber-800">{ownership.message || 'Your tracking account ownership has not been verified yet.'}</p>}
            {owned && ownership.verified_at && <p className="mt-2 text-xs text-gray-500">Last ownership check: {new Date(ownership.verified_at).toLocaleString()}</p>}
            {owned && ownership.requires_recheck && <p className="mt-2 text-sm text-amber-800">These permissions need a fresh check. Verify the same container again before handover.</p>}
            {ownership.status === 'legacy_platform_container' && <p className="mt-2 text-sm text-amber-800">The existing container is in SiteToSpend’s shared account. It does not establish your ownership. Select your own account below; you will not receive access to the shared account.</p>}
            {!identityVerified && <p className="mt-3 text-sm text-gray-600">Confirm your Google identity in step 1 before choosing your tracking account.</p>}
            {ownership.platform_email ? <p className="mt-3 text-sm text-gray-700">In your own Tag Manager account, invite <strong>{ownership.platform_email}</strong> as an Administrator and give it Publish permission on the chosen container. This lets SiteToSpend verify your ownership and configure tracking in that container.</p>
                : <p role="alert" className="mt-3 text-sm text-amber-800">SiteToSpend’s tracking access is not configured yet. We need to finish that before you can connect your container.</p>}
            <form onSubmit={connect} className="mt-4 space-y-4">
                <p id="tracking-account-number-help" className="text-xs text-gray-600">Find these numbers in your Tag Manager page address: accounts/<strong>123456</strong>/containers/<strong>987654</strong>. The container number is not the public GTM-XXXX code installed on your website.</p>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div><label htmlFor="owned-gtm-account" className="block text-sm font-medium text-gray-900">Tag Manager account number</label>
                        <input id="owned-gtm-account" inputMode="numeric" pattern="[0-9]+" required value={data.account_id} onChange={event => setData('account_id', event.target.value)}
                            aria-describedby="tracking-account-number-help" aria-invalid={Boolean(errors.account_id)} className="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                        {errors.account_id && <p role="alert" className="mt-1 text-sm text-red-700">{errors.account_id}</p>}</div>
                    <div><label htmlFor="owned-gtm-container" className="block text-sm font-medium text-gray-900">Container number</label>
                        <input id="owned-gtm-container" inputMode="numeric" pattern="[0-9]+" required value={data.container_id} onChange={event => setData('container_id', event.target.value)}
                            aria-describedby="tracking-account-number-help" aria-invalid={Boolean(errors.container_id)} className="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                        {errors.container_id && <p role="alert" className="mt-1 text-sm text-red-700">{errors.container_id}</p>}</div>
                </div>
                <PrimaryButton type="submit" disabled={!identityVerified || !ownership.platform_email || !validSelection || connecting}>
                    {connecting ? 'Checking your container…' : 'Verify and use this container'}
                </PrimaryButton>
            </form>
        </section>

        <section aria-label="Website tag installation" className="rounded-lg bg-white p-6 shadow-sm">
            <h3 className="text-lg font-semibold text-gray-900">4. Check the tag on your website</h3>
            <p className="mt-2 text-sm text-gray-600">If this same container is already installed, keep it. A new campaign needs its conversion tag and trigger configured inside the container; you do not reinstall the base website snippet for every campaign.</p>
            {existingTags?.detected_container_id && <p className="mt-2 text-sm text-gray-700">Previously found on your website: <strong>{existingTags.detected_container_id}</strong>. Finding a tag does not prove that a conversion event works.</p>}
            {!owned ? <p className="mt-3 text-sm text-amber-800">Choose and verify your own container in step 3 before checking its website installation.</p>
                : <>
                    <p className={`mt-3 text-sm ${installed ? 'text-green-800' : 'text-amber-800'}`}>{installed ? 'Your selected website tag was found. A real conversion event still needs testing.' : 'Installation of your selected website tag has not been verified.'}</p>
                    <PrimaryButton type="button" onClick={onVerify} disabled={processing || !customer.website} className="mt-4">{processing ? 'Scanning…' : 'Check website tag'}</PrimaryButton>
                    {!customer.website && <p className="mt-2 text-sm text-amber-800">Add your website address to your business profile before checking installation.</p>}
                    {snippet && <details className="mt-5"><summary className="cursor-pointer text-sm font-medium text-brand-dark">Installation code — only if this container is missing</summary>
                        <div className="mt-4"><HandOffPanel customer={customer} />
                            <p className="mb-3 text-sm text-gray-600">Install this container once. Do not add a duplicate copy when it is already on your website.</p>
                            <CopyBlock label="Paste inside <head>" code={snippet.head} /><CopyBlock label="Paste after opening <body>" code={snippet.body} />
                        </div>
                    </details>}
                </>}
        </section>

        <section aria-label="Conversion event test" className="rounded-lg bg-white p-6 shadow-sm">
            <h3 className="text-lg font-semibold text-gray-900">5. Test the conversion you want to measure</h3>
            <p className="mt-2 text-sm font-medium text-amber-800">A successful conversion test is not yet verified here.</p>
            <p className="mt-2 text-sm text-gray-600">Before launching, complete the intended enquiry or purchase flow in Tag Assistant and check that it sends the correct event to the selected Google Ads conversion action. A found snippet or a saved conversion goal does not confirm this test.</p>
            <div className="mt-4 flex flex-wrap gap-4">
                {goalSetupUrl && <Link href={goalSetupUrl} className="text-sm font-medium text-brand-dark underline">Review your conversion goal</Link>}
                <a href="https://tagassistant.google.com/" target="_blank" rel="noopener noreferrer" className="text-sm font-medium text-brand-dark underline">Open Tag Assistant</a>
            </div>
        </section>
    </>;
}

export default function GTMSetupPage({ auth, customer: initialCustomer, snippet: initialSnippet, existingTags, trackingOwnership = null, goalSetupUrl = null, handoverRevision = null }) {
    const { flash, errors: pageErrors = {} } = usePage().props;
    const [customer, setCustomer] = useState(initialCustomer);
    const [snippet, setSnippet] = useState(initialSnippet);
    const [processing, setProcessing] = useState(false);
    const [successMessage, setSuccessMessage] = useState(flash?.success || (flash?.type === 'success' ? flash.message : null));
    const [errorMessage, setErrorMessage] = useState(flash?.error || (flash?.type === 'error' ? flash.message : null));
    const [statusRefreshError, setStatusRefreshError] = useState(false);
    const [statusRetry, setStatusRetry] = useState(0);
    const requestedRevision = useRef(null);
    const observedPageRevision = useRef(handoverRevision);
    const statusReloading = useRef(false);
    const setupOnly = initialCustomer.service_type === 'setup_only';
    const statusPoll = usePolling(setupOnly ? route('customers.gtm.status', { customer: initialCustomer.uuid || initialCustomer.id }) : null, {
        enabled: setupOnly, interval: 10000, immediate: false, restartKey: `${handoverRevision}:${statusRetry}`,
        parse: response => {
            if (typeof response?.handover_revision !== 'string') throw new Error('Account status could not be read.');
            return response;
        },
    });
    const refreshStatus = useCallback(() => {
        if (statusReloading.current) return;
        statusReloading.current = true;
        setStatusRefreshError(false);
        router.reload({
            only: ['customer', 'snippet', 'trackingOwnership', 'handoverRevision', 'goalSetupUrl'], preserveScroll: true,
            onError: () => setStatusRefreshError(true),
            onFinish: () => { statusReloading.current = false; setStatusRetry(value => value + 1); },
        });
    }, []);
    useEffect(() => {
        // A new page response supersedes an older poll still in memory. Wait
        // for the restarted poll before comparing against the new revision.
        if (observedPageRevision.current !== handoverRevision) {
            observedPageRevision.current = handoverRevision;
            requestedRevision.current = null;
            return;
        }
        const revision = statusPoll.data?.handover_revision;
        if (!setupOnly || !handoverRevision || !revision || revision === handoverRevision
            || requestedRevision.current === revision || statusReloading.current) return;
        requestedRevision.current = revision;
        refreshStatus();
    }, [setupOnly, handoverRevision, statusPoll.data, refreshStatus]);

    // Sync state from props when Inertia re-renders with fresh data after redirect
    useEffect(() => {
        setCustomer(initialCustomer);
        setSnippet(initialSnippet);
    }, [initialCustomer, initialSnippet]);

    useEffect(() => {
        const success = flash?.success || (flash?.type === 'success' ? flash.message : null);
        const error = flash?.error || (flash?.type === 'error' ? flash.message : null);
        if (error) {
            setErrorMessage(error);
            setSuccessMessage(null);
        } else if (success) {
            setSuccessMessage(success);
            setErrorMessage(null);
            const timer = setTimeout(() => setSuccessMessage(null), 6000);
            return () => clearTimeout(timer);
        }
        return undefined;
    }, [flash?.success, flash?.error, flash?.type, flash?.message]);

    const handleProvision = () => {
        setProcessing(true);
        router.post(route('customers.gtm.provision', customer.uuid), {}, {
            onFinish: () => setProcessing(false),
        });
    };

    const handleVerify = () => {
        setProcessing(true);
        setErrorMessage(null);
        router.post(route('customers.gtm.verify', customer.uuid), {}, {
            onFinish: () => setProcessing(false),
        });
    };

    const handleRescan = () => {
        setProcessing(true);
        router.post(route('customers.gtm.rescan', customer.uuid), {}, {
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                    {customer.service_type === 'setup_only' ? 'Account access & tracking' : 'Tracking Setup'} — {customer.name}
                </h2>
            }
        >
            <Head title={customer.service_type === 'setup_only' ? 'Account access & tracking' : 'Tracking Setup'} />

            <div className="py-12">
                <div className="max-w-3xl mx-auto space-y-6">

                    {successMessage && (
                        <div role="status" className="bg-green-50 border border-green-200 rounded-lg p-4 text-green-800">
                            {successMessage}
                        </div>
                    )}
                    {errorMessage && (
                        <div role="alert" className="bg-red-50 border border-red-200 rounded-lg p-4 text-red-800">
                            {errorMessage}
                        </div>
                    )}
                    {setupOnly && (statusPoll.error || statusRefreshError) && <div role="alert" className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        <p>We could not refresh account and tracking status. These are the last confirmed details.</p>
                        <button type="button" onClick={refreshStatus} className="mt-2 font-semibold underline">Refresh account status</button>
                    </div>}

                    {customer.service_type === 'setup_only' ? <CustomerOwnedTracking customer={customer} snippet={snippet} existingTags={existingTags}
                        trackingOwnership={trackingOwnership} goalSetupUrl={goalSetupUrl} identityError={pageErrors.google_identity} processing={processing} onVerify={handleVerify} /> : <>

                    {/* Existing Google Tags Guidance */}
                    {(customer.gtm_detected || existingTags) && (
                        <div className="bg-amber-50 border border-amber-200 shadow-sm sm:rounded-lg p-6">
                            <div className="flex items-start gap-3">
                                <svg className="h-6 w-6 text-amber-500 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                                </svg>
                                <div>
                                    <h3 className="text-lg font-semibold text-amber-900">
                                        Existing Google Tags Detected
                                    </h3>
                                    {existingTags?.detected_container_id && (
                                        <p className="mt-1 text-sm text-amber-800">
                                            We found container <code className="font-mono font-semibold bg-amber-100 px-1 rounded">{existingTags.detected_container_id}</code> on your website.
                                        </p>
                                    )}
                                    <p className="mt-2 text-sm text-amber-800">
                                        No worries — our tracking works alongside your existing tags. Here's what you need to know:
                                    </p>
                                    <ul className="mt-3 space-y-2 text-sm text-amber-800">
                                        <li className="flex items-start gap-2">
                                            <span className="font-bold text-amber-600 mt-px">•</span>
                                            <span><strong>Existing GTM container:</strong> You can keep your current container in place. Our container runs independently and won't interfere with your existing tags or tracking.</span>
                                        </li>
                                        <li className="flex items-start gap-2">
                                            <span className="font-bold text-amber-600 mt-px">•</span>
                                            <span><strong>Google Analytics (GA4):</strong> If you have GA4 installed directly (not through GTM), it will continue to work normally. Our container only handles conversion tracking for your ad campaigns.</span>
                                        </li>
                                        <li className="flex items-start gap-2">
                                            <span className="font-bold text-amber-600 mt-px">•</span>
                                            <span><strong>Google Ads tags:</strong> If you already have Google Ads conversion tags on your site, we recommend removing them once our tracking is verified to avoid duplicate conversion counting.</span>
                                        </li>
                                        <li className="flex items-start gap-2">
                                            <span className="font-bold text-amber-600 mt-px">•</span>
                                            <span><strong>Other tags (Facebook Pixel, etc.):</strong> No changes needed. Our container is completely separate and won't affect any other tracking you have in place.</span>
                                        </li>
                                    </ul>
                                    <p className="mt-3 text-sm text-amber-700 italic">
                                        Continue with the steps below to set up conversion tracking — just add our snippet alongside your existing tags.
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* General guidance when no tags detected but helpful context */}
                    {!customer.gtm_detected && !existingTags && !customer.gtm_container_id && (
                        <ExistingTagsInfo />
                    )}

                    {/* Step 1: Provision */}
                    <div className="bg-white shadow-sm sm:rounded-lg p-6">
                        <div className="flex items-start justify-between">
                            <div>
                                <h3 className="text-lg font-semibold text-gray-900">
                                    Step 1 — Enable Conversion Tracking
                                </h3>
                                <p className="mt-1 text-sm text-gray-600">
                                    We create and manage a Google Tag Manager container for your site. No Google account connection required.
                                </p>
                            </div>
                            {customer.gtm_container_id && (
                                <span className="ml-4 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    ✓ Provisioned
                                </span>
                            )}
                        </div>

                        {!customer.gtm_container_id ? (
                            <div className="mt-4">
                                <PrimaryButton onClick={handleProvision} disabled={processing}>
                                    {processing ? 'Setting up…' : 'Set up tracking'}
                                </PrimaryButton>
                            </div>
                        ) : (
                            <p className="mt-3 text-sm text-gray-500">
                                Container ID: <code className="font-mono font-semibold text-gray-800">{customer.gtm_container_id}</code>
                            </p>
                        )}
                    </div>

                    {/* Step 2: Install snippet */}
                    {(snippet || customer.gtm_container_id) && (
                        <div className="bg-white shadow-sm sm:rounded-lg p-6">
                            <h3 className="text-lg font-semibold text-gray-900 mb-1">
                                Step 2 — Add the snippet to your website
                            </h3>

                            {/*
                                What it is, before what to do with it.
                                This step gates every number the product
                                produces — conversions, cost per enquiry, the
                                forecast's accuracy — and it previously opened
                                with raw markup and no explanation of why a
                                customer should care.
                            */}
                            <p className="text-sm text-gray-700 mb-3">
                                This is how we know which clicks turned into real enquiries and sales.
                                Without it your ads still run, but we cannot tell you what they earned —
                                and the agents have nothing to optimise toward.
                            </p>

                            {/*
                                The hand-off. Most owner-operators do not edit
                                their own site, and this page previously offered
                                them nowhere to go.
                            */}
                            <HandOffPanel customer={customer} />

                            <p className="text-sm text-gray-600 mb-5">
                                Editing the site yourself? Paste these two snippets in. The first goes in the{' '}
                                <code className="text-xs bg-gray-100 px-1 rounded">&lt;head&gt;</code>, the
                                second immediately after the opening{' '}
                                <code className="text-xs bg-gray-100 px-1 rounded">&lt;body&gt;</code> tag.
                            </p>

                            {snippet ? (
                                <>
                                    <CopyBlock label="Paste inside <head>" code={snippet.head} />
                                    <CopyBlock label="Paste after opening <body>" code={snippet.body} />
                                </>
                            ) : (
                                <p className="text-sm text-gray-500">Reload the page to see your snippet.</p>
                            )}
                        </div>
                    )}

                    {/* Step 3: Verify */}
                    {customer.gtm_container_id && (
                        <div className="bg-white shadow-sm sm:rounded-lg p-6">
                            <div className="flex items-start justify-between">
                                <div>
                                    <h3 className="text-lg font-semibold text-gray-900">
                                        Step 3 — Verify installation
                                    </h3>
                                    <p className="mt-1 text-sm text-gray-600">
                                        Once you've added the snippet, click below and we'll scan your site to confirm it's live.
                                    </p>
                                </div>
                                {customer.gtm_installed && (
                                    <span className="ml-4 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        ✓ Verified
                                    </span>
                                )}
                            </div>

                            {customer.gtm_installed ? (
                                <div className="mt-4 bg-green-50 border border-green-200 rounded-lg p-4 text-sm text-green-800">
                                    Snippet confirmed on your website. Next, verify that a real signup or purchase
                                    reaches the intended conversion action in Google Ads.
                                </div>
                            ) : (
                                <div className="mt-4 flex gap-3">
                                    <PrimaryButton onClick={handleVerify} disabled={processing || !customer.website}>
                                        {processing ? 'Scanning…' : 'Verify snippet'}
                                    </PrimaryButton>
                                    <SecondaryButton onClick={handleRescan} disabled={processing}>
                                        Re-scan site
                                    </SecondaryButton>
                                </div>
                            )}

                            {!customer.website && (
                                <p className="mt-2 text-xs text-amber-600">
                                    Add your website URL in your profile settings to enable verification.
                                </p>
                            )}

                            {customer.gtm_last_verified && (
                                <p className="mt-3 text-xs text-gray-500">
                                    Last verified: {new Date(customer.gtm_last_verified).toLocaleString()}
                                </p>
                            )}
                        </div>
                    )}

                    </>}

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
