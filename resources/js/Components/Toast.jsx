import { useState, useEffect, useCallback, createContext, useContext } from 'react';
import { Transition } from '@headlessui/react';
import { CheckCircleIcon, ExclamationTriangleIcon, XCircleIcon, InformationCircleIcon, XMarkIcon } from '@heroicons/react/24/outline';

const ToastContext = createContext(null);

const ICONS = {
    success: CheckCircleIcon,
    error: XCircleIcon,
    warning: ExclamationTriangleIcon,
    info: InformationCircleIcon,
};

const STYLES = {
    success: 'bg-white border-green-200 text-green-800',
    error: 'bg-white border-red-200 text-red-800',
    warning: 'bg-white border-yellow-200 text-yellow-800',
    info: 'bg-white border-blue-200 text-blue-800',
};

const ICON_STYLES = {
    success: 'text-green-500',
    error: 'text-red-500',
    warning: 'text-yellow-500',
    info: 'text-blue-500',
};

let toastId = 0;

function Toast({ id, type = 'info', message, onDismiss, duration = 5000 }) {
    const [show, setShow] = useState(true);
    const Icon = ICONS[type] || ICONS.info;

    useEffect(() => {
        if (duration <= 0) return;
        const timer = setTimeout(() => setShow(false), duration);
        return () => clearTimeout(timer);
    }, [duration]);

    return (
        <Transition
            appear
            show={show}
            enter="transform transition duration-300 ease-out"
            enterFrom="translate-x-full opacity-0"
            enterTo="translate-x-0 opacity-100"
            leave="transform transition duration-200 ease-in"
            leaveFrom="translate-x-0 opacity-100"
            leaveTo="translate-x-full opacity-0"
            afterLeave={() => onDismiss(id)}
        >
            <div className={`pointer-events-auto w-full max-w-sm rounded-lg border shadow-lg ${STYLES[type] || STYLES.info}`}>
                <div className="flex items-start gap-3 p-4">
                    <Icon className={`h-5 w-5 flex-shrink-0 mt-0.5 ${ICON_STYLES[type] || ICON_STYLES.info}`} />
                    <p className="flex-1 text-sm font-medium">{message}</p>
                    <button
                        onClick={() => setShow(false)}
                        className="flex-shrink-0 rounded-md p-1 text-gray-400 hover:text-gray-600 focus:outline-none"
                    >
                        <XMarkIcon className="h-4 w-4" />
                    </button>
                </div>
            </div>
        </Transition>
    );
}

export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);

    const addToast = useCallback((type, message, duration = 5000) => {
        const id = ++toastId;
        setToasts((prev) => [...prev, { id, type, message, duration }]);
        return id;
    }, []);

    const removeToast = useCallback((id) => {
        setToasts((prev) => prev.filter((t) => t.id !== id));
    }, []);

    const toast = useCallback({
        success: (msg, dur) => addToast('success', msg, dur),
        error: (msg, dur) => addToast('error', msg, dur ?? 8000),
        warning: (msg, dur) => addToast('warning', msg, dur),
        info: (msg, dur) => addToast('info', msg, dur),
    }, [addToast]);

    return (
        <ToastContext.Provider value={toast}>
            {children}
            {/* Toast container — fixed top-right */}
            <div
                aria-live="assertive"
                className="pointer-events-none fixed inset-0 z-[60] flex flex-col items-end gap-3 p-4 sm:p-6"
            >
                {toasts.map((t) => (
                    <Toast key={t.id} {...t} onDismiss={removeToast} />
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast() {
    const ctx = useContext(ToastContext);
    if (!ctx) throw new Error('useToast must be used within <ToastProvider>');
    return ctx;
}
