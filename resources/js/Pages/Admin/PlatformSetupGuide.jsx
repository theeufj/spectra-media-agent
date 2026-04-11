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

function CodeBlock({ children }) {
    return (
        <pre className="bg-gray-900 text-green-400 rounded-lg p-4 text-xs overflow-x-auto font-mono">
            {children}
        </pre>
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

function FieldRef({ name }) {
    return <code className="bg-gray-100 text-flame-orange-700 px-1.5 py-0.5 rounded text-xs font-mono">{name}</code>;
}

// ─── Google Ads Tab ─────────────────────────────────────────────
function GoogleAdsGuide() {
    return (
        <div className="space-y-6">
            <Section title="Overview">
                <p>
                    Google Ads uses a <strong>Manager Account (MCC)</strong> owned by Spectra. All customer ad accounts are
                    created as sub-accounts under this MCC. A single refresh token stored in the environment (or the <code>mcc_accounts</code> database table)
                    authenticates all API calls. The customer's sub-account ID is passed per-request.
                </p>
                <InfoBox type="important">
                    Never store Google Ads access tokens or refresh tokens on a Customer record.
                    The only fields stored on Customer are identifiers: <FieldRef name="google_ads_customer_id" /> and optionally <FieldRef name="google_ads_manager_customer_id" />.
                </InfoBox>
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

            <Section title="Environment Variables">
                <CodeBlock>{`# .env — Google Ads MCC Credentials
GOOGLE_ADS_MCC_CUSTOMER_ID=1234567890
GOOGLE_ADS_MCC_REFRESH_TOKEN=1//0abc...your_token_here

# Optional: also stored in mcc_accounts table (encrypted)
# The DB record takes precedence over .env if it exists`}</CodeBlock>
            </Section>

            <Section title="Customer Model Fields">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm border border-gray-200 rounded-lg overflow-hidden">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="px-4 py-2 text-left font-medium text-gray-600">Field</th>
                                <th className="px-4 py-2 text-left font-medium text-gray-600">Description</th>
                                <th className="px-4 py-2 text-left font-medium text-gray-600">Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr className="border-t"><td className="px-4 py-2 font-mono text-xs">google_ads_customer_id</td><td className="px-4 py-2">Sub-account customer ID</td><td className="px-4 py-2"><code>1234567890</code></td></tr>
                            <tr className="border-t bg-gray-50"><td className="px-4 py-2 font-mono text-xs">google_ads_manager_customer_id</td><td className="px-4 py-2">MCC ID (optional, defaults to config)</td><td className="px-4 py-2"><code>9876543210</code></td></tr>
                            <tr className="border-t"><td className="px-4 py-2 font-mono text-xs">google_ads_customer_is_manager</td><td className="px-4 py-2">Whether the account is itself a manager</td><td className="px-4 py-2"><code>false</code></td></tr>
                        </tbody>
                    </table>
                </div>
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
                    Facebook Ads uses a <strong>Business Manager</strong> owned by Spectra with a <strong>System User</strong> token.
                    Ad accounts are created manually inside Business Manager and then assigned to the customer in Spectra.
                    The System User token never expires (it's a long-lived token generated in Business Manager settings).
                </p>
                <InfoBox type="important">
                    Never store Facebook access tokens or refresh tokens on a Customer record.
                    The only fields stored on Customer are: <FieldRef name="facebook_ads_account_id" />, <FieldRef name="facebook_bm_owned" />,
                    and optionally <FieldRef name="facebook_page_id" /> / <FieldRef name="facebook_page_name" />.
                </InfoBox>
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
                    <p>Select the Spectra System User (or create one if it doesn't exist — see "System User Setup" below).</p>
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
                    <p>The system will automatically verify that the System User has access via the Graph API.</p>
                    <p>Once verified, <FieldRef name="facebook_bm_owned" /> is set to <code>true</code>.</p>
                </Step>

                <Step number={5} title="Set up billing on the ad account">
                    <p>Add a payment method to the ad account in Business Manager (credit card or invoicing).</p>
                    <p>Alternatively, use Business Manager consolidated billing.</p>
                </Step>
            </Section>

            <Section title="System User Setup (One-Time)">
                <InfoBox type="info">
                    This only needs to be done once when first setting up Facebook integration.
                </InfoBox>
                <Step number={1} title="Create the System User">
                    <p>In Business Manager → <strong>Business Settings → Users → System Users → Add</strong>.</p>
                    <p>Name it "Spectra API" and set role to <strong>Admin</strong>.</p>
                </Step>
                <Step number={2} title="Generate a token">
                    <p>Click <strong>Generate New Token</strong> on the System User.</p>
                    <p>Select the app (your Facebook App).</p>
                    <p>Grant scopes: <code>ads_management</code>, <code>ads_read</code>, <code>business_management</code>.</p>
                    <p>Copy the token and add it to <code>.env</code> as <code>FACEBOOK_SYSTEM_USER_TOKEN</code>.</p>
                </Step>
                <InfoBox type="warning">
                    System User tokens do not expire. However, they are invalidated if the System User is removed or the app is deleted.
                    Store the token securely.
                </InfoBox>
            </Section>

            <Section title="Environment Variables">
                <CodeBlock>{`# .env — Facebook Ads Credentials
FACEBOOK_APP_ID=123456789
FACEBOOK_APP_SECRET=abc123secret
FACEBOOK_BUSINESS_MANAGER_ID=123456789012345
FACEBOOK_SYSTEM_USER_TOKEN=EAAWBmI...long_token_here
FACEBOOK_PAGE_ID=123456789  # Spectra's Facebook page`}</CodeBlock>
            </Section>

            <Section title="Customer Model Fields">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm border border-gray-200 rounded-lg overflow-hidden">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="px-4 py-2 text-left font-medium text-gray-600">Field</th>
                                <th className="px-4 py-2 text-left font-medium text-gray-600">Description</th>
                                <th className="px-4 py-2 text-left font-medium text-gray-600">Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr className="border-t"><td className="px-4 py-2 font-mono text-xs">facebook_ads_account_id</td><td className="px-4 py-2">Numeric account ID (without <code>act_</code> prefix)</td><td className="px-4 py-2"><code>123456789012345</code></td></tr>
                            <tr className="border-t bg-gray-50"><td className="px-4 py-2 font-mono text-xs">facebook_bm_owned</td><td className="px-4 py-2">Whether account is owned by Spectra BM</td><td className="px-4 py-2"><code>true</code></td></tr>
                            <tr className="border-t"><td className="px-4 py-2 font-mono text-xs">facebook_page_id</td><td className="px-4 py-2">Customer's Facebook page (optional)</td><td className="px-4 py-2"><code>987654321</code></td></tr>
                            <tr className="border-t bg-gray-50"><td className="px-4 py-2 font-mono text-xs">facebook_page_name</td><td className="px-4 py-2">Customer's page name (optional)</td><td className="px-4 py-2"><code>Acme Corp</code></td></tr>
                        </tbody>
                    </table>
                </div>
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
                    Microsoft Ads uses a <strong>Manager Account</strong> owned by Spectra. Authentication is via an Azure AD app registration
                    with a refresh token that is exchanged for a bearer token on each API call. The customer's Customer ID and Account ID are
                    passed as headers on every request.
                </p>
                <InfoBox type="warning">
                    The Microsoft Ads setup is more complex than Google/Facebook because it involves Azure AD app registration,
                    a service principal, and admin consent. The refresh token must be regenerated manually if it expires.
                </InfoBox>
            </Section>

            <Section title="How It Works (Architecture)">
                <p>Unlike Google (which uses an MCC) and Facebook (which uses a Business Manager), Microsoft uses:</p>
                <ul className="list-disc list-inside space-y-1 ml-2">
                    <li><strong>Azure AD App Registration</strong> — an OAuth2 application that can request <code>msads.manage</code> scope</li>
                    <li><strong>Manager Account</strong> — a Microsoft Advertising manager account that owns customer sub-accounts</li>
                    <li><strong>Developer Token</strong> — a separate token issued by Microsoft for API access</li>
                </ul>
                <p className="mt-2">On each API call, the service:</p>
                <ol className="list-decimal list-inside space-y-1 ml-2">
                    <li>Exchanges the refresh token for a short-lived bearer token via Azure OAuth</li>
                    <li>Sends the bearer token + developer token + customer IDs as headers</li>
                    <li>Microsoft routes the request to the correct sub-account</li>
                </ol>
            </Section>

            <Section title="Initial Setup (One-Time)">
                <Step number={1} title="Create the Azure AD service principal">
                    <p>Open <a href="https://portal.azure.com" target="_blank" rel="noopener" className="text-flame-orange-600 underline">Azure Portal</a> Cloud Shell and run:</p>
                    <CodeBlock>az ad sp create --id d42ffc93-c136-491d-b4fd-6f18168c68fd</CodeBlock>
                    <p className="mt-1 text-xs text-gray-500">This registers the Microsoft Advertising service principal in your Azure tenant.</p>
                </Step>

                <Step number={2} title="Grant admin consent">
                    <p>Open this URL in your browser (replace the tenant ID and client ID with yours):</p>
                    <CodeBlock>{`https://login.microsoftonline.com/{tenant_id}/v2.0/adminconsent
  ?client_id={your_app_client_id}
  &state=12345
  &scope=d42ffc93-c136-491d-b4fd-6f18168c68fd/msads.manage`}</CodeBlock>
                    <p>Sign in with the Azure AD admin and approve.</p>
                </Step>

                <Step number={3} title="Generate the refresh token">
                    <p>Run the token generation script:</p>
                    <CodeBlock>php scripts/generate_microsoft_ads_token.php</CodeBlock>
                    <p>This will open a browser for OAuth consent and output a refresh token. Add it to <code>.env</code>.</p>
                </Step>

                <Step number={4} title="Get a Developer Token">
                    <p>Log in to <a href="https://ads.microsoft.com" target="_blank" rel="noopener" className="text-flame-orange-600 underline">Microsoft Advertising</a>.</p>
                    <p>Go to <strong>Tools → Developer Token</strong> and request one for production access.</p>
                    <p>Add it to <code>.env</code> as <code>MICROSOFT_ADS_DEVELOPER_TOKEN</code>.</p>
                </Step>
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

                <Step number={3} title="Test the connection">
                    <p>Run the artisan test command to verify:</p>
                    <CodeBlock>php artisan microsoftads:test</CodeBlock>
                </Step>
            </Section>

            <Section title="Token Refresh">
                <InfoBox type="warning">
                    Microsoft OAuth refresh tokens can expire (typically 90 days of inactivity). If API calls start failing with
                    authentication errors, regenerate the token by running <code>php scripts/generate_microsoft_ads_token.php</code> again
                    and updating the <code>.env</code>.
                </InfoBox>
            </Section>

            <Section title="Environment Variables">
                <CodeBlock>{`# .env — Microsoft Ads Credentials
MICROSOFT_ADS_CLIENT_ID=your_azure_app_id
MICROSOFT_ADS_CLIENT_SECRET=your_azure_app_secret
MICROSOFT_ADS_TENANT_ID=common
MICROSOFT_ADS_DEVELOPER_TOKEN=your_developer_token
MICROSOFT_ADS_REFRESH_TOKEN=M.R3_BAY...token_here
MICROSOFT_ADS_MANAGER_ACCOUNT_ID=123456789
MICROSOFT_ADS_ENVIRONMENT=production`}</CodeBlock>
            </Section>

            <Section title="Customer Model Fields">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm border border-gray-200 rounded-lg overflow-hidden">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="px-4 py-2 text-left font-medium text-gray-600">Field</th>
                                <th className="px-4 py-2 text-left font-medium text-gray-600">Description</th>
                                <th className="px-4 py-2 text-left font-medium text-gray-600">Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr className="border-t"><td className="px-4 py-2 font-mono text-xs">microsoft_ads_customer_id</td><td className="px-4 py-2">Top-level customer ID</td><td className="px-4 py-2"><code>123456789</code></td></tr>
                            <tr className="border-t bg-gray-50"><td className="px-4 py-2 font-mono text-xs">microsoft_ads_account_id</td><td className="px-4 py-2">Ad account ID within the customer</td><td className="px-4 py-2"><code>987654321</code></td></tr>
                        </tbody>
                    </table>
                </div>
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
                    LinkedIn Ads uses an <strong>Organization-level OAuth token</strong> stored in <code>.env</code>.
                    The refresh token is exchanged for a bearer token on each API call. Customer ad account IDs are
                    stored on the Customer model and passed per-request.
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

            <Section title="Environment Variables">
                <CodeBlock>{`# .env — LinkedIn Ads Credentials
LINKEDIN_ADS_CLIENT_ID=your_client_id
LINKEDIN_ADS_CLIENT_SECRET=your_client_secret
LINKEDIN_ADS_REFRESH_TOKEN=AQG...token_here
LINKEDIN_ADS_REDIRECT_URI=https://yourdomain.com/auth/linkedin-ads/callback
LINKEDIN_ADS_API_VERSION=202404`}</CodeBlock>
            </Section>

            <Section title="Customer Model Fields">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm border border-gray-200 rounded-lg overflow-hidden">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="px-4 py-2 text-left font-medium text-gray-600">Field</th>
                                <th className="px-4 py-2 text-left font-medium text-gray-600">Description</th>
                                <th className="px-4 py-2 text-left font-medium text-gray-600">Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr className="border-t"><td className="px-4 py-2 font-mono text-xs">linkedin_ads_account_id</td><td className="px-4 py-2">Ad account URN or ID</td><td className="px-4 py-2"><code>508123456</code></td></tr>
                        </tbody>
                    </table>
                </div>
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
                                        <p className="text-delft-blue-700">One management-level token in <code>.env</code> (or encrypted DB) handles all API calls.</p>
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
