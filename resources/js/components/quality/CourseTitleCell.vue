<script setup lang="ts">
defineProps<{
    code: string;
    title: string;
    courseType?: string;
    subtitle?: string | null;
}>();
</script>

<template>
    <div class="min-w-0">
        <div class="flex items-center gap-2">
            <span class="font-semibold text-[#075b2e]">{{ code }}</span>
            <span
                v-if="courseType === 'module'"
                class="rounded border border-[#d6c9f2] bg-[#f4effc] px-1.5 text-[10px] font-semibold tracking-wide text-[#56349c] uppercase"
            >
                Module
            </span>
        </div>
        <div class="truncate text-sm text-[#3c5059]">{{ title }}</div>
        <div v-if="subtitle" class="truncate text-xs text-[#66808a]">
            {{ subtitle }}
        </div>
    </div>
</template>
