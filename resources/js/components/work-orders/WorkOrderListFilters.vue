<script setup lang="ts">
import { computed } from 'vue';
import WorkOrderController from '@/actions/App/Http/Controllers/WorkOrders/WorkOrderController';
import ActiveFilterChips from '@/components/list-filters/ActiveFilterChips.vue';
import DateRangeFilter from '@/components/list-filters/DateRangeFilter.vue';
import FilterField from '@/components/list-filters/FilterField.vue';
import FilterPopover from '@/components/list-filters/FilterPopover.vue';
import FilterSelect from '@/components/list-filters/FilterSelect.vue';
import FilterSheet from '@/components/list-filters/FilterSheet.vue';
import FilterToolbar from '@/components/list-filters/FilterToolbar.vue';
import SearchFilter from '@/components/list-filters/SearchFilter.vue';
import SortSelect from '@/components/list-filters/SortSelect.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { ALL, useListFilters } from '@/composables/useListFilters';
import type { CalendarDateRange } from '@/lib/dateRangePresets';
import type {
    WorkOrderFilterChipKey,
    WorkOrderFilterOptions,
    WorkOrderFilters,
} from '@/lib/workOrderFilters';
import { workOrderFilterChips } from '@/lib/workOrderFilters';

/**
 * The Work Order list's toolbar (docs/DESIGN.md › Toolbar filter): search,
 * Status (with the "Aktif" group first) and Urgensi inline, the rest behind "Filter", the sort at the far
 * right, and a chip per active filter. Query parameters are the server's, so
 * existing URLs, the export, and dashboard links keep working.
 */
const props = defineProps<
    WorkOrderFilterOptions & {
        /** As the server validated it, so chips name what the list shows. */
        filters: WorkOrderFilters;
        canRestore: boolean;
    }
>();

const filters = useListFilters(
    {
        ...props.filters,
        status: props.filters.status || ALL,
        urgency: props.filters.urgency || ALL,
        sort: props.filters.sort || ALL,
        department: props.filters.department || ALL,
        target: props.filters.target || ALL,
        category: props.filters.category || ALL,
    },
    () => WorkOrderController.index(),
);

const departmentOptions = computed(() =>
    (props.departments ?? []).map((department) => ({
        value: String(department.id),
        label: department.name,
        code: department.code,
    })),
);
const targetDepartmentOptions = computed(() =>
    props.targetDepartments.map((department) => ({
        value: String(department.id),
        label: department.name,
        code: department.code,
    })),
);
/** Groups ("Aktif") first, then every single status. */
const statusOptions = computed(() => [
    ...props.statusGroups,
    ...props.statuses,
]);
const categoryOptions = computed(() =>
    props.categories.map((category) => ({
        value: String(category.id),
        label: category.name,
        code: category.code,
    })),
);
const sortOptions = [
    { value: ALL, label: 'Terbaru' },
    { value: 'urgensi', label: 'Paling mendesak' },
    { value: 'diperbarui', label: 'Terakhir diperbarui' },
];

/** Both ends change together, so the list reloads once. */
const setCreated = (range: CalendarDateRange) => {
    filters.from = range.from;
    filters.to = range.to;
};

/** Active filters behind the desktop "Filter" button. */
const popoverCount = computed(
    () =>
        [
            filters.department !== ALL,
            filters.target !== ALL,
            filters.category !== ALL,
            filters.overdue,
            filters.from !== '' || filters.to !== '',
            filters.trashed,
        ].filter(Boolean).length,
);

/** Active filters in the narrow-screen sheet: all but search and sort. */
const sheetCount = computed(
    () =>
        popoverCount.value +
        [filters.status !== ALL, filters.urgency !== ALL].filter(Boolean)
            .length,
);

const chips = computed(() => workOrderFilterChips(props.filters, props));

const clear = (key: WorkOrderFilterChipKey) => {
    if (key === 'search') {
        filters.search = '';
    } else if (key === 'created') {
        setCreated({ from: '', to: '' });
    } else if (key === 'trashed' || key === 'overdue') {
        filters[key] = false;
    } else {
        filters[key] = ALL;
    }
};

const FILTER_KEYS: WorkOrderFilterChipKey[] = [
    'search',
    'status',
    'urgency',
    'department',
    'target',
    'category',
    'overdue',
    'created',
    'trashed',
];

/** Clears every filter; the sort is not a filter, so it stays. */
const reset = () => FILTER_KEYS.forEach(clear);
</script>

