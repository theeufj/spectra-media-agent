import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link } from '@inertiajs/react';
import PlatformIcon from '@/Components/PlatformIcon';

export default function Register({ enabledPlatforms = [] }) {
    return (
        <GuestLayout>
            <Head title="Register" />

            <div className="w-full mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
                <div className="mb-4 text-sm text-gray-600 text-center">
                    Create your account to get started.
                </div>

                <div className="space-y-4">
                    {enabledPlatforms.length > 0 ? (
                        enabledPlatforms.map((platform) => (
                            <a
                                key={platform.slug}
                                href={route(`${platform.slug}.redirect`)}
                                className="w-full flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                            >
                                <PlatformIcon slug={platform.slug} />
                                Sign up with {platform.name}
                            </a>
                        ))
                    ) : (
                        <div className="text-center text-gray-500">
                            No registration methods enabled.
                        </div>
                    )}
                </div>

                <div className="mt-4 text-center">
                    <Link
                        href={route('login')}
                        className="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                        Already registered?
                    </Link>
                </div>
            </div>
        </GuestLayout>
    );
}
