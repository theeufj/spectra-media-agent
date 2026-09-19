import { useEffect, useRef } from 'react';
import { trackConversion } from '@/utils/conversions';

/**
 * Fire a named conversion event once when the component mounts.
 *
 * Usage:
 *   useConversionEvent('pricing_visit');
 *   useConversionEvent('sandbox_launched', urlParams.get('launched') === '1');
 *
 * @param {string}  event     Key from CONVERSIONS in utils/conversions.js
 * @param {boolean} condition Set to false to suppress (default: always fires)
 */
export function useConversionEvent(event, condition = true) {
    const initial = useRef({event, condition});
    useEffect(() => {
        if (initial.current.condition) trackConversion(initial.current.event);
    }, []);
}
