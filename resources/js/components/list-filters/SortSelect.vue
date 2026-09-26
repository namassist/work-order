<script setup lang="ts">
import { ArrowDownUp } from '@lucide/vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

/**
 * A list's order ("Urutkan"). Not a filter: it gets no chip and does not
 * count as filtering.
 */
defineProps<{
    id?: string;
    options: { value: string; label: string }[];
    triggerClass?: string;
}>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <Select v-model="model">
        <SelectTrigger
            :id="id"
            :class="triggerClass ?? 'w-full'"
            aria-label="Urutkan"
        >
            <span class="flex items-center gap-2">
                <ArrowDownUp class="text-muted-foreground" />
                <SelectValue />
            </span>
        </SelectTrigger>
        <SelectContent>
            <SelectItem
                v-for="option in options"
                :key="option.value"
                :value="option.value"
            >
                {{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
