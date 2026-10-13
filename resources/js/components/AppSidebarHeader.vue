<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Bell, Moon, Sun } from 'lucide-vue-next';
import { computed } from 'vue';
import GlobalCourseSearch from '@/components/quality/GlobalCourseSearch.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useAppearance } from '@/composables/useAppearance';
import type { BreadcrumbItem } from '@/types';

const page = usePage();
const { resolvedAppearance, updateAppearance } = useAppearance();
const isDark = computed(() => resolvedAppearance.value === 'dark');
const toggleTheme = () => updateAppearance(isDark.value ? 'light' : 'dark');
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
        class="sticky top-0 z-20 flex h-16 shrink-0 items-center justify-between gap-3 border-b border-[#bfe7ce] bg-[linear-gradient(90deg,#f8fffb_0%,#edf9f1_48%,#ffffff_100%)] px-4 shadow-[0_10px_30px_rgba(0,122,47,0.08)] backdrop-blur transition-[width,height] ease-linear sm:h-20 sm:gap-6 sm:px-6 lg:px-10 dark:bg-[linear-gradient(90deg,#0c1a12_0%,#10231a_48%,#0b1410_100%)]"
    >
        <div class="flex min-w-0 flex-1 items-center gap-4">
            <SidebarTrigger
                class="size-10 rounded-xl border border-[#bfe7ce] bg-white text-[#064b27] shadow-sm hover:bg-[#eefaf3] hover:text-[#007a2f] focus-visible:ring-2 focus-visible:ring-[#007a2f]/30"
            />
        </div>

        <div
            class="flex min-w-0 shrink-0 items-center gap-2 rounded-2xl border border-[#bfe7ce] bg-white/90 px-2 py-1.5 shadow-sm sm:gap-3 sm:px-3 sm:py-2"
        >
            <GlobalCourseSearch />

            <button
                type="button"
                class="flex size-10 items-center justify-center rounded-xl border border-[#d6efdf] bg-[#f4fbf7] text-[#064b27] transition hover:border-[#9fd8b8] hover:bg-[#e8f7ee]"
                :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                @click="toggleTheme"
            >
                <Sun v-if="isDark" class="size-5" />
                <Moon v-else class="size-5" />
                <span class="sr-only">{{
                    isDark ? 'Switch to light mode' : 'Switch to dark mode'
                }}</span>
            </button>

            <button
                type="button"
                class="relative hidden size-10 items-center justify-center rounded-xl border border-[#d6efdf] bg-[#f4fbf7] text-[#064b27] transition hover:border-[#9fd8b8] hover:bg-[#e8f7ee] sm:flex"
            >
                <Bell class="size-5" />
                <span
                    class="absolute top-2 right-2 size-3 rounded-full bg-[#ef3340] ring-2 ring-white"
                />
            </button>

            <div class="hidden h-9 w-px bg-[#bfe7ce] sm:block" />

            <div class="flex items-center gap-4">
                <div
                    class="flex size-10 items-center justify-center rounded-xl bg-[linear-gradient(135deg,#007a2f,#1f8f43)] text-sm font-extrabold text-white shadow-[0_8px_22px_rgba(0,122,47,0.28)] ring-2 ring-[#c8efd6] sm:size-12 sm:rounded-2xl sm:text-base"
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
