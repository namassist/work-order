<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import UserForm from '@/components/admin/UserForm.vue';
import PageHeader from '@/components/PageHeader.vue';
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

    <div class="flex flex-1 flex-col gap-4 p-4">
        <PageHeader
            title="Tambah pengguna"
            description="Pengguna baru masuk dengan password default dan wajib menggantinya."
        />
        <div class="max-w-3xl rounded-2xl border bg-card p-6">
            <UserForm :user="null" :departments="departments" :roles="roles" />
        </div>
    </div>
</template>
