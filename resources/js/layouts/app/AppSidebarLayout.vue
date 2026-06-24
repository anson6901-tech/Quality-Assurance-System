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
        <AppContent variant="sidebar" class="overflow-x-hidden bg-[#f4f7fb]">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <main
                class="mx-auto w-full max-w-[1680px] px-6 py-7 sm:px-8 lg:px-10"
            >
                <slot />
            </main>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
