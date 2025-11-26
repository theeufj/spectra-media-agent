import React, { useState, useEffect } from 'react';
import { Link, usePage } from '@inertiajs/react';

/**
 * SetupProgressNav - Shows setup progress for new users
 * Guides them through Knowledge Base → Brand Guidelines → Platform Connection → Campaign
 */
export default function SetupProgressNav() {
    const { auth } = usePage().props;
    const user = auth?.user;
    const [setupData, setSetupData] = useState(null);
    const [isLoading, setIsLoading] = useState(true);
    
    // Fetch setup progress from API
    useEffect(() => {
        const fetchSetupProgress = async () => {
            try {
                const response = await fetch('/api/setup-progress', {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    }
                });
                if (response.ok) {
                    const data = await response.json();
                    setSetupData(data);
                }
            } catch (error) {
                console.error('Failed to fetch setup progress:', error);
            } finally {
                setIsLoading(false);
            }
        };
        
        fetchSetupProgress();
    }, []);
    
    // Don't show while loading or if setup is complete
    if (isLoading || !setupData || setupData.progress === 100) return null;
    
    const { steps, progress, completed_steps, total_steps } = setupData;
    
    return (
        <div className="bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-100 rounded-lg p-4 mb-4">
            <div className="flex items-center justify-between mb-3">
                <div className="flex items-center space-x-2">
                    <span className="text-lg">🚀</span>
                    <h3 className="text-sm font-semibold text-gray-900">Get Started</h3>
                </div>
                <span className="text-xs text-gray-500">{completed_steps}/{total_steps} complete</span>
            </div>
            
            {/* Progress Bar */}
            <div className="w-full bg-gray-200 rounded-full h-1.5 mb-3">
                <div 
                    className="bg-gradient-to-r from-indigo-500 to-purple-500 h-1.5 rounded-full transition-all duration-500"
                    style={{ width: `${progress}%` }}
                />
            </div>
            
            {/* Steps */}
            <div className="flex gap-2">
                {steps.map((step, index) => (
                    <Link 
                        key={step.key}
                        href={step.action_url}
                        className={`
                            flex-1 flex items-center space-x-2 px-3 py-2 rounded-lg text-xs
                            transition-all duration-200
                            ${step.completed 
                                ? 'bg-green-100 text-green-700 hover:bg-green-200' 
                                : step.partial
                                    ? 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200 ring-2 ring-yellow-300'
                                    : index === completed_steps
                                        ? 'bg-indigo-100 text-indigo-700 hover:bg-indigo-200 ring-2 ring-indigo-300'
                                        : 'bg-white text-gray-500 hover:bg-gray-50'
                            }
                        `}
                        title={step.description}
                    >
                        <span className="flex-shrink-0">
                            {step.completed ? '✓' : step.partial ? '◐' : getStepIcon(step.key)}
                        </span>
                        <div className="min-w-0">
                            <p className="font-medium truncate">{step.title}</p>
                            {step.connected_platforms?.length > 0 && (
                                <p className="text-[10px] opacity-75 truncate">
                                    {step.connected_platforms.join(', ')}
                                </p>
                            )}
                        </div>
                    </Link>
                ))}
            </div>
        </div>
    );
}

function getStepIcon(stepKey) {
    const icons = {
        knowledge_base: '📚',
        brand_guidelines: '🎨',
        platform_connection: '🔗',
        first_campaign: '🚀',
    };
    return icons[stepKey] || '📋';
}

/**
 * Inline Setup Progress for the navigation bar
 */
export function InlineSetupProgress() {
    const [setupData, setSetupData] = useState(null);
    const [isLoading, setIsLoading] = useState(true);
    
    useEffect(() => {
        const fetchSetupProgress = async () => {
            try {
                const response = await fetch('/api/setup-progress', {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    }
                });
                if (response.ok) {
                    const data = await response.json();
                    setSetupData(data);
                }
            } catch (error) {
                console.error('Failed to fetch setup progress:', error);
            } finally {
                setIsLoading(false);
            }
        };
        
        fetchSetupProgress();
    }, []);
    
    if (isLoading || !setupData || setupData.progress === 100) return null;
    
    const nextStep = setupData.current_step;
    
    if (!nextStep) return null;
    
    return (
        <Link
            href={nextStep.action_url}
            className="inline-flex items-center px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-100 rounded-full hover:bg-indigo-200 transition-colors"
        >
            <span className="mr-1.5">→</span>
            {nextStep.action_text || nextStep.title}
        </Link>
    );
}
