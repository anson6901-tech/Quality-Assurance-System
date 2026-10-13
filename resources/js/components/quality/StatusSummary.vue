<script setup lang="ts">
import { computed } from 'vue';
import { reportStatusOrder, reportStatusStyles } from '@/lib/reportStatus';
import type { ReportStatusKey } from '@/lib/reportStatus';

const props = defineProps<{
    counts: Partial<Record<ReportStatusKey, number>>;
    statuses?: ReportStatusKey[];
}>();

const selected = defineModel<ReportStatusKey | ''>({ default: '' });

const total = computed(() =>
    Object.values(props.counts).reduce((sum, count) => sum + (count ?? 0), 0),
);

const visibleStatuses = computed(() =>
    (props.statuses ?? reportStatusOrder).filter(
        (status) =>
            (props.counts[status] ?? 0) > 0 || selected.value === status,
    ),
);

const toggle = (status: ReportStatusKey | '') => {
    selected.value = selected.value === status ? '' : status;
};
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-2"
        role="group"
        aria-label="Filter by status"
    >
        <button
            type="button"
            class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm transition"
            :class="
                selected === ''
                    ? 'border-[#007a2f] bg-[#e1f5e8] font-medium text-[#0b4a26]'
                    : 'border-[#d5dee3] bg-white text-[#3c5059] hover:border-[#9fd8b8]'
            "
            :aria-pressed="selected === ''"
            @click="selected = ''"
        >
            All
            <span class="font-semibold">{{ total }}</span>
        </button>
        <button
            v-for="status in visibleStatuses"
            :key="status"
            type="button"
            class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm transition"
            :class="
                selected === status
                    ? 'border-[#007a2f] bg-[#e1f5e8] font-medium text-[#0b4a26]'
                    : 'border-[#d5dee3] bg-white text-[#3c5059] hover:border-[#9fd8b8]'
            "
            :aria-pressed="selected === status"
            @click="toggle(status)"
        >
            <span
                class="size-2 rounded-full"
                :class="reportStatusStyles[status].dot"
            />
            {{ reportStatusStyles[status].label }}
            <span class="font-semibold">{{ counts[status] ?? 0 }}</span>
        </button>
    </div>
</template>
