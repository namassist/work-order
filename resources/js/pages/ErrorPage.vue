<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';

const props = defineProps<{
    status: number;
}>();

const MESSAGES: Record<number, { title: string; description: string }> = {
    403: {
        title: 'Akses ditolak',
        description:
            'Anda tidak memiliki hak akses ke halaman ini. Hubungi admin bila Anda memerlukannya.',
    },
    404: {
        title: 'Halaman tidak ditemukan',
        description: 'Alamat yang Anda buka tidak ada atau sudah dipindahkan.',
    },
    419: {
        title: 'Sesi berakhir',
        description:
            'Halaman ini sudah terlalu lama terbuka. Muat ulang halaman, lalu coba lagi.',
    },
    500: {
        title: 'Terjadi kesalahan',
        description:
            'Ada masalah di server. Coba lagi beberapa saat lagi; bila berlanjut, hubungi admin.',
    },
    503: {
        title: 'Sedang dalam pemeliharaan',
        description:
            'Aplikasi sedang diperbarui. Coba lagi beberapa menit lagi.',
    },
};

const message = computed(() => MESSAGES[props.status] ?? MESSAGES[500]);
const sessionExpired = computed(() => props.status === 419);

const back = () => window.history.back();
const reload = () => window.location.reload();
</script>

<template>
    <Head :title="message.title" />

    <main
        class="flex min-h-svh flex-col items-center justify-center bg-background p-6"
    >
        <div
            class="flex w-full max-w-md flex-col items-center gap-6 rounded-2xl border bg-card p-8 text-center"
        >
            <AppLogoIcon class="size-12" />
            <div class="space-y-2">
                <p
                    class="font-mono text-sm font-semibold text-muted-foreground"
                >
                    Galat {{ status }}
                </p>
                <h1 class="font-serif text-3xl">{{ message.title }}</h1>
                <p class="text-sm text-muted-foreground">
                    {{ message.description }}
                </p>
            </div>
            <div class="flex flex-wrap justify-center gap-2">
                <template v-if="sessionExpired">
                    <Button @click="reload">Muat ulang</Button>
                </template>
                <template v-else>
                    <Button variant="outline" @click="back">Kembali</Button>
                    <Button as-child>
                        <Link :href="home()">Ke beranda</Link>
                    </Button>
                </template>
            </div>
        </div>
    </main>
</template>
