import { Head } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import PlatformIcon from '@/Components/PlatformIcon';

// In Go, you'd define a struct for props. In React, we define the component's "signature".
// This component receives a `status` prop, which might contain a session status message.
export default function Login({ status, enabledPlatforms = [] }) {
    // The return value of a React component is JSX, which is like HTML but with JavaScript capabilities.
    // This defines the UI that will be rendered.
    return (
        // GuestLayout is a wrapper component that provides a consistent layout for non-authenticated pages.
        // Think of it like embedding a standard template in Go.
        <GuestLayout>
            {/* Head is a component from Inertia.js to manage the document's <head> tag.
                This is similar to defining a `{{define "title"}}` block in a Go template. */}
            <Head title="Log in" />

            {/* If a session status message exists (e.g., "password reset link sent"), display it. */}
            {status && (
                <div className="mb-4 font-medium text-sm text-green-600">
                    {status}
                </div>
            )}

            <div className="w-full mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg space-y-4">
                {/* 
                    Instead of a form with inputs, we now have a single link that acts as our login button.
                    In a traditional Go web app, this would be a simple <a href="/auth/google/redirect"> tag.
                    Here, it's styled to look like a button.
                */}
                {enabledPlatforms.length > 0 ? (
                    enabledPlatforms.map((platform) => (
                        <a
                            key={platform.slug}
                            href={route(`${platform.slug}.redirect`)} // `route()` is a helper function to generate URLs from named Laravel routes.
                            className="w-full flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                        >
                            <PlatformIcon slug={platform.slug} />
                            Sign in with {platform.name}
                        </a>
                    ))
                ) : (
                    <div className="text-center text-gray-500">
                        No login methods enabled.
                    </div>
                )}
            </div>
        </GuestLayout>
    );
}
