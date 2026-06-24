<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Bell } from 'lucide-vue-next';
import { computed } from 'vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

const page = usePage();
const user = page.props.auth.user;
const roleLabel = computed(
    () => page.props.qualityProfile?.roleLabel ?? 'QA User',
);
const userInitials = computed(() =>
    user.name
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2),
);

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <header
        class="flex h-20 shrink-0 items-center justify-between gap-6 border-b border-[#bfe7ce] bg-[linear-gradient(90deg,#f8fffb_0%,#edf9f1_48%,#ffffff_100%)] px-6 shadow-[0_10px_30px_rgba(0,122,47,0.08)] backdrop-blur transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-18 lg:px-10"
    >
        <div class="flex min-w-0 flex-1 items-center gap-4">
            <SidebarTrigger
                class="size-10 rounded-xl border border-[#bfe7ce] bg-white text-[#064b27] shadow-sm hover:bg-[#eefaf3] hover:text-[#007a2f]"
            />
        </div>

        <div
            class="flex shrink-0 items-center gap-3 rounded-2xl border border-[#bfe7ce] bg-white/85 px-3 py-2 shadow-sm"
        >
            <button
                type="button"
                class="relative flex size-10 items-center justify-center rounded-xl border border-[#d6efdf] bg-[#f4fbf7] text-[#064b27] transition hover:border-[#9fd8b8] hover:bg-[#e8f7ee]"
            >
                <Bell class="size-5" />
                <span
                    class="absolute top-2 right-2 size-3 rounded-full bg-[#ef3340] ring-2 ring-white"
                />
            </button>

            <div class="hidden h-9 w-px bg-[#bfe7ce] sm:block" />

            <div class="flex items-center gap-4">
                <div
                    class="flex size-12 items-center justify-center rounded-2xl bg-[linear-gradient(135deg,#007a2f,#1f8f43)] text-base font-extrabold text-white shadow-[0_8px_22px_rgba(0,122,47,0.28)] ring-2 ring-[#c8efd6]"
                >
                    {{ userInitials }}
                </div>
                <div class="hidden min-w-40 text-left sm:block">
                    <div class="truncate text-base font-bold text-[#071126]">
                        {{ user.name }}
                    </div>
                    <div class="truncate text-sm font-medium text-[#007a2f]">
                        {{ roleLabel }}
                    </div>
                </div>
            </div>
        </div>
    </header>
</template>
