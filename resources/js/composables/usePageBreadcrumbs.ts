import type { InjectionKey, Ref } from 'vue';
import { computed, inject, provide } from 'vue';
import type { BreadcrumbItem } from '@/types';

const key: InjectionKey<Ref<BreadcrumbItem[]>> = Symbol('pageBreadcrumbs');

/**
 * Called by the app layout with the page's `breadcrumbs` layout prop, so the
 * page panel header can show them without every page passing them twice.
 */
export function providePageBreadcrumbs(
    breadcrumbs: () => BreadcrumbItem[],
): void {
    provide(key, computed(breadcrumbs));
}

export function usePageBreadcrumbs(): Ref<BreadcrumbItem[]> {
    return inject(key, () => computed(() => []), true);
}
