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
import ListToolbar from '@/components/ListToolbar.vue';
import PagePanel from '@/components/PagePanel.vue';
import PersonName from '@/components/PersonName.vue';
import { panelTableClass } from '@/lib/panel';
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

    <PagePanel title="Log Aktivitas">
        <ListToolbar>
            <Select v-model="filters.subject_type">
                <SelectTrigger
                    class="w-full sm:w-44"
                    aria-label="Filter jenis data"
                >
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
                <SelectTrigger
                    class="w-full sm:w-48"
                    aria-label="Filter peristiwa"
                >
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
                <SelectTrigger
                    class="w-full sm:w-48"
                    aria-label="Filter pelaku"
                >
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
            <fieldset class="flex w-full min-w-0 items-center gap-2 sm:w-auto">
                <legend class="sr-only">Tanggal</legend>
                <Label for="filter-from" class="text-muted-foreground">
                    Tanggal
                </Label>
                <Input
                    id="filter-from"
                    v-model="filters.from"
                    type="date"
                    class="min-w-0 flex-1 sm:w-36 sm:flex-none"
                    aria-label="Dari tanggal"
                />
                <span class="text-muted-foreground" aria-hidden="true">–</span>
                <Input
                    id="filter-to"
                    v-model="filters.to"
                    type="date"
                    class="min-w-0 flex-1 sm:w-36 sm:flex-none"
                    aria-label="Sampai tanggal"
                    :min="filters.from || undefined"
                />
            </fieldset>
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
        </ListToolbar>

        <Table :class="panelTableClass">
            <TableHeader class="sticky top-0 bg-card">
                <TableRow>
                    <TableHead>Waktu</TableHead>
                    <TableHead class="hidden md:table-cell"
                        >Peristiwa</TableHead
                    >
                    <TableHead>Data</TableHead>
                    <TableHead class="hidden md:table-cell">Oleh</TableHead>
                    <TableHead class="hidden md:table-cell"
                        >Perubahan</TableHead
                    >
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow
                    v-for="entry in activities.data"
                    :key="entry.id"
                    class="align-top"
                >
                    <TableCell class="whitespace-nowrap tabular-nums">
                        <time :datetime="entry.created_at" class="block">
                            {{ formatDateTime(entry.created_at) }}
                        </time>
                        <ActivityEventBadge
                            :entry="entry"
                            class="mt-1 md:hidden"
                        />
                    </TableCell>
                    <TableCell class="hidden md:table-cell">
                        <ActivityEventBadge :entry="entry" />
                    </TableCell>
                    <TableCell>
                        <template v-if="entry.subject">
                            <div class="text-xs text-muted-foreground">
                                {{ entry.subject.type_label }}
                            </div>
                            <div
                                :class="{
                                    'font-mono': entry.subject.type !== 'user',
                                }"
                            >
                                {{ entry.subject.label }}
                            </div>
                        </template>
                        <span v-else class="text-muted-foreground">—</span>
                    </TableCell>
                    <TableCell class="hidden md:table-cell">
                        <PersonName
                            v-if="entry.causer"
                            :name="entry.causer.name"
                        />
                        <span v-else class="text-muted-foreground">Sistem</span>
                    </TableCell>
                    <TableCell
                        class="hidden min-w-72 whitespace-normal md:table-cell"
                    >
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
                <TableEmpty v-if="activities.data.length === 0" :colspan="5">
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
    </PagePanel>
</template>
