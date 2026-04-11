import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import SideNav from './SideNav';
import { useState } from 'react';

const TABS = ['Google Ads', 'Facebook Ads', 'Microsoft Ads', 'LinkedIn Ads'];

function TabButton({ label, active, onClick }) {
    return (
        <button
            onClick={onClick}
            className={`px-5 py-3 text-sm font-medium rounded-t-lg transition border-b-2 ${
                active
                    ? 'border-flame-orange-500 text-flame-orange-700 bg-white'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
            }`}
        >
            {label}
        </button>
    );
}

function Section({ title, children }) {
    return (
        <div className="mb-8">
            <h3 className="text-lg font-semibold text-gray-900 mb-3 flex items-center gap-2">
                <span className="w-1.5 h-1.5 rounded-full bg-flame-orange-500" />
                {title}
            </h3>
            <div className="text-sm text-gray-700 leading-relaxed space-y-3">{children}</div>
        </div>
    );
}

function Step({ number, title, children }) {
    return (
        <div className="flex gap-4 py-3">
            <div className="flex-shrink-0 w-8 h-8 rounded-full bg-flame-orange-100 text-flame-orange-700 flex items-center justify-center text-sm font-bold">
                {number}
            </div>
            <div className="flex-1">
                <p className="font-medium text-gray-900 mb-1">{title}</p>
                <div className="text-sm text-gray-600 space-y-2">{children}</div>
            </div>
        </div>
    );
}

function InfoBox({ type = 'info', children }) {
    const styles = {
        info: 'bg-blue-50 border-blue-200 text-blue-800',
        warning: 'bg-yellow-50 border-yellow-200 text-yellow-800',
        important: 'bg-red-50 border-red-200 text-red-800',
    };
    const icons = {
        info: 'ℹ️',
        warning: '⚠️',
        important: '🚨',
    };
    return (
        <div className={`rounded-lg border p-4 text-sm ${styles[type]}`}>
            <span className="mr-2">{icons[type]}</span>
            {children}
        </div>
    );
}

// ─── Google Ads Tab ─────────────────────────────────────────────
function GoogleAdsGuide() {
    return (
        <div className="space-y-6">
            <Section title="Overview">
                <p>
                    Google Ads uses a <strong>Manager Account (MCC)</strong> owned by Spectra. All customer ad accounts are
                    created as sub-accounts under this MCC. The system handles authentication automatically —
                    you just need to create the sub-account and assign the ID.
                </p>
            </Section>

            <Section title="Setup Steps">
                <Step number={1} title="Create a sub-account in Google Ads MCC">
                    <p>Log in to the Spectra MCC at <a href="https://ads.google.com" target="_blank" rel="noopener" className="text-flame-orange-600 underline">ads.google.com</a>.</p>
                    <p>Navigate to <strong>Accounts → Performance → + (New Account)</strong> and create a new sub-account for the customer.</p>
                    <p>Note the new account's <strong>Customer ID</strong> (format: <code>xxx-xxx-xxxx</code>).</p>
                </Step>

                <Step number={2} title="Configure the sub-account">
                    <p>Set up billing on the sub-account (or use consolidated billing through the MCC).</p>
                    <p>Set the timezone and currency for the customer's market.</p>
                </Step>

                <Step number={3} title="Assign the account ID in Spectra">
                    <p>Navigate to <strong>Admin → Customers → [Customer Name]</strong>.</p>
                    <p>In the Google Ads section, enter the Customer ID (with or without dashes — the system strips them automatically).</p>
                    <p>The Manager ID will default to the active MCC if left blank.</p>
                    <p>Click <strong>Save</strong>.</p>
                </Step>

                <Step number={4} title="Verify the connection">
                    <p>Go back to the customer's dashboard. The system should now be able to fetch campaign data and deploy to this account.</p>
                </Step>
            </Section>


        </div>
    );
}

