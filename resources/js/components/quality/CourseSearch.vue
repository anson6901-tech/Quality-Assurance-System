<script setup lang="ts">
import { Search, X } from 'lucide-vue-next';

defineProps<{
    shown: number;
    total: number;
    placeholder?: string;
}>();

const query = defineModel<string>({ default: '' });
</script>

<template>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#66808a]"
            />
            <input
                v-model="query"
                type="text"
                inputmode="search"
                enterkeyhint="search"
                role="searchbox"
                :placeholder="
                    placeholder ??
                    'Search courses by code, title, program or lecturer'
                "
                aria-label="Search courses"
                class="h-11 w-full rounded-lg border border-[#c8d7de] bg-white pr-10 pl-10 text-sm text-[#1d2f37] shadow-sm outline-none placeholder:text-[#8a9aa1] focus:border-[#007a2f] focus:ring-2 focus:ring-[#007a2f]/20"
            />
            <button
                v-if="query"
                type="button"
                class="absolute top-1/2 right-2 flex size-7 -translate-y-1/2 items-center justify-center rounded-md text-[#66808a] hover:bg-[#eef3f5]"
                aria-label="Clear search"
                @click="query = ''"
            >
                <X class="size-4" />
            </button>
        </div>
        <div class="shrink-0 text-sm text-[#52646c]" aria-live="polite">
            {{
                shown === total
                    ? `${total} courses`
                    : `${shown} of ${total} courses`
            }}
        </div>
    </div>
</template>
