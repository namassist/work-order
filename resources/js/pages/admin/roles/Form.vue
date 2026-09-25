<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { EditableRole } from '@/types';

const props = defineProps<{
    role: EditableRole | null;
    permissionGroups: Record<string, string[]>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Role & Permission', href: RoleController.index() },
            { title: 'Form', href: RoleController.index() },
        ],
    },
});

const locked = props.role?.is_system ?? false;

const form = useForm({
    name: props.role?.name ?? '',
    permissions: props.role?.permissions ?? ([] as string[]),
});

const togglePermission = (
    permission: string,
    checked: boolean | 'indeterminate',
) => {
    form.permissions =
        checked === true
            ? [...form.permissions, permission]
            : form.permissions.filter((name) => name !== permission);
};

const actionLabel = (permission: string) => permission.split('.')[1];

const submit = () => {
    form.submit(
        props.role
            ? RoleController.update(props.role.id)
            : RoleController.store(),
    );
};
</script>

<template>
    <Head :title="role ? `Ubah role ${role.name}` : 'Tambah role'" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <h1 class="font-serif text-3xl">
            {{ role ? `Ubah role ${role.name}` : 'Tambah role' }}
        </h1>

        <form
            class="max-w-3xl space-y-6 rounded-2xl border bg-card p-6"
            @submit.prevent="submit"
        >
            <p
                v-if="locked"
                class="rounded-lg bg-muted p-3 text-sm text-muted-foreground"
            >
                Role admin adalah role sistem: namanya tetap dan selalu memiliki
                semua permission.
            </p>

            <div class="grid max-w-sm gap-2">
                <Label for="role-name">Nama role</Label>
                <Input
                    id="role-name"
                    v-model="form.name"
                    class="font-mono"
                    required
                    :readonly="locked"
                    placeholder="teknisi"
                />
                <p class="text-xs text-muted-foreground">
                    Huruf kecil, angka, dan tanda hubung.
                </p>
                <InputError :message="form.errors.name" />
            </div>

            <fieldset class="space-y-4">
                <legend class="text-sm font-medium">Permission</legend>
                <div
                    v-for="(permissions, resource) in permissionGroups"
                    :key="resource"
                    class="grid gap-2 sm:grid-cols-[10rem_1fr]"
                >
                    <span class="font-mono text-sm">{{ resource }}</span>
                    <div class="flex flex-wrap gap-x-5 gap-y-2">
                        <div
                            v-for="permission in permissions"
                            :key="permission"
                            class="flex items-center gap-2"
                        >
                            <Checkbox
                                :id="`permission-${permission}`"
                                :model-value="
                                    form.permissions.includes(permission)
                                "
                                :disabled="locked"
                                @update:model-value="
                                    togglePermission(permission, $event)
                                "
                            />
                            <Label :for="`permission-${permission}`">
                                {{ actionLabel(permission) }}
                            </Label>
                        </div>
                    </div>
                </div>
                <InputError :message="form.errors.permissions" />
            </fieldset>

            <div class="flex items-center gap-2">
                <Button type="submit" :disabled="form.processing || locked">
                    Simpan
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="RoleController.index()">Batal</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
