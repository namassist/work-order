<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Building,
    History,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    SearchX,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CompanyController from '@/actions/App/Http/Controllers/Admin/CompanyController';
import ActivityHistorySheet from '@/components/admin/ActivityHistorySheet.vue';
import CompanyFormDialog from '@/components/admin/CompanyFormDialog.vue';
import ConfirmDialog from '@/components/admin/ConfirmDialog.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import TablePagination from '@/components/admin/TablePagination.vue';
import ClickableRow from '@/components/ClickableRow.vue';
import EmptyState from '@/components/EmptyState.vue';
import ListToolbar from '@/components/ListToolbar.vue';
import PagePanel from '@/components/PagePanel.vue';
import RowActionsMenu from '@/components/RowActionsMenu.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenuItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
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
import { useCan } from '@/composables/useCan';
import { isFiltering, useListFilters } from '@/composables/useListFilters';
import { panelTableClass } from '@/lib/panel';
import type { Company, ListAbilities, Paginated } from '@/types';

const props = defineProps<{
    companies: Paginated<Company>;
    filters: { search: string; status: string; type: string; trashed: boolean };
    can: ListAbilities;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Master Data' },
            { title: 'Perusahaan', href: CompanyController.index() },
        ],
    },
});

const filters = useListFilters(
    {
        ...props.filters,
        status: props.filters.status || 'all',
        type: props.filters.type || 'all',
    },
    () => CompanyController.index(),
);

const filtered = computed(() => isFiltering(props.filters));

const formOpen = ref(false);
const editing = ref<Company | null>(null);
const deleting = ref<Company | null>(null);
const deleteOpen = ref(false);
const processing = ref(false);

const openCreate = () => {
    editing.value = null;
    formOpen.value = true;
};

const openEdit = (company: Company) => {
    editing.value = company;
    formOpen.value = true;
};

const confirmDelete = (company: Company) => {
    deleting.value = company;
    deleteOpen.value = true;
};

const destroy = () => {
    if (!deleting.value) {
        return;
    }

    router.visit(CompanyController.destroy(deleting.value.id), {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            deleteOpen.value = false;
        },
    });
};

const restore = (company: Company) => {
    router.visit(CompanyController.restore(company.id), {
        preserveScroll: true,
    });
};

const hasPermission = useCan();
const historyOpen = ref(false);
const historyOf = ref<Company | null>(null);

const openHistory = (company: Company) => {
    historyOf.value = company;
    historyOpen.value = true;
};

const canEdit = (company: Company) => props.can.update && !company.deleted_at;

const hasActions = (company: Company) =>
    hasPermission('activity-log.view') ||
    (company.deleted_at
        ? props.can.restore
        : props.can.update || props.can.delete);
</script>

