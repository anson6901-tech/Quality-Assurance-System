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
        class="flex h-[104px] shrink-0 items-center justify-between gap-6 border-b border-[#dbe1e8] bg-white px-10 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-20"
    >
        <div class="flex min-w-0 flex-1 items-center gap-9">
            <SidebarTrigger
                class="size-10 rounded-xl text-[#071126] hover:bg-[#eef3f8]"
            />

            <div
                class="flex h-16 w-full max-w-[720px] items-center gap-4 rounded-[18px] bg-[#eef3f8] px-6 text-[#718093]"
            >
                <Search class="size-7" />
                <input
                    class="h-full min-w-0 flex-1 bg-transparent text-2xl text-[#071126] outline-none placeholder:text-[#718093]"
                    placeholder="Search reports, lecturers, departments..."
                    type="search"
                />
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-6">
            <button
                type="button"
                class="relative flex size-12 items-center justify-center rounded-2xl text-[#071126] hover:bg-[#eef3f8]"
            >
                <Bell class="size-7" />
                <span
                    class="absolute top-2 right-2 size-3 rounded-full bg-[#ef3340] ring-2 ring-white"
                />
            </button>

            <div class="h-14 w-px bg-[#dbe1e8]" />

            <div class="flex items-center gap-4">
                <div
                    class="flex size-14 items-center justify-center rounded-full bg-[#007a2f] text-2xl font-bold text-white"
                >
                    {{
                        user.name
                            .split(' ')
                            .map((part) => part[0])
                            .join('')
                            .slice(0, 2)
                    }}
                </div>
                <div class="hidden min-w-44 text-left sm:block">
                    <div class="truncate text-xl font-bold text-[#071126]">
                        {{ user.name }}
                    </div>
                    <div class="truncate text-lg text-[#5f6b7d]">
                        {{ page.props.qualityProfile?.roleLabel ?? 'QA User' }}
                    </div>
                </div>
            </div>
        </div>
    </header>
</template>
