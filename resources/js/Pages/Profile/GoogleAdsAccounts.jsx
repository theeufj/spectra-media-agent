import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

export default function GoogleAdsAccounts({ auth, accounts = [], selectedAccountId = null, customerName }) {
    const { errors } = usePage().props;
    const { data, setData, put, processing } = useForm({
        google_ads_customer_id: selectedAccountId ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        put(route('profile.google-ads.accounts.update'));
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Select Google Ads Account</h2>}
        >
            <Head title="Select Google Ads Account" />

            <div className="py-12">
                <div className="mx-auto max-w-4xl sm:px-6 lg:px-8">
                    <div className="bg-white shadow sm:rounded-lg">
                        <form onSubmit={submit} className="p-6 sm:p-8">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <h3 className="text-lg font-medium text-gray-900">Choose the account Spectra should deploy into</h3>
                                    <p className="mt-1 text-sm text-gray-600">
                                        Connected Google login for {customerName}. Select the exact Google Ads account ID to use for future deployments.
                                    </p>
                                </div>
                                <Link
                                    href={route('profile.edit')}
                                    className="text-sm font-medium text-gray-600 hover:text-gray-900"
                                >
                                    Back to Profile
                                </Link>
                            </div>

                            <div className="mt-6 space-y-3">
                                {accounts.map((account) => {
                                    const isSelected = data.google_ads_customer_id === account.id;

                                    return (
                                        <label
                                            key={account.id}
                                            className={`block cursor-pointer rounded-xl border p-4 transition ${isSelected ? 'border-flame-orange-500 bg-flame-orange-50' : 'border-gray-200 bg-white hover:border-gray-300'}`}
                                        >
                                            <div className="flex items-start gap-3">
                                                <input
                                                    type="radio"
                                                    name="google_ads_customer_id"
                                                    value={account.id}
                                                    checked={isSelected}
                                                    onChange={() => setData('google_ads_customer_id', account.id)}
                                                    className="mt-1 h-4 w-4 border-gray-300 text-flame-orange-600 focus:ring-flame-orange-500"
                                                />
                                                <div>
                                                    <div className="text-sm font-semibold text-gray-900">{account.name}</div>
                                                    <div className="mt-1 text-sm text-gray-600">Customer ID: {account.id}</div>
                                                    <div className="mt-1 text-xs text-gray-500">Resource: {account.resource_name}</div>
                                                </div>
                                            </div>
                                        </label>
                                    );
                                })}
                            </div>

                            <InputError className="mt-3" message={errors.google_ads_customer_id} />

                            <div className="mt-6 flex items-center gap-3">
                                <PrimaryButton disabled={processing || !data.google_ads_customer_id}>
                                    {processing ? 'Saving...' : 'Save Google Ads Account'}
                                </PrimaryButton>
                                <Link
                                    href={route('profile.edit')}
                                    className="text-sm font-medium text-gray-600 hover:text-gray-900"
                                >
                                    Cancel
                                </Link>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}