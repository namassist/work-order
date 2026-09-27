<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { computed, watch, watchEffect } from 'vue';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
import InputError from '@/components/InputError.vue';
import FormFooter from '@/components/FormFooter.vue';
import PagePanel from '@/components/PagePanel.vue';
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
import type { CompanyScope, EditableRole, SelectOption } from '@/types';

const props = defineProps<{
    role: EditableRole | null;
    permissionGroups: Record<string, string[]>;
    companyScopes: SelectOption[];
    /** Permissions only a role for the executor company can hold. */
    internalOnlyPermissions: string[];
}>();

/** The select's value for a role that fits users of any company. */
const ANY_COMPANY = 'any';

const pageTitle = computed(() =>
    props.role ? `Ubah role ${props.role.name}` : 'Tambah role',
);

// Create and edit share this page, so keep the last crumb in step with the prop.
watchEffect(() =>
    setLayoutProps({
        breadcrumbs: [
            { title: 'Administrasi' },
            { title: 'Role & Hak Akses', href: RoleController.index() },
            { title: props.role ? 'Ubah' : 'Tambah' },
        ],
    }),
);

const locked = props.role?.is_system ?? false;

const form = useForm({
    name: props.role?.name ?? '',
    company_scope: (props.role?.company_scope ?? ANY_COMPANY) as
        | CompanyScope
        | typeof ANY_COMPANY,
    permissions: props.role?.permissions ?? ([] as string[]),
});

const forExecutor = computed(() => form.company_scope === 'executor');

const isUnavailable = (permission: string) =>
    !forExecutor.value && props.internalOnlyPermissions.includes(permission);

// Only executor roles hold internal-only permissions, so drop them when the
// scope changes away from executor.
watch(forExecutor, (executor) => {
    if (!executor) {
        form.permissions = form.permissions.filter(
            (permission) => !props.internalOnlyPermissions.includes(permission),
        );
    }
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
    form.transform((data) => ({
        ...data,
        company_scope:
            data.company_scope === ANY_COMPANY ? null : data.company_scope,
    })).submit(
        props.role
            ? RoleController.update(props.role.id)
            : RoleController.store(),
    );
};
</script>

<template>
    <Head :title="pageTitle" />

    <PagePanel :title="pageTitle">
        <form @submit.prevent="submit">
            <div class="space-y-6 px-4 py-6 sm:px-6">
                <p
                    v-if="locked"
                    class="rounded-lg bg-muted p-3 text-sm text-muted-foreground"
                >
                    Role admin adalah role sistem: namanya tetap dan selalu
                    memiliki semua permission.
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

                <div class="grid max-w-sm gap-2">
                    <Label for="role-scope">Berlaku untuk</Label>
                    <Select v-model="form.company_scope" :disabled="locked">
                        <SelectTrigger id="role-scope" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ANY_COMPANY">
                                Semua perusahaan
                            </SelectItem>
                            <SelectItem
                                v-for="scope in companyScopes"
                                :key="scope.value"
                                :value="scope.value"
                            >
                                {{ scope.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        Role hanya dapat diberikan kepada pengguna perusahaan
                        yang sesuai. Hak akses internal (master data, pengguna,
                        role, log aktivitas, semua WO) hanya untuk role
                        perusahaan pelaksana.
                    </p>
                    <InputError :message="form.errors.company_scope" />
                </div>

                <fieldset class="space-y-4">
                    <legend class="text-sm font-medium">Hak akses</legend>
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
                                    :disabled="
                                        locked || isUnavailable(permission)
                                    "
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
            </div>
            <FormFooter>
                <Button type="submit" :disabled="form.processing || locked">
                    Simpan
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="RoleController.index()">Batal</Link>
                </Button>
            </FormFooter>
        </form>
    </PagePanel>
</template>
