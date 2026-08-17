import React from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import DangerButton from '@/Components/DangerButton';

export default function TwoFactor({
    auth,
    enabled,
    confirmedAt,
    setupSecret,
    setupQr,
    recoveryCodes,
    recoveryCodesRemaining,
}) {
    const enrol = useForm({});
    const confirm = useForm({ code: '' });
    const disable = useForm({ code: '' });

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Two-Factor Authentication</h2>}
        >
            <Head title="Two-Factor Authentication" />

            <div className="py-12">
                <div className="mx-auto max-w-2xl sm:px-6 lg:px-8">
                    <div className="rounded-lg bg-white p-6 shadow-sm">
                        {/* Shown once, immediately after confirming. There is no
                            second chance to see these, which is the point. */}
                        {recoveryCodes && (
                            <div className="mb-6 rounded-md border border-amber-300 bg-amber-50 p-4">
                                <h3 className="font-semibold text-amber-900">Save your recovery codes</h3>
                                <p className="mt-1 text-sm text-amber-800">
                                    These are shown once. Each works a single time, and they are the way back in if you
                                    lose your phone.
                                </p>
                                <ul className="mt-3 grid grid-cols-2 gap-2 font-mono text-sm text-amber-900">
                                    {recoveryCodes.map((code) => (
                                        <li key={code}>{code}</li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {enabled ? (
                            <>
                                <p className="text-sm text-gray-700">
                                    Two-factor is <strong>on</strong>
                                    {confirmedAt ? ` since ${new Date(confirmedAt).toLocaleDateString()}` : ''}.
                                    {typeof recoveryCodesRemaining === 'number' && (
                                        <> {recoveryCodesRemaining} recovery code(s) remaining.</>
                                    )}
                                </p>

                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        disable.post(route('admin.two-factor.disable'));
                                    }}
                                    className="mt-6 border-t border-gray-200 pt-6"
                                >
                                    <InputLabel htmlFor="disable-code" value="Enter a current code to turn it off" />
                                    <TextInput
                                        id="disable-code"
                                        value={disable.data.code}
                                        onChange={(e) => disable.setData('code', e.target.value)}
                                        className="mt-1 block w-40 font-mono"
                                        placeholder="123456"
                                        autoComplete="one-time-code"
                                    />
                                    <InputError message={disable.errors.code} className="mt-2" />
                                    <DangerButton className="mt-3" disabled={disable.processing}>
                                        Turn off two-factor
                                    </DangerButton>
                                </form>
                            </>
                        ) : setupQr ? (
                            <>
                                <h3 className="font-semibold text-gray-900">Scan this with your authenticator</h3>
                                <p className="mt-1 text-sm text-gray-600">
                                    Then enter the six-digit code it shows. Nothing changes until you do.
                                </p>

                                <img src={setupQr} alt="Two-factor QR code" className="my-4 h-56 w-56" />

                                <p className="text-xs text-gray-500">
                                    Cannot scan? Enter this key manually:{' '}
                                    <span className="font-mono text-gray-700">{setupSecret}</span>
                                </p>

                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        confirm.post(route('admin.two-factor.confirm'));
                                    }}
                                    className="mt-6"
                                >
                                    <InputLabel htmlFor="confirm-code" value="Code from your authenticator" />
                                    <TextInput
                                        id="confirm-code"
                                        value={confirm.data.code}
                                        onChange={(e) => confirm.setData('code', e.target.value)}
                                        className="mt-1 block w-40 font-mono"
                                        placeholder="123456"
                                        autoComplete="one-time-code"
                                        autoFocus
                                    />
                                    <InputError message={confirm.errors.code} className="mt-2" />
                                    <PrimaryButton className="mt-3" disabled={confirm.processing}>
                                        Confirm and enable
                                    </PrimaryButton>
                                </form>
                            </>
                        ) : (
                            <>
                                <h3 className="font-semibold text-gray-900">Protect your admin account</h3>
                                <p className="mt-1 text-sm text-gray-600">
                                    The admin console can change billing, delete customers and rotate the advertising
                                    credentials the whole platform runs on. A password alone is the only thing between a
                                    stolen session and all of that.
                                </p>
                                <PrimaryButton
                                    className="mt-4"
                                    disabled={enrol.processing}
                                    onClick={() => enrol.post(route('admin.two-factor.enrol'))}
                                >
                                    Set up two-factor
                                </PrimaryButton>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
