import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import SideNav from './SideNav';

/**
 * The chrome every admin page repeats by hand: AuthenticatedLayout wrapping a
 * flex row of SideNav + content.
 *
 * Note there is no `user` prop. AuthenticatedLayout is ({header, children}) and
 * reads the user from usePage().props.auth.user itself — the `user={auth.user}`
 * that the older admin pages pass has always been discarded.
 *
 * New admin pages only. The existing ~25 are left alone deliberately; churning
 * them is unrelated risk for no behaviour change.
 */
export default function AdminShell({ title, heading, subheading, actions, header, children }) {
    return (
        <AuthenticatedLayout header={header}>
            <Head title={title} />
            <div className="flex">
                <SideNav />
                <div className="flex-1 p-6">
                    {(heading || actions) && (
                        <div className="flex items-start justify-between mb-6 gap-4">
                            <div>
                                {heading && <h1 className="text-2xl font-bold text-gray-900">{heading}</h1>}
                                {subheading && <p className="text-sm text-gray-500 mt-1">{subheading}</p>}
                            </div>
                            {actions && <div className="flex gap-2 flex-wrap justify-end">{actions}</div>}
                        </div>
                    )}
                    {children}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
