<script setup lang="ts">
import { ArrowRight } from '@lucide/vue';
import { computed } from 'vue';
import { isListValue, listDiff } from '@/lib/activity';
import type { ActivityChange, ActivityValue } from '@/types';

const props = defineProps<{
    changes: ActivityChange[];
}>();

const rows = computed(() =>
    props.changes.map((change) => ({
        ...change,
        diff:
            isListValue(change.old) || isListValue(change.new)
                ? listDiff(
                      change.old as string[] | null,
                      change.new as string[] | null,
                  )
                : null,
    })),
);

const display = (value: ActivityValue): string =>
    value === null || value === '' ? '—' : String(value);
</script>

<template>
    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
        <template v-for="row in rows" :key="row.field">
            <dt class="text-muted-foreground">{{ row.label }}</dt>
            <dd v-if="row.diff" class="flex flex-wrap gap-1">
                <span
                    v-for="item in row.diff.added"
                    :key="`added-${item}`"
                    class="rounded-md bg-success px-1.5 text-success-foreground"
                    >+ {{ item }}</span
                >
                <span
                    v-for="item in row.diff.removed"
                    :key="`removed-${item}`"
                    class="rounded-md bg-destructive/10 px-1.5 text-destructive line-through"
                    >− {{ item }}</span
                >
            </dd>
            <dd v-else class="flex min-w-0 flex-wrap items-center gap-1.5">
                <template v-if="row.old !== null">
                    <span class="break-all text-muted-foreground line-through">
                        {{ display(row.old) }}
                    </span>
                    <ArrowRight
                        v-if="row.new !== null"
                        class="size-3.5 shrink-0 text-muted-foreground"
                        aria-label="menjadi"
                    />
                </template>
                <span v-if="row.new !== null" class="break-all">
                    {{ display(row.new) }}
                </span>
            </dd>
        </template>
    </dl>
</template>
