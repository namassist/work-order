<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { History } from '@lucide/vue';
import { ref } from 'vue';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import ActivityHistorySheet from '@/components/admin/ActivityHistorySheet.vue';
import UserForm from '@/components/admin/UserForm.vue';
import { Button } from '@/components/ui/button';
import { useCan } from '@/composables/useCan';
import type { DepartmentOption, EditableUser } from '@/types';

defineProps<{
    user: EditableUser;
    departments: DepartmentOption[];
    roles: string[] | null;
    isSelf: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pengguna', href: UserController.index() },
            { title: 'Ubah', href: UserController.index() },
        ],
    },
});

const hasPermission = useCan();
const historyOpen = ref(false);
</script>

<template>
    <Head :title="`Ubah ${user.name}`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <div class="flex max-w-3xl flex-wrap items-end justify-between gap-4">
            <h1 class="font-serif text-3xl">Ubah pengguna</h1>
            <Button
                v-if="hasPermission('activity-log.view')"
                variant="outline"
                @click="historyOpen = true"
            >
                <History /> Riwayat
            </Button>
        </div>
        <div class="max-w-3xl rounded-2xl border bg-card p-6">
            <UserForm
                :user="user"
                :departments="departments"
                :roles="roles"
                :is-self="isSelf"
            />
        </div>
    </div>

    <ActivityHistorySheet
        v-if="hasPermission('activity-log.view')"
        v-model:open="historyOpen"
        subject-type="user"
        :subject-id="user.id"
        :title="user.name"
    />
</template>
