import React, { useState } from 'react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

/**
 * GTMLinkForm Component
 * Form for linking an existing GTM container (Path A)
 */
export default function GTMLinkForm({ customer, onSubmit, onCancel, processing = false, errors = {} }) {
    const [formData, setFormData] = useState({
        gtm_container_id: customer.gtm_container_id || '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        onSubmit(formData);
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-6">
            <div>
                <InputLabel htmlFor="gtm_container_id" value="GTM Container ID" />
                <p className="text-sm text-gray-600 mt-1 mb-2">
                    Enter your existing GTM container ID (format: GTM-XXXXXXX)
                </p>
                <TextInput
                    id="gtm_container_id"
                    name="gtm_container_id"
                    value={formData.gtm_container_id}
                    className="mt-1 block w-full"
                    placeholder="GTM-XXXXXXX"
                    onChange={(e) => setFormData({ ...formData, gtm_container_id: e.target.value })}
                    required
                />
                <InputError message={errors.gtm_container_id} className="mt-2" />
            </div>

            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <h4 className="text-sm font-medium text-blue-900 mb-2">Before linking:</h4>
                <ul className="text-sm text-blue-800 space-y-1 list-disc list-inside">
                    <li>Make sure you have the correct GTM container ID</li>
                    <li>Ensure your Google account has access to this container</li>
                    <li>The container must be active and published</li>
                </ul>
            </div>

            <div className="flex items-center gap-4">
                <PrimaryButton disabled={processing}>
                    {processing ? 'Linking...' : 'Link Container'}
                </PrimaryButton>
                {onCancel && (
                    <SecondaryButton type="button" onClick={onCancel} disabled={processing}>
                        Cancel
                    </SecondaryButton>
                )}
            </div>
        </form>
    );
}
