<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, History, Search, SearchX, UserPlus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import RegistrationController from '@/actions/App/Http/Controllers/Admin/RegistrationController';
import ActivityHistorySheet from '@/components/admin/ActivityHistorySheet.vue';
import ApproveRegistrationDialog from '@/components/admin/ApproveRegistrationDialog.vue';
import RejectRegistrationDialog from '@/components/admin/RejectRegistrationDialog.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import TablePagination from '@/components/admin/TablePagination.vue';
import ClickableRow from '@/components/ClickableRow.vue';
import EmptyState from '@/components/EmptyState.vue';
import ListToolbar from '@/components/ListToolbar.vue';
import PagePanel from '@/components/PagePanel.vue';
import PersonName from '@/components/PersonName.vue';
import RowActionsMenu from '@/components/RowActionsMenu.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
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
import { useFormatDate } from '@/composables/useFormatDate';
import { useListFilters } from '@/composables/useListFilters';
import { panelTableClass } from '@/lib/panel';
import type {
    AccountStatus,
    AssignableRole,
    Paginated,
    Registration,
    RegistrationDepartment,
} from '@/types';

const props = defineProps<{
    registrations: Paginated<Registration>;
    filters: { status: AccountStatus; search: string };
    counts: Record<AccountStatus, number>;
    departments: RegistrationDepartment[];
    roles: AssignableRole[];
    can: { approve: boolean; reject: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administrasi' },
            { title: 'Pendaftaran', href: RegistrationController.index() },
        ],
    },
});

const TABS: { status: AccountStatus; label: string }[] = [
    { status: 'pending', label: 'Menunggu' },
    { status: 'rejected', label: 'Ditolak' },
    { status: 'approved', label: 'Disetujui' },
];

const { formatDateTime } = useFormatDate();
const hasPermission = useCan();

// The status tabs are links; only the search is a live filter.
const filters = useListFilters({ search: props.filters.search }, () =>
    RegistrationController.index({ query: { status: props.filters.status } }),
);

const pendingTab = computed(() => props.filters.status === 'pending');

const canApprove = (registration: Registration) =>
    props.can.approve && registration.account_status !== 'approved';
const canReject = (registration: Registration) =>
    props.can.reject && registration.account_status === 'pending';
const hasActions = (registration: Registration) =>
    canApprove(registration) ||
    canReject(registration) ||
    hasPermission('activity-log.view');

const selected = ref<Registration | null>(null);
const approveOpen = ref(false);
const rejectOpen = ref(false);
const historyOpen = ref(false);

const openApprove = (registration: Registration) => {
    selected.value = registration;
    approveOpen.value = true;
};

const openReject = (registration: Registration) => {
    selected.value = registration;
    rejectOpen.value = true;
};

const openHistory = (registration: Registration) => {
    selected.value = registration;
    historyOpen.value = true;
};
</script>

