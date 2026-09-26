<script setup lang="ts">
import { MoreHorizontal } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * The three-dot menu in a table row's last column. Put the row's actions
 * (Ubah, Riwayat, Hapus, Pulihkan) in the default slot as
 * `DropdownMenuItem`s; destructive ones use `variant="destructive"`.
 */
defineProps<{
    /** Accessible name, e.g. "Aksi WO/IT/2026/09/0001". */
    label: string;
}>();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" :aria-label="label">
                <MoreHorizontal />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="min-w-40">
            <slot />
        </DropdownMenuContent>
    </DropdownMenu>
</template>
