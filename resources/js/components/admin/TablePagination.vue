<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

const props = defineProps<{
    paginator: Paginated<unknown>;
}>();

// Laravel's first and last links are the previous/next arrows.
const pageLinks = computed(() => props.paginator.links.slice(1, -1));
const previous = computed(() => props.paginator.links[0]?.url ?? null);
const next = computed(() => props.paginator.links.at(-1)?.url ?? null);
</script>

<template>
    <nav
        class="flex flex-col items-center justify-between gap-3 border-t px-6 py-4 text-sm sm:flex-row"
        aria-label="Navigasi halaman"
    >
        <p class="text-muted-foreground tabular-nums">
            <template v-if="paginator.total > 0">
                Menampilkan {{ paginator.from }}–{{ paginator.to }} dari
                {{ paginator.total }}
            </template>
            <template v-else>Tidak ada data</template>
        </p>

        <div v-if="paginator.last_page > 1" class="flex items-center gap-1">
            <Button
                variant="ghost"
                size="icon"
                :disabled="!previous"
                :as="previous ? Link : 'button'"
                :href="previous ?? undefined"
                preserve-scroll
                aria-label="Halaman sebelumnya"
            >
                <ChevronLeft />
            </Button>
            <template v-for="link in pageLinks" :key="link.label">
                <span
                    v-if="!link.url"
                    class="px-2 text-muted-foreground"
                    aria-hidden="true"
                    >…</span
                >
                <Button
                    v-else
                    :variant="link.active ? 'outline' : 'ghost'"
                    size="icon"
                    :as="Link"
                    :href="link.url"
                    preserve-scroll
                    class="tabular-nums"
                    :aria-current="link.active ? 'page' : undefined"
                >
                    {{ link.label }}
                </Button>
            </template>
            <Button
                variant="ghost"
                size="icon"
                :disabled="!next"
                :as="next ? Link : 'button'"
                :href="next ?? undefined"
                preserve-scroll
                aria-label="Halaman berikutnya"
            >
                <ChevronRight />
            </Button>
        </div>
    </nav>
</template>