// ─── Facebook Ads Tab ───────────────────────────────────────────
function FacebookAdsGuide() {
    return (
        <div className="space-y-6">
            <Section title="Overview">
                <p>
                    Facebook Ads uses a <strong>Business Manager</strong> owned by Spectra. Ad accounts are created manually
                    inside Business Manager and then assigned to the customer in Spectra. Authentication is handled automatically.
                </p>
            </Section>

            <Section title="Setup Steps">
                <Step number={1} title="Create an ad account in Business Manager">
                    <p>Log in to <a href="https://business.facebook.com" target="_blank" rel="noopener" className="text-flame-orange-600 underline">business.facebook.com</a>.</p>
                    <p>Navigate to <strong>Business Settings → Accounts → Ad Accounts → + Add → Create a new ad account</strong>.</p>
                    <p>Name the account after the customer (e.g., "Acme Corp - Search").</p>
                    <p>Set the timezone and currency.</p>
                </Step>

                <Step number={2} title="Assign the System User to the ad account">
                    <p>In Business Settings, go to <strong>Users → System Users</strong>.</p>
                    <p>Select the Spectra System User.</p>
                    <p>Click <strong>Assign Assets → Ad Accounts</strong> and select the newly created ad account.</p>
                    <p>Grant <strong>Full Control (Admin)</strong> permissions.</p>
                </Step>

                <Step number={3} title="Copy the ad account ID">
                    <p>The account ID is the numeric ID shown next to the account name (e.g., <code>123456789012345</code>).</p>
                    <p>Do <strong>not</strong> include the <code>act_</code> prefix — the system adds that automatically when making API calls.</p>
                </Step>

                <Step number={4} title="Assign the account in Spectra">
                    <p>Navigate to <strong>Admin → Customers → [Customer Name]</strong>.</p>
                    <p>In the Facebook Ads section, enter the numeric account ID.</p>
                    <p>The system will automatically verify access. A green checkmark will appear once verified.</p>
                </Step>

                <Step number={5} title="Set up billing on the ad account">
                    <p>Add a payment method to the ad account in Business Manager (credit card or invoicing).</p>
                    <p>Alternatively, use Business Manager consolidated billing.</p>
                </Step>
            </Section>


        </div>
    );
}

// ─── Microsoft Ads Tab ──────────────────────────────────────────
function MicrosoftAdsGuide() {
    return (
        <div className="space-y-6">
            <Section title="Overview">
                <p>
                    Microsoft Ads uses a <strong>Manager Account</strong> owned by Spectra. Customer ad accounts are created
                    under this manager account. Authentication is handled automatically — you just need to create the account
                    and assign the IDs.
                </p>
                <InfoBox type="info">
                    Microsoft requires two IDs per customer: a <strong>Customer ID</strong> (top-level) and an <strong>Account ID</strong> (the specific ad account). Both are needed.
                </InfoBox>
            </Section>

            <Section title="Per-Customer Setup">
                <Step number={1} title="Create or link a customer account">
                    <p>In the Microsoft Advertising UI, navigate to your Manager Account.</p>
                    <p>Create a new account under your manager, or request an existing customer to link their account to your manager.</p>
                    <p>You will need two IDs:</p>
                    <ul className="list-disc list-inside ml-2">
                        <li><strong>Customer ID</strong> — the top-level customer identifier</li>
                        <li><strong>Account ID</strong> — the specific ad account within that customer</li>
                    </ul>
                    <p className="text-xs text-gray-500 mt-1">Both are visible in Microsoft Advertising under Account Settings. The Customer ID is also shown in the URL.</p>
                </Step>

                <Step number={2} title="Assign the IDs in Spectra">
                    <p>Navigate to <strong>Admin → Customers → [Customer Name]</strong>.</p>
                    <p>In the Microsoft Ads section, enter both the <strong>Customer ID</strong> and <strong>Account ID</strong>.</p>
                    <p>Click <strong>Save</strong>.</p>
                </Step>

                <Step number={3} title="Verify the connection">
                    <p>Go to the customer's dashboard. The system should now be able to fetch campaign data from Microsoft Ads.</p>
                </Step>
            </Section>


        </div>
    );
}