<template>
    <Head title="Perusahaan" />

    <PagePanel title="Perusahaan">
        <ListToolbar>
            <template v-if="can.create" #actions>
                <Button @click="openCreate">
                    <Plus /> Tambah perusahaan
                </Button>
            </template>

            <div class="relative w-full sm:w-64">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="filters.search"
                    type="search"
                    class="pl-9"
                    placeholder="Cari kode atau nama"
                    aria-label="Cari perusahaan"
                />
            </div>
            <Select v-model="filters.type">
                <SelectTrigger class="w-full sm:w-40" aria-label="Filter jenis">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">Semua jenis</SelectItem>
                    <SelectItem value="client">Klien</SelectItem>
                    <SelectItem value="executor">Pelaksana</SelectItem>
                </SelectContent>
            </Select>
            <Select v-model="filters.status">
                <SelectTrigger
                    class="w-full sm:w-40"
                    aria-label="Filter status"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">Semua status</SelectItem>
                    <SelectItem value="active">Aktif</SelectItem>
                    <SelectItem value="inactive">Nonaktif</SelectItem>
                </SelectContent>
            </Select>
            <div v-if="can.restore" class="flex items-center gap-2">
                <Checkbox id="show-trashed" v-model="filters.trashed" />
                <Label for="show-trashed">Tampilkan terhapus</Label>
            </div>
        </ListToolbar>

        <Table :class="panelTableClass">
            <TableHeader class="sticky top-0 bg-card">
                <TableRow>
                    <TableHead>Perusahaan</TableHead>
                    <TableHead class="hidden sm:table-cell">Jenis</TableHead>
                    <TableHead class="hidden lg:table-cell"
                        >Domain email</TableHead
                    >
                    <TableHead class="hidden text-right sm:table-cell"
                        >Departemen</TableHead
                    >
                    <TableHead>Status</TableHead>
                    <TableHead class="w-0">
                        <span class="sr-only">Aksi</span>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <ClickableRow
                    v-for="company in companies.data"
                    :key="company.id"
                    :disabled="!canEdit(company)"
                    @activate="openEdit(company)"
                >
                    <TableCell class="w-full max-w-0 min-w-48 py-3">
                        <component
                            :is="canEdit(company) ? 'button' : 'span'"
                            v-bind="canEdit(company) ? { type: 'button' } : {}"
                            class="flex max-w-full min-w-0 items-baseline gap-2 rounded-sm text-left underline-offset-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :class="{ 'hover:underline': canEdit(company) }"
                            @click="canEdit(company) && openEdit(company)"
                        >
                            <span
                                class="shrink-0 font-mono text-xs text-muted-foreground"
                            >
                                {{ company.code }}
                            </span>
                            <span class="truncate font-semibold">
                                {{ company.name }}
                            </span>
                        </component>
                    </TableCell>
                    <TableCell class="hidden sm:table-cell">
                        {{ company.is_client ? 'Klien' : 'Pelaksana' }}
                    </TableCell>
                    <TableCell
                        class="hidden max-w-64 truncate font-mono text-xs text-muted-foreground lg:table-cell"
                    >
                        {{ company.email_domains.join(', ') || '-' }}
                    </TableCell>
                    <TableCell
                        class="hidden text-right tabular-nums sm:table-cell"
                    >
                        {{ company.departments_count }}
                    </TableCell>
                    <TableCell>
                        <StatusBadge
                            :is-active="company.is_active"
                            :deleted="company.deleted_at !== null"
                        />
                    </TableCell>
                    <TableCell class="text-right">
                        <RowActionsMenu
                            v-if="hasActions(company)"
                            :label="`Aksi ${company.code}`"
                        >
                            <template v-if="company.deleted_at">
                                <DropdownMenuItem
                                    v-if="can.restore"
                                    @select="restore(company)"
                                >
                                    <RotateCcw /> Pulihkan
                                </DropdownMenuItem>
                            </template>
                            <DropdownMenuItem
                                v-else-if="can.update"
                                @select="openEdit(company)"
                            >
                                <Pencil /> Ubah
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="hasPermission('activity-log.view')"
                                @select="openHistory(company)"
                            >
                                <History /> Riwayat
                            </DropdownMenuItem>
                            <template v-if="!company.deleted_at && can.delete">
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    @select="confirmDelete(company)"
                                >
                                    <Trash2 /> Hapus
                                </DropdownMenuItem>
                            </template>
                        </RowActionsMenu>
                    </TableCell>
                </ClickableRow>
                <TableEmpty v-if="companies.data.length === 0" :colspan="6">
                    <EmptyState
                        v-if="filtered"
                        :icon="SearchX"
                        title="Tidak ada perusahaan yang cocok"
                        description="Ubah kata kunci atau filter pencarian."
                    >
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="CompanyController.index()">
                                Hapus filter
                            </Link>
                        </Button>
                    </EmptyState>
                    <EmptyState
                        v-else
                        :icon="Building"
                        title="Belum ada perusahaan"
                        description="Setiap departemen milik satu perusahaan: klien atau pelaksana."
                    >
                        <Button v-if="can.create" size="sm" @click="openCreate">
                            <Plus /> Tambah perusahaan
                        </Button>
                    </EmptyState>
                </TableEmpty>
            </TableBody>
        </Table>

        <TablePagination :paginator="companies" />
    </PagePanel>

    <CompanyFormDialog v-model:open="formOpen" :company="editing" />

    <ActivityHistorySheet
        v-if="hasPermission('activity-log.view')"
        v-model:open="historyOpen"
        subject-type="company"
        :subject-id="historyOf?.id ?? null"
        :title="historyOf?.code ?? ''"
    />

    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Hapus perusahaan?"
        :description="`Perusahaan ${deleting?.code ?? ''} akan disembunyikan dari daftar dan pilihan. Perusahaan yang masih memiliki departemen tidak dapat dihapus; nonaktifkan saja.`"
        confirm-label="Hapus"
        :processing="processing"
        @confirm="destroy"
    />
</template>
