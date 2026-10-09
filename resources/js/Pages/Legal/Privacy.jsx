import React from 'react';
import PageTitle from '@/Components/PageTitle';
import Header from '@/Components/Header';
import Footer from '@/Components/Footer';

export default function Privacy({ auth, legalContent }) {
    return (
        <>
            <PageTitle />
                        <div className="min-h-screen flex flex-col bg-gray-50">
                <Header auth={auth} />
                
                <div className="flex-1 font-sans text-gray-900 antialiased">
                    <div className="pt-4 bg-gray-100">
                        <div className="min-h-screen flex flex-col items-center pt-6 sm:pt-0">
                            <div className="w-full sm:max-w-4xl mt-6 p-8 bg-white shadow-md overflow-hidden sm:rounded-lg">
                                <article dangerouslySetInnerHTML={{ __html: legalContent }} />
                            </div>
                        </div>
                    </div>
                </div>
                
                <Footer />
            </div>
        </>
    );
}
