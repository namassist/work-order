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
import PagePanel from '@/components/PagePanel.vue';
import type { StatItem } from '@/components/StatStrip.vue';
import StatStrip from '@/components/StatStrip.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormatDate } from '@/composables/useFormatDate';
import { greeting } from '@/lib/greeting';
import { dashboard } from '@/routes';
import type { ActivityEntry } from '@/types';

const props = defineProps<{
    department: { code: string; name: string } | null;
    /** Undefined while the deferred prop loads; null when not allowed. */
    workOrderCounts?: { total: number; pending: number } | null;
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
 * Work order metrics over the WO the user may see. "Menunggu Persetujuan"
 * counts submitted WOs in the provisional flow; the execution metrics wait
 * for their WO stages.
 */
const metrics = computed<StatItem[]>(() => {
    const counts = props.workOrderCounts;

    return [
        {
            label: 'Total WO',
            icon: ClipboardList,
            value: counts === undefined ? undefined : (counts?.total ?? null),
        },
        {
            label: 'Menunggu Persetujuan',
            icon: Hourglass,
            value: counts === undefined ? undefined : (counts?.pending ?? null),
        },
        {
            label: 'Dalam Pengerjaan',
            icon: Wrench,
            value: null,
            hint: 'Segera hadir',
        },
        {
            label: 'Terlambat',
            icon: AlarmClock,
            value: null,
            hint: 'Segera hadir',
        },
    ];
});
</script>

<template>
    <Head title="Dashboard" />

    <PagePanel :title="title">
        <template #meta>
            {{
                department
                    ? `${department.name} (${department.code})`
                    : 'Belum terdaftar di departemen'
            }}
        </template>

        <StatStrip :items="metrics" />

        <section
            v-if="recentActivities !== null"
            aria-labelledby="recent-activity-heading"
        >
            <div
                class="flex items-center justify-between gap-2 border-b px-4 py-3 sm:px-6"
            >
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
                <li
                    v-for="n in 4"
                    :key="n"
                    class="flex items-center gap-3 px-4 py-4 sm:px-6"
                >
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
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm sm:px-6"
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
    </PagePanel>
</template>
