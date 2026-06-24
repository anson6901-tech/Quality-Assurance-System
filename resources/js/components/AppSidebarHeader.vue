<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Bell, Search } from 'lucide-vue-next';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

const page = usePage();
const user = page.props.auth.user;

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
        class="flex h-20 shrink-0 items-center justify-between gap-6 border-b border-[#dbe1e8] bg-white/95 px-6 shadow-sm backdrop-blur transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-18 lg:px-10"
    >
        <div class="flex min-w-0 flex-1 items-center gap-5">
            <SidebarTrigger
                class="size-9 rounded-lg text-[#071126] hover:bg-[#eef3f8]"
            />

            <div
                class="flex h-12 w-full max-w-[680px] items-center gap-3 rounded-xl border border-[#dbe1e8] bg-[#f6f8fb] px-4 text-[#718093]"
            >
                <Search class="size-5" />
                <input
                    class="h-full min-w-0 flex-1 bg-transparent text-base text-[#071126] outline-none placeholder:text-[#718093]"
                    placeholder="Search reports, lecturers, departments..."
                    type="search"
                />
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-6">
            <button
                type="button"
                class="relative flex size-10 items-center justify-center rounded-lg text-[#071126] hover:bg-[#eef3f8]"
            >
                <Bell class="size-5" />
                <span
                    class="absolute top-2 right-2 size-3 rounded-full bg-[#ef3340] ring-2 ring-white"
                />
            </button>

            <div class="hidden h-10 w-px bg-[#dbe1e8] sm:block" />

            <div class="flex items-center gap-4">
                <div
                    class="flex size-11 items-center justify-center rounded-full bg-[#007a2f] text-base font-bold text-white"
                >
                    {{
                        user.name
                            .split(' ')
                            .map((part) => part[0])
                            .join('')
                            .slice(0, 2)
                    }}
                </div>
                <div class="hidden min-w-40 text-left sm:block">
                    <div class="truncate text-base font-bold text-[#071126]">
                        {{ user.name }}
                    </div>
                    <div class="truncate text-sm text-[#5f6b7d]">
                        {{ page.props.qualityProfile?.roleLabel ?? 'QA User' }}
                    </div>
                </div>
            </div>
        </div>
    </header>
</template>
