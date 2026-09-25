<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import UserForm from '@/components/admin/UserForm.vue';
import type { DepartmentOption } from '@/types';

defineProps<{
    departments: DepartmentOption[];
    roles: string[] | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pengguna', href: UserController.index() },
            { title: 'Tambah', href: UserController.create() },
        ],
    },
});
</script>

<template>
    <Head title="Tambah pengguna" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="font-serif text-3xl">Tambah pengguna</h1>
        <div class="max-w-3xl rounded-2xl border bg-card p-6">
            <UserForm :user="null" :departments="departments" :roles="roles" />
        </div>
    </div>
</template>
