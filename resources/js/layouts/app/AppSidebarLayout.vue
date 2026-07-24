<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent
            variant="sidebar"
            class="min-w-0 overflow-x-hidden bg-[#f4f7fb]"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <main
                class="mx-auto w-full max-w-[1680px] min-w-0 px-4 py-5 sm:px-6 sm:py-7 lg:px-10"
            >
                <slot />
            </main>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