<template>
    <Head title="Pendaftaran" />

    <PagePanel title="Pendaftaran">
        <ListToolbar>
            <template #actions>
                <nav
                    aria-label="Status pendaftaran"
                    class="flex flex-wrap items-center gap-1"
                >
                    <Button
                        v-for="tab in TABS"
                        :key="tab.status"
                        :variant="
                            props.filters.status === tab.status
                                ? 'secondary'
                                : 'ghost'
                        "
                        size="sm"
                        as-child
                    >
                        <Link
                            :href="
                                RegistrationController.index({
                                    query: { status: tab.status },
                                })
                            "
                            :aria-current="
                                props.filters.status === tab.status
                                    ? 'page'
                                    : undefined
                            "
                            preserve-scroll
                        >
                            {{ tab.label }}
                            <span
                                class="text-muted-foreground tabular-nums"
                                data-test="tab-count"
                                >{{ counts[tab.status] }}</span
                            >
                        </Link>
                    </Button>
                </nav>
            </template>

            <div class="relative w-full sm:w-64">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="filters.search"
                    type="search"
                    class="pl-9"
                    placeholder="Cari nama atau email"
                    aria-label="Cari pendaftaran"
                />
            </div>
        </ListToolbar>

        <Table :class="panelTableClass">
            <TableHeader class="sticky top-0 bg-card">
                <TableRow>
                    <TableHead>Nama</TableHead>
                    <TableHead class="hidden md:table-cell">
                        Perusahaan · Departemen
                    </TableHead>
                    <TableHead class="hidden lg:table-cell">
                        Didaftarkan
                    </TableHead>
                    <TableHead v-if="!pendingTab" class="hidden lg:table-cell">
                        {{
                            props.filters.status === 'rejected'
                                ? 'Alasan'
                                : 'Role'
                        }}
                    </TableHead>
                    <TableHead class="hidden sm:table-cell">Status</TableHead>
                    <TableHead class="w-0">
                        <span class="sr-only">Aksi</span>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <ClickableRow
                    v-for="registration in registrations.data"
                    :key="registration.id"
                    :disabled="!canApprove(registration)"
                    @activate="openApprove(registration)"
                >
                    <TableCell class="w-full max-w-0 min-w-48 py-3">
                        <PersonName :name="registration.name">
                            <p class="truncate text-muted-foreground">
                                {{ registration.email }}
                            </p>
                            <p
                                class="truncate text-xs text-muted-foreground md:hidden"
                            >
                                {{ registration.company.name }} ·
                                <span class="font-mono">{{
                                    registration.department.code
                                }}</span>
                            </p>
                        </PersonName>
                    </TableCell>
                    <TableCell class="hidden md:table-cell">
                        <p>{{ registration.company.name }}</p>
                        <p class="text-muted-foreground">
                            <span class="font-mono">{{
                                registration.department.code
                            }}</span>
                            {{ registration.department.name }}
                        </p>
                    </TableCell>
                    <TableCell
                        class="hidden whitespace-nowrap text-muted-foreground lg:table-cell"
                    >
                        {{
                            registration.registered_at
                                ? formatDateTime(registration.registered_at)
                                : '—'
                        }}
                    </TableCell>
                    <TableCell
                        v-if="!pendingTab"
                        class="hidden max-w-72 min-w-48 whitespace-normal lg:table-cell"
                    >
                        <p
                            v-if="registration.account_status === 'rejected'"
                            class="line-clamp-2"
                        >
                            {{ registration.rejection_reason }}
                        </p>
                        <div v-else class="flex flex-wrap gap-1">
                            <Badge
                                v-for="role in registration.roles"
                                :key="role.name"
                                variant="outline"
                            >
                                {{ role.label }}
                            </Badge>
                        </div>
                        <p
                            v-if="registration.reviewed_at"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            {{ registration.reviewer ?? '—' }},
                            {{ formatDateTime(registration.reviewed_at) }}
                        </p>
                    </TableCell>
                    <TableCell class="hidden sm:table-cell">
                        <StatusBadge
                            :is-active="registration.is_active"
                            :account-status="registration.account_status"
                        />
                    </TableCell>
                    <TableCell class="text-right">
                        <div class="flex items-center justify-end gap-2">
                            <Button
                                v-if="canApprove(registration)"
                                variant="outline"
                                size="sm"
                                @click="openApprove(registration)"
                            >
                                <Check />
                                <span class="hidden sm:inline">{{
                                    registration.account_status === 'rejected'
                                        ? 'Tinjau ulang'
                                        : 'Setujui'
                                }}</span>
                                <span class="sr-only sm:hidden"
                                    >Setujui {{ registration.name }}</span
                                >
                            </Button>
                            <RowActionsMenu
                                v-if="hasActions(registration)"
                                :label="`Aksi ${registration.name}`"
                            >
                                <DropdownMenuItem
                                    v-if="canReject(registration)"
                                    variant="destructive"
                                    @select="openReject(registration)"
                                >
                                    <X /> Tolak
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="hasPermission('activity-log.view')"
                                    @select="openHistory(registration)"
                                >
                                    <History /> Riwayat
                                </DropdownMenuItem>
                            </RowActionsMenu>
                        </div>
                    </TableCell>
                </ClickableRow>
                <TableEmpty
                    v-if="registrations.data.length === 0"
                    :colspan="pendingTab ? 5 : 6"
                >
                    <EmptyState
                        v-if="props.filters.search"
                        :icon="SearchX"
                        title="Tidak ada pendaftaran yang cocok"
                        description="Ubah kata kunci pencarian."
                    >
                        <Button variant="outline" size="sm" as-child>
                            <Link
                                :href="
                                    RegistrationController.index({
                                        query: { status: props.filters.status },
                                    })
                                "
                            >
                                Hapus filter
                            </Link>
                        </Button>
                    </EmptyState>
                    <EmptyState
                        v-else
                        :icon="UserPlus"
                        :title="
                            pendingTab
                                ? 'Belum ada pendaftaran yang menunggu'
                                : props.filters.status === 'rejected'
                                  ? 'Belum ada pendaftaran yang ditolak'
                                  : 'Belum ada pendaftaran yang disetujui'
                        "
                        description="Pendaftaran baru muncul di sini setelah seseorang mendaftar."
                    />
                </TableEmpty>
            </TableBody>
        </Table>

        <TablePagination :paginator="registrations" />
    </PagePanel>

    <ApproveRegistrationDialog
        v-model:open="approveOpen"
        :registration="selected"
        :departments="departments"
        :roles="roles"
    />

    <RejectRegistrationDialog
        v-model:open="rejectOpen"
        :registration="selected"
    />

    <ActivityHistorySheet
        v-if="hasPermission('activity-log.view')"
        v-model:open="historyOpen"
        subject-type="user"
        :subject-id="selected?.id ?? null"
        :title="selected?.name ?? ''"
    />
</template>
