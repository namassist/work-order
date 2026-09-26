<script setup lang="ts">
import ThemeToggle from '@/components/ThemeToggle.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import UserMenu from '@/components/UserMenu.vue';

/**
 * The topbar: sidebar toggle on the left; theme toggle and the avatar menu
 * on the right. Breadcrumbs live in each page's panel header. Global search
 * and notifications join here once those features exist.
 */
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-4"
    >
        <SidebarTrigger class="-ml-1" />
        <div class="ml-auto flex items-center gap-1">
            <ThemeToggle />
            <UserMenu />
        </div>
    </header>
</template>
