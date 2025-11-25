import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputError from '@/Components/InputError';
import TextInput from '@/Components/TextInput';

export default function ExtendVideoModal({ video, onClose, onExtensionStart }) {
    const [isPreviewingOriginal, setIsPreviewingOriginal] = useState(false);
    
    const { data, setData, post, processing, errors, reset } = useForm({
        prompt: '',
    });

    const extensionsRemaining = 20 - (video.extension_count || 0);
    const canExtend = video.status === 'completed' && 
                     video.gemini_video_uri && 
                     extensionsRemaining > 0;

    const handleSubmit = (e) => {
        e.preventDefault();
        
        post(route('video-collaterals.extend', video.id), {
            preserveScroll: true,
            onSuccess: () => {
                onExtensionStart?.();
                reset();
                onClose();
            },
        });
    };

    const examplePrompts = [
        "Continue the scene with a smooth pan to the right, revealing more of the environment",
        "Zoom in slowly on the main subject while maintaining focus",
        "Transition to a different angle showing the same scene from above",
        "Add a gentle fade as the scene continues naturally",
    ];

    return (
        <Modal show={true} onClose={onClose} maxWidth="3xl">
            <div className="p-6">
                <div className="flex items-center justify-between mb-4">
                    <h2 className="text-2xl font-bold text-gray-900">Extend Video</h2>
                    <button
                        onClick={onClose}
                        className="text-gray-400 hover:text-gray-600"
                    >
                        <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {/* Video Preview */}
                <div className="mb-6 bg-black rounded-lg overflow-hidden">
                    <video 
                        controls 
                        src={video.cloudfront_url} 
                        className="w-full h-auto max-h-96"
                    />
                </div>

                {/* Extension Info */}
                <div className="mb-6 p-4 bg-blue-50 rounded-lg border border-blue-200">
                    <div className="flex items-start">
                        <svg className="w-5 h-5 text-blue-600 mt-0.5 mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <div className="flex-1">
                            <h3 className="font-semibold text-blue-900 mb-2">Video Extension</h3>
                            <ul className="text-sm text-blue-800 space-y-1">
                                <li>• Extends your video by up to 7 additional seconds</li>
                                <li>• Creates a seamless continuation of the current video</li>
                                <li>• Extensions remaining: <span className="font-semibold">{extensionsRemaining}/20</span></li>
                                <li>• Current duration: ~{video.duration_seconds || 8} seconds</li>
                            </ul>
                        </div>
                    </div>
                </div>

                {!canExtend ? (
                    <div className="mb-6 p-4 bg-red-50 rounded-lg border border-red-200">
                        <p className="text-sm text-red-800">
                            {!video.gemini_video_uri 
                                ? "This video cannot be extended. Only Veo-generated videos support extension."
                                : extensionsRemaining <= 0
                                ? "Maximum extension limit (20) reached for this video."
                                : "Video must be completed before it can be extended."}
                        </p>
                    </div>
                ) : (
                    <form onSubmit={handleSubmit}>
                        {/* Extension Prompt */}
                        <div className="mb-6">
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Extension Prompt
                                <span className="text-red-500 ml-1">*</span>
                            </label>
                            <textarea
                                value={data.prompt}
                                onChange={(e) => setData('prompt', e.target.value)}
                                placeholder="Describe how you want to continue or extend the video scene..."
                                className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent resize-none"
                                rows="4"
                                disabled={processing}
                                required
                            />
                            <InputError message={errors.prompt} className="mt-2" />
                            <p className="mt-2 text-sm text-gray-500">
                                Describe the continuation you want. The AI will generate a 7-second extension that flows naturally from your current video.
                            </p>
                        </div>

                        {/* Example Prompts */}
                        <div className="mb-6">
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Example Prompts (click to use)
                            </label>
                            <div className="grid grid-cols-1 gap-2">
                                {examplePrompts.map((prompt, index) => (
                                    <button
                                        key={index}
                                        type="button"
                                        onClick={() => setData('prompt', prompt)}
                                        className="text-left px-3 py-2 text-sm bg-gray-50 hover:bg-purple-50 border border-gray-200 hover:border-purple-300 rounded-lg transition-colors"
                                        disabled={processing}
                                    >
                                        {prompt}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Warning */}
                        <div className="mb-6 p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                            <p className="text-sm text-yellow-800">
                                <strong>Note:</strong> Video extension takes several minutes to process. 
                                You'll receive a new video that combines your original with the 7-second extension.
                            </p>
                        </div>

                        {/* Actions */}
                        <div className="flex items-center justify-end space-x-3">
                            <SecondaryButton type="button" onClick={onClose} disabled={processing}>
                                Cancel
                            </SecondaryButton>
                            <PrimaryButton type="submit" disabled={processing || !data.prompt.trim()}>
                                {processing ? (
                                    <>
                                        <svg className="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                        </svg>
                                        Starting Extension...
                                    </>
                                ) : (
                                    <>
                                        <svg className="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Extend Video (+7s)
                                    </>
                                )}
                            </PrimaryButton>
                        </div>
                    </form>
                )}
            </div>
        </Modal>
    );
}
