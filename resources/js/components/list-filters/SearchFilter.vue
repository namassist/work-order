<script setup lang="ts">
import { Search } from '@lucide/vue';
import { Input } from '@/components/ui/input';

/** A list's search box; the list filters debounce it. */
defineProps<{ label: string; placeholder: string }>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <div class="relative">
        <Search
            class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
        />
        <Input
            v-model="model"
            type="search"
            class="pl-9"
            :placeholder="placeholder"
            :aria-label="label"
        />
    </div>
</template>
