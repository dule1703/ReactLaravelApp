import { BUSY_DELAY_MS, dimsCurrentPage } from '@/lib/navigation';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** True while a GET to the current path is running longer than BUSY_DELAY_MS (search, filter, page). */
export default function useNavigating(delay = BUSY_DELAY_MS) {
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let timer = null;

        const stop = () => {
            clearTimeout(timer);
            timer = null;
            setBusy(false);
        };

        const offStart = router.on('start', (event) => {
            if (dimsCurrentPage(event.detail.visit, window.location.pathname)) {
                clearTimeout(timer);
                timer = setTimeout(() => setBusy(true), delay);
            }
        });
        const offFinish = router.on('finish', stop);

        return () => {
            offStart();
            offFinish();
            clearTimeout(timer);
        };
    }, [delay]);

    return busy;
}
