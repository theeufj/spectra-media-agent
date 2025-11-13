import { Head } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';

// In Go, you'd define a struct for props. In React, we define the component's "signature".
// This component receives a `status` prop, which might contain a session status message.
export default function Login({ status }) {
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

            <div className="w-full mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
                {/* 
                    Instead of a form with inputs, we now have a single link that acts as our login button.
                    In a traditional Go web app, this would be a simple <a href="/auth/google/redirect"> tag.
                    Here, it's styled to look like a button.
                */}
                <a
                    href={route('login.google.redirect')} // `route()` is a helper function to generate URLs from named Laravel routes.
                    className="w-full flex items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                >
                    {/* Simple SVG for the Google logo */}
                    <svg
                        className="w-6 h-6 mr-2"
                        viewBox="0 0 48 48"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            fill="#4285F4"
                            d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12s5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24s8.955,20,20,20s20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z"
                        />
                        <path
                            fill="#34A853"
                            d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571c0.001-0.001,0.002-0.001,0.003-0.002l6.19,5.238C42.612,36.372,44,30.638,44,24C44,22.659,43.862,21.35,43.611,20.083z"
                        />
                        <path
                            fill="#FBBC05"
                            d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z"
                        />
                        <path
                            fill="#EA4335"
                            d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238c-2.008,1.521-4.525,2.434-7.219,2.434c-5.226,0-9.631-3.27-11.283-7.943l-6.522,5.025C9.505,39.556,16.227,44,24,44z"
                        />
                        <path fill="none" d="M4,4h40v40H4z" />
                    </svg>
                    Sign in with Google
                </a>
            </div>
        </GuestLayout>
    );
}
