<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { RegistrationCompany } from '@/lib/registration';
import {
    companyAllowsEmail,
    companyForEmail,
    emailDomain,
} from '@/lib/registration';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineOptions({
    layout: {
        title: 'Daftar akun',
        description:
            'Gunakan email perusahaan Anda. Admin meninjau pendaftaran sebelum akun dapat dipakai.',
    },
});

type RegistrationDepartment = {
    id: number;
    code: string;
    name: string;
    company_id: number;
};

const props = defineProps<{
    companies: RegistrationCompany[];
    departments: RegistrationDepartment[];
    passwordRules: string;
}>();

const form = useForm({
    name: '',
    email: '',
    company_id: null as number | null,
    department_id: null as number | null,
    password: '',
    password_confirmation: '',
});

const hasDomain = computed(() => emailDomain(form.email) !== null);

// The email domain fixes the company: once there is a domain, only its
// company can be chosen (the server checks the same rule).
const selectable = (company: RegistrationCompany) =>
    !hasDomain.value || companyAllowsEmail(company, form.email);

watch(
    () => form.email,
    (email) => {
        const match = companyForEmail(props.companies, email);

        if (match) {
            form.company_id = match.id;
        } else if (hasDomain.value) {
            form.company_id = null;
        }
    },
);

const companyDepartments = computed(() =>
    props.departments.filter(
        (department) => department.company_id === form.company_id,
    ),
);

watch(
    () => form.company_id,
    () => {
        if (
            !companyDepartments.value.some(
                (department) => department.id === form.department_id,
            )
        ) {
            form.department_id = null;
        }
    },
);

const unknownDomain = computed(
    () =>
        hasDomain.value &&
        companyForEmail(props.companies, form.email) === null,
);

const submit = () => {
    form.submit(store(), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Daftar" />

    <form class="flex flex-col gap-6" @submit.prevent="submit">
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">Nama lengkap</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    v-focus
                    autocomplete="name"
                    maxlength="255"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email perusahaan</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="nama@perusahaan.co.id"
                    :aria-describedby="
                        unknownDomain ? 'email-domain-hint' : undefined
                    "
                />
                <p
                    v-if="unknownDomain"
                    id="email-domain-hint"
                    class="text-sm text-muted-foreground"
                >
                    Domain ini tidak terdaftar. Gunakan email
                    {{
                        companies
                            .flatMap((company) => company.email_domains)
                            .map((domain) => `@${domain}`)
                            .join(', ')
                    }}.
                </p>
                <InputError :message="form.errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="company">Perusahaan</Label>
                <Select v-model="form.company_id">
                    <SelectTrigger id="company" class="w-full">
                        <SelectValue placeholder="Dipilih dari domain email" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="company in companies"
                            :key="company.id"
                            :value="company.id"
                            :disabled="!selectable(company)"
                        >
                            {{ company.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.company_id" />
            </div>

            <div class="grid gap-2">
                <Label for="department">Departemen</Label>
                <Select
                    v-model="form.department_id"
                    :disabled="form.company_id === null"
                >
                    <SelectTrigger id="department" class="w-full">
                        <SelectValue
                            :placeholder="
                                form.company_id === null
                                    ? 'Pilih perusahaan dulu'
                                    : 'Pilih departemen'
                            "
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="department in companyDepartments"
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

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="form.errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Konfirmasi password</Label>
                <PasswordInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="form.errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                :disabled="form.processing"
                data-test="register-button"
            >
                <Spinner v-if="form.processing" />
                Daftar
            </Button>
        </div>

        <p class="text-center text-sm text-muted-foreground">
            Sudah punya akun?
            <TextLink :href="login()">Masuk</TextLink>
        </p>
    </form>
</template>
