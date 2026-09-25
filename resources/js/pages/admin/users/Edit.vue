<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import UserForm from '@/components/admin/UserForm.vue';
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
</script>

<template>
    <Head :title="`Ubah ${user.name}`" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="font-serif text-3xl">Ubah pengguna</h1>
        <div class="max-w-3xl rounded-2xl border bg-card p-6">
            <UserForm
                :user="user"
                :departments="departments"
                :roles="roles"
                :is-self="isSelf"
            />
        </div>
    </div>
</template>
