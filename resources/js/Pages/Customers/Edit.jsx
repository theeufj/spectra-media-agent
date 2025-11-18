import React from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

// Google Ads supported countries (ISO 3166-1 alpha-2)
const COUNTRIES = [
    { code: 'US', name: 'United States' },
    { code: 'CA', name: 'Canada' },
    { code: 'GB', name: 'United Kingdom' },
    { code: 'AU', name: 'Australia' },
    { code: 'NZ', name: 'New Zealand' },
    { code: 'IE', name: 'Ireland' },
    { code: 'DE', name: 'Germany' },
    { code: 'FR', name: 'France' },
    { code: 'ES', name: 'Spain' },
    { code: 'IT', name: 'Italy' },
    { code: 'NL', name: 'Netherlands' },
    { code: 'BE', name: 'Belgium' },
    { code: 'AT', name: 'Austria' },
    { code: 'CH', name: 'Switzerland' },
    { code: 'SE', name: 'Sweden' },
    { code: 'NO', name: 'Norway' },
    { code: 'DK', name: 'Denmark' },
    { code: 'FI', name: 'Finland' },
    { code: 'PL', name: 'Poland' },
    { code: 'CZ', name: 'Czech Republic' },
    { code: 'PT', name: 'Portugal' },
    { code: 'GR', name: 'Greece' },
    { code: 'JP', name: 'Japan' },
    { code: 'KR', name: 'South Korea' },
    { code: 'SG', name: 'Singapore' },
    { code: 'HK', name: 'Hong Kong' },
    { code: 'IN', name: 'India' },
    { code: 'BR', name: 'Brazil' },
    { code: 'MX', name: 'Mexico' },
    { code: 'AR', name: 'Argentina' },
    { code: 'ZA', name: 'South Africa' },
    { code: 'AE', name: 'United Arab Emirates' },
    { code: 'SA', name: 'Saudi Arabia' },
];

// Google Ads supported timezones (IANA timezone database)
const TIMEZONES = [
    { value: 'America/New_York', label: 'Eastern Time (US & Canada)' },
    { value: 'America/Chicago', label: 'Central Time (US & Canada)' },
    { value: 'America/Denver', label: 'Mountain Time (US & Canada)' },
    { value: 'America/Los_Angeles', label: 'Pacific Time (US & Canada)' },
    { value: 'America/Phoenix', label: 'Arizona' },
    { value: 'America/Anchorage', label: 'Alaska' },
    { value: 'Pacific/Honolulu', label: 'Hawaii' },
    { value: 'America/Toronto', label: 'Eastern Time (Canada)' },
    { value: 'America/Vancouver', label: 'Pacific Time (Canada)' },
    { value: 'Europe/London', label: 'London' },
    { value: 'Europe/Dublin', label: 'Dublin' },
    { value: 'Europe/Paris', label: 'Paris' },
    { value: 'Europe/Berlin', label: 'Berlin' },
    { value: 'Europe/Amsterdam', label: 'Amsterdam' },
    { value: 'Europe/Brussels', label: 'Brussels' },
    { value: 'Europe/Madrid', label: 'Madrid' },
    { value: 'Europe/Rome', label: 'Rome' },
    { value: 'Europe/Vienna', label: 'Vienna' },
    { value: 'Europe/Stockholm', label: 'Stockholm' },
    { value: 'Europe/Copenhagen', label: 'Copenhagen' },
    { value: 'Europe/Oslo', label: 'Oslo' },
    { value: 'Europe/Helsinki', label: 'Helsinki' },
    { value: 'Europe/Warsaw', label: 'Warsaw' },
    { value: 'Europe/Prague', label: 'Prague' },
    { value: 'Europe/Lisbon', label: 'Lisbon' },
    { value: 'Europe/Athens', label: 'Athens' },
    { value: 'Europe/Zurich', label: 'Zurich' },
    { value: 'Australia/Sydney', label: 'Sydney' },
    { value: 'Australia/Melbourne', label: 'Melbourne' },
    { value: 'Australia/Brisbane', label: 'Brisbane' },
    { value: 'Australia/Perth', label: 'Perth' },
    { value: 'Pacific/Auckland', label: 'Auckland' },
    { value: 'Asia/Tokyo', label: 'Tokyo' },
    { value: 'Asia/Seoul', label: 'Seoul' },
    { value: 'Asia/Singapore', label: 'Singapore' },
    { value: 'Asia/Hong_Kong', label: 'Hong Kong' },
    { value: 'Asia/Dubai', label: 'Dubai' },
    { value: 'Asia/Riyadh', label: 'Riyadh' },
    { value: 'Asia/Kolkata', label: 'Mumbai / Kolkata' },
    { value: 'America/Sao_Paulo', label: 'São Paulo' },
    { value: 'America/Mexico_City', label: 'Mexico City' },
    { value: 'America/Buenos_Aires', label: 'Buenos Aires' },
    { value: 'Africa/Johannesburg', label: 'Johannesburg' },
];

