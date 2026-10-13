<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    names: string[];
    detail?: string | null;
}>();

const initials = (name: string) =>
    name
        .replace(/^(Dr|Prof|Mr|Mrs|Ms)\.?\s+/i, '')
        .split(/\s+/)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

const label = computed(() =>
    props.names.length <= 1
        ? (props.names[0] ?? 'Not assigned')
        : `${props.names.length} lecturers`,
);
</script>

<template>
    <div class="flex min-w-0 items-center gap-2.5">
        <div class="flex shrink-0 -space-x-2">
            <span
                v-for="name in names.slice(0, 3)"
                :key="name"
                class="flex size-7 items-center justify-center rounded-full bg-[#dff1e6] text-[11px] font-semibold text-[#0b5a2b] ring-2 ring-white"
                :title="name"
            >
                {{ initials(name) }}
            </span>
        </div>
        <div class="min-w-0">
            <div
                class="truncate text-sm font-medium text-[#1d2f37]"
                :title="names.join(', ')"
            >
                {{ label }}
            </div>
            <div v-if="detail" class="truncate text-xs text-[#66808a]">
                {{ detail }}
            </div>
        </div>
    </div>
</template>
