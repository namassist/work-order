<script setup lang="ts">
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';

/**
 * A person in a table cell: small initials avatar and the name, linked when
 * `href` is given (the row's keyboard entry point). Put a second line (e.g.
 * the email) in the default slot.
 */
defineProps<{
    name: string;
    href?: InertiaLinkProps['href'];
}>();

const { getInitials } = useInitials();
</script>

<template>
    <div class="flex min-w-0 items-center gap-2">
        <Avatar class="size-7 shrink-0">
            <AvatarFallback class="text-[11px]">
                {{ getInitials(name) }}
            </AvatarFallback>
        </Avatar>
        <div class="min-w-0">
            <Link
                v-if="href"
                :href="href"
                class="block truncate rounded-sm font-semibold underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {{ name }}
            </Link>
            <p v-else class="truncate">{{ name }}</p>
            <slot />
        </div>
    </div>
</template>
