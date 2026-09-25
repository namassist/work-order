<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { History, SearchX, X } from '@lucide/vue';
import { computed, watch } from 'vue';
import ActivityLogController from '@/actions/App/Http/Controllers/Admin/ActivityLogController';
import ActivityChanges from '@/components/admin/ActivityChanges.vue';
import ActivityEventBadge from '@/components/admin/ActivityEventBadge.vue';
import ActivityProperties from '@/components/admin/ActivityProperties.vue';
import TablePagination from '@/components/admin/TablePagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ALL, isFiltering, useListFilters } from '@/composables/useListFilters';
import { useFormatDate } from '@/composables/useFormatDate';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import type { ActivityEntry, Paginated, SelectOption } from '@/types';

const props = defineProps<{
    activities: Paginated<ActivityEntry>;
    filters: {
        subject_type: string;
        subject_id: string;
        causer: string;
        event: string;
        from: string;
        to: string;
    };
    subjectTypes: SelectOption[];
    events: SelectOption[];
    causers: { id: number; name: string }[];
}>();

const { formatDateTime } = useFormatDate();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administrasi' },
            { title: 'Log Aktivitas', href: ActivityLogController.index() },
        ],
    },
});

const filtered = computed(() => isFiltering(props.filters));

const filters = useListFilters(
    {
        ...props.filters,
        subject_type: props.filters.subject_type || ALL,
        causer: props.filters.causer || ALL,
        event: props.filters.event || ALL,
    },
    () => ActivityLogController.index(),
);

// A record filter (from a history panel) belongs to one subject type.
watch(
    () => filters.subject_type,
    () => (filters.subject_id = ''),
);

const subjectTypeLabel = computed(
    () =>
        props.subjectTypes.find(
            (option) => option.value === filters.subject_type,
        )?.label ?? '',
);
</script>

<template>
    <Head title="Log Aktivitas" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <PageHeader
            title="Log Aktivitas"
            description="Perubahan data master, role, dan aktivitas login. Password tidak pernah dicatat."
        />

        <div class="rounded-2xl border bg-card">
            <div class="flex flex-wrap items-end gap-3 border-b p-4">
                <Select v-model="filters.subject_type">
                    <SelectTrigger class="w-44" aria-label="Filter jenis data">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">Semua data</SelectItem>
                        <SelectItem
                            v-for="option in subjectTypes"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Select v-model="filters.event">
                    <SelectTrigger class="w-48" aria-label="Filter peristiwa">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">Semua peristiwa</SelectItem>
                        <SelectItem
                            v-for="option in events"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Select v-model="filters.causer">
                    <SelectTrigger class="w-48" aria-label="Filter pelaku">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">Semua pelaku</SelectItem>
                        <SelectItem
                            v-for="causer in causers"
                            :key="causer.id"
                            :value="String(causer.id)"
                        >
                            {{ causer.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <div class="grid gap-1">
                    <Label for="filter-from" class="text-xs">Dari</Label>
                    <Input
                        id="filter-from"
                        v-model="filters.from"
                        type="date"
                        class="w-40"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="filter-to" class="text-xs">Sampai</Label>
                    <Input
                        id="filter-to"
                        v-model="filters.to"
                        type="date"
                        class="w-40"
                        :min="filters.from || undefined"
                    />
                </div>
                <Button
                    v-if="filters.subject_id"
                    variant="outline"
                    size="sm"
                    @click="filters.subject_id = ''"
                >
                    {{ subjectTypeLabel }} #{{ filters.subject_id }}
                    <X />
                    <span class="sr-only">Hapus filter data</span>
                </Button>
            </div>

            <Table>
                <TableHeader class="sticky top-0 bg-card">
                    <TableRow>
                        <TableHead>Waktu</TableHead>
                        <TableHead>Peristiwa</TableHead>
                        <TableHead>Data</TableHead>
                        <TableHead>Oleh</TableHead>
                        <TableHead>Perubahan</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="entry in activities.data"
                        :key="entry.id"
                        class="align-top"
                    >
                        <TableCell class="whitespace-nowrap tabular-nums">
                            <time :datetime="entry.created_at">
                                {{ formatDateTime(entry.created_at) }}
                            </time>
                        </TableCell>
                        <TableCell>
                            <ActivityEventBadge :entry="entry" />
                        </TableCell>
                        <TableCell>
                            <template v-if="entry.subject">
                                <div class="text-xs text-muted-foreground">
                                    {{ entry.subject.type_label }}
                                </div>
                                <div
                                    :class="{
                                        'font-mono':
                                            entry.subject.type !== 'user',
                                    }"
                                >
                                    {{ entry.subject.label }}
                                </div>
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell>
                            {{ entry.causer?.name ?? 'Sistem' }}
                        </TableCell>
                        <TableCell class="min-w-72 whitespace-normal">
                            <div class="flex flex-col gap-1.5">
                                <ActivityChanges
                                    v-if="entry.changes.length > 0"
                                    :changes="entry.changes"
                                />
                                <ActivityProperties
                                    :properties="entry.properties"
                                />
                            </div>
                        </TableCell>
                    </TableRow>
                    <TableEmpty
                        v-if="activities.data.length === 0"
                        :colspan="5"
                    >
                        <EmptyState
                            v-if="filtered"
                            :icon="SearchX"
                            title="Tidak ada aktivitas yang cocok"
                            description="Ubah kata kunci atau filter pencarian."
                        >
                            <Button variant="outline" size="sm" as-child>
                                <Link :href="ActivityLogController.index()">
                                    Hapus filter
                                </Link>
                            </Button>
                        </EmptyState>
                        <EmptyState
                            v-else
                            :icon="History"
                            title="Belum ada aktivitas"
                            description="Aktivitas tercatat otomatis saat data diubah atau pengguna masuk."
                        />
                    </TableEmpty>
                </TableBody>
            </Table>

            <TablePagination :paginator="activities" />
        </div>
    </div>
</template>
