import React from 'react';
import { useForm } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';

export default function RefineImageModal({ image, onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        prompt: '',
        context_image: null,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('image-collaterals.update', image.id), {
            onSuccess: () => onClose(),
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
            <div className="bg-white p-8 rounded-lg shadow-xl w-full max-w-2xl">
                <h2 className="text-2xl font-bold mb-6 text-jet">Refine Image</h2>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <div>
                        <label htmlFor="prompt" className="block text-sm font-medium text-gray-700">
                            Refinement Prompt
                        </label>
                        <textarea
                            id="prompt"
                            value={data.prompt}
                            onChange={(e) => setData('prompt', e.target.value)}
                            className="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                            rows="3"
                            placeholder="e.g., 'Make the sky more dramatic and add a futuristic cityscape in the background.'"
                        ></textarea>
                        {errors.prompt && <p className="mt-2 text-sm text-red-600">{errors.prompt}</p>}
                    </div>

                    <div>
                        <label htmlFor="context_image" className="block text-sm font-medium text-gray-700">
                            Optional Context Image
                        </label>
                        <input
                            type="file"
                            id="context_image"
                            onChange={(e) => setData('context_image', e.target.files[0])}
                            className="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100"
                        />
                        {errors.context_image && <p className="mt-2 text-sm text-red-600">{errors.context_image}</p>}
                    </div>

                    <div className="flex justify-end space-x-4">
                        <button type="button" onClick={onClose} className="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">
                            Cancel
                        </button>
                        <PrimaryButton disabled={processing}>
                            {processing ? 'Submitting...' : 'Submit Refinement'}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    );
}
