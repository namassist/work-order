<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { LogOut } from '@lucide/vue';
import { computed, watchEffect } from 'vue';
import { Button } from '@/components/ui/button';
import { useFormatDate } from '@/composables/useFormatDate';
import { logout } from '@/routes';

type RegistrationStatus = {
    status: 'pending' | 'rejected';
    name: string;
    email: string;
    company: string;
    department: string;
    registered_at: string | null;
    rejection_reason: string | null;
};

const props = defineProps<{
    registration: RegistrationStatus;
}>();

const { formatDateTime } = useFormatDate();

const rejected = computed(() => props.registration.status === 'rejected');

watchEffect(() =>
    setLayoutProps(
        rejected.value
            ? {
                  title: 'Pendaftaran Anda ditolak',
                  description:
                      'Hubungi admin jika menurut Anda ini keliru. Admin dapat meninjau ulang pendaftaran Anda.',
              }
            : {
                  title: 'Akun Anda sedang ditinjau admin',
                  description:
                      'Anda dapat memakai aplikasi setelah admin menyetujui pendaftaran dan memberi akses.',
              },
    ),
);

const details = computed(() => [
    { label: 'Nama', value: props.registration.name },
    { label: 'Email', value: props.registration.email },
    { label: 'Perusahaan', value: props.registration.company },
    { label: 'Departemen', value: props.registration.department },
    ...(props.registration.registered_at
        ? [
              {
                  label: 'Didaftarkan',
                  value: formatDateTime(props.registration.registered_at),
              },
          ]
        : []),
]);
</script>

<template>
    <Head :title="rejected ? 'Pendaftaran ditolak' : 'Menunggu review'" />

    <div class="flex flex-col gap-6">
        <div
            v-if="rejected"
            class="rounded-lg border border-destructive/40 px-4 py-3"
            role="status"
        >
            <p class="text-sm font-medium">Alasan penolakan</p>
            <p
                class="mt-1 text-sm whitespace-pre-line text-muted-foreground"
                data-test="rejection-reason"
            >
                {{ registration.rejection_reason }}
            </p>
        </div>

        <dl class="divide-y rounded-lg border text-sm">
            <div
                v-for="detail in details"
                :key="detail.label"
                class="flex justify-between gap-4 px-4 py-2.5"
            >
                <dt class="text-muted-foreground">{{ detail.label }}</dt>
                <dd class="min-w-0 text-right font-medium break-words">
                    {{ detail.value }}
                </dd>
            </div>
        </dl>

        <Button variant="outline" class="w-full" as-child>
            <Link
                :href="logout()"
                as="button"
                data-test="logout-button"
                @click="router.flushAll()"
            >
                <LogOut />
                Keluar
            </Link>
        </Button>
    </div>
</template>
