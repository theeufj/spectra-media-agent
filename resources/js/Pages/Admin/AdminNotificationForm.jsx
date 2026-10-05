import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import FormErrors from '@/Components/FormErrorSummary';

const AdminNotificationForm = () => {
    const { data, setData, post, processing, errors, reset } = useForm({ subject: '', body: '' });
    const { subject, body } = data;
    const [confirming, setConfirming] = useState(false);

    const handleSubmit = (e) => {
        e.preventDefault();
        if (!confirming) { setConfirming(true); return; }
        post(route('admin.notification.send'), { onSuccess: () => { reset(); setConfirming(false); } });
    };

    return (
        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div className="p-6 text-gray-900">
                <h3 className="text-lg font-medium text-gray-900 mb-4">Send Notification to All Users</h3>
                <form onSubmit={handleSubmit}>
                    <FormErrors errors={errors} />
                    {confirming && <p role="status" className="mb-4 rounded bg-amber-50 p-3 text-sm text-amber-900">This will notify every user. Review the subject and body below, then confirm sending.</p>}
                    <div className="mb-4">
                        <label htmlFor="subject" className="block text-sm font-medium text-gray-700">Subject</label>
                        <input
                            type="text"
                            id="subject"
                            className="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-brand-primary focus:border-brand-primary sm:text-sm"
                            value={subject}
                            onChange={(e) => setData('subject', e.target.value)}
                            required
                        />
                    </div>
                    <div className="mb-4">
                        <label htmlFor="body" className="block text-sm font-medium text-gray-700">Body</label>
                        <textarea
                            id="body"
                            rows="5"
                            className="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-brand-primary focus:border-brand-primary sm:text-sm"
                            value={body}
                            onChange={(e) => setData('body', e.target.value)}
                            required
                        ></textarea>
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-brand-dark hover:bg-brand-darker focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-primary"
                    >
                        {processing ? 'Sending…' : confirming ? 'Confirm send to all users' : 'Review notification'}
                    </button>
                    {confirming && <button type="button" disabled={processing} onClick={() => setConfirming(false)} className="ml-3 text-sm text-gray-600">Cancel</button>}
                </form>
            </div>
        </div>
    );
};

export default AdminNotificationForm;
