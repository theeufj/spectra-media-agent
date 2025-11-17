import React from 'react';
import { Link } from '@inertiajs/react';

export default function Header({ auth }) {
    return (
        <header className="bg-white shadow-sm">
            <div className="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8 flex justify-between items-center">
                <Link href="/">
                    <h1 className="text-2xl font-bold text-indigo-600">cvseeyou</h1>
                </Link>
                <div>
                    {auth && auth.user ? (
                        <Link href={route('dashboard')} className="text-base font-medium text-gray-600 hover:text-gray-900">
                            Dashboard
                        </Link>
                    ) : (
                        <>
                            <a href="/login" className="text-base font-medium text-gray-600 hover:text-gray-900">
                                Log in
                            </a>
                            <a
                                href="/register"
                                className="ml-8 inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-base font-medium text-white bg-indigo-600 hover:bg-indigo-700"
                            >
                                Sign up
                            </a>
                        </>
                    )}
                </div>
            </div>
        </header>
    );
}