// ─── LinkedIn Ads Tab ───────────────────────────────────────────
function LinkedInAdsGuide() {
    return (
        <div className="space-y-6">
            <Section title="Overview">
                <p>
                    LinkedIn Ads uses an <strong>Organization-level account</strong> owned by Spectra. Customer ad accounts
                    are managed through LinkedIn Campaign Manager. Authentication is handled automatically —
                    you just need to assign the ad account ID.
                </p>
            </Section>

            <Section title="Per-Customer Setup">
                <Step number={1} title="Create or access the LinkedIn ad account">
                    <p>In LinkedIn Campaign Manager, create a new ad account under Spectra's organization, or request access to the customer's existing account.</p>
                    <p>Note the <strong>Ad Account ID</strong> (visible in the Campaign Manager URL).</p>
                </Step>

                <Step number={2} title="Assign the account in Spectra">
                    <p>Navigate to <strong>Admin → Customers → [Customer Name]</strong>.</p>
                    <p>Enter the LinkedIn Ad Account ID.</p>
                    <p>Click <strong>Save</strong>.</p>
                </Step>
            </Section>


        </div>
    );
}

// ═══════════════════════════════════════════════════════════════
// Main Page
// ═══════════════════════════════════════════════════════════════
export default function PlatformSetupGuide() {
    const [activeTab, setActiveTab] = useState(0);

    const panels = [GoogleAdsGuide, FacebookAdsGuide, MicrosoftAdsGuide, LinkedInAdsGuide];
    const ActivePanel = panels[activeTab];

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Admin — Platform Setup Guide
                </h2>
            }
        >
            <Head title="Platform Setup Guide" />

            <div className="flex">
                <SideNav />
                <div className="flex-1 p-8">
                    <div className="max-w-4xl mx-auto">
                        {/* Header */}
                        <div className="mb-8">
                            <h1 className="text-2xl font-bold text-gray-900">Platform Setup Guide</h1>
                            <p className="text-sm text-gray-500 mt-1">
                                How to create and assign ad platform accounts for customers. All platforms use the management account pattern —
                                Spectra owns the credentials, customers only get account IDs.
                            </p>
                        </div>

                        {/* Architecture Summary */}
                        <div className="bg-gradient-to-r from-delft-blue-50 to-air-superiority-blue-50 rounded-xl border border-delft-blue-200 p-6 mb-8">
                            <h2 className="text-base font-semibold text-delft-blue-900 mb-3">Architecture Overview</h2>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                <div className="flex items-start gap-3">
                                    <span className="text-lg">🔑</span>
                                    <div>
                                        <p className="font-medium text-delft-blue-900">Single Credential Per Platform</p>
                                        <p className="text-delft-blue-700">One management-level credential per platform handles all API calls automatically.</p>
                                    </div>
                                </div>
                                <div className="flex items-start gap-3">
                                    <span className="text-lg">🏢</span>
                                    <div>
                                        <p className="font-medium text-delft-blue-900">Sub-Accounts Under Spectra</p>
                                        <p className="text-delft-blue-700">Customer ad accounts are created inside Spectra's manager account on each platform.</p>
                                    </div>
                                </div>
                                <div className="flex items-start gap-3">
                                    <span className="text-lg">🔗</span>
                                    <div>
                                        <p className="font-medium text-delft-blue-900">ID-Only on Customer</p>
                                        <p className="text-delft-blue-700">Only account IDs are stored on the Customer model — never tokens or secrets.</p>
                                    </div>
                                </div>
                                <div className="flex items-start gap-3">
                                    <span className="text-lg">🚫</span>
                                    <div>
                                        <p className="font-medium text-delft-blue-900">No Customer OAuth</p>
                                        <p className="text-delft-blue-700">There are no "Connect your account" buttons. Admins assign account IDs manually.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Tabs */}
                        <div className="border-b border-gray-200 mb-6">
                            <div className="flex gap-1">
                                {TABS.map((tab, i) => (
                                    <TabButton key={tab} label={tab} active={activeTab === i} onClick={() => setActiveTab(i)} />
                                ))}
                            </div>
                        </div>

                        {/* Active Tab Content */}
                        <ActivePanel />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
