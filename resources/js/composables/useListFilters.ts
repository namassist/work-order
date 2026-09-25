import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { reactive, watch } from 'vue';
import type { RouteDefinition } from '@/wayfinder';

const SEARCH_DEBOUNCE_MS = 300;

/** Select value meaning "no filter" (reka-ui items cannot use ''). */
export const ALL = 'all';

/**
 * Builds the query string for a filter set: drops empty values, and sends
 * booleans as 1 because Laravel's boolean rule rejects "true".
 */
export function toFilterQuery(
    filters: Record<string, string | boolean>,
): Record<string, string | number> {
    const query: Record<string, string | number> = {};

    for (const [key, value] of Object.entries(filters)) {
        if (value === true) {
            query[key] = 1;
        } else if (value !== false && value !== '' && value !== ALL) {
            query[key] = value;
        }
    }

    return query;
}

/**
 * Keeps list filters in the query string. Changing a filter reloads the page
 * from page 1; the search box is debounced.
 */
export function useListFilters<T extends { search: string }>(
    initial: T,
    route: () => RouteDefinition<'get'>,
) {
    const filters = reactive({ ...initial }) as T;

    const apply = () => {
        const query = toFilterQuery(
            filters as Record<string, string | boolean>,
        );

        router.get(route().url, query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const applyDebounced = useDebounceFn(apply, SEARCH_DEBOUNCE_MS);

    watch(
        () => filters.search,
        () => applyDebounced(),
    );
    // Read only the non-search keys so typing does not bypass the debounce.
    const otherKeys = Object.keys(initial).filter((key) => key !== 'search');
    watch(
        () => otherKeys.map((key) => filters[key as keyof T]),
        () => apply(),
    );

    return filters;
}
