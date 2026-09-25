<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';

// oxfmt-ignore
type Props = {
    passwordRules: string;
} ;

const props = defineProps<Props>();

const page = usePage();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Pengaturan keamanan',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Pengaturan keamanan" />

    <h1 class="sr-only">Pengaturan keamanan</h1>

    <div class="space-y-6">
        <Alert v-if="page.props.auth.user.must_change_password">
            <KeyRound />
            <AlertTitle>Ganti password default Anda</AlertTitle>
            <AlertDescription>
                Akun Anda masih memakai password default dari admin. Isi
                password default sebagai password saat ini, lalu buat password
                baru untuk melanjutkan.
            </AlertDescription>
        </Alert>

        <Heading
            variant="small"
            title="Ganti password"
            description="Gunakan password yang panjang dan acak agar akun tetap aman"
        />

        <Form
            v-bind="SecurityController.update.form()"
            :options="{
                preserveScroll: true,
            }"
            reset-on-success
            :reset-on-error="[
                'password',
                'password_confirmation',
                'current_password',
            ]"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="current_password">Password saat ini</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    placeholder="Password saat ini"
                />
                <InputError :message="errors.current_password" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password baru</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Password baru"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Konfirmasi password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Konfirmasi password"
                    :passwordrules="props.passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-password-button"
                >
                    Simpan
                </Button>
            </div>
        </Form>
    </div>
</template>
