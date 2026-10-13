<script setup lang="ts">
defineProps<{
    /** Column headings shown once above the cards (hidden on small screens). */
    headings: string[];
    /** CSS grid-template-columns shared by the heading and every row. */
    columns: string;
}>();
</script>

<template>
    <div class="space-y-3" :style="{ '--course-cols': columns }">
        <div
            class="hidden rounded-lg border border-l-4 border-[#d5e2e8] border-l-transparent bg-[#eef3f5] px-4 py-2 text-xs font-semibold tracking-wide text-[#5b707a] uppercase md:grid md:grid-cols-[var(--course-cols)] md:gap-4"
        >
            <div
                v-for="(heading, index) in headings"
                :key="index"
                :class="{ 'text-right': index === headings.length - 1 }"
            >
                {{ heading }}
            </div>
        </div>
        <slot />
    </div>
</template>
