import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import ProductSelection from './ProductSelection';

// A simple component for a form section with a title and description
const FormSection = ({ title, description, children }) => (
    <div className="p-4 sm:p-8 bg-mint-cream shadow sm:rounded-lg">
        <div className="max-w-xl">
            <h2 className="text-lg font-medium text-delft-blue">{title}</h2>
            <p className="mt-1 text-sm text-jet">{description}</p>
        </div>
        <div className="mt-6 space-y-6">{children}</div>
    </div>
);

// A reusable component for a textarea input
const TextArea = (props) => (
    <textarea
        {...props}
        className="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full"
    />
);

export default function Create({ auth }) {
    const isLocalhost = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';

    const prefillData = {
        name: 'Localhost Test Campaign',
        reason: 'This is a test campaign created on localhost for development purposes.',
        goals: 'To test the campaign creation flow and strategy generation.',
        target_market: 'Developers and testers working on the cvseeyou project.',
        voice: 'Informative and technical.',
        total_budget: 1000,
        start_date: '2025-11-12',
        end_date: '2025-12-12',
        primary_kpi: 'Successful strategy generation.',
        product_focus: 'cvseeyou platform features.',
        exclusions: 'Avoid real client data.',
    };

    const { data, setData, post, processing, errors } = useForm(isLocalhost ? prefillData : {
        name: '',
        reason: '',
        goals: '',
        target_market: '',
        voice: '',
        total_budget: '',
        start_date: '',
        end_date: '',
        primary_kpi: '',
        product_focus: '',
        exclusions: '',
        selected_pages: [],
    });

    // Get the current customer ID from the auth user (assuming single customer context for now)
    // In a real multi-tenant app, this might come from a route param or a selector.
    const customerId = auth.user?.active_customer?.id; // Ensure your User model appends this or it's available via relationship

    const submit = (e) => {
        e.preventDefault();
        post(route('campaigns.store'));
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-jet leading-tight">Create a New Campaign</h2>}
        >
            <Head title="New Campaign" />

            <div className="py-12">
                <form onSubmit={submit} className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <FormSection
                        title="Core Campaign Brief"
                        description="Start with the high-level details. What is this campaign about and why are you running it?"
                    >
                        <div>
                            <InputLabel htmlFor="name" value="Campaign Name" />
                            <TextInput id="name" className="mt-1 block w-full" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                            <InputError message={errors.name} className="mt-2" />
                        </div>
                        <div>
                            <InputLabel htmlFor="reason" value="Reason for Campaign" />
                            <TextArea id="reason" className="mt-1 block w-full" value={data.reason} onChange={(e) => setData('reason', e.target.value)} required />
                            <InputError message={errors.reason} className="mt-2" />
                        </div>
                        <div>
                            <InputLabel htmlFor="goals" value="Primary Goals" />
                            <TextArea id="goals" className="mt-1 block w-full" value={data.goals} onChange={(e) => setData('goals', e.target.value)} required />
                            <InputError message={errors.goals} className="mt-2" />
                        </div>
                    </FormSection>

                    <FormSection
                        title="Audience & Messaging"
                        description="Describe who you're targeting and the tone you want to use."
                    >
                        <div>
                            <InputLabel htmlFor="target_market" value="Target Market" />
                            <TextArea id="target_market" className="mt-1 block w-full" value={data.target_market} onChange={(e) => setData('target_market', e.target.value)} required />
                            <InputError message={errors.target_market} className="mt-2" />
                        </div>
                        <div>
                            <InputLabel htmlFor="voice" value="Brand Voice" />
                            <TextInput id="voice" className="mt-1 block w-full" value={data.voice} onChange={(e) => setData('voice', e.target.value)} required />
                            <InputError message={errors.voice} className="mt-2" />
                        </div>
                    </FormSection>

                    <FormSection
                        title="Constraints & Specifics"
                        description="Provide the key constraints like budget, duration, and KPIs to guide the strategy."
                    >
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <InputLabel htmlFor="total_budget" value="Total Budget ($)" />
                                <TextInput id="total_budget" type="number" className="mt-1 block w-full" value={data.total_budget} onChange={(e) => setData('total_budget', e.target.value)} required />
                                <InputError message={errors.total_budget} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel htmlFor="primary_kpi" value="Primary KPI" />
                                <TextInput id="primary_kpi" className="mt-1 block w-full" value={data.primary_kpi} onChange={(e) => setData('primary_kpi', e.target.value)} required placeholder="e.g., 4x ROAS or $25 CPA" />
                                <InputError message={errors.primary_kpi} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel htmlFor="start_date" value="Start Date" />
                                <TextInput id="start_date" type="date" className="mt-1 block w-full" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} required />
                                <InputError message={errors.start_date} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel htmlFor="end_date" value="End Date" />
                                <TextInput id="end_date" type="date" className="mt-1 block w-full" value={data.end_date} onChange={(e) => setData('end_date', e.target.value)} required />
                                <InputError message={errors.end_date} className="mt-2" />
                            </div>
                        </div>
                        <div>
                            <InputLabel htmlFor="product_focus" value="Product/Service Focus (Optional)" />
                            <TextArea id="product_focus" className="mt-1 block w-full" value={data.product_focus} onChange={(e) => setData('product_focus', e.target.value)} />
                            <InputError message={errors.product_focus} className="mt-2" />
                        </div>
                        
                        {/* Product Selection Component */}
                        {customerId && (
                            <div className="col-span-1 md:col-span-2">
                                <ProductSelection 
                                    customerId={customerId}
                                    selectedPages={data.selected_pages || []}
                                    onSelectionChange={(pages) => setData('selected_pages', pages)}
                                />
                            </div>
                        )}

                        <div>
                            <InputLabel htmlFor="exclusions" value="Exclusions / What to Avoid (Optional)" />
                            <TextArea id="exclusions" className="mt-1 block w-full" value={data.exclusions} onChange={(e) => setData('exclusions', e.target.value)} />
                            <InputError message={errors.exclusions} className="mt-2" />
                        </div>
                    </FormSection>

                    <div className="flex items-center justify-end mt-6 p-4 sm:p-8 bg-mint-cream shadow sm:rounded-lg">
                        <PrimaryButton className="bg-delft-blue hover:bg-air-superiority-blue text-white" disabled={processing}>
                            Generate Strategy
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