<template>
    <FilterToolbar>
        <template v-if="$slots.actions" #actions>
            <slot name="actions" />
        </template>

        <SearchFilter
            v-model="filters.search"
            class="min-w-44 flex-1 xl:max-w-64"
            label="Cari work order"
            placeholder="Cari nomor atau judul"
        />

        <template #filters>
            <FilterSelect
                v-model="filters.status"
                label="Filter status"
                all-label="Semua status"
                :options="statusOptions"
                trigger-class="w-fit min-w-36 shrink-0"
            />
            <FilterSelect
                v-model="filters.urgency"
                label="Filter urgensi"
                all-label="Semua urgensi"
                :options="urgencies"
                trigger-class="w-fit min-w-36 shrink-0"
            />
            <FilterPopover :count="popoverCount">
                <FilterField
                    v-if="departments"
                    label="Departemen pemohon"
                    for="filter-department"
                    data-test="filter-department"
                >
                    <FilterSelect
                        id="filter-department"
                        v-model="filters.department"
                        label="Filter departemen pemohon"
                        all-label="Semua departemen"
                        :options="departmentOptions"
                    />
                </FilterField>
                <FilterField
                    label="Departemen tujuan"
                    for="filter-target"
                    data-test="filter-target"
                >
                    <FilterSelect
                        id="filter-target"
                        v-model="filters.target"
                        label="Filter departemen tujuan"
                        all-label="Semua departemen"
                        :options="targetDepartmentOptions"
                    />
                </FilterField>
                <FilterField
                    label="Kategori"
                    for="filter-category"
                    data-test="filter-category"
                >
                    <FilterSelect
                        id="filter-category"
                        v-model="filters.category"
                        label="Filter kategori"
                        all-label="Semua kategori"
                        :options="categoryOptions"
                    />
                </FilterField>
                <FilterField label="Dibuat" for="filter-created">
                    <DateRangeFilter
                        id="filter-created"
                        label="Dibuat"
                        :from="filters.from"
                        :to="filters.to"
                        @update:range="setCreated"
                    />
                </FilterField>
                <div class="flex items-center gap-2" data-test="filter-overdue">
                    <Checkbox id="filter-overdue" v-model="filters.overdue" />
                    <Label for="filter-overdue">Hanya yang terlambat</Label>
                </div>
                <div
                    v-if="canRestore"
                    class="flex items-center gap-2"
                    data-test="filter-trashed"
                >
                    <Checkbox id="show-trashed" v-model="filters.trashed" />
                    <Label for="show-trashed">Tampilkan terhapus</Label>
                </div>
            </FilterPopover>
        </template>

        <template #sheet>
            <FilterSheet :count="sheetCount">
                <FilterField label="Status" for="sheet-status">
                    <FilterSelect
                        id="sheet-status"
                        v-model="filters.status"
                        label="Filter status"
                        all-label="Semua status"
                        :options="statusOptions"
                    />
                </FilterField>
                <FilterField label="Urgensi" for="sheet-urgency">
                    <FilterSelect
                        id="sheet-urgency"
                        v-model="filters.urgency"
                        label="Filter urgensi"
                        all-label="Semua urgensi"
                        :options="urgencies"
                    />
                </FilterField>
                <FilterField
                    v-if="departments"
                    label="Departemen pemohon"
                    for="sheet-department"
                >
                    <FilterSelect
                        id="sheet-department"
                        v-model="filters.department"
                        label="Filter departemen pemohon"
                        all-label="Semua departemen"
                        :options="departmentOptions"
                    />
                </FilterField>
                <FilterField label="Departemen tujuan" for="sheet-target">
                    <FilterSelect
                        id="sheet-target"
                        v-model="filters.target"
                        label="Filter departemen tujuan"
                        all-label="Semua departemen"
                        :options="targetDepartmentOptions"
                    />
                </FilterField>
                <FilterField label="Kategori" for="sheet-category">
                    <FilterSelect
                        id="sheet-category"
                        v-model="filters.category"
                        label="Filter kategori"
                        all-label="Semua kategori"
                        :options="categoryOptions"
                    />
                </FilterField>
                <FilterField label="Dibuat" for="sheet-created">
                    <DateRangeFilter
                        id="sheet-created"
                        label="Dibuat"
                        :from="filters.from"
                        :to="filters.to"
                        @update:range="setCreated"
                    />
                </FilterField>
                <div class="flex items-center gap-2">
                    <Checkbox id="sheet-overdue" v-model="filters.overdue" />
                    <Label for="sheet-overdue">Hanya yang terlambat</Label>
                </div>
                <div v-if="canRestore" class="flex items-center gap-2">
                    <Checkbox id="sheet-trashed" v-model="filters.trashed" />
                    <Label for="sheet-trashed">Tampilkan terhapus</Label>
                </div>
                <FilterField label="Urutkan" for="sheet-sort">
                    <SortSelect
                        id="sheet-sort"
                        v-model="filters.sort"
                        :options="sortOptions"
                    />
                </FilterField>
            </FilterSheet>
        </template>

        <template #sort>
            <SortSelect
                v-model="filters.sort"
                :options="sortOptions"
                trigger-class="w-48 shrink-0"
            />
        </template>

        <template #chips>
            <ActiveFilterChips :chips="chips" @remove="clear" @reset="reset" />
        </template>
    </FilterToolbar>
</template>