export default function Edit({ auth, customer: initialCustomer }) {
    const { flash } = usePage().props;
    const { data, setData, put, processing, errors } = useForm({
        name: initialCustomer.name || '',
        business_type: initialCustomer.business_type || '',
        description: initialCustomer.description || '',
        country: initialCustomer.country || '',
        timezone: initialCustomer.timezone || '',
        currency_code: initialCustomer.currency_code || '',
        website: initialCustomer.website || '',
        phone: initialCustomer.phone || '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('customers.update', initialCustomer.id));
    };

    const handleCancel = () => {
        window.history.back();
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                    Edit Customer Profile - {initialCustomer.name}
                </h2>
            }
        >
            <Head title="Edit Customer Profile" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="mb-4 bg-green-50 border border-green-200 rounded-lg p-4">
                            <p className="text-sm text-green-800">{flash.success}</p>
                        </div>
                    )}

                    {flash?.error && (
                        <div className="mb-4 bg-red-50 border border-red-200 rounded-lg p-4">
                            <p className="text-sm text-red-800">{flash.error}</p>
                        </div>
                    )}

                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            <form onSubmit={submit} className="space-y-6">
                                {/* Basic Information */}
                                <div>
                                    <h3 className="text-lg font-medium text-gray-900 mb-4">Basic Information</h3>
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <InputLabel htmlFor="name" value="Customer Name *" />
                                            <TextInput
                                                id="name"
                                                name="name"
                                                value={data.name}
                                                className="mt-1 block w-full"
                                                onChange={(e) => setData('name', e.target.value)}
                                                required
                                            />
                                            <InputError message={errors.name} className="mt-2" />
                                        </div>

                                        <div>
                                            <InputLabel htmlFor="business_type" value="Business Type" />
                                            <TextInput
                                                id="business_type"
                                                name="business_type"
                                                value={data.business_type}
                                                className="mt-1 block w-full"
                                                placeholder="e.g., Retail, SaaS, E-commerce"
                                                onChange={(e) => setData('business_type', e.target.value)}
                                            />
                                            <InputError message={errors.business_type} className="mt-2" />
                                        </div>

                                        <div className="md:col-span-2">
                                            <InputLabel htmlFor="description" value="Description" />
                                            <textarea
                                                id="description"
                                                name="description"
                                                value={data.description}
                                                className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                                rows="3"
                                                placeholder="Brief description of the business..."
                                                onChange={(e) => setData('description', e.target.value)}
                                            />
                                            <InputError message={errors.description} className="mt-2" />
                                        </div>
                                    </div>
                                </div>

                                {/* Contact Information */}
                                <div className="pt-6 border-t border-gray-200">
                                    <h3 className="text-lg font-medium text-gray-900 mb-4">Contact Information</h3>
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <InputLabel htmlFor="website" value="Website URL" />
                                            <TextInput
                                                id="website"
                                                name="website"
                                                type="url"
                                                value={data.website}
                                                className="mt-1 block w-full"
                                                placeholder="https://example.com"
                                                onChange={(e) => setData('website', e.target.value)}
                                            />
                                            <InputError message={errors.website} className="mt-2" />
                                            <p className="mt-1 text-sm text-gray-500">
                                                Used for GTM detection and campaign setup
                                            </p>
                                        </div>

                                        <div>
                                            <InputLabel htmlFor="phone" value="Phone Number" />
                                            <TextInput
                                                id="phone"
                                                name="phone"
                                                type="tel"
                                                value={data.phone}
                                                className="mt-1 block w-full"
                                                placeholder="+1 (555) 123-4567"
                                                onChange={(e) => setData('phone', e.target.value)}
                                            />
                                            <InputError message={errors.phone} className="mt-2" />
                                        </div>
                                    </div>
                                </div>

                                {/* Regional Settings */}
                                <div className="pt-6 border-t border-gray-200">
                                    <h3 className="text-lg font-medium text-gray-900 mb-4">Regional Settings</h3>
                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                                        <div>
                                            <InputLabel htmlFor="country" value="Country" />
                                            <select
                                                id="country"
                                                name="country"
                                                value={data.country}
                                                className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                                onChange={(e) => setData('country', e.target.value)}
                                            >
                                                <option value="">Select a country</option>
                                                {COUNTRIES.map((country) => (
                                                    <option key={country.code} value={country.code}>
                                                        {country.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError message={errors.country} className="mt-2" />
                                            <p className="mt-1 text-sm text-gray-500">
                                                Required for Google Ads account
                                            </p>
                                        </div>

                                        <div>
                                            <InputLabel htmlFor="timezone" value="Timezone" />
                                            <select
                                                id="timezone"
                                                name="timezone"
                                                value={data.timezone}
                                                className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                                onChange={(e) => setData('timezone', e.target.value)}
                                            >
                                                <option value="">Select a timezone</option>
                                                {TIMEZONES.map((tz) => (
                                                    <option key={tz.value} value={tz.value}>
                                                        {tz.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError message={errors.timezone} className="mt-2" />
                                            <p className="mt-1 text-sm text-gray-500">
                                                Required for Google Ads account
                                            </p>
                                        </div>

                                        <div>
                                            <InputLabel htmlFor="currency_code" value="Currency Code" />
                                            <TextInput
                                                id="currency_code"
                                                name="currency_code"
                                                value={data.currency_code}
                                                className="mt-1 block w-full"
                                                placeholder="USD"
                                                maxLength="3"
                                                onChange={(e) => setData('currency_code', e.target.value.toUpperCase())}
                                            />
                                            <InputError message={errors.currency_code} className="mt-2" />
                                            <p className="mt-1 text-sm text-gray-500">
                                                3-letter ISO code (e.g., USD, EUR, GBP)
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                {/* Account IDs (Read-only) */}
                                {(initialCustomer.google_ads_customer_id || initialCustomer.facebook_ads_account_id) && (
                                    <div className="pt-6 border-t border-gray-200">
                                        <h3 className="text-lg font-medium text-gray-900 mb-4">Connected Accounts</h3>
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                            {initialCustomer.google_ads_customer_id && (
                                                <div>
                                                    <InputLabel value="Google Ads Customer ID" />
                                                    <div className="mt-1 px-3 py-2 bg-gray-50 border border-gray-300 rounded-md text-sm text-gray-700 font-mono">
                                                        {initialCustomer.google_ads_customer_id}
                                                    </div>
                                                </div>
                                            )}

                                            {initialCustomer.facebook_ads_account_id && (
                                                <div>
                                                    <InputLabel value="Facebook Ads Account ID" />
                                                    <div className="mt-1 px-3 py-2 bg-gray-50 border border-gray-300 rounded-md text-sm text-gray-700 font-mono">
                                                        {initialCustomer.facebook_ads_account_id}
                                                    </div>
                                                </div>
                                            )}

                                            {initialCustomer.gtm_container_id && (
                                                <div>
                                                    <InputLabel value="GTM Container ID" />
                                                    <div className="mt-1 px-3 py-2 bg-gray-50 border border-gray-300 rounded-md text-sm text-gray-700 font-mono">
                                                        {initialCustomer.gtm_container_id}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}

                                {/* Form Actions */}
                                <div className="flex items-center gap-4 pt-6 border-t border-gray-200">
                                    <PrimaryButton disabled={processing}>
                                        {processing ? 'Saving...' : 'Save Changes'}
                                    </PrimaryButton>
                                    <SecondaryButton type="button" onClick={handleCancel} disabled={processing}>
                                        Cancel
                                    </SecondaryButton>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
