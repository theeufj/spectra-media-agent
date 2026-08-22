import { useForm } from '@inertiajs/react';

/**
 * Accept the budget on a campaign we generated for the customer.
 *
 * The daily figure came from a language model, and it is not a display value:
 * deploying charges seven days of it up front. DeploymentController refuses to
 * deploy until this is confirmed, so without this panel a customer would click
 * Deploy and be told to confirm a budget with nowhere to do it.
 *
 * The number is presented as ours to justify and theirs to change — the
 * rationale is shown, the field is editable, and confirming is an explicit act
 * rather than a side effect of deploying.
 */
export default function BudgetConfirmation({ campaign, currency = 'USD' }) {
    const { data, setData, post, processing, errors } = useForm({
        daily_budget: campaign.daily_budget ?? '',
    });

    const confirmed = Boolean(campaign.budget_confirmed_at);
    const weekly = Number(data.daily_budget || 0) * 7;

    const submit = (e) => {
        e.preventDefault();
        post(route('campaigns.confirm-budget', { campaign: campaign.id }), { preserveScroll: true });
    };

    if (confirmed) {
        return (
            <div className="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3">
                <p className="text-sm text-green-900">
                    <span className="font-semibold">Budget confirmed</span> —{' '}
                    {currency} {Number(campaign.daily_budget).toFixed(2)} a day. You can deploy whenever you're ready.
                </p>
            </div>
        );
    }

    return (
        <div className="mb-6 overflow-hidden rounded-lg border-2 border-flame-orange-300 bg-white shadow-sm">
            <div className="border-b border-flame-orange-200 bg-flame-orange-50 px-6 py-4">
                <h3 className="text-base font-semibold text-gray-900">Confirm your budget before going live</h3>
                <p className="mt-1 text-sm text-gray-600">
                    We built this campaign from your website, including a suggested budget. Nothing is live
                    and nothing has been charged — check the number below and it's yours.
                </p>
            </div>

            <form onSubmit={submit} className="p-6">
                {campaign.budget_rationale && (
                    <div className="mb-4 rounded-md bg-gray-50 p-3">
                        <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">Why we suggested this</p>
                        <p className="mt-1 text-sm text-gray-700">{campaign.budget_rationale}</p>
                    </div>
                )}

                <label htmlFor="daily_budget" className="block text-sm font-medium text-gray-700">
                    Daily budget ({currency})
                </label>

                <div className="mt-1 flex flex-wrap items-center gap-3">
                    <input
                        id="daily_budget"
                        type="number"
                        min="1"
                        step="1"
                        value={data.daily_budget}
                        onChange={(e) => setData('daily_budget', e.target.value)}
                        className="w-36 rounded-lg border-gray-300 text-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                    />

                    <button
                        type="submit"
                        disabled={processing || !data.daily_budget}
                        className="rounded-lg bg-flame-orange-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-flame-orange-600 disabled:cursor-not-allowed disabled:bg-gray-300"
                    >
                        {processing ? 'Saving…' : 'Confirm budget'}
                    </button>
                </div>

                {errors.daily_budget && (
                    <p className="mt-2 text-sm text-red-600">{errors.daily_budget}</p>
                )}

                {/* The number that actually leaves their account, stated before
                    they agree to it rather than after. */}
                <p className="mt-3 text-sm text-gray-600">
                    You'll be charged{' '}
                    <strong>
                        {currency} {weekly.toFixed(2)}
                    </strong>{' '}
                    when you deploy — seven days up front. After that we top up as you spend, and you can
                    change or pause the budget at any time.
                </p>
            </form>
        </div>
    );
}
