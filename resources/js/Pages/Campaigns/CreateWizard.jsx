import React, { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage, router } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import ProgressStepper from '@/Components/ProgressStepper';
import ProductSelection from './ProductSelection';
import KeywordSelector from '@/Components/KeywordSelector';
import { useTenant } from '@/hooks/useTenant';

// Campaign Templates for quick start
const CAMPAIGN_TEMPLATES = [
    {
        id: 'property-listing',
        name: 'Property Listing',
        icon: '🏠',
        description: 'Drive buyer enquiries for a property',
        verticals: ['real_estate'],
        prefill: {
            reason: 'Promoting a residential property listing to generate qualified buyer enquiries and inspection bookings.',
            goals: 'Drive enquiry form submissions, phone calls, and inspection bookings from serious buyers in the local area.',
            primary_kpi: 'Cost per Enquiry under $80, minimum 5 enquiries per week',
            target_market: 'Home buyers actively searching for properties in the local suburb and surrounding areas, aged 25-60, with household income suitable for the property price range.',
            exclusions: 'Renters, property investors seeking commercial property, real estate students, competitors.',
        }
    },
    {
        id: 'seller-leads',
        name: 'Seller Lead Generation',
        icon: '🏡',
        description: 'Win new property listings from sellers',
        verticals: ['real_estate'],
        prefill: {
            reason: 'Generating appraisal requests and listing opportunities from homeowners considering selling.',
            goals: 'Drive free appraisal requests, grow listing pipeline, build brand recognition among homeowners in target suburbs.',
            primary_kpi: 'Cost per Appraisal Request under $120',
            target_market: 'Homeowners in target suburbs aged 35-65 who may be considering selling in the next 6-12 months.',
            exclusions: 'Renters, first home buyers, commercial property owners.',
        }
    },
    {
        id: 'product-launch',
        name: 'Product Launch',
        icon: '🚀',
        description: 'Launch a new product or service',
        prefill: {
            reason: 'Launching a new product/service to the market and need to generate awareness and initial sales.',
            goals: 'Generate awareness, drive traffic to product page, achieve initial sales targets.',
            primary_kpi: '4x ROAS or $25 CPA',
        }
    },
    {
        id: 'seasonal-sale',
        name: 'Seasonal Sale',
        icon: '🎁',
        description: 'Promote a limited-time offer',
        prefill: {
            reason: 'Running a seasonal promotion to boost sales and clear inventory.',
            goals: 'Maximize conversions during the promotional period, increase average order value.',
            primary_kpi: '5x ROAS',
        }
    },
    {
        id: 'brand-awareness',
        name: 'Brand Awareness',
        icon: '📢',
        description: 'Increase brand recognition',
        prefill: {
            reason: 'Building brand awareness and recognition in our target market.',
            goals: 'Reach new audiences, increase brand recall, grow social following.',
            primary_kpi: 'Reach 100,000 people, CPM under $10',
        }
    },
    {
        id: 'lead-generation',
        name: 'Lead Generation',
        icon: '📧',
        description: 'Capture qualified leads',
        prefill: {
            reason: 'Generating qualified leads for our sales team.',
            goals: 'Capture contact information, qualify leads, nurture toward conversion.',
            primary_kpi: '$15 Cost per Lead',
        }
    },
    {
        id: 'blank',
        name: 'Start Fresh',
        icon: '✨',
        description: 'Create from scratch',
        prefill: {}
    }
];

// Wizard Steps Configuration
const WIZARD_STEPS = [
    {
        id: 'method',
        title: 'Choose Method',
        description: 'How to create'
    },
    {
        id: 'basics',
        title: 'Campaign Basics',
        description: 'Name & objectives'
    },
    {
        id: 'platforms',
        title: 'Platforms',
        description: 'Where to advertise'
    },
    {
        id: 'audience',
        title: 'Target Audience',
        description: 'Who to reach'
    },
    {
        id: 'budget',
        title: 'Budget & Schedule',
        description: 'Investment & timing'
    },
    {
        id: 'products',
        title: 'Product Focus',
        description: 'What to promote'
    },
    {
        id: 'keywords',
        title: 'Keywords',
        description: 'Search terms'
    },
    {
        id: 'assets',
        title: 'Images & Videos',
        description: 'Creative assets'
    },
    {
        id: 'review',
        title: 'Review & Create',
        description: 'Final check'
    }
];

// TextArea Component
const TextArea = ({ className = '', ...props }) => (
    <textarea
        {...props}
        className={`border-gray-300 focus:border-flame-orange-500 focus:ring-flame-orange-500 rounded-md shadow-sm w-full ${className}`}
        rows={3}
    />
);

// Tooltip Component
const Tooltip = ({ children, text }) => (
    <div className="group relative inline-block">
        {children}
        <div className="invisible group-hover:visible absolute z-10 w-64 p-2 mt-1 text-sm text-white bg-gray-900 rounded-lg shadow-lg -left-1/2 transform">
            {text}
        </div>
    </div>
);

// Help Text Component
const HelpText = ({ text }) => (
    <p className="mt-1 text-sm text-gray-500">{text}</p>
);

