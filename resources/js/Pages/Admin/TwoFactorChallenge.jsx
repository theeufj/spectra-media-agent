import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';

export default function TwoFactorChallenge({ auth }) {
    const form = useForm({ code: '' });

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Confirm it's you</h2>}
        >
            <Head title="Two-Factor Challenge" />

            <div className="py-12">
                <div className="mx-auto max-w-md sm:px-6 lg:px-8">
                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        <p className="text-sm text-gray-600">
                            Enter the six-digit code from your authenticator, or one of your recovery codes.
                        </p>

                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(route('admin.two-factor.verify'));
                            }}
                            className="mt-4"
                        >
                            <InputLabel htmlFor="code" value="Authentication code" />
                            <TextInput
                                id="code"
                                value={form.data.code}
                                onChange={(e) => form.setData('code', e.target.value)}
                                className="mt-1 block w-full font-mono"
                                placeholder="123456"
                                autoComplete="one-time-code"
                                autoFocus
                            />
                            <InputError message={form.errors.code} className="mt-2" />

                            <PrimaryButton className="mt-4" disabled={form.processing}>
                                Continue
                            </PrimaryButton>
                        </form>

                        <p className="mt-4 text-xs text-gray-500">
                            A recovery code works once and is then used up.
                        </p>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
