<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { History } from '@lucide/vue';
import { ref } from 'vue';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import ActivityHistorySheet from '@/components/admin/ActivityHistorySheet.vue';
import UserForm from '@/components/admin/UserForm.vue';
import ListToolbar from '@/components/ListToolbar.vue';
import PagePanel from '@/components/PagePanel.vue';
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
            { title: 'Administrasi' },
            { title: 'Pengguna', href: UserController.index() },
            { title: 'Ubah' },
        ],
    },
});

const hasPermission = useCan();
const historyOpen = ref(false);
</script>

<template>
    <Head :title="`Ubah ${user.name}`" />

    <PagePanel title="Ubah pengguna">
        <template #meta>{{ user.email }}</template>
        <ListToolbar v-if="hasPermission('activity-log.view')">
            <template #actions>
                <Button variant="outline" @click="historyOpen = true">
                    <History /> Riwayat
                </Button>
            </template>
        </ListToolbar>
        <UserForm
            :user="user"
            :departments="departments"
            :roles="roles"
            :is-self="isSelf"
        />
    </PagePanel>

    <ActivityHistorySheet
        v-if="hasPermission('activity-log.view')"
        v-model:open="historyOpen"
        subject-type="user"
        :subject-id="user.id"
        :title="user.name"
    />
</template>
