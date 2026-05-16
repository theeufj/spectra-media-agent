import { useForm } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputError from '@/Components/InputError';

export default function ExtendVideoModal({ video, onClose, onExtensionStart }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        prompt: '',
    });

    const maxExtensions = 3;
    const extensionsUsed = video.refinement_depth ?? 0;
    const extensionsRemaining = maxExtensions - extensionsUsed;
    const canExtend = video.status === 'completed' &&
                     video.gemini_video_uri &&
                     extensionsRemaining > 0;

    const handleSubmit = (e) => {
        e.preventDefault();
        if (!data.prompt.trim()) {
            document.getElementById('extend-prompt')?.focus();
            return;
        }
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
        "Continue the scene with a smooth pan to the right",
        "Zoom in slowly on the main subject",
        "Add a gentle fade as the scene continues naturally",
    ];

    return (
        <Modal show={true} onClose={onClose} maxWidth="2xl">
            <div className="p-5">
                {/* Header */}
                <div className="flex items-center justify-between mb-4">
                    <div>
                        <h2 className="text-lg font-semibold text-gray-900">Extend Video</h2>
                        <p className="text-sm text-gray-500 mt-0.5">
                            Adds up to 7 seconds · {extensionsRemaining}/{maxExtensions} extensions remaining
                        </p>
                    </div>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600 p-1">
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {/* Compact video preview */}
                <div className="mb-4 bg-black rounded-lg overflow-hidden">
                    <video
                        controls
                        src={video.cloudfront_url}
                        className="w-full max-h-48 object-contain"
                    />
                </div>

                {!canExtend ? (
                    <div className="p-3 bg-red-50 rounded-lg border border-red-200 mb-4">
                        <p className="text-sm text-red-800">
                            {!video.gemini_video_uri
                                ? "Only Veo-generated videos can be extended."
                                : extensionsRemaining <= 0
                                ? `Maximum ${maxExtensions} extensions reached.`
                                : "Video must be completed before extending."}
                        </p>
                    </div>
                ) : (
                    <form onSubmit={handleSubmit}>
                        {/* Prompt */}
                        <div className="mb-3">
                            <label htmlFor="extend-prompt" className="block text-sm font-medium text-gray-700 mb-1.5">
                                How should the video continue? <span className="text-red-500">*</span>
                            </label>
                            <textarea
                                id="extend-prompt"
                                value={data.prompt}
                                onChange={(e) => setData('prompt', e.target.value)}
                                placeholder="Describe the continuation..."
                                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent resize-none text-sm"
                                rows="3"
                                disabled={processing}
                            />
                            <InputError message={errors.prompt} className="mt-1" />
                        </div>

                        {/* Example prompts */}
                        <div className="mb-4">
                            <p className="text-xs text-gray-500 mb-1.5">Quick examples:</p>
                            <div className="flex flex-wrap gap-1.5">
                                {examplePrompts.map((prompt, index) => (
                                    <button
                                        key={index}
                                        type="button"
                                        onClick={() => setData('prompt', prompt)}
                                        className="text-xs px-2.5 py-1 bg-gray-100 hover:bg-purple-50 hover:text-purple-700 border border-gray-200 hover:border-purple-300 rounded-full transition-colors"
                                        disabled={processing}
                                    >
                                        {prompt}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Actions */}
                        <div className="flex items-center justify-end gap-2 pt-3 border-t border-gray-100">
                            <SecondaryButton type="button" onClick={onClose} disabled={processing}>
                                Cancel
                            </SecondaryButton>
                            <PrimaryButton type="submit" disabled={processing || !data.prompt.trim()}>
                                {processing ? 'Starting...' : 'Extend Video (+7s)'}
                            </PrimaryButton>
                        </div>
                    </form>
                )}
            </div>
        </Modal>
    );
}
