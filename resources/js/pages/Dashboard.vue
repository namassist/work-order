<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    AlarmClock,
    ArrowRight,
    ClipboardList,
    Hourglass,
    History,
    Wrench,
} from '@lucide/vue';
import { computed } from 'vue';
import ActivityLogController from '@/actions/App/Http/Controllers/Admin/ActivityLogController';
import ActivityEventBadge from '@/components/admin/ActivityEventBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormatDate } from '@/composables/useFormatDate';
import { greeting } from '@/lib/greeting';
import { dashboard } from '@/routes';
import type { ActivityEntry } from '@/types';

defineProps<{
    department: { code: string; name: string } | null;
    /** Undefined while the deferred prop loads; null when not allowed. */
    recentActivities?: ActivityEntry[] | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const page = usePage();
const { formatDateTime } = useFormatDate();

const title = computed(
    () =>
        `${greeting(new Date(), page.props.displayTimezone)}, ${page.props.auth.user.name}`,
);

/**
 * Work order metrics arrive with the WO module; until then each card says
 * so. Tones follow the status colours in docs/DESIGN.md.
 */
const metrics = [
    {
        label: 'Total WO',
        icon: ClipboardList,
        tone: 'bg-secondary text-secondary-foreground',
    },
    {
        label: 'Menunggu Persetujuan',
        icon: Hourglass,
        tone: 'bg-warning text-warning-foreground',
    },
    {
        label: 'Dalam Pengerjaan',
        icon: Wrench,
        tone: 'bg-info text-info-foreground',
    },
    {
        label: 'Terlambat',
        icon: AlarmClock,
        tone: 'bg-destructive/10 text-destructive',
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <PageHeader
            :title="title"
            :description="
                department
                    ? `${department.name} (${department.code})`
                    : 'Belum terdaftar di departemen'
            "
        />

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div
                v-for="metric in metrics"
                :key="metric.label"
                class="flex flex-col gap-4 rounded-2xl border bg-card p-6"
            >
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm font-medium text-muted-foreground">
                        {{ metric.label }}
                    </p>
                    <span
                        :class="[
                            'flex size-8 items-center justify-center rounded-lg',
                            metric.tone,
                        ]"
                    >
                        <component
                            :is="metric.icon"
                            class="size-4"
                            aria-hidden="true"
                        />
                    </span>
                </div>
                <div class="flex items-end justify-between gap-2">
                    <p
                        class="text-3xl font-semibold text-muted-foreground tabular-nums"
                        aria-hidden="true"
                    >
                        —
                    </p>
                    <Badge variant="outline">Segera hadir</Badge>
                </div>
            </div>
        </div>

        <section
            v-if="recentActivities !== null"
            class="rounded-2xl border bg-card"
            aria-labelledby="recent-activity-heading"
        >
            <div class="flex items-center justify-between gap-2 border-b p-4">
                <h2 id="recent-activity-heading" class="font-medium">
                    Aktivitas terbaru
                </h2>
                <Button variant="ghost" size="sm" as-child>
                    <Link :href="ActivityLogController.index()">
                        Lihat semua <ArrowRight />
                    </Link>
                </Button>
            </div>

            <ul v-if="recentActivities === undefined" class="divide-y">
                <li v-for="n in 4" :key="n" class="flex items-center gap-3 p-4">
                    <Skeleton class="h-5 w-24" />
                    <Skeleton class="h-4 flex-1" />
                    <Skeleton class="h-4 w-28" />
                </li>
            </ul>

            <div
                v-else-if="recentActivities.length === 0"
                class="flex justify-center p-10"
            >
                <EmptyState
                    :icon="History"
                    title="Belum ada aktivitas"
                    description="Aktivitas tercatat otomatis saat data diubah atau pengguna masuk."
                />
            </div>

            <ul v-else class="divide-y">
                <li
                    v-for="entry in recentActivities"
                    :key="entry.id"
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 p-4 text-sm"
                >
                    <ActivityEventBadge :entry="entry" />
                    <span class="min-w-0 flex-1 truncate">
                        <template v-if="entry.subject">
                            <span class="text-muted-foreground">
                                {{ entry.subject.type_label }}
                            </span>
                            {{ entry.subject.label }}
                        </template>
                        <template v-else>{{ entry.event_label }}</template>
                        <span class="text-muted-foreground">
                            oleh {{ entry.causer?.name ?? 'Sistem' }}
                        </span>
                    </span>
                    <time
                        :datetime="entry.created_at"
                        class="text-muted-foreground tabular-nums"
                    >
                        {{ formatDateTime(entry.created_at) }}
                    </time>
                </li>
            </ul>
        </section>
    </div>
</template>
