<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { Check, Search } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import RequesterAccountController from '@/actions/App/Http/Controllers/WorkOrders/RequesterAccountController';
import { Input } from '@/components/ui/input';
import type { RequesterAccount } from '@/types';

/**
 * Picks the requester's account among the active accounts of one IC
 * department, for a koordinator entering a work order on their behalf. The
 * search runs on the server (koordinator-only endpoint).
 */
const props = defineProps<{
    departmentId: number | null;
    inputId: string;
}>();

const selected = defineModel<RequesterAccount | null>({ required: true });

const http = useHttp<Record<string, never>, { data: RequesterAccount[] }>();
const search = ref('');
const results = ref<RequesterAccount[]>([]);
const failed = ref(false);

// Only the latest request may update the list: an older one that finishes
// later, or is cancelled (which rejects), must not overwrite it.
let latestRequest = 0;

const load = async () => {
    const request = ++latestRequest;
    http.cancel();
    failed.value = false;

    if (props.departmentId === null) {
        results.value = [];

        return;
    }

    try {
        const response = await http.get(
            RequesterAccountController.url({
                query: {
                    department: props.departmentId,
                    search: search.value || undefined,
                },
            }),
        );

        if (request === latestRequest) {
            results.value = response.data;
        }
    } catch {
        if (request === latestRequest) {
            failed.value = true;
        }
    }
};

const loadDebounced = useDebounceFn(load, 300);

watch(search, () => loadDebounced());

// Another department means other accounts: drop the choice and reload.
watch(
    () => props.departmentId,
    (current, previous) => {
        if (previous !== undefined && current !== previous) {
            selected.value = null;
        }

        search.value = '';
        load();
    },
    { immediate: true },
);
</script>

<template>
    <div class="grid gap-2">
        <p v-if="departmentId === null" class="text-sm text-muted-foreground">
            Pilih departemen pemohon terlebih dahulu.
        </p>
        <template v-else>
            <div class="relative">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    :id="inputId"
                    v-model="search"
                    type="search"
                    class="pl-9"
                    placeholder="Cari nama atau email"
                    autocomplete="off"
                />
            </div>
            <p v-if="failed" class="text-sm text-destructive">
                Daftar akun gagal dimuat. Coba lagi.
            </p>
            <p
                v-else-if="!http.processing && results.length === 0"
                class="text-sm text-muted-foreground"
            >
                Tidak ada akun aktif yang cocok di departemen ini.
            </p>
            <ul
                v-else
                class="max-h-56 divide-y overflow-y-auto rounded-lg border"
                aria-label="Akun pemohon"
            >
                <li v-for="account in results" :key="account.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                        :aria-pressed="selected?.id === account.id"
                        @click="selected = account"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{
                                account.name
                            }}</span>
                            <span
                                class="block truncate text-xs text-muted-foreground"
                                >{{ account.email }}</span
                            >
                        </span>
                        <Check
                            v-if="selected?.id === account.id"
                            class="size-4 shrink-0"
                        />
                    </button>
                </li>
            </ul>
            <p v-if="selected" class="text-sm">
                Dipilih: <span class="font-medium">{{ selected.name }}</span>
                <span class="text-muted-foreground">
                    ({{ selected.email }})</span
                >
            </p>
        </template>
    </div>
</template>
