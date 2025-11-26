import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage, router } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';
import ConnectedAccountsForm from './Partials/ConnectedAccountsForm';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import PrimaryButton from '@/Components/PrimaryButton';
import DangerButton from '@/Components/DangerButton';
import FacebookPageSelector from '@/Components/FacebookPageSelector';
import { useState, useEffect } from 'react';

export default function Edit({ auth, mustVerifyEmail, status, facebookAppId, connections = [] }) {
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
    const [showPageSelector, setShowPageSelector] = useState(false);
    const [tokenStatus, setTokenStatus] = useState(null);

    // Check if we need to show page selector (from URL param)
    useEffect(() => {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('select_facebook_page') === 'true') {
            setShowPageSelector(true);
            // Clean up URL
            window.history.replaceState({}, '', window.location.pathname);
        }
    }, []);

    // Fetch token status for connected customers
    useEffect(() => {
        const fetchTokenStatus = async () => {
            try {
                const response = await fetch('/facebook/token-status', {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                if (response.ok) {
                    const data = await response.json();
                    setTokenStatus(data);
                }
            } catch (err) {
                console.error('Failed to fetch token status:', err);
            }
        };

        if (customers.some(c => c.facebook_ads_account_id)) {
            fetchTokenStatus();
        }
    }, [customers]);

    useEffect(() => {
        if (!facebookAppId) return;

        window.fbAsyncInit = function() {
            window.FB.init({
                appId      : facebookAppId,
                cookie     : true,
                xfbml      : true,
                version    : 'v19.0'
            });
            window.FB.AppEvents.logPageView();
        };

        (function(d, s, id){
            var js, fjs = d.getElementsByTagName(s)[0];
            if (d.getElementById(id)) {return;}
            js = d.createElement(s); js.id = id;
            js.src = "https://connect.facebook.net/en_US/sdk.js";
            fjs.parentNode.insertBefore(js, fjs);
        }(document, 'script', 'facebook-jssdk'));
    }, [facebookAppId]);

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
                    {/* Connected Accounts - Most Important for email/password users */}
                    <div id="connections" className="bg-white p-4 shadow sm:rounded-lg sm:p-8">
                        <ConnectedAccountsForm connections={connections} />
                    </div>

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
                                            className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
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
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
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
                                            className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
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
                                            className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
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
                                            className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
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

                                {/* Facebook Integration Section */}
                                <div className="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                                    <h3 className="text-md font-medium text-gray-900 mb-3">Facebook Ads Integration</h3>
                                    {customer.facebook_ads_account_id ? (
                                        <div>
                                            <p className="text-sm text-gray-600 mb-2">
                                                <span className="text-green-600 font-semibold">✓ Connected</span> - Facebook Ads account ID: <code className="bg-white px-2 py-1 rounded text-xs font-mono">{customer.facebook_ads_account_id}</code>
                                            </p>
                                            
                                            {/* Facebook Page Info */}
                                            {customer.facebook_page_id ? (
                                                <p className="text-sm text-gray-600 mb-2">
                                                    <span className="text-green-600 font-semibold">✓ Page:</span> {customer.facebook_page_name || customer.facebook_page_id}
                                                    <button 
                                                        type="button"
                                                        onClick={() => setShowPageSelector(true)}
                                                        className="ml-2 text-blue-600 hover:text-blue-800 text-xs underline"
                                                    >
                                                        Change
                                                    </button>
                                                </p>
                                            ) : (
                                                <p className="text-sm text-yellow-600 mb-2">
                                                    ⚠ No Facebook Page selected.
                                                    <button 
                                                        type="button"
                                                        onClick={() => setShowPageSelector(true)}
                                                        className="ml-2 text-blue-600 hover:text-blue-800 underline"
                                                    >
                                                        Select a Page
                                                    </button>
                                                </p>
                                            )}
                                            
                                            {/* Token Status Warning */}
                                            {tokenStatus && tokenStatus.needs_refresh && (
                                                <div className="mb-3 p-2 bg-yellow-100 border border-yellow-300 rounded text-sm text-yellow-800">
                                                    ⚠ Your Facebook connection expires in {tokenStatus.expires_in_days} days.
                                                    <a href={route('facebook-ads.redirect')} className="ml-2 text-blue-600 hover:text-blue-800 underline">
                                                        Reconnect now
                                                    </a>
                                                </div>
                                            )}
                                            
                                            {tokenStatus && !tokenStatus.valid && (
                                                <div className="mb-3 p-2 bg-red-100 border border-red-300 rounded text-sm text-red-800">
                                                    ✕ Your Facebook connection has expired.
                                                    <a href={route('facebook-ads.redirect')} className="ml-2 text-blue-600 hover:text-blue-800 underline font-semibold">
                                                        Reconnect now
                                                    </a>
                                                </div>
                                            )}
                                            
                                            <form 
                                                onSubmit={(e) => {
                                                    e.preventDefault();
                                                    router.post(route('facebook-ads.disconnect'), {});
                                                }}
                                                className="inline"
                                            >
                                                <DangerButton type="submit">
                                                    Disconnect Facebook
                                                </DangerButton>
                                            </form>
                                        </div>
                                    ) : (
                                        <div>
                                            <p className="text-sm text-gray-600 mb-3">
                                                Connect your Facebook account to manage ads through cvseeyou.
                                            </p>
                                            <a href={route('facebook-ads.redirect')}>
                                                <button 
                                                    type="button"
                                                    className="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 active:bg-blue-800"
                                                >
                                                    Connect Facebook Account
                                                </button>
                                            </a>
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
                                                    <select id="role" name="role" className="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
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
            
            {/* Facebook Page Selector Modal */}
            <FacebookPageSelector
                isOpen={showPageSelector}
                onClose={() => setShowPageSelector(false)}
                onSelect={(page) => {
                    // Refresh the page to show updated data
                    router.reload({ only: ['customers'] });
                }}
            />
        </AuthenticatedLayout>
    );
}
