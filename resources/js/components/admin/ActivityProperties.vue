<script setup lang="ts">
import { computed } from 'vue';
import { describeProperties } from '@/lib/activity';

const props = defineProps<{
    properties: Record<string, string>;
}>();

const details = computed(() => describeProperties(props.properties));
</script>

<template>
    <dl
        v-if="details.length > 0"
        class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
    >
        <div v-for="detail in details" :key="detail.label" class="flex gap-1">
            <dt>{{ detail.label }}:</dt>
            <dd class="font-mono">{{ detail.value }}</dd>
        </div>
    </dl>
</template>
