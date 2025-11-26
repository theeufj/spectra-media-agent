import React, { useState, useEffect, useRef } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage, router } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import ProgressStepper from '@/Components/ProgressStepper';
import ProductSelection from './ProductSelection';

// Campaign Templates for quick start
const CAMPAIGN_TEMPLATES = [
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
        id: 'review', 
        title: 'Review & Create',
        description: 'Final check'
    }
];

// TextArea Component
const TextArea = ({ className = '', ...props }) => (
    <textarea
        {...props}
        className={`border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-full ${className}`}
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

export default function CreateWizard({ auth, pages = [] }) {
    const [currentStep, setCurrentStep] = useState(0);
    const [selectedTemplate, setSelectedTemplate] = useState(null);
    const [creationMode, setCreationMode] = useState(null); // 'template' or 'ai'
    const [aiMessages, setAiMessages] = useState([
        {
            role: 'assistant',
            content: "Hi! I'm here to help you create a campaign. Just tell me about your business and what you'd like to achieve, and I'll help you build the perfect campaign.\n\nFor example, you could say:\n• \"I want to promote my new summer collection\"\n• \"I need to generate leads for my consulting business\"\n• \"Help me create a Black Friday sale campaign\"\n\nWhat would you like to promote?"
        }
    ]);
    const [aiInput, setAiInput] = useState('');
    const [aiLoading, setAiLoading] = useState(false);
    const [aiCampaignReady, setAiCampaignReady] = useState(false);
    const chatEndRef = useRef(null);
    const customerId = auth.user?.active_customer?.id;
    
    const { data, setData, post, processing, errors, reset } = useForm({
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
    
    // Auto-scroll chat to bottom
    useEffect(() => {
        chatEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [aiMessages]);
    
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
    
    // Handle AI chat message
    const handleAiSend = async () => {
        if (!aiInput.trim() || aiLoading) return;
        
        const userMessage = aiInput.trim();
        setAiInput('');
        setAiMessages(prev => [...prev, { role: 'user', content: userMessage }]);
        setAiLoading(true);
        
        try {
            const response = await fetch('/api/campaigns/ai-assist', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                body: JSON.stringify({
                    messages: [...aiMessages, { role: 'user', content: userMessage }],
                    current_data: data,
                }),
            });
            
            const result = await response.json();
            
            setAiMessages(prev => [...prev, { role: 'assistant', content: result.message }]);
            
            // If AI extracted campaign data, update the form
            if (result.campaign_data) {
                Object.keys(result.campaign_data).forEach(key => {
                    if (result.campaign_data[key]) {
                        setData(key, result.campaign_data[key]);
                    }
                });
                setAiCampaignReady(true);
            }
        } catch (error) {
            setAiMessages(prev => [...prev, { 
                role: 'assistant', 
                content: "I'm sorry, I encountered an error. Please try again or use the template option instead." 
            }]);
        } finally {
            setAiLoading(false);
        }
    };
    
    // Switch from AI to manual mode with extracted data
    const continueWithAiData = () => {
        setCreationMode('template');
        setCurrentStep(1);
    };
    
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
    
    const startAiMode = () => {
        setCreationMode('ai');
    };
    
    const backToMethodSelection = () => {
        setCreationMode(null);
        setCurrentStep(0);
    };
    
    const validateStep = (step) => {
        switch (step) {
            case 1: // Basics
                return data.name && data.reason && data.goals;
            case 2: // Audience
                return data.target_market && data.voice;
            case 3: // Budget
                return data.total_budget && data.start_date && data.end_date && data.primary_kpi;
            case 4: // Products
                return true; // Optional step
            case 5: // Review
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
        post(route('campaigns.store'));
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
                // If AI mode is active, show chat interface
                if (creationMode === 'ai') {
                    return (
                        <div className="space-y-4 max-w-3xl mx-auto">
                            <div className="flex items-center justify-between mb-4">
                                <button
                                    onClick={backToMethodSelection}
                                    className="flex items-center text-gray-600 hover:text-gray-900"
                                >
                                    <svg className="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                                    </svg>
                                    Back to options
                                </button>
                                <h2 className="text-xl font-semibold text-gray-900 flex items-center">
                                    <span className="text-2xl mr-2">🤖</span>
                                    AI Campaign Builder
                                </h2>
                            </div>
                            
                            {/* Chat Messages */}
                            <div className="bg-gray-50 rounded-lg border border-gray-200 h-96 overflow-y-auto p-4 space-y-4">
                                {aiMessages.map((msg, idx) => (
                                    <div
                                        key={idx}
                                        className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
                                    >
                                        <div
                                            className={`max-w-[80%] rounded-lg px-4 py-2 ${
                                                msg.role === 'user'
                                                    ? 'bg-indigo-600 text-white'
                                                    : 'bg-white border border-gray-200 text-gray-800'
                                            }`}
                                        >
                                            <p className="whitespace-pre-wrap text-sm">{msg.content}</p>
                                        </div>
                                    </div>
                                ))}
                                {aiLoading && (
                                    <div className="flex justify-start">
                                        <div className="bg-white border border-gray-200 rounded-lg px-4 py-2">
                                            <div className="flex space-x-2">
                                                <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce"></div>
                                                <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '0.1s' }}></div>
                                                <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '0.2s' }}></div>
                                            </div>
                                        </div>
                                    </div>
                                )}
                                <div ref={chatEndRef} />
                            </div>
                            
                            {/* AI Ready Banner */}
                            {aiCampaignReady && (
                                <div className="bg-green-50 border border-green-200 rounded-lg p-4 flex items-center justify-between">
                                    <div className="flex items-center">
                                        <span className="text-2xl mr-3">✅</span>
                                        <div>
                                            <p className="font-medium text-green-800">Campaign details captured!</p>
                                            <p className="text-sm text-green-600">Ready to review and customize your campaign</p>
                                        </div>
                                    </div>
                                    <button
                                        onClick={continueWithAiData}
                                        className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium"
                                    >
                                        Continue to Review →
                                    </button>
                                </div>
                            )}
                            
                            {/* Chat Input */}
                            <div className="flex space-x-2">
                                <input
                                    type="text"
                                    value={aiInput}
                                    onChange={(e) => setAiInput(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && handleAiSend()}
                                    placeholder="Describe your campaign..."
                                    className="flex-1 rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                                    disabled={aiLoading}
                                />
                                <button
                                    onClick={handleAiSend}
                                    disabled={aiLoading || !aiInput.trim()}
                                    className="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    Send
                                </button>
                            </div>
                        </div>
                    );
                }
                
                // Default: Method selection screen
                return (
                    <div className="space-y-8">
                        <div className="text-center mb-8">
                            <h2 className="text-2xl font-bold text-gray-900">How would you like to create your campaign?</h2>
                            <p className="mt-2 text-gray-600">Choose AI assistance or start with a template</p>
                        </div>
                        
                        {/* AI Option - Featured */}
                        <div 
                            onClick={startAiMode}
                            className="cursor-pointer bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 rounded-2xl p-1 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1"
                        >
                            <div className="bg-white rounded-xl p-6 flex items-center space-x-6">
                                <div className="flex-shrink-0 w-16 h-16 bg-gradient-to-br from-indigo-100 to-purple-100 rounded-xl flex items-center justify-center">
                                    <span className="text-4xl">🤖</span>
                                </div>
                                <div className="flex-1">
                                    <div className="flex items-center space-x-2">
                                        <h3 className="text-xl font-bold text-gray-900">Create with AI</h3>
                                        <span className="px-2 py-0.5 bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-xs rounded-full font-medium">
                                            Recommended
                                        </span>
                                    </div>
                                    <p className="text-gray-600 mt-1">
                                        Just describe what you want to achieve. Our AI will help you build the perfect campaign through a simple conversation.
                                    </p>
                                </div>
                                <div className="flex-shrink-0">
                                    <svg className="w-8 h-8 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        
                        {/* Divider */}
                        <div className="relative">
                            <div className="absolute inset-0 flex items-center">
                                <div className="w-full border-t border-gray-200"></div>
                            </div>
                            <div className="relative flex justify-center text-sm">
                                <span className="px-4 bg-gray-50 text-gray-500">or choose a template</span>
                            </div>
                        </div>
                        
                        {/* Template Options */}
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            {CAMPAIGN_TEMPLATES.map((template) => (
                                <button
                                    key={template.id}
                                    onClick={() => applyTemplate(template)}
                                    className={`
                                        p-6 rounded-lg border-2 text-left transition-all duration-200
                                        hover:border-indigo-500 hover:shadow-lg
                                        ${selectedTemplate === template.id 
                                            ? 'border-indigo-500 bg-indigo-50' 
                                            : 'border-gray-200 bg-white'
                                        }
                                    `}
                                >
                                    <span className="text-3xl mb-3 block">{template.icon}</span>
                                    <h3 className="font-semibold text-gray-900">{template.name}</h3>
                                    <p className="text-sm text-gray-500 mt-1">{template.description}</p>
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
                
            case 2: // Audience
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
                
            case 3: // Budget & Schedule
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
                
            case 4: // Products
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
                                        onSelectionChange={(pages) => setData('selected_pages', pages)}
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
                
            case 5: // Review
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
                            
                            <ReviewSection title="Target Audience" step={2} onEdit={() => goToStep(2)}>
                                <ReviewItem label="Target Market" value={data.target_market} />
                                <ReviewItem label="Brand Voice" value={data.voice} />
                            </ReviewSection>
                            
                            <ReviewSection title="Budget & Schedule" step={3} onEdit={() => goToStep(3)}>
                                <ReviewItem label="Total Budget" value={`$${data.total_budget}`} />
                                <ReviewItem label="Primary KPI" value={data.primary_kpi} />
                                <ReviewItem label="Duration" value={`${data.start_date} to ${data.end_date}`} />
                            </ReviewSection>
                            
                            <ReviewSection title="Product Focus" step={4} onEdit={() => goToStep(4)}>
                                <ReviewItem label="Product Focus" value={data.product_focus || 'Not specified'} />
                                <ReviewItem label="Selected Pages" value={data.selected_pages?.length ? `${data.selected_pages.length} pages selected` : 'None'} />
                                <ReviewItem label="Exclusions" value={data.exclusions || 'None'} />
                            </ReviewSection>
                        </div>
                        
                        <div className="bg-indigo-50 border border-indigo-200 rounded-lg p-4">
                            <div className="flex">
                                <svg className="w-5 h-5 text-indigo-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M11 3a1 1 0 10-2 0v1a1 1 0 102 0V3zM15.657 5.757a1 1 0 00-1.414-1.414l-.707.707a1 1 0 001.414 1.414l.707-.707zM18 10a1 1 0 01-1 1h-1a1 1 0 110-2h1a1 1 0 011 1zM5.05 6.464A1 1 0 106.464 5.05l-.707-.707a1 1 0 00-1.414 1.414l.707.707zM5 10a1 1 0 01-1 1H3a1 1 0 110-2h1a1 1 0 011 1zM8 16v-1h4v1a2 2 0 11-4 0zM12 14c.015-.34.208-.646.477-.859a4 4 0 10-4.954 0c.27.213.462.519.476.859h4.002z" />
                                </svg>
                                <div>
                                    <p className="text-sm text-indigo-700">
                                        <strong>What happens next?</strong>
                                    </p>
                                    <p className="text-sm text-indigo-600 mt-1">
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
                                            className="bg-indigo-600 hover:bg-indigo-700"
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
                className="text-sm text-indigo-600 hover:text-indigo-800"
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
