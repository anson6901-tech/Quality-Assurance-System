<script setup lang="ts">
import { computed } from 'vue';
import { reportStatusStyles, toReportStatus } from '@/lib/reportStatus';
import type { ReportStatusKey } from '@/lib/reportStatus';

const props = defineProps<{
    status?: string | null;
    mixed?: boolean;
}>();

const style = computed(
    () =>
        reportStatusStyles[
            props.mixed
                ? 'mixed'
                : (toReportStatus(props.status) as ReportStatusKey)
        ],
);
</script>

<template>
    <span
        class="inline-flex w-fit items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="style.pill"
    >
        <span class="size-1.5 rounded-full" :class="style.dot" />
        {{ style.label }}
    </span>
</template>
