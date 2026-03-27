import React, { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import SideNav from './SideNav';

const emptyPlan = {
    name: '',
    slug: '',
    description: '',
    price_cents: 0,
    billing_interval: 'month',
    stripe_price_id: '',
    features: [''],
    is_active: true,
    is_free: false,
    is_popular: false,
    cta_text: '',
    badge_text: '',
    sort_order: 0,
};

function PlanForm({ plan, onClose, isEdit }) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: plan.name || '',
        slug: plan.slug || '',
        description: plan.description || '',
        price_cents: plan.price_cents || 0,
        billing_interval: plan.billing_interval || 'month',
        stripe_price_id: plan.stripe_price_id || '',
        features: plan.features?.length ? plan.features : [''],
        is_active: plan.is_active ?? true,
        is_free: plan.is_free ?? false,
        is_popular: plan.is_popular ?? false,
        cta_text: plan.cta_text || '',
        badge_text: plan.badge_text || '',
        sort_order: plan.sort_order || 0,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        const filtered = { ...data, features: data.features.filter(f => f.trim() !== '') };
        if (isEdit) {
            put(route('admin.plans.update', plan.id), {
                data: filtered,
                onSuccess: onClose,
            });
        } else {
            post(route('admin.plans.store'), {
                data: filtered,
                onSuccess: onClose,
            });
        }
    };

    const autoSlug = (name) => {
        return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    };

    const addFeature = () => setData('features', [...data.features, '']);
    const removeFeature = (index) => setData('features', data.features.filter((_, i) => i !== index));
    const updateFeature = (index, value) => {
        const updated = [...data.features];
        updated[index] = value;
        setData('features', updated);
    };

    return (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div className="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
                <div className="px-6 py-4 bg-gradient-to-r from-purple-600 to-flame-orange-600 rounded-t-lg">
                    <h3 className="text-lg font-semibold text-white">{isEdit ? 'Edit Plan' : 'Create Plan'}</h3>
                </div>
                <form onSubmit={handleSubmit} className="p-6 space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Name</label>
                            <input
                                type="text"
                                value={data.name}
                                onChange={(e) => {
                                    setData('name', e.target.value);
                                    if (!isEdit) setData('slug', autoSlug(e.target.value));
                                }}
                                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                            />
                            {errors.name && <p className="text-red-500 text-xs mt-1">{errors.name}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Slug</label>
                            <input
                                type="text"
                                value={data.slug}
                                onChange={(e) => setData('slug', e.target.value)}
                                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                            />
                            {errors.slug && <p className="text-red-500 text-xs mt-1">{errors.slug}</p>}
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            rows={2}
                            className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                        />
                    </div>

                    <div className="grid grid-cols-3 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Price (cents)</label>
                            <input
                                type="number"
                                value={data.price_cents}
                                onChange={(e) => setData('price_cents', parseInt(e.target.value) || 0)}
                                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                            />
                            <p className="text-xs text-gray-500 mt-1">${(data.price_cents / 100).toFixed(2)}</p>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Billing Interval</label>
                            <select
                                value={data.billing_interval}
                                onChange={(e) => setData('billing_interval', e.target.value)}
                                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                            >
                                <option value="month">Monthly</option>
                                <option value="year">Yearly</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Sort Order</label>
                            <input
                                type="number"
                                value={data.sort_order}
                                onChange={(e) => setData('sort_order', parseInt(e.target.value) || 0)}
                                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                            />
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Stripe Price ID</label>
                        <input
                            type="text"
                            value={data.stripe_price_id}
                            onChange={(e) => setData('stripe_price_id', e.target.value)}
                            placeholder="price_..."
                            className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                        />
                        <p className="text-xs text-gray-500 mt-1">From your Stripe dashboard. Leave empty for free plans.</p>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">Features</label>
                        {data.features.map((feature, index) => (
                            <div key={index} className="flex items-center gap-2 mb-2">
                                <input
                                    type="text"
                                    value={feature}
                                    onChange={(e) => updateFeature(index, e.target.value)}
                                    placeholder="Feature description"
                                    className="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm"
                                />
                                {data.features.length > 1 && (
                                    <button type="button" onClick={() => removeFeature(index)} className="text-red-500 hover:text-red-700 text-sm">
                                        Remove
                                    </button>
                                )}
                            </div>
                        ))}
                        <button type="button" onClick={addFeature} className="text-sm text-purple-600 hover:text-purple-800">
                            + Add Feature
                        </button>
                    </div>

                    <div className="flex items-center gap-6">
                        <label className="flex items-center cursor-pointer">
                            <input
                                type="checkbox"
                                checked={data.is_active}
                                onChange={(e) => setData('is_active', e.target.checked)}
                                className="rounded border-gray-300 text-purple-600 shadow-sm mr-2"
                            />
                            <span className="text-sm text-gray-700">Active</span>
                        </label>
                        <label className="flex items-center cursor-pointer">
                            <input
                                type="checkbox"
                                checked={data.is_free}
                                onChange={(e) => setData('is_free', e.target.checked)}
                                className="rounded border-gray-300 text-purple-600 shadow-sm mr-2"
                            />
                            <span className="text-sm text-gray-700">Free Plan</span>
                        </label>
                        <label className="flex items-center cursor-pointer">
                            <input
                                type="checkbox"
                                checked={data.is_popular}
                                onChange={(e) => setData('is_popular', e.target.checked)}
                                className="rounded border-gray-300 text-purple-600 shadow-sm mr-2"
                            />
                            <span className="text-sm text-gray-700">Popular (highlighted)</span>
                        </label>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">CTA Button Text</label>
                            <input
                                type="text"
                                value={data.cta_text}
                                onChange={(e) => setData('cta_text', e.target.value)}
                                placeholder="e.g. Start Free Trial"
                                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                            />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Badge Text</label>
                            <input
                                type="text"
                                value={data.badge_text}
                                onChange={(e) => setData('badge_text', e.target.value)}
                                placeholder="e.g. MOST POPULAR"
                                className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-3 pt-4 border-t">
                        <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-gray-700 hover:text-gray-900">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={processing}
                            className="px-4 py-2 text-sm bg-purple-600 text-white rounded-md hover:bg-purple-700 disabled:opacity-50"
                        >
                            {processing ? 'Saving...' : (isEdit ? 'Update Plan' : 'Create Plan')}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

export default function Plans({ plans }) {
    const [showForm, setShowForm] = useState(false);
    const [editingPlan, setEditingPlan] = useState(null);

    const handleDelete = (plan) => {
        if (confirm(`Are you sure you want to delete "${plan.name}"?`)) {
            router.delete(route('admin.plans.destroy', plan.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Admin - Plans
                </h2>
            }
        >
            <Head title="Admin - Plans" />

            <div className="flex">
                <SideNav />
                <div className="flex-1 p-8">
                    <div className="max-w-6xl mx-auto">
                        <div className="bg-white shadow-md rounded-lg overflow-hidden">
                            <div className="px-6 py-4 bg-gradient-to-r from-purple-600 to-flame-orange-600 flex items-center justify-between">
                                <div>
                                    <h3 className="text-lg font-semibold text-white">Subscription Plans</h3>
                                    <p className="text-sm text-purple-100 mt-1">Manage your pricing plans and Stripe configuration</p>
                                </div>
                                <button
                                    onClick={() => { setEditingPlan(null); setShowForm(true); }}
                                    className="px-4 py-2 bg-white text-purple-600 rounded-md text-sm font-medium hover:bg-gray-50"
                                >
                                    + New Plan
                                </button>
                            </div>

                            <div className="p-6">
                                {plans.length === 0 ? (
                                    <div className="text-center py-12 text-gray-500">
                                        <p className="text-lg">No plans configured yet.</p>
                                        <p className="text-sm mt-1">Create your first plan to get started.</p>
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left text-gray-500">
                                                    <th className="pb-3 font-medium">Plan</th>
                                                    <th className="pb-3 font-medium">Price</th>
                                                    <th className="pb-3 font-medium">Stripe Price ID</th>
                                                    <th className="pb-3 font-medium">Status</th>
                                                    <th className="pb-3 font-medium">Features</th>
                                                    <th className="pb-3 font-medium text-right">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {plans.map((plan) => (
                                                    <tr key={plan.id} className="hover:bg-gray-50">
                                                        <td className="py-3">
                                                            <div className="font-medium text-gray-900">{plan.name}</div>
                                                            <div className="text-xs text-gray-500">{plan.slug}</div>
                                                        </td>
                                                        <td className="py-3">{plan.formatted_price}</td>
                                                        <td className="py-3">
                                                            {plan.stripe_price_id ? (
                                                                <code className="text-xs bg-gray-100 px-2 py-1 rounded">{plan.stripe_price_id}</code>
                                                            ) : (
                                                                <span className="text-gray-400">—</span>
                                                            )}
                                                        </td>
                                                        <td className="py-3">
                                                            <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${
                                                                plan.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                                                            }`}>
                                                                {plan.is_active ? 'Active' : 'Inactive'}
                                                            </span>
                                                            {plan.is_free && (
                                                                <span className="ml-1 inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                                    Free
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="py-3">
                                                            <span className="text-gray-600">{plan.features?.length || 0} features</span>
                                                        </td>
                                                        <td className="py-3 text-right space-x-2">
                                                            <button
                                                                onClick={() => { setEditingPlan(plan); setShowForm(true); }}
                                                                className="text-purple-600 hover:text-purple-800 text-sm"
                                                            >
                                                                Edit
                                                            </button>
                                                            <button
                                                                onClick={() => handleDelete(plan)}
                                                                className="text-red-600 hover:text-red-800 text-sm"
                                                            >
                                                                Delete
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {showForm && (
                <PlanForm
                    plan={editingPlan || emptyPlan}
                    isEdit={!!editingPlan}
                    onClose={() => { setShowForm(false); setEditingPlan(null); }}
                />
            )}
        </AuthenticatedLayout>
    );
}
