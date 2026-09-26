<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { usePageBreadcrumbs } from '@/composables/usePageBreadcrumbs';

/**
 * The one panel of every app page: a header with the serif h1 on the left
 * and the page's breadcrumbs (its `breadcrumbs` layout prop) on the right,
 * then the page content. Separate the content's parts (statistics, toolbar,
 * table, form) with `border-b`, not with boxes of their own.
 *
 * The `meta` slot sits under the title (e.g. a WO number and status badge).
 */
defineProps<{
    title: string;
}>();

const breadcrumbs = usePageBreadcrumbs();
</script>

<template>
    <div class="flex flex-1 flex-col p-4">
        <section class="rounded-2xl border bg-card">
            <header
                class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-b px-4 py-5 sm:px-6"
            >
                <div class="min-w-0 space-y-1">
                    <h1 class="font-serif text-2xl break-words">
                        {{ title }}
                    </h1>
                    <div
                        v-if="$slots.meta"
                        class="text-sm text-muted-foreground"
                    >
                        <slot name="meta" />
                    </div>
                </div>
                <Breadcrumbs
                    v-if="breadcrumbs.length > 0"
                    :breadcrumbs="breadcrumbs"
                />
            </header>
            <slot />
        </section>
    </div>
</template>
