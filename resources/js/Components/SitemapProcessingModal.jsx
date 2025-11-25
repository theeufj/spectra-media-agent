import { Fragment } from 'react';
import { Dialog, Transition } from '@headlessui/react';
import { CheckCircleIcon } from '@heroicons/react/24/outline';

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
                    <div className="fixed inset-0 bg-black bg-opacity-25" />
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
                            <Dialog.Panel className="w-full max-w-md transform overflow-hidden rounded-2xl bg-white p-6 text-left align-middle shadow-xl transition-all">
                                <div className="flex flex-col items-center">
                                    <CheckCircleIcon className="h-16 w-16 text-green-500 mb-4" />
                                    <Dialog.Title
                                        as="h3"
                                        className="text-lg font-medium leading-6 text-gray-900 mb-2"
                                    >
                                        Sitemap Processing Started
                                    </Dialog.Title>
                                    <div className="mt-2">
                                        <p className="text-sm text-gray-500 text-center">
                                            Your sitemap is being crawled. This could take some time depending on the number of pages.
                                        </p>
                                        <p className="text-sm text-gray-500 text-center mt-2 font-medium">
                                            You'll receive an email when we've finished processing your sitemap.
                                        </p>
                                    </div>

                                    <div className="mt-6">
                                        <button
                                            type="button"
                                            className="inline-flex justify-center rounded-md border border-transparent bg-delft-blue px-4 py-2 text-sm font-medium text-white hover:bg-air-superiority-blue focus:outline-none focus-visible:ring-2 focus-visible:ring-delft-blue focus-visible:ring-offset-2"
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
