import { router } from '@inertiajs/react';
import { trackConversion } from '@/utils/conversions';

export function launchSandbox() {
    router.post(route('sandbox.launch'), {}, {
        onSuccess: page => {
            if (page.component === 'Sandbox/Results') trackConversion('sandbox_launched');
        },
    });
}
