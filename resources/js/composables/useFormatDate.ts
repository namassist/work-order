import { usePage } from '@inertiajs/vue3';
import { formatDate, formatDateTime } from '@/lib/format';

/**
 * Date formatters bound to the display timezone shared by HandleInertiaRequests.
 */
export function useFormatDate() {
    const page = usePage();

    return {
        formatDateTime: (iso: string): string =>
            formatDateTime(iso, page.props.displayTimezone),
        formatDate: (iso: string): string =>
            formatDate(iso, page.props.displayTimezone),
    };
}
