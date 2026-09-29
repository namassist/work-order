<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import FormFooter from '@/components/FormFooter.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    AssignableDepartment,
    AssignableRole,
    CompanyScope,
    EditableUser,
} from '@/types';

const props = defineProps<{
    user: EditableUser | null;
    departments: AssignableDepartment[];
    /** Null when the current user may not assign roles. */
    roles: AssignableRole[] | null;
    isSelf?: boolean;
}>();

const SCOPE_LABELS: Record<CompanyScope, string> = {
    client: 'perusahaan klien',
    executor: 'perusahaan pelaksana',
};

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    department_id: props.user?.department_id ?? null,
    is_active: props.user?.is_active ?? true,
    roles: props.user?.roles ?? [],
});

// Departments grouped under their company, in the order the server sent them.
const departmentGroups = computed(() => {
    const groups = new Map<string, AssignableDepartment[]>();

    for (const department of props.departments) {
        const name = department.company.name;
        groups.set(name, [...(groups.get(name) ?? []), department]);
    }

    return [...groups].map(([company, departments]) => ({
        company,
        departments,
    }));
});

// Roles fit users of one company kind, see App\Enums\CompanyScope. The
// server refuses the rest; the form only disables them.
const selectedScope = computed<CompanyScope | null>(
    () =>
        props.departments.find(
            (department) => department.id === form.department_id,
        )?.company.scope ?? null,
);

const fits = (role: AssignableRole) =>
    role.company_scope === null ||
    selectedScope.value === null ||
    role.company_scope === selectedScope.value;

watch(selectedScope, () => {
    form.roles = form.roles.filter((name) => {
        const role = props.roles?.find((candidate) => candidate.name === name);

        return role === undefined || fits(role);
    });
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
    <form @submit.prevent="submit">
        <div class="space-y-6 px-4 py-6 sm:px-6">
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
                            <SelectGroup
                                v-for="group in departmentGroups"
                                :key="group.company"
                            >
                                <SelectLabel>{{ group.company }}</SelectLabel>
                                <SelectItem
                                    v-for="department in group.departments"
                                    :key="department.id"
                                    :value="department.id"
                                >
                                    <span class="font-mono">{{
                                        department.code
                                    }}</span>
                                    {{ department.name }}
                                </SelectItem>
                            </SelectGroup>
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
                        :key="role.name"
                        class="flex items-center gap-2"
                    >
                        <Checkbox
                            :id="`role-${role.name}`"
                            :model-value="form.roles.includes(role.name)"
                            :disabled="!fits(role)"
                            @update:model-value="toggleRole(role.name, $event)"
                        />
                        <Label
                            :for="`role-${role.name}`"
                            :class="{ 'text-muted-foreground': !fits(role) }"
                        >
                            {{ role.label }}
                            <span
                                v-if="!fits(role) && role.company_scope"
                                class="text-xs font-normal"
                            >
                                (khusus {{ SCOPE_LABELS[role.company_scope] }})
                            </span>
                        </Label>
                    </div>
                </div>
                <InputError :message="form.errors.roles" />
            </fieldset>

            <p v-if="!user" class="text-sm text-muted-foreground">
                Pengguna baru login dengan password default. Minta mereka
                menggantinya di Pengaturan &rsaquo; Security setelah login
                pertama.
            </p>
        </div>

        <FormFooter>
            <Button type="submit" :disabled="form.processing">Simpan</Button>
            <Button variant="outline" as-child>
                <Link :href="UserController.index()">Batal</Link>
            </Button>
        </FormFooter>
    </form>
</template>
