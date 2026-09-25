<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';

/**
 * One-click switch between the light and dark themes. "Sistem" stays
 * available under Pengaturan › Tampilan.
 */
const { resolvedAppearance, updateAppearance } = useAppearance();

const isDark = computed(() => resolvedAppearance.value === 'dark');
const label = computed(() =>
    isDark.value ? 'Ganti ke tema terang' : 'Ganti ke tema gelap',
);

const toggle = () => updateAppearance(isDark.value ? 'light' : 'dark');
</script>

<template>
    <Button
        variant="ghost"
        size="icon"
        :aria-label="label"
        :title="label"
        @click="toggle"
    >
        <Sun v-if="isDark" />
        <Moon v-else />
    </Button>
</template>
