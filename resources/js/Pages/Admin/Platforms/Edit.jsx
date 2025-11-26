import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import SideNav from '../SideNav';

export default function Edit({ auth, platform }) {
    const { data, setData, put, processing, errors } = useForm({
        name: platform.name,
        slug: platform.slug,
        description: platform.description || '',
        is_enabled: platform.is_enabled,
        sort_order: platform.sort_order,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.platforms.update', platform.id));
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-jet leading-tight">Edit Platform: {platform.name}</h2>}
        >
            <Head title={`Edit ${platform.name}`} />

            <div className="flex">
                <SideNav />
                <div className="flex-1 py-12">
                    <div className="max-w-3xl mx-auto sm:px-6 lg:px-8">
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6">
                                <form onSubmit={handleSubmit} className="space-y-6">
                                <div>
                                    <InputLabel htmlFor="name" value="Platform Name" />
                                    <TextInput
                                        id="name"
                                        type="text"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        className="mt-1 block w-full"
                                        required
                                    />
                                    <InputError message={errors.name} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="slug" value="Slug (URL-friendly identifier)" />
                                    <TextInput
                                        id="slug"
                                        type="text"
                                        value={data.slug}
                                        onChange={(e) => setData('slug', e.target.value)}
                                        className="mt-1 block w-full"
                                        required
                                    />
                                    <InputError message={errors.slug} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="description" value="Description" />
                                    <textarea
                                        id="description"
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                        className="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
                                        rows="3"
                                    />
                                    <InputError message={errors.description} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="sort_order" value="Sort Order" />
                                    <TextInput
                                        id="sort_order"
                                        type="number"
                                        value={data.sort_order}
                                        onChange={(e) => setData('sort_order', e.target.value)}
                                        className="mt-1 block w-full"
                                    />
                                    <InputError message={errors.sort_order} className="mt-2" />
                                </div>

                                <div className="flex items-center">
                                    <input
                                        id="is_enabled"
                                        type="checkbox"
                                        checked={data.is_enabled}
                                        onChange={(e) => setData('is_enabled', e.target.checked)}
                                        className="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                    />
                                    <InputLabel htmlFor="is_enabled" value="Enabled" className="ml-2" />
                                    <InputError message={errors.is_enabled} className="mt-2" />
                                </div>

                                <div className="flex items-center justify-end space-x-4">
                                    <Link href={route('admin.platforms.index')} className="text-gray-600 hover:text-gray-900">
                                        Cancel
                                    </Link>
                                    <PrimaryButton disabled={processing}>
                                        Update Platform
                                    </PrimaryButton>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            </div>
        </AuthenticatedLayout>
    );
}
