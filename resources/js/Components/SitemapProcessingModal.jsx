import { Fragment } from 'react';
import { Dialog, Transition } from '@headlessui/react';
import { CheckCircleIcon } from '@heroicons/react/24/outline';
import { Link } from '@inertiajs/react';

export default function SitemapProcessingModal({ show, onClose }) {
    return (
        <Transition appear show={show} as={Fragment}>
            <Dialog as="div" className="relative z-50" onClose={onClose}>
                <Transition.Child
                    as={Fragment}
                    enter="ease-out duration-300"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-200"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-black/30 backdrop-blur-sm" />
                </Transition.Child>

                <div className="fixed inset-0 overflow-y-auto">
                    <div className="flex min-h-full items-center justify-center p-4 text-center">
                        <Transition.Child
                            as={Fragment}
                            enter="ease-out duration-300"
                            enterFrom="opacity-0 scale-95"
                            enterTo="opacity-100 scale-100"
                            leave="ease-in duration-200"
                            leaveFrom="opacity-100 scale-100"
                            leaveTo="opacity-0 scale-95"
                        >
                            <Dialog.Panel className="w-full max-w-md transform overflow-hidden rounded-2xl bg-white p-8 text-left align-middle shadow-2xl transition-all">
                                <div className="flex flex-col items-center">
                                    {/* Animated success icon with pulse ring */}
                                    <div className="relative mb-6">
                                        <div className="absolute inset-0 rounded-full bg-green-400/20 animate-ping" />
                                        <div className="relative flex items-center justify-center w-16 h-16 rounded-full bg-gradient-to-br from-green-400 to-emerald-500">
                                            <CheckCircleIcon className="h-9 w-9 text-white" />
                                        </div>
                                    </div>

                                    <Dialog.Title
                                        as="h3"
                                        className="text-xl font-semibold leading-6 text-gray-900 mb-3"
                                    >
                                        Sitemap Processing Started
                                    </Dialog.Title>

                                    <div className="space-y-3 text-center">
                                        <p className="text-sm text-gray-500">
                                            Your sitemap is being crawled. This may take a few minutes depending on the number of pages.
                                        </p>

                                        {/* Animated progress dots */}
                                        <div className="flex items-center justify-center gap-1.5 py-2">
                                            <span className="w-2 h-2 rounded-full bg-delft-blue animate-bounce [animation-delay:0ms]" />
                                            <span className="w-2 h-2 rounded-full bg-air-superiority-blue animate-bounce [animation-delay:150ms]" />
                                            <span className="w-2 h-2 rounded-full bg-delft-blue animate-bounce [animation-delay:300ms]" />
                                        </div>

                                        <p className="text-sm text-gray-600 font-medium">
                                            We'll email you when processing is complete.
                                        </p>
                                    </div>

                                    <div className="mt-7 flex items-center gap-3">
                                        <Link
                                            href={route('knowledge-base.index')}
                                            className="inline-flex justify-center items-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-delft-blue focus-visible:ring-offset-2 transition"
                                        >
                                            View Knowledge Base
                                        </Link>
                                        <button
                                            type="button"
                                            className="inline-flex justify-center items-center rounded-lg border border-transparent bg-gradient-to-r from-delft-blue to-air-superiority-blue px-5 py-2.5 text-sm font-medium text-white hover:from-delft-blue/90 hover:to-air-superiority-blue/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-delft-blue focus-visible:ring-offset-2 transition shadow-sm"
                                            onClick={onClose}
                                        >
                                            Got it, thanks!
                                        </button>
                                    </div>
                                </div>
                            </Dialog.Panel>
                        </Transition.Child>
                    </div>
                </div>
            </Dialog>
        </Transition>
    );
}