// Pre-flight Banner Component
const PreflightBanner = ({ brandGuideline, pages, selectablePlatforms, configuredPlatforms, allowedPlatforms }) => {
    const [dismissed, setDismissed] = useState(false);
    if (dismissed) return null;

    const warnings = [];

    if (!brandGuideline) {
        warnings.push({
            key: 'brand',
            message: "Your brand hasn't been extracted yet.",
            action: 'Extract brand guidelines',
            href: '/brand-guidelines',
        });
    }

    if (!pages || pages.length === 0) {
        warnings.push({
            key: 'pages',
            message: 'No website pages have been crawled.',
            action: 'Add pages to your knowledge base',
            href: '/knowledge-base',
        });
    }

    if (selectablePlatforms.length === 0) {
        warnings.push({
            key: 'platforms',
            message: 'No ad platform sub-accounts are set up yet. Contact us to get your account configured.',
            action: null,
            href: null,
        });
    }

    if (warnings.length === 0) return null;

    return (
        <div className="mb-6 bg-amber-50 border border-amber-200 rounded-lg p-4">
            <div className="flex items-start justify-between">
                <div className="flex items-start gap-3">
                    <svg className="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                    </svg>
                    <div>
                        <p className="text-sm font-semibold text-amber-800 mb-1">
                            A few things will help your campaign perform better:
                        </p>
                        <ul className="space-y-1">
                            {warnings.map(w => (
                                <li key={w.key} className="text-sm text-amber-700 flex items-center gap-2">
                                    <span className="w-1.5 h-1.5 rounded-full bg-amber-400 flex-shrink-0" />
                                    {w.message}
                                    {w.action && w.href && (
                                        <>
                                            {' '}
                                            <a href={w.href} className="font-medium underline hover:text-amber-900">
                                                {w.action} →
                                            </a>
                                        </>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <p className="text-xs text-amber-600 mt-2">You can still create the campaign — these just improve AI output quality.</p>
                    </div>
                </div>
                <button
                    type="button"
                    onClick={() => setDismissed(true)}
                    className="text-amber-400 hover:text-amber-600 flex-shrink-0 ml-4"
                    aria-label="Dismiss"
                >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    );
};

export default function CreateWizard({ auth, pages = [], brandGuideline, selectablePlatforms = [], allowedPlatforms = [], configuredPlatforms = [] }) {
    const tenant = useTenant();
    const tenantVertical = tenant?.vertical ?? null;

    // Show vertical-specific templates first, then generic ones
    const visibleTemplates = [
        ...CAMPAIGN_TEMPLATES.filter(t => t.verticals?.includes(tenantVertical)),
        ...CAMPAIGN_TEMPLATES.filter(t => !t.verticals),
    ];

    const [currentStep, setCurrentStep] = useState(0);
    const [selectedTemplate, setSelectedTemplate] = useState(null);
    const [creationMode, setCreationMode] = useState(null); // 'template'
    const customerId = auth.user?.active_customer?.id;
    
    // Build initial form values from brand guidelines if available
    const brandDefaults = brandGuideline ? {
        target_market: brandGuideline.target_audience
            ? [brandGuideline.target_audience.primary, brandGuideline.target_audience.demographics, brandGuideline.target_audience.psychographics].filter(Boolean).join('. ')
            : '',
        voice: brandGuideline.brand_voice?.description || (brandGuideline.tone_attributes?.length
            ? brandGuideline.tone_attributes.join(', ')
            : ''),
        product_focus: brandGuideline.unique_selling_propositions?.length
            ? brandGuideline.unique_selling_propositions.join(', ')
            : '',
        exclusions: brandGuideline.do_not_use?.length
            ? brandGuideline.do_not_use.join(', ')
            : '',
    } : {};

    // stagedImages: array of { file: File, isSeed: boolean }
    const [stagedImages, setStagedImages] = useState([]);
    const [stagedVideos, setStagedVideos] = useState([]);

    const form = useForm({
        name: '',
        reason: '',
        goals: '',
        target_market: brandDefaults.target_market || '',
        voice: brandDefaults.voice || '',
        total_budget: '',
        start_date: '',
        end_date: '',
        primary_kpi: '',
        product_focus: brandDefaults.product_focus || '',
        exclusions: brandDefaults.exclusions || '',
        selected_pages: [],
        landing_page_url: '',
        keywords: [],
        platforms: selectablePlatforms,
    });

    const { data, setData, post, processing, errors, reset } = form;

    // Auto-save draft to localStorage
    useEffect(() => {
        const savedDraft = localStorage.getItem('campaign_draft');
        if (savedDraft && currentStep > 0) {
            const draft = JSON.parse(savedDraft);
            // Only restore if no data has been entered yet
            if (!data.name) {
                Object.keys(draft).forEach(key => {
                    if (draft[key]) setData(key, draft[key]);
                });
            }
        }
    }, []);
    
    useEffect(() => {
        if (currentStep > 0) {
            localStorage.setItem('campaign_draft', JSON.stringify(data));
        }
    }, [data, currentStep]);
    
    const applyTemplate = (template) => {
        setSelectedTemplate(template.id);
        setCreationMode('template');
        if (template.prefill) {
            Object.keys(template.prefill).forEach(key => {
                setData(key, template.prefill[key]);
            });
        }
        setCurrentStep(1);
    };

    const validateStep = (step) => {
        switch (step) {
            case 1: // Basics
                return data.name && data.reason && data.goals;
            case 2: // Platforms
                return data.platforms && data.platforms.length > 0;
            case 3: // Audience
                return data.target_market && data.voice;
            case 4: // Budget
                return data.total_budget && data.start_date && data.end_date && data.primary_kpi;
            case 5: // Products
                return true; // Optional step
            case 6: // Keywords
                return true; // Optional step
            case 7: // Assets
                return true; // Optional step
            case 8: // Review
                return true;
            default:
                return true;
        }
    };
    
    const nextStep = () => {
        if (validateStep(currentStep)) {
            setCurrentStep(prev => Math.min(prev + 1, WIZARD_STEPS.length - 1));
        }
    };
    
    const prevStep = () => {
        if (currentStep === 1) {
            // Going back from step 1 to method selection
            setCreationMode(null);
            setCurrentStep(0);
        } else {
            setCurrentStep(prev => Math.max(prev - 1, 0));
        }
    };
    
    const goToStep = (index) => {
        // Only allow going back or to current step
        if (index <= currentStep) {
            setCurrentStep(index);
        }
    };
    
    const submit = (e) => {
        e.preventDefault();
        localStorage.removeItem('campaign_draft');
        form.transform((d) => ({
            ...d,
            images: stagedImages.filter(s => !s.isSeed).map(s => s.file),
            seed_images: stagedImages.filter(s => s.isSeed).map(s => s.file),
            videos: stagedVideos,
        }));
        post(route('campaigns.store'), {
            onError: () => {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            },
        });
    };
    
    // Calculate default dates
    const getDefaultDates = () => {
        const start = new Date();
        start.setDate(start.getDate() + 1);
        const end = new Date(start);
        end.setDate(end.getDate() + 30);
        return {
            start: start.toISOString().split('T')[0],
            end: end.toISOString().split('T')[0]
        };
    };
    
    // Set default dates if not set
    useEffect(() => {
        if (currentStep === 3 && !data.start_date && !data.end_date) {
            const dates = getDefaultDates();
            setData(prev => ({ ...prev, start_date: dates.start, end_date: dates.end }));
        }
    }, [currentStep]);
    
    // Render Step Content
    const renderStepContent = () => {
        switch (currentStep) {
            case 0: // Method Selection
                // Method selection screen
                return (
                    <div className="space-y-8">
                        <div className="text-center mb-8">
                            <h2 className="text-2xl font-bold text-gray-900">Choose a template to get started</h2>
                            <p className="mt-2 text-gray-600">Pick a starting point below — you can customize everything in the next steps.</p>
                        </div>

                        {/* Template Options */}
                        {tenantVertical && visibleTemplates.some(t => t.verticals?.includes(tenantVertical)) && (
                            <p className="text-xs font-semibold text-brand-primary uppercase tracking-wider -mb-2">
                                Recommended for your vertical
                            </p>
                        )}
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            {visibleTemplates.map((template) => (
                                <button
                                    key={template.id}
                                    onClick={() => applyTemplate(template)}
                                    className={`
                                        p-6 rounded-lg border-2 text-left transition-all duration-200
                                        hover:border-brand-primary hover:shadow-lg
                                        ${selectedTemplate === template.id
                                            ? 'border-brand-primary bg-brand-primary/5'
                                            : 'border-gray-200 bg-white'
                                        }
                                    `}
                                >
                                    <span className="text-3xl mb-3 block">{template.icon}</span>
                                    <h3 className="font-semibold text-gray-900">{template.name}</h3>
                                    <p className="text-sm text-gray-500 mt-1">{template.description}</p>
                                    {template.verticals?.includes(tenantVertical) && (
                                        <span className="mt-3 inline-block text-xs font-medium text-brand-primary bg-brand-primary/10 px-2 py-0.5 rounded-full">
                                            Recommended
                                        </span>
                                    )}
                                </button>
                            ))}
                        </div>
                    </div>
                );
                
            case 1: // Basics
                return (
                    <div className="space-y-6 max-w-2xl mx-auto">
                        <div>
                            <InputLabel htmlFor="name" value="Campaign Name" />
                            <HelpText text="A memorable name to identify this campaign" />
                            <TextInput 
                                id="name" 
                                className="mt-2 block w-full" 
                                value={data.name} 
                                onChange={(e) => setData('name', e.target.value)} 
                                placeholder="e.g., Summer Sale 2025"
                                required 
                            />
                            <InputError message={errors.name} className="mt-2" />
                        </div>
                        
                        <div>
                            <InputLabel htmlFor="reason" value="Why are you running this campaign?" />
                            <HelpText text="Explain the business context and motivation" />
                            <TextArea 
                                id="reason" 
                                className="mt-2" 
                                value={data.reason} 
                                onChange={(e) => setData('reason', e.target.value)} 
                                placeholder="We're launching a new product line and want to generate initial buzz and sales..."
                                required 
                            />
                            <InputError message={errors.reason} className="mt-2" />
                        </div>
                        
                        <div>
                            <InputLabel htmlFor="goals" value="What are your primary goals?" />
                            <HelpText text="Be specific about what success looks like" />
                            <TextArea 
                                id="goals" 
                                className="mt-2" 
                                value={data.goals} 
                                onChange={(e) => setData('goals', e.target.value)} 
                                placeholder="Increase website traffic by 50%, generate 100 qualified leads, achieve 4x ROAS..."
                                required 
                            />
                            <InputError message={errors.goals} className="mt-2" />
                        </div>
                    </div>
                );
                
            case 2: // Platforms
                return (
                    <div className="space-y-6 max-w-2xl mx-auto">
                        <div className="text-center mb-4">
                            <h3 className="text-lg font-semibold text-gray-900">Where do you want to advertise?</h3>
                            <p className="text-sm text-gray-500 mt-1">Select the platforms to run this campaign on. Only platforms configured for your account are available.</p>
                        </div>

                        {selectablePlatforms.length === 0 && (
                            <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-sm text-yellow-800">
                                <strong>No platforms available.</strong> Please contact your admin to set up ad platform accounts for your business.
                            </div>
                        )}

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            {[
                                { id: 'google', name: 'Google Ads', icon: '🔍', desc: 'Search, Display, YouTube, Shopping, Performance Max' },
                                { id: 'facebook', name: 'Facebook Ads', icon: '📘', desc: 'Facebook & Instagram feeds, stories, reels' },
                                { id: 'microsoft', name: 'Microsoft Ads', icon: '🪟', desc: 'Bing Search, Microsoft Audience Network' },
                                { id: 'linkedin', name: 'LinkedIn Ads', icon: '💼', desc: 'Sponsored content, InMail, lead gen forms' },
                            ].map(platform => {
                                const isSelectable = selectablePlatforms.includes(platform.id);
                                const isSelected = data.platforms.includes(platform.id);
                                const isAllowed = allowedPlatforms.includes(platform.id);
                                const isConfigured = configuredPlatforms.includes(platform.id);

                                let disabledReason = null;
                                if (!isAllowed) disabledReason = 'Upgrade your plan to unlock';
                                else if (!isConfigured) disabledReason = 'Contact admin to set up';
                                else if (!isSelectable) disabledReason = 'Not available';

                                return (
                                    <button
                                        key={platform.id}
                                        type="button"
                                        disabled={!isSelectable}
                                        onClick={() => {
                                            if (!isSelectable) return;
                                            const updated = isSelected
                                                ? data.platforms.filter(p => p !== platform.id)
                                                : [...data.platforms, platform.id];
                                            setData('platforms', updated);
                                        }}
                                        className={`relative flex flex-col items-start p-5 rounded-xl border-2 text-left transition-all ${
                                            isSelected
                                                ? 'border-flame-orange-500 bg-flame-orange-50 ring-2 ring-flame-orange-200'
                                                : isSelectable
                                                    ? 'border-gray-200 bg-white hover:border-gray-300 hover:shadow-sm'
                                                    : 'border-gray-100 bg-gray-50 opacity-60 cursor-not-allowed'
                                        }`}
                                    >
                                        {isSelected && (
                                            <div className="absolute top-3 right-3">
                                                <svg className="w-5 h-5 text-flame-orange-600" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                                </svg>
                                            </div>
                                        )}
                                        <span className="text-2xl mb-2">{platform.icon}</span>
                                        <span className="font-semibold text-gray-900">{platform.name}</span>
                                        <span className="text-xs text-gray-500 mt-1">{platform.desc}</span>
                                        {disabledReason && (
                                            <span className="text-xs text-yellow-600 mt-2 font-medium">{disabledReason}</span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>

                        {data.platforms.length > 0 && (
                            <div className="bg-green-50 border border-green-200 rounded-lg p-4">
                                <p className="text-sm text-green-700">
                                    <strong>{data.platforms.length} platform{data.platforms.length > 1 ? 's' : ''} selected</strong> — strategies will be generated for each.
                                </p>
                            </div>
                        )}

                        <InputError message={errors.platforms} className="mt-2" />
                    </div>
                );

            case 3: // Audience
                return (
                    <div className="space-y-6 max-w-2xl mx-auto">
                        <div>
                            <InputLabel htmlFor="target_market" value="Who is your target audience?" />
                            <HelpText text="Describe demographics, interests, behaviors, and pain points" />
                            <TextArea 
                                id="target_market" 
                                className="mt-2" 
                                value={data.target_market} 
                                onChange={(e) => setData('target_market', e.target.value)} 
                                placeholder="Small business owners aged 30-55 who are struggling with manual inventory management and looking for automation solutions..."
                                rows={4}
                                required 
                            />
                            <InputError message={errors.target_market} className="mt-2" />
                        </div>
                        
                        <div>
                            <InputLabel htmlFor="voice" value="Brand Voice / Tone" />
                            <HelpText text="How should your ads sound? Professional, friendly, urgent, etc." />
                            <TextInput 
                                id="voice" 
                                className="mt-2 block w-full" 
                                value={data.voice} 
                                onChange={(e) => setData('voice', e.target.value)} 
                                placeholder="Professional yet approachable, confident, solution-focused"
                                required 
                            />
                            <InputError message={errors.voice} className="mt-2" />
                        </div>
                        
                        <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <div className="flex">
                                <svg className="w-5 h-5 text-blue-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                                </svg>
                                <p className="text-sm text-blue-700">
                                    <strong>Tip:</strong> Your brand guidelines will be automatically applied to ensure consistency. 
                                    <a href="/brand-guidelines" className="underline ml-1">Review them here</a>
                                </p>
                            </div>
                        </div>
                    </div>
                );
                
            case 4: // Budget & Schedule
                return (
                    <div className="space-y-6 max-w-2xl mx-auto">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <InputLabel htmlFor="total_budget" value="Total Budget ($)" />
                                <HelpText text="Total amount to spend over the campaign period" />
                                <TextInput 
                                    id="total_budget" 
                                    type="number" 
                                    className="mt-2 block w-full" 
                                    value={data.total_budget} 
                                    onChange={(e) => setData('total_budget', e.target.value)} 
                                    placeholder="1000"
                                    min="100"
                                    required 
                                />
                                <InputError message={errors.total_budget} className="mt-2" />
                            </div>
                            
                            <div>
                                <InputLabel htmlFor="primary_kpi" value="Primary KPI / Target" />
                                <HelpText text="How will you measure success?" />
                                <TextInput 
                                    id="primary_kpi" 
                                    className="mt-2 block w-full" 
                                    value={data.primary_kpi} 
                                    onChange={(e) => setData('primary_kpi', e.target.value)} 
                                    placeholder="4x ROAS or $25 CPA"
                                    required 
                                />
                                <InputError message={errors.primary_kpi} className="mt-2" />
                            </div>
                            
                            <div>
                                <InputLabel htmlFor="start_date" value="Start Date" />
                                <TextInput 
                                    id="start_date" 
                                    type="date" 
                                    className="mt-2 block w-full" 
                                    value={data.start_date} 
                                    onChange={(e) => setData('start_date', e.target.value)} 
                                    min={new Date().toISOString().split('T')[0]}
                                    required 
                                />
                                <InputError message={errors.start_date} className="mt-2" />
                            </div>
                            
                            <div>
                                <InputLabel htmlFor="end_date" value="End Date" />
                                <TextInput 
                                    id="end_date" 
                                    type="date" 
                                    className="mt-2 block w-full" 
                                    value={data.end_date} 
                                    onChange={(e) => setData('end_date', e.target.value)}
                                    min={data.start_date || new Date().toISOString().split('T')[0]}
                                    required 
                                />
                                <InputError message={errors.end_date} className="mt-2" />
                            </div>
                        </div>
                        
                        {data.total_budget && data.start_date && data.end_date && (
                            <div className="bg-green-50 border border-green-200 rounded-lg p-4">
                                <p className="text-sm text-green-700">
                                    <strong>Daily Budget:</strong> ~$
                                    {(data.total_budget / Math.max(1, Math.ceil((new Date(data.end_date) - new Date(data.start_date)) / (1000 * 60 * 60 * 24)))).toFixed(2)}
                                    /day over {Math.ceil((new Date(data.end_date) - new Date(data.start_date)) / (1000 * 60 * 60 * 24))} days
                                </p>
                            </div>
                        )}
                    </div>
                );
                
            case 5: // Products
                return (
                    <div className="space-y-6 max-w-2xl mx-auto">
                        <div>
                            <InputLabel htmlFor="product_focus" value="Product/Service Focus (Optional)" />
                            <HelpText text="Describe specific products or services to highlight" />
                            <TextArea 
                                id="product_focus" 
                                className="mt-2" 
                                value={data.product_focus} 
                                onChange={(e) => setData('product_focus', e.target.value)}
                                placeholder="Our flagship inventory management software, starting at $99/month..."
                                rows={3}
                            />
                            <InputError message={errors.product_focus} className="mt-2" />
                        </div>
                        
                        {customerId && (
                            <div>
                                <InputLabel value="Select Landing Pages" />
                                <HelpText text="Choose product pages from your website to promote" />
                                <div className="mt-2">
                                    <ProductSelection
                                        customerId={customerId}
                                        selectedPages={data.selected_pages || []}
                                        onSelectionChange={(page) => {
                                            setData('selected_pages', page ? [page.id] : []);
                                            setData('landing_page_url', page?.url || '');
                                        }}
                                    />
                                </div>
                            </div>
                        )}
                        
                        <div>
                            <InputLabel htmlFor="exclusions" value="Exclusions / What to Avoid (Optional)" />
                            <HelpText text="Any topics, competitors, or approaches to avoid" />
                            <TextArea 
                                id="exclusions" 
                                className="mt-2" 
                                value={data.exclusions} 
                                onChange={(e) => setData('exclusions', e.target.value)}
                                placeholder="Don't mention competitor X, avoid price comparisons, no urgency language..."
                                rows={2}
                            />
                            <InputError message={errors.exclusions} className="mt-2" />
                        </div>
                    </div>
                );
                
            case 6: // Keywords
                return (
                    <div className="space-y-6 max-w-3xl mx-auto">
                        <div className="mb-4">
                            <h3 className="text-lg font-semibold text-gray-900">Keywords (Optional)</h3>
                            <p className="text-sm text-gray-500 mt-1">
                                Research and select keywords for your campaign, or skip this step and let our AI choose them automatically.
                            </p>
                        </div>
                        <KeywordSelector
                            value={data.keywords}
                            onChange={(keywords) => setData('keywords', keywords)}
                            landingPage={data.landing_page_url || ''}
                        />
                    </div>
                );

            case 7: // Assets
                return (
                    <div className="space-y-8 max-w-3xl mx-auto">
                        <div className="mb-4">
                            <h3 className="text-lg font-semibold text-gray-900">Images & Videos (Optional)</h3>
                            <p className="text-sm text-gray-500 mt-1">
                                Upload your own creative assets and they'll be included when we deploy your ads. You can also add more after the campaign is created.
                            </p>
                        </div>

                        {/* Image Upload */}
                        <div>
                            <div className="flex items-center justify-between mb-1">
                                <div>
                                    <h4 className="text-sm font-semibold text-gray-800">Images</h4>
                                    <p className="text-xs text-gray-500">JPEG, PNG, or WebP · Max 10MB each · Up to 10 images</p>
                                </div>
                                <span className="text-xs text-gray-400">{stagedImages.length}/10</span>
                            </div>
                            <p className="text-xs text-gray-400 mb-3">
                                Toggle <span className="font-medium text-brand-primary">AI Seed</span> on any image to use it as a visual reference when generating new ad creatives.
                            </p>

                            {stagedImages.length > 0 && (
                                <div className="grid grid-cols-3 sm:grid-cols-4 gap-3 mb-3">
                                    {stagedImages.map((item, i) => (
                                        <div
                                            key={i}
                                            className={`relative group aspect-square rounded-lg overflow-hidden border-2 bg-gray-50 transition-colors ${
                                                item.isSeed ? 'border-brand-primary' : 'border-gray-200'
                                            }`}
                                        >
                                            <img
                                                src={URL.createObjectURL(item.file)}
                                                alt={item.file.name}
                                                className="w-full h-full object-cover"
                                            />
                                            {/* Seed badge */}
                                            <button
                                                type="button"
                                                title={item.isSeed ? 'Remove as AI seed' : 'Use as AI seed'}
                                                onClick={() => setStagedImages(prev =>
                                                    prev.map((s, idx) => idx === i ? { ...s, isSeed: !s.isSeed } : s)
                                                )}
                                                className={`absolute bottom-1 left-1 text-xs font-semibold px-1.5 py-0.5 rounded transition-colors ${
                                                    item.isSeed
                                                        ? 'bg-brand-primary text-white'
                                                        : 'bg-black/50 text-white opacity-0 group-hover:opacity-100'
                                                }`}
                                            >
                                                {item.isSeed ? '✦ Seed' : 'Seed?'}
                                            </button>
                                            {/* Remove button */}
                                            <button
                                                type="button"
                                                onClick={() => setStagedImages(prev => prev.filter((_, idx) => idx !== i))}
                                                className="absolute top-1 right-1 bg-red-500 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs opacity-0 group-hover:opacity-100 transition-opacity"
                                            >
                                                ×
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {stagedImages.length < 10 && (
                                <label className="flex flex-col items-center justify-center w-full h-32 border-2 border-dashed border-gray-300 rounded-lg cursor-pointer bg-gray-50 hover:bg-gray-100 hover:border-brand-primary transition-colors">
                                    <div className="flex flex-col items-center justify-center pt-5 pb-6">
                                        <svg className="w-8 h-8 mb-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <p className="text-sm text-gray-500">Click to upload images</p>
                                    </div>
                                    <input
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        multiple
                                        className="hidden"
                                        onChange={(e) => {
                                            const files = Array.from(e.target.files || []);
                                            setStagedImages(prev => {
                                                const incoming = files.map(f => ({ file: f, isSeed: false }));
                                                return [...prev, ...incoming].slice(0, 10);
                                            });
                                            e.target.value = '';
                                        }}
                                    />
                                </label>
                            )}
                        </div>

                        {/* Video Upload */}
                        <div>
                            <div className="flex items-center justify-between mb-3">
                                <div>
                                    <h4 className="text-sm font-semibold text-gray-800">Videos</h4>
                                    <p className="text-xs text-gray-500">MP4, MOV, or WebM · Max 100MB each · Up to 3 videos</p>
                                </div>
                                <span className="text-xs text-gray-400">{stagedVideos.length}/3</span>
                            </div>

                            {stagedVideos.length > 0 && (
                                <div className="space-y-2 mb-3">
                                    {stagedVideos.map((file, i) => (
                                        <div key={i} className="flex items-center gap-3 p-3 bg-gray-50 rounded-lg border border-gray-200">
                                            <svg className="w-8 h-8 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15 10l4.553-2.069A1 1 0 0121 8.87v6.26a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                            </svg>
                                            <div className="flex-1 min-w-0">
                                                <p className="text-sm font-medium text-gray-900 truncate">{file.name}</p>
                                                <p className="text-xs text-gray-500">{(file.size / 1024 / 1024).toFixed(1)} MB</p>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => setStagedVideos(prev => prev.filter((_, idx) => idx !== i))}
                                                className="text-red-400 hover:text-red-600 flex-shrink-0"
                                            >
                                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {stagedVideos.length < 3 && (
                                <label className="flex flex-col items-center justify-center w-full h-24 border-2 border-dashed border-gray-300 rounded-lg cursor-pointer bg-gray-50 hover:bg-gray-100 hover:border-brand-primary transition-colors">
                                    <div className="flex items-center gap-2">
                                        <svg className="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15 10l4.553-2.069A1 1 0 0121 8.87v6.26a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                        </svg>
                                        <p className="text-sm text-gray-500">Click to upload a video</p>
                                    </div>
                                    <input
                                        type="file"
                                        accept="video/mp4,video/quicktime,video/webm"
                                        className="hidden"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0];
                                            if (file) {
                                                setStagedVideos(prev => [...prev, file].slice(0, 3));
                                            }
                                            e.target.value = '';
                                        }}
                                    />
                                </label>
                            )}
                        </div>

                        <p className="text-xs text-gray-400 text-center">
                            Skipping this step is fine — our AI will generate creative assets for you automatically.
                        </p>
                    </div>
                );

            case 8: // Review
                return (
                    <div className="space-y-6 max-w-3xl mx-auto">
                        <div className="text-center mb-8">
                            <h2 className="text-2xl font-bold text-gray-900">Review Your Campaign</h2>
                            <p className="mt-2 text-gray-600">Make sure everything looks good before generating your strategy</p>
                        </div>
                        
                        <div className="bg-white rounded-lg border border-gray-200 divide-y">
                            <ReviewSection title="Campaign Basics" step={1} onEdit={() => goToStep(1)}>
                                <ReviewItem label="Name" value={data.name} />
                                <ReviewItem label="Reason" value={data.reason} />
                                <ReviewItem label="Goals" value={data.goals} />
                            </ReviewSection>

                            <ReviewSection title="Platforms" step={2} onEdit={() => goToStep(2)}>
                                <ReviewItem label="Selected Platforms" value={data.platforms.map(p => p.charAt(0).toUpperCase() + p.slice(1)).join(', ') || 'None'} />
                            </ReviewSection>
                            
                            <ReviewSection title="Target Audience" step={3} onEdit={() => goToStep(3)}>
                                <ReviewItem label="Target Market" value={data.target_market} />
                                <ReviewItem label="Brand Voice" value={data.voice} />
                            </ReviewSection>
                            
                            <ReviewSection title="Budget & Schedule" step={4} onEdit={() => goToStep(4)}>
                                <ReviewItem label="Total Budget" value={`$${data.total_budget}`} />
                                <ReviewItem label="Primary KPI" value={data.primary_kpi} />
                                <ReviewItem label="Duration" value={`${data.start_date} to ${data.end_date}`} />
                            </ReviewSection>
                            
                            <ReviewSection title="Product Focus" step={5} onEdit={() => goToStep(5)}>
                                <ReviewItem label="Product Focus" value={data.product_focus || 'Not specified'} />
                                <ReviewItem label="Selected Pages" value={data.selected_pages?.length ? `${data.selected_pages.length} pages selected` : 'None'} />
                                <ReviewItem label="Exclusions" value={data.exclusions || 'None'} />
                            </ReviewSection>
                            
                            <ReviewSection title="Keywords" step={6} onEdit={() => goToStep(6)}>
                                <ReviewItem label="Keywords" value={data.keywords?.length ? `${data.keywords.length} keywords selected` : 'None (AI will choose)'} />
                                {data.keywords?.length > 0 && (
                                    <div className="flex flex-wrap gap-1 mt-1">
                                        {data.keywords.slice(0, 10).map((kw, i) => (
                                            <span key={i} className="text-xs bg-gray-100 border border-gray-200 rounded px-2 py-0.5 text-gray-700">{kw.text}</span>
                                        ))}
                                        {data.keywords.length > 10 && <span className="text-xs text-gray-400">+{data.keywords.length - 10} more</span>}
                                    </div>
                                )}
                            </ReviewSection>

                            <ReviewSection title="Images & Videos" step={7} onEdit={() => goToStep(7)}>
                                {(() => {
                                    const seedCount = stagedImages.filter(s => s.isSeed).length;
                                    const regularCount = stagedImages.filter(s => !s.isSeed).length;
                                    let imageLabel = 'None (AI will generate)';
                                    if (stagedImages.length > 0) {
                                        const parts = [];
                                        if (regularCount > 0) parts.push(`${regularCount} uploaded`);
                                        if (seedCount > 0) parts.push(`${seedCount} as AI seed`);
                                        imageLabel = parts.join(', ');
                                    }
                                    return <ReviewItem label="Images" value={imageLabel} />;
                                })()}
                                <ReviewItem label="Videos" value={stagedVideos.length > 0 ? `${stagedVideos.length} video${stagedVideos.length !== 1 ? 's' : ''} uploaded` : 'None (AI will generate)'} />
                            </ReviewSection>
                        </div>
                        
                        {Object.keys(errors).length > 0 && (
                            <div className="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                                <h4 className="text-sm font-semibold text-red-800 mb-2">Please fix the following errors:</h4>
                                <ul className="list-disc list-inside text-sm text-red-700 space-y-1">
                                    {Object.entries(errors).map(([field, message]) => (
                                        <li key={field}><strong>{field.replace(/_/g, ' ')}:</strong> {message}</li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <div className="bg-flame-orange-50 border border-flame-orange-200 rounded-lg p-4">
                            <div className="flex">
                                <svg className="w-5 h-5 text-flame-orange-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M11 3a1 1 0 10-2 0v1a1 1 0 102 0V3zM15.657 5.757a1 1 0 00-1.414-1.414l-.707.707a1 1 0 001.414 1.414l.707-.707zM18 10a1 1 0 01-1 1h-1a1 1 0 110-2h1a1 1 0 011 1zM5.05 6.464A1 1 0 106.464 5.05l-.707-.707a1 1 0 00-1.414 1.414l.707.707zM5 10a1 1 0 01-1 1H3a1 1 0 110-2h1a1 1 0 011 1zM8 16v-1h4v1a2 2 0 11-4 0zM12 14c.015-.34.208-.646.477-.859a4 4 0 10-4.954 0c.27.213.462.519.476.859h4.002z" />
                                </svg>
                                <div>
                                    <p className="text-sm text-flame-orange-700">
                                        <strong>What happens next?</strong>
                                    </p>
                                    <p className="text-sm text-flame-orange-600 mt-1">
                                        Our AI will analyze your inputs along with your knowledge base and brand guidelines to generate 
                                        platform-specific advertising strategies. This usually takes 1-2 minutes.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                );
                
            default:
                return null;
        }
    };
    
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                    Create New Campaign
                </h2>
            }
        >
            <Head title="Create Campaign" />
            
            <div className="py-8">
                <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Progress Stepper */}
                    {currentStep > 0 && (
                        <ProgressStepper 
                            steps={WIZARD_STEPS.slice(1)} 
                            currentStep={currentStep - 1}
                            onStepClick={(index) => goToStep(index + 1)}
                            allowNavigation={true}
                        />
                    )}
                    
                    {/* Pre-flight Warnings */}
                    {currentStep === 0 && (
                        <PreflightBanner
                            brandGuideline={brandGuideline}
                            pages={pages}
                            selectablePlatforms={selectablePlatforms}
                            configuredPlatforms={configuredPlatforms}
                            allowedPlatforms={allowedPlatforms}
                        />
                    )}

                    {/* Step Content */}
                    <div className="bg-white rounded-lg shadow-md p-6 sm:p-8">
                        <form onSubmit={submit}>
                            {renderStepContent()}
                            
                            {/* Navigation Buttons */}
                            {currentStep > 0 && (
                                <div className="flex justify-between mt-8 pt-6 border-t">
                                    <SecondaryButton 
                                        type="button"
                                        onClick={prevStep}
                                        disabled={processing}
                                    >
                                        ← Back
                                    </SecondaryButton>
                                    
                                    {currentStep < WIZARD_STEPS.length - 1 ? (
                                        <PrimaryButton 
                                            type="button"
                                            onClick={nextStep}
                                            disabled={!validateStep(currentStep)}
                                            className="bg-flame-orange-600 hover:bg-flame-orange-700"
                                        >
                                            Continue →
                                        </PrimaryButton>
                                    ) : (
                                        <PrimaryButton 
                                            type="submit"
                                            disabled={processing}
                                            className="bg-green-600 hover:bg-green-700"
                                        >
                                            {processing ? 'Creating...' : '🚀 Generate Strategy'}
                                        </PrimaryButton>
                                    )}
                                </div>
                            )}
                        </form>
                    </div>
                    
                    {/* Draft Saved Indicator */}
                    {currentStep > 0 && (
                        <p className="text-center text-sm text-gray-400 mt-4">
                            Draft auto-saved • {new Date().toLocaleTimeString()}
                        </p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

// Helper Components
const ReviewSection = ({ title, step, onEdit, children }) => (
    <div className="p-4">
        <div className="flex justify-between items-center mb-3">
            <h3 className="font-semibold text-gray-900">{title}</h3>
            <button 
                type="button"
                onClick={onEdit}
                className="text-sm text-flame-orange-600 hover:text-flame-orange-800"
            >
                Edit
            </button>
        </div>
        <div className="space-y-2">
            {children}
        </div>
    </div>
);

const ReviewItem = ({ label, value }) => (
    <div className="flex">
        <span className="text-sm text-gray-500 w-32 flex-shrink-0">{label}:</span>
        <span className="text-sm text-gray-900">{value || '-'}</span>
    </div>
);
