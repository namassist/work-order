<script setup lang="ts">
import { TableRow } from '@/components/ui/table';

/**
 * A table row that opens its record when clicked anywhere that is not
 * itself interactive: links, buttons, form controls, and menu items keep
 * their own click. Modified clicks (Ctrl, ⌘, Shift, middle button) and
 * clicks that end a text selection are ignored.
 *
 * The row is not focusable; keyboard users reach the record through the
 * link or button in the row's main cell, which must do the same thing.
 */
const props = defineProps<{
    disabled?: boolean;
}>();

const emit = defineEmits<{
    activate: [];
}>();

const INTERACTIVE =
    'a, button, input, select, textarea, label, [role="menuitem"], [role="checkbox"], [data-row-click-ignore]';

const onClick = (event: MouseEvent) => {
    if (
        props.disabled ||
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
    ) {
        return;
    }

    if ((event.target as Element | null)?.closest(INTERACTIVE)) {
        return;
    }

    if (window.getSelection()?.toString()) {
        return;
    }

    emit('activate');
};
</script>

<template>
    <TableRow :class="{ 'cursor-pointer': !disabled }" @click="onClick">
        <slot />
    </TableRow>
</template>
