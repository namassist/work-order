<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import UserForm from '@/components/admin/UserForm.vue';
import PagePanel from '@/components/PagePanel.vue';
import type { DepartmentOption } from '@/types';

defineProps<{
    departments: DepartmentOption[];
    roles: string[] | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administrasi' },
            { title: 'Pengguna', href: UserController.index() },
            { title: 'Tambah' },
        ],
    },
});
</script>

<template>
    <Head title="Tambah pengguna" />

    <PagePanel title="Tambah pengguna">
        <UserForm :user="null" :departments="departments" :roles="roles" />
    </PagePanel>
</template>
