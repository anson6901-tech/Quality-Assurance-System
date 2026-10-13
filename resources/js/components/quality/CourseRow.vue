<script setup lang="ts">
import { computed } from 'vue';
import { reportStatusStyles, toReportStatus } from '@/lib/reportStatus';

const props = defineProps<{
    /** Report status driving the coloured stripe on the left edge. */
    status?: string | null;
    mixed?: boolean;
    /** Neutral stripe for lists that are not about report status. */
    plain?: boolean;
    /** Explicit stripe colour class, e.g. for assignment state. */
    stripeClass?: string;
    /** Renders as an indented program sub-row inside an expanded course. */
    nested?: boolean;
}>();

const stripe = computed(() => {
    if (props.stripeClass) {
        return props.stripeClass;
    }

    if (props.plain) {
        return 'border-l-[#d5e2e8]';
    }

    return reportStatusStyles[
        props.mixed ? 'mixed' : toReportStatus(props.status)
    ].stripe;
});
</script>

<template>
    <div
        class="border-l-4"
        :class="[
            stripe,
            nested
                ? 'border-t border-t-[#e6edf0] bg-[#f7fafb]'
                : 'overflow-hidden rounded-xl border border-[#d5e2e8] bg-white shadow-sm',
        ]"
    >
        <div
            class="grid gap-x-4 gap-y-2 px-4 md:grid-cols-[var(--course-cols)] md:items-center"
            :class="nested ? 'py-2.5 text-sm md:pl-10' : 'py-4'"
        >
            <slot />
        </div>
        <div v-if="$slots.children">
            <slot name="children" />
        </div>
    </div>
</template>
