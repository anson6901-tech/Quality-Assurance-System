<script setup lang="ts">
import { computed } from 'vue';
import type { CourseCell } from '@/types';

const props = defineProps<{
    label: string;
    cells: CourseCell[];
}>();

const detail = computed(() =>
    [
        ...new Set(
            props.cells.map((cell) =>
                [cell.program?.name, cell.section?.name]
                    .filter(Boolean)
                    .join(' — '),
            ),
        ),
    ].join(' · '),
);
</script>

<template>
    <div class="min-w-0">
        <div class="font-medium text-[#1d2f37]">{{ label }}</div>
        <div class="truncate text-xs text-[#66808a]" :title="detail">
            {{ detail }}
        </div>
    </div>
</template>
