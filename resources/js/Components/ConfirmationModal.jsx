import Modal from '@/Components/Modal';
import { Description, DialogTitle } from '@headlessui/react';
import { useEffect, useRef, useState } from 'react';

/**
 * The dialog in front of every irreversible action in the product (deletes,
 * refunds, MCC switches — 15 call sites).
 *
 * It used to hand-roll the shell: `role="dialog" aria-modal="true"` on a plain
 * div, which tells a screen reader to ignore the rest of the page while focus
 * was still sitting on the button behind it — Tab walked straight out the back
 * and Escape did nothing. Building on `Modal` (Headless UI `Dialog`) means the
 * focus trap, Escape, focus restore to the trigger and the modal semantics all
 * come from one implementation instead of being reinvented per dialog.
 */
export default function ConfirmationModal({
    show,
    onClose,
    onConfirm,
    title = 'Confirm Action',
    message,
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    confirmButtonClass = 'bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800',
    isDestructive = false,
    // Caller-owned busy flag, for pages that track their own request state
    // (`processing` from useForm, say). OR'd with the internal one.
    processing = false,
    // Pass false when the caller closes the dialog itself from an Inertia
    // `onSuccess`/`onFinish` callback, so it isn't closed twice.
    closeOnConfirm = true,
}) {
    // Focus opens on Cancel, never on Confirm.
    const cancelButtonRef = useRef(null);

    const [pending, setPending] = useState(false);
    const [failure, setFailure] = useState(null);
    const busy = pending || processing;

    // A reopened dialog must not inherit the previous attempt's spinner or its
    // error text — the component stays mounted between openings.
    useEffect(() => {
        if (show) {
            setPending(false);
            setFailure(null);
        }
    }, [show]);

    const handleConfirm = async () => {
        if (busy) return;

        setFailure(null);
        setPending(true);

        try {
            // `onConfirm` may be synchronous (fire-and-forget `router.delete`)
            // or return a promise. Awaiting it keeps the dialog and its spinner
            // on screen until the action actually finishes; the old version
            // called onClose() on the next line, so the dialog vanished at click
            // and the row it was deleting sat there looking untouched.
            await onConfirm?.();

            if (closeOnConfirm) {
                onClose?.();
            }
        } catch (e) {
            // Closing on click also meant a failed delete looked exactly like a
            // successful one. Stay up and say what broke.
            setFailure(
                e?.message || 'That did not go through. Please try again.',
            );
        } finally {
            setPending(false);
        }
    };

    return (
        <Modal
            show={show}
            onClose={() => onClose?.()}
            maxWidth="lg"
            // Escape and the backdrop stay live, but not mid-request: dismissing
            // then hides an operation the user can no longer cancel or observe.
            closeable={!busy}
            initialFocus={cancelButtonRef}
        >
            <div className="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                <div className="sm:flex sm:items-start">
                    <div
                        className={`mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full ${
                            isDestructive
                                ? 'bg-red-100'
                                : 'bg-gradient-to-br from-blue-100 to-brand-primary/20'
                        } sm:mx-0 sm:h-10 sm:w-10`}
                    >
                        {isDestructive ? (
                            <svg
                                className="h-6 w-6 text-red-600"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    strokeWidth={2}
                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                />
                            </svg>
                        ) : (
                            <svg
                                className="h-6 w-6 text-blue-600"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    strokeWidth={2}
                                    d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                />
                            </svg>
                        )}
                    </div>
                    <div className="mt-3 flex-1 text-center sm:ml-4 sm:mt-0 sm:text-left">
                        {/* DialogTitle / Description wire aria-labelledby and
                            aria-describedby themselves — the old markup pointed
                            aria-labelledby at a hardcoded "modal-title" id that
                            collided whenever two dialogs were on one page. */}
                        <DialogTitle
                            as="h3"
                            className="text-lg font-medium leading-6 text-gray-900"
                        >
                            {title}
                        </DialogTitle>
                        <Description as="p" className="mt-2 text-sm text-gray-600">
                            {message}
                        </Description>

                        {failure && (
                            <p
                                role="alert"
                                className="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
                            >
                                {failure}
                            </p>
                        )}
                    </div>
                </div>
            </div>
            {/* Cancel comes first in the DOM, so it comes first in the tab order
                and first visually. The old `sm:flex-row-reverse` put the
                destructive button in both first positions. */}
            <div className="flex flex-col gap-3 bg-gray-50 px-4 py-3 sm:flex-row sm:justify-end sm:px-6">
                <button
                    ref={cancelButtonRef}
                    type="button"
                    onClick={() => onClose?.()}
                    disabled={busy}
                    className="inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto sm:text-sm"
                >
                    {cancelText}
                </button>
                {/* The focus ring follows the button it is drawn around: the old
                    fixed `ring-blue-500` flashed blue on a red delete button and
                    on a brand-coloured one, matching neither skin. brand-primary
                    clears 3:1 against both the white ring offset (3.33:1 on
                    sitetospend, 11.02:1 on navy) and the gray-50 footer behind
                    it (3.18:1); red-600 clears at 4.83:1. */}
                <button
                    type="button"
                    onClick={handleConfirm}
                    disabled={busy}
                    aria-busy={busy}
                    className={`inline-flex w-full items-center justify-center rounded-md border border-transparent px-4 py-2 shadow-sm ${confirmButtonClass} text-base font-medium text-white transition-all focus:outline-none focus:ring-2 focus:ring-offset-2 ${
                        isDestructive
                            ? 'focus:ring-red-600'
                            : 'focus:ring-brand-primary'
                    } disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto sm:text-sm`}
                >
                    {busy && (
                        <svg
                            className="-ml-1 mr-2 h-4 w-4 animate-spin"
                            viewBox="0 0 24 24"
                            fill="none"
                            aria-hidden="true"
                        >
                            <circle
                                className="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                strokeWidth="4"
                            />
                            <path
                                className="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
                            />
                        </svg>
                    )}
                    {confirmText}
                </button>
                {/* The spinner is the only sighted cue that the click landed;
                    this is its screen-reader equivalent. */}
                <span role="status" className="sr-only">
                    {busy ? 'Working…' : ''}
                </span>
            </div>
        </Modal>
    );
}
