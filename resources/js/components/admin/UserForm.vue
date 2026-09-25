<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { DepartmentOption, EditableUser } from '@/types';

const props = defineProps<{
    user: EditableUser | null;
    departments: DepartmentOption[];
    /** Null when the current user may not assign roles. */
    roles: string[] | null;
    isSelf?: boolean;
}>();

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    department_id: props.user?.department_id ?? null,
    is_active: props.user?.is_active ?? true,
    roles: props.user?.roles ?? [],
});

const toggleRole = (role: string, checked: boolean | 'indeterminate') => {
    form.roles =
        checked === true
            ? [...form.roles, role]
            : form.roles.filter((name) => name !== role);
};

const submit = () => {
    form.transform((data) => {
        const { roles, is_active, ...rest } = data;

        return {
            ...rest,
            ...(props.user ? { is_active } : {}),
            ...(props.roles ? { roles } : {}),
        };
    }).submit(
        props.user
            ? UserController.update(props.user.id)
            : UserController.store(),
    );
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div class="grid gap-6 md:grid-cols-2">
            <div class="grid gap-2">
                <Label for="user-name">Nama</Label>
                <Input
                    id="user-name"
                    v-model="form.name"
                    required
                    autocomplete="off"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="user-email">Email</Label>
                <Input
                    id="user-email"
                    v-model="form.email"
                    type="email"
                    required
                    autocomplete="off"
                />
                <InputError :message="form.errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="user-department">Departemen</Label>
                <Select v-model="form.department_id">
                    <SelectTrigger id="user-department" class="w-full">
                        <SelectValue placeholder="Pilih departemen" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="department in departments"
                            :key="department.id"
                            :value="department.id"
                        >
                            <span class="font-mono">{{ department.code }}</span>
                            {{ department.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.department_id" />
            </div>

            <div v-if="user" class="grid content-start gap-2">
                <Label>Status</Label>
                <div class="flex h-9 items-center gap-2">
                    <Checkbox
                        id="user-active"
                        v-model="form.is_active"
                        :disabled="isSelf"
                    />
                    <Label for="user-active">Aktif</Label>
                </div>
                <p v-if="isSelf" class="text-xs text-muted-foreground">
                    Anda tidak dapat menonaktifkan akun sendiri.
                </p>
                <InputError :message="form.errors.is_active" />
            </div>
        </div>

        <fieldset v-if="roles" class="space-y-3">
            <legend class="text-sm font-medium">Role</legend>
            <div class="flex flex-wrap gap-x-6 gap-y-3">
                <div
                    v-for="role in roles"
                    :key="role"
                    class="flex items-center gap-2"
                >
                    <Checkbox
                        :id="`role-${role}`"
                        :model-value="form.roles.includes(role)"
                        @update:model-value="toggleRole(role, $event)"
                    />
                    <Label :for="`role-${role}`">{{ role }}</Label>
                </div>
            </div>
            <InputError :message="form.errors.roles" />
        </fieldset>

        <p v-if="!user" class="text-sm text-muted-foreground">
            Pengguna baru login dengan password default. Minta mereka
            menggantinya di Pengaturan &rsaquo; Security setelah login pertama.
        </p>

        <div class="flex items-center gap-2">
            <Button type="submit" :disabled="form.processing">Simpan</Button>
            <Button variant="outline" as-child>
                <Link :href="UserController.index()">Batal</Link>
            </Button>
        </div>
    </form>
</template>
