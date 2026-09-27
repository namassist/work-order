<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import RegistrationController from '@/actions/App/Http/Controllers/Admin/RegistrationController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    AssignableRole,
    Registration,
    RegistrationDepartment,
} from '@/types';

const props = defineProps<{
    registration: Registration | null;
    departments: RegistrationDepartment[];
    /** Roles an approval may grant (never role management). */
    roles: AssignableRole[];
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({
    roles: [] as string[],
    department_id: null as number | null,
});

watch(open, (isOpen) => {
    if (!isOpen || !props.registration) {
        return;
    }

    form.clearErrors();
    form.defaults({
        roles: [],
        department_id: props.registration.department.id,
    });
    form.reset();
});

// Only roles that fit the registration's company; the server refuses the rest.
const fittingRoles = computed(() =>
    props.roles.filter(
        (role) =>
            role.company_scope === null ||
            role.company_scope === props.registration?.company.scope,
    ),
);

// The department may only be corrected within the company the email chose.
const companyDepartments = computed(() => {
    const registration = props.registration;

    if (!registration) {
        return [];
    }

    const active = props.departments.filter(
        (department) => department.company_id === registration.company.id,
    );

    return active.some(
        (department) => department.id === registration.department.id,
    )
        ? active
        : [
              {
                  ...registration.department,
                  company_id: registration.company.id,
              },
              ...active,
          ];
});

const toggleRole = (role: string, checked: boolean | 'indeterminate') => {
    form.roles =
        checked === true
            ? [...form.roles, role]
            : form.roles.filter((name) => name !== role);
};

const submit = () => {
    if (!props.registration) {
        return;
    }

    form.submit(RegistrationController.approve(props.registration.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Setujui pendaftaran</DialogTitle>
                    <DialogDescription v-if="registration">
                        {{ registration.name }} ({{ registration.email }}),
                        {{ registration.company.name }}. Akun baru dapat dipakai
                        dengan role yang Anda pilih.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="approve-department">Departemen</Label>
                    <Select v-model="form.department_id">
                        <SelectTrigger id="approve-department" class="w-full">
                            <SelectValue placeholder="Pilih departemen" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="department in companyDepartments"
                                :key="department.id"
                                :value="department.id"
                            >
                                <span class="font-mono">{{
                                    department.code
                                }}</span>
                                {{ department.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        Hanya departemen {{ registration?.company.name }};
                        perusahaan ditentukan oleh domain email.
                    </p>
                    <InputError :message="form.errors.department_id" />
                </div>

                <fieldset class="space-y-3">
                    <legend class="text-sm font-medium">Role</legend>
                    <div class="flex flex-wrap gap-x-6 gap-y-3">
                        <div
                            v-for="role in fittingRoles"
                            :key="role.name"
                            class="flex items-center gap-2"
                        >
                            <Checkbox
                                :id="`approve-role-${role.name}`"
                                :model-value="form.roles.includes(role.name)"
                                @update:model-value="
                                    toggleRole(role.name, $event)
                                "
                            />
                            <Label :for="`approve-role-${role.name}`">
                                {{ role.name }}
                            </Label>
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Role admin dan pengelola role diberikan lewat halaman
                        Pengguna.
                    </p>
                    <InputError
                        :message="form.errors.roles ?? form.errors['roles.0']"
                    />
                </fieldset>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Batal</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="form.processing || form.roles.length === 0"
                    >
                        Setujui
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
