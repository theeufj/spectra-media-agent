import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage, router, Link } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import PrimaryButton from '@/Components/PrimaryButton';
import DangerButton from '@/Components/DangerButton';
import { startTour } from '@/Components/OnboardingTour';
import { useState } from 'react';

export default function Edit({ auth, mustVerifyEmail, status, googleApiConnection }) {
    const { customers } = usePage().props;
    const [formData, setFormData] = useState({
        name: '',
        business_type: '',
        description: '',
        country: '',
        timezone: 'America/New_York',
        currency_code: 'USD',
        website: '',
        phone: '',
    });
    const [isCreating, setIsCreating] = useState(false);

    const handleInputChange = (e) => {
        const { name, value } = e.target;
        setFormData(prev => ({
            ...prev,
            [name]: value
        }));
    };

    const handleCreateCustomer = (e) => {
        e.preventDefault();
        setIsCreating(true);
        
        router.post(route('customers.store'), formData, {
            onSuccess: () => {
                setFormData({
                    name: '',
                    business_type: '',
                    description: '',
                    country: '',
                    timezone: 'America/New_York',
                    currency_code: 'USD',
                    website: '',
                    phone: '',
                });
                setIsCreating(false);
            },
            onError: () => {
                setIsCreating(false);
            }
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Profile
                </h2>
            }
        >
            <Head title="Profile" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                            className="max-w-xl"
                        />
                    </div>

                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <UpdatePasswordForm className="max-w-xl" />
                    </div>

                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <section className="max-w-xl">
                            <header>
                                <h2 className="text-lg font-medium text-gray-900">Site Tour</h2>
                                <p className="mt-1 text-sm text-gray-600">
                                    Replay the guided walkthrough to familiarise yourself with Site to Spend's features.
                                </p>
                            </header>
                            <div className="mt-4">
                                <button
                                    type="button"
                                    onClick={() => { router.visit(route('dashboard'), { onFinish: () => setTimeout(startTour, 400) }); }}
                                    className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 transition-colors"
                                >
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    Restart Site Tour
                                </button>
                            </div>
                        </section>
                    </div>

                    {/* Google API connection */}
                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <section className="max-w-xl">
                            <header className="flex items-start justify-between">
                                <div>
                                    <h2 className="text-lg font-medium text-gray-900">Google API Access</h2>
                                    <p className="mt-1 text-sm text-gray-600">
                                        Authorise SiteToSpend to manage Google Ads campaigns, publish GTM containers, and read GA4 data on your behalf.
                                    </p>
                                </div>
                                <div className="ml-4 flex-shrink-0">
                                    <svg className="w-8 h-8" viewBox="0 0 24 24" fill="none">
                                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/>
                                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                                    </svg>
                                </div>
                            </header>
                            <div className="mt-4">
                                {googleApiConnection ? (
                                    <div className="flex items-center justify-between p-3 bg-green-50 border border-green-200 rounded-lg">
                                        <div className="flex items-center gap-2">
                                            <span className="text-green-600 font-semibold text-sm">Connected</span>
                                            <span className="text-gray-500 text-sm">·</span>
                                            <span className="text-sm text-gray-600">{googleApiConnection.account_name}</span>
                                        </div>
                                        <Link
                                            href={route('google-api.show')}
                                            className="text-xs text-gray-500 hover:text-gray-700 underline underline-offset-2"
                                        >
                                            Manage
                                        </Link>
                                    </div>
                                ) : (
                                    <Link
                                        href={route('google-api.show')}
                                        className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gray-900 rounded-lg hover:bg-gray-800 transition-colors"
                                    >
                                        Connect Google APIs
                                    </Link>
                                )}
                            </div>
                        </section>
                    </div>

                                        <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <section>
                            <header>
                                <h2 className="text-lg font-medium text-gray-900">Create New Customer Account</h2>
                                <p className="mt-1 text-sm text-gray-600">
                                    Add a new customer account to manage campaigns and users. Provide details for proper account setup.
                                </p>
                            </header>
                            <form onSubmit={handleCreateCustomer} className="mt-6 space-y-6 max-w-4xl">
                                {/* Row 1: Name and Business Type */}
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <InputLabel htmlFor="name" value="Customer Name *" />
                                        <TextInput
                                            id="name"
                                            name="name"
                                            type="text"
                                            className="mt-1 block w-full"
                                            value={formData.name}
                                            onChange={handleInputChange}
                                            placeholder="e.g., Acme Corporation"
                                            required
                                        />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="business_type" value="Business Type" />
                                        <select
                                            id="business_type"
                                            name="business_type"
                                            className="mt-1 block w-full border-gray-300 focus:border-flame-orange-500 focus:ring-flame-orange-500 rounded-md shadow-sm"
                                            value={formData.business_type}
                                            onChange={handleInputChange}
                                        >
                                            <option value="">Select a business type...</option>
                                            <option value="ecommerce">E-commerce</option>
                                            <option value="saas">SaaS</option>
                                            <option value="agency">Agency</option>
                                            <option value="retail">Retail</option>
                                            <option value="services">Services</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                </div>

                                {/* Row 2: Description */}
                                <div>
                                    <InputLabel htmlFor="description" value="Business Description" />
                                    <textarea
                                        id="description"
                                        name="description"
                                        className="mt-1 block w-full border-gray-300 focus:border-flame-orange-500 focus:ring-flame-orange-500 rounded-md shadow-sm"
                                        rows="3"
                                        value={formData.description}
                                        onChange={handleInputChange}
                                        placeholder="Brief description of your business..."
                                    />
                                </div>

                                {/* Row 3: Country, Timezone, Currency */}
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div>
                                        <InputLabel htmlFor="country" value="Country" />
                                        <select
                                            id="country"
                                            name="country"
                                            className="mt-1 block w-full border-gray-300 focus:border-flame-orange-500 focus:ring-flame-orange-500 rounded-md shadow-sm"
                                            value={formData.country}
                                            onChange={handleInputChange}
                                        >
                                            <option value="">Select a country...</option>
                                            <option value="US">United States</option>
                                            <option value="CA">Canada</option>
                                            <option value="GB">United Kingdom</option>
                                            <option value="AU">Australia</option>
                                            <option value="NZ">New Zealand</option>
                                            <option value="OTHER">Other</option>
                                        </select>
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="timezone" value="Timezone" />
                                        <select
                                            id="timezone"
                                            name="timezone"
                                            className="mt-1 block w-full border-gray-300 focus:border-flame-orange-500 focus:ring-flame-orange-500 rounded-md shadow-sm"
                                            value={formData.timezone}
                                            onChange={handleInputChange}
                                        >
                                            <option value="America/New_York">Eastern Time (EST/EDT)</option>
                                            <option value="America/Chicago">Central Time (CST/CDT)</option>
                                            <option value="America/Denver">Mountain Time (MST/MDT)</option>
                                            <option value="America/Los_Angeles">Pacific Time (PST/PDT)</option>
                                            <option value="Europe/London">London (GMT/BST)</option>
                                            <option value="Europe/Paris">Central European Time</option>
                                            <option value="Asia/Tokyo">Japan Standard Time</option>
                                            <option value="Australia/Sydney">Sydney (AEDT/AEST)</option>
                                        </select>
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="currency_code" value="Currency" />
                                        <select
                                            id="currency_code"
                                            name="currency_code"
                                            className="mt-1 block w-full border-gray-300 focus:border-flame-orange-500 focus:ring-flame-orange-500 rounded-md shadow-sm"
                                            value={formData.currency_code}
                                            onChange={handleInputChange}
                                        >
                                            <option value="USD">USD - US Dollar</option>
                                            <option value="CAD">CAD - Canadian Dollar</option>
                                            <option value="GBP">GBP - British Pound</option>
                                            <option value="EUR">EUR - Euro</option>
                                            <option value="AUD">AUD - Australian Dollar</option>
                                            <option value="NZD">NZD - New Zealand Dollar</option>
                                            <option value="JPY">JPY - Japanese Yen</option>
                                        </select>
                                    </div>
                                </div>

                                {/* Row 4: Website and Phone */}
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <InputLabel htmlFor="website" value="Website" />
                                        <TextInput
                                            id="website"
                                            name="website"
                                            type="url"
                                            className="mt-1 block w-full"
                                            value={formData.website}
                                            onChange={handleInputChange}
                                            placeholder="https://example.com"
                                        />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="phone" value="Phone Number" />
                                        <TextInput
                                            id="phone"
                                            name="phone"
                                            type="tel"
                                            className="mt-1 block w-full"
                                            value={formData.phone}
                                            onChange={handleInputChange}
                                            placeholder="+1 (555) 123-4567"
                                        />
                                    </div>
                                </div>

                                <PrimaryButton disabled={isCreating || !formData.name.trim()}>
                                    {isCreating ? 'Creating...' : 'Create Customer'}
                                </PrimaryButton>
                            </form>
                        </section>
                    </div>

                    {customers.map(customer => (
                        <div key={customer.id} className="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                            <section>
                                <header>
                                    <h2 className="text-lg font-medium text-gray-900">Customer Account: {customer.name || 'Unnamed'}</h2>
                                    <p className="mt-1 text-sm text-gray-600">
                                        Your role: <span className="font-semibold">{auth.user.customers.find(c => c.id === customer.id)?.role}</span>
                                    </p>
                                </header>

                                {/* Facebook Ads Integration Section (Business Manager) */}
                                <div className="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                    <h3 className="text-md font-medium text-gray-900 mb-3">Facebook Ads Integration</h3>
                                    {customer.facebook_ads_account_id ? (
                                        <div>
                                            <p className="text-sm text-gray-600 mb-1">
                                                <span className="text-green-600 font-semibold">✓ Connected</span> - Facebook Ads account managed via Business Manager.
                                            </p>
                                            <p className="text-xs text-gray-500">
                                                Account ID: <code className="bg-white px-2 py-1 rounded font-mono">{customer.facebook_ads_account_id}</code>
                                                {customer.facebook_page_name && (
                                                    <> | Page: {customer.facebook_page_name}</>
                                                )}
                                            </p>
                                        </div>
                                    ) : (
                                        <div>
                                            <p className="text-sm text-gray-600">
                                                Facebook Ads is managed via our Business Manager. Contact your account manager to link a Facebook Ads account.
                                            </p>
                                        </div>
                                    )}
                                </div>

                                {/* LinkedIn Ads Integration Section */}
                                <div className="mt-6 p-4 bg-sky-50 border border-sky-200 rounded-lg">
                                    <h3 className="text-md font-medium text-gray-900 mb-3">LinkedIn Ads Integration</h3>
                                    {customer.linkedin_ads_account_id ? (
                                        <div>
                                            <p className="text-sm text-gray-600 mb-2">
                                                <span className="text-green-600 font-semibold">✓ Active</span> - LinkedIn Ads account ID: <code className="bg-white px-2 py-1 rounded text-xs font-mono">{customer.linkedin_ads_account_id}</code>
                                            </p>
                                        </div>
                                    ) : (
                                        <div>
                                            <p className="text-sm text-gray-600">
                                                LinkedIn Ads is managed via our organization account. Contact your account manager to link a LinkedIn Ads account.
                                            </p>
                                        </div>
                                    )}
                                </div>

                                {/* Microsoft Ads Integration Section */}
                                <div className="mt-6 p-4 bg-teal-50 border border-teal-200 rounded-lg">
                                    <h3 className="text-md font-medium text-gray-900 mb-3">Microsoft Ads Integration</h3>
                                    {customer.microsoft_ads_account_id ? (
                                        <div>
                                            <p className="text-sm text-gray-600 mb-2">
                                                <span className="text-green-600 font-semibold">✓ Active</span> - Microsoft Ads account ID: <code className="bg-white px-2 py-1 rounded text-xs font-mono">{customer.microsoft_ads_account_id}</code>
                                            </p>
                                        </div>
                                    ) : (
                                        <div>
                                            <p className="text-sm text-gray-600">
                                                Microsoft Ads is managed via our management account. Contact your account manager to link a Microsoft Ads account.
                                            </p>
                                        </div>
                                    )}
                                </div>

                                {auth.user.customers.find(c => c.id === customer.id)?.role === 'owner' && (
                                    <div className="mt-6">
                                        <h3 className="text-md font-medium text-gray-900">Users on this Account</h3>
                                        <ul className="mt-2 divide-y divide-gray-200">
                                            {customer.users.map(user => (
                                                <li key={user.id} className="py-4 flex justify-between items-center">
                                                    <div>
                                                        <p className="text-sm font-medium text-gray-900">{user.name}</p>
                                                        <p className="text-sm text-gray-500">{user.email}</p>
                                                    </div>
                                                    <div className="text-sm text-gray-500">
                                                        {user.pivot.role}
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                        <div className="mt-6">
                                            <h3 className="text-md font-medium text-gray-900">Invite New User</h3>
                                            <form onSubmit={(e) => {
                                                e.preventDefault();
                                                const form = new FormData(e.target);
                                                const email = form.get('email');
                                                const role = form.get('role');
                                                router.post(route('invitations.store', customer.id), { email, role });
                                            }} className="mt-4 flex items-center gap-4">
                                                <div className="flex-1">
                                                    <InputLabel htmlFor="email" value="Email" className="sr-only" />
                                                    <TextInput id="email" name="email" type="email" className="block w-full" placeholder="user@example.com" />
                                                </div>
                                                <div className="flex-1">
                                                    <InputLabel htmlFor="role" value="Role" className="sr-only" />
                                                    <select id="role" name="role" className="block w-full border-gray-300 focus:border-flame-orange-500 focus:ring-flame-orange-500 rounded-md shadow-sm">
                                                        <option>biller</option>
                                                        <option>marketing</option>
                                                    </select>
                                                </div>
                                                <PrimaryButton>Send Invitation</PrimaryButton>
                                            </form>
                                        </div>
                                    </div>
                                )}
                            </section>
                        </div>
                    ))}

                    <div className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <DeleteUserForm className="max-w-xl" />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}