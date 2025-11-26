import React from 'react';

/**
 * ProgressStepper - A visual step indicator for multi-step forms
 * 
 * @param {Array} steps - Array of step objects with { id, title, description? }
 * @param {number} currentStep - Zero-indexed current step
 * @param {function} onStepClick - Optional callback when a step is clicked
 * @param {boolean} allowNavigation - Whether clicking on completed steps navigates
 */
export default function ProgressStepper({ 
    steps, 
    currentStep, 
    onStepClick = null,
    allowNavigation = true 
}) {
    const getStepStatus = (index) => {
        if (index < currentStep) return 'completed';
        if (index === currentStep) return 'current';
        return 'upcoming';
    };

    return (
        <nav aria-label="Progress" className="mb-8">
            <ol className="flex items-center justify-between">
                {steps.map((step, index) => {
                    const status = getStepStatus(index);
                    const isClickable = allowNavigation && onStepClick && index < currentStep;
                    
                    return (
                        <li key={step.id} className="relative flex-1">
                            {/* Connector Line */}
                            {index !== steps.length - 1 && (
                                <div 
                                    className={`absolute top-5 left-1/2 w-full h-0.5 ${
                                        index < currentStep ? 'bg-indigo-600' : 'bg-gray-200'
                                    }`}
                                    aria-hidden="true"
                                />
                            )}
                            
                            <div 
                                className={`relative flex flex-col items-center group ${
                                    isClickable ? 'cursor-pointer' : ''
                                }`}
                                onClick={() => isClickable && onStepClick(index)}
                            >
                                {/* Step Circle */}
                                <span 
                                    className={`
                                        w-10 h-10 flex items-center justify-center rounded-full 
                                        border-2 transition-all duration-200 z-10 bg-white
                                        ${status === 'completed' 
                                            ? 'bg-indigo-600 border-indigo-600 text-white' 
                                            : status === 'current'
                                                ? 'border-indigo-600 text-indigo-600'
                                                : 'border-gray-300 text-gray-500'
                                        }
                                        ${isClickable ? 'group-hover:ring-2 group-hover:ring-indigo-200' : ''}
                                    `}
                                >
                                    {status === 'completed' ? (
                                        <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path 
                                                fillRule="evenodd" 
                                                d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" 
                                                clipRule="evenodd" 
                                            />
                                        </svg>
                                    ) : (
                                        <span className="text-sm font-semibold">{index + 1}</span>
                                    )}
                                </span>
                                
                                {/* Step Label */}
                                <span 
                                    className={`
                                        mt-2 text-sm font-medium text-center
                                        ${status === 'current' 
                                            ? 'text-indigo-600' 
                                            : status === 'completed'
                                                ? 'text-gray-900'
                                                : 'text-gray-500'
                                        }
                                    `}
                                >
                                    {step.title}
                                </span>
                                
                                {/* Step Description (optional) */}
                                {step.description && (
                                    <span className="mt-0.5 text-xs text-gray-400 text-center max-w-[120px]">
                                        {step.description}
                                    </span>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

/**
 * Compact horizontal stepper for smaller spaces
 */
export function CompactStepper({ steps, currentStep }) {
    return (
        <div className="flex items-center space-x-2">
            {steps.map((step, index) => (
                <React.Fragment key={step.id}>
                    <div 
                        className={`
                            flex items-center justify-center w-6 h-6 rounded-full text-xs font-medium
                            ${index < currentStep 
                                ? 'bg-indigo-600 text-white' 
                                : index === currentStep
                                    ? 'bg-indigo-100 text-indigo-600 ring-2 ring-indigo-600'
                                    : 'bg-gray-100 text-gray-400'
                            }
                        `}
                    >
                        {index < currentStep ? '✓' : index + 1}
                    </div>
                    {index < steps.length - 1 && (
                        <div 
                            className={`w-8 h-0.5 ${
                                index < currentStep ? 'bg-indigo-600' : 'bg-gray-200'
                            }`}
                        />
                    )}
                </React.Fragment>
            ))}
        </div>
    );
}
