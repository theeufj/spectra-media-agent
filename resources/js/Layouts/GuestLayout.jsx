import Header from '@/Components/Header';
import Footer from '@/Components/Footer';
import { usePage } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    const { auth } = usePage().props;

    return (
        <div className="flex flex-col min-h-screen bg-gray-50">
            <Header auth={auth} />
            <main className="flex-1 flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
                <div className="w-full max-w-md">
                    {children}
                </div>
            </main>
            <Footer />
        </div>
    );
}
