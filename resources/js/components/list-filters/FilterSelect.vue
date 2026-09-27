<script setup lang="ts">
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ALL } from '@/composables/useListFilters';

export type FilterOption = { value: string; label: string; code?: string };

/**
 * One list filter as a select whose first item ("Semua …") is no filter.
 */
defineProps<{
    id?: string;
    label: string;
    allLabel: string;
    options: FilterOption[];
    triggerClass?: string;
}>();

const model = defineModel<string>({ required: true });
</script>

<template>
    <Select v-model="model">
        <SelectTrigger
            :id="id"
            :class="triggerClass ?? 'w-full'"
            :aria-label="label"
        >
            <SelectValue />
        </SelectTrigger>
        <SelectContent>
            <SelectItem :value="ALL">{{ allLabel }}</SelectItem>
            <SelectItem
                v-for="option in options"
                :key="option.value"
                :value="option.value"
            >
                <span v-if="option.code" class="font-mono">
                    {{ option.code }}
                </span>
                {{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
