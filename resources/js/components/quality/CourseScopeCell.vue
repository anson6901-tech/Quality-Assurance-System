<script setup lang="ts">
import { Scissors } from 'lucide-vue-next';
import { computed } from 'vue';
import type { SplitMode } from '@/types';

const props = defineProps<{
    programs: Array<{ id: number; code: string; name: string }>;
    levels: string[];
    sections: Array<{ id: number; name: string }>;
    mode: SplitMode;
    partCount?: number;
}>();

const modeLabel = computed(
    () =>
        ({
            level_program: 'program and level',
            level_section: 'level and section',
            section_program: 'program and section',
            level_program_section: 'level, program and section',
            merged: '',
        })[props.mode],
);
</script>

<template>
    <div class="min-w-0 space-y-1 text-sm">
        <div class="flex flex-wrap gap-1">
            <span
                v-for="program in programs"
                :key="program.id"
                class="rounded-md border border-[#c8d7de] bg-[#f5f8fa] px-1.5 py-0.5 text-xs font-medium text-[#1d2f37]"
                :title="program.name"
            >
                {{ program.code }}
            </span>
        </div>
        <div class="text-xs text-[#66808a]">
            {{ levels.length ? `Level ${levels.join(', ')}` : 'Level not set' }}
            <template v-if="sections.length">
                · {{ sections.length }}
                {{ sections.length === 1 ? 'section' : 'sections' }}
            </template>
        </div>
        <div
            v-if="mode !== 'merged'"
            class="inline-flex items-center gap-1 rounded-full bg-[#fdf3d7] px-2 py-0.5 text-[11px] font-medium text-[#8a4b06]"
        >
            <Scissors class="size-3" />
            Split by {{ modeLabel }}
            <template v-if="partCount"> · {{ partCount }} parts</template>
        </div>
    </div>
</template>
