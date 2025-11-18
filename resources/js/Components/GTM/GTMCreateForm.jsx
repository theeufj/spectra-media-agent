import React, { useState } from 'react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

/**
 * GTMCreateForm Component
 * Form for creating a new GTM container (Path B)
 */
export default function GTMCreateForm({ customer, onSubmit, onCancel, processing = false, errors = {} }) {
    const [formData, setFormData] = useState({
        container_name: customer.name || '',
        website_url: customer.website_url || '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        onSubmit(formData);
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-6">
            <div>
                <InputLabel htmlFor="container_name" value="Container Name" />
                <p className="text-sm text-gray-600 mt-1 mb-2">
                    A friendly name for your GTM container (usually your business name)
                </p>
                <TextInput
                    id="container_name"
                    name="container_name"
                    value={formData.container_name}
                    className="mt-1 block w-full"
                    placeholder="My Business"
                    onChange={(e) => setFormData({ ...formData, container_name: e.target.value })}
                    required
                />
                <InputError message={errors.container_name} className="mt-2" />
            </div>

            <div>
                <InputLabel htmlFor="website_url" value="Website URL" />
                <p className="text-sm text-gray-600 mt-1 mb-2">
                    The primary website URL where this container will be used
                </p>
                <TextInput
                    id="website_url"
                    name="website_url"
                    value={formData.website_url}
                    className="mt-1 block w-full"
                    placeholder="https://example.com"
                    onChange={(e) => setFormData({ ...formData, website_url: e.target.value })}
                    required
                />
                <InputError message={errors.website_url} className="mt-2" />
            </div>

            <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <h4 className="text-sm font-medium text-yellow-900 mb-2">Note:</h4>
                <p className="text-sm text-yellow-800">
                    Creating a new container will require you to add GTM code to your website. 
                    We'll provide installation instructions after the container is created.
                </p>
            </div>

            <div className="flex items-center gap-4">
                <PrimaryButton disabled={processing}>
                    {processing ? 'Creating...' : 'Create Container'}
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
