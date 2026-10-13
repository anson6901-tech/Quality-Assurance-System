<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Check,
    Download,
    Eye,
    MoreHorizontal,
    Printer,
    RotateCcw,
    Trash2,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { QualityReport } from '@/types';

type WorkflowAction = 'forward' | 'return' | 'publish' | 'unapprove' | 'delete';

const props = defineProps<{
    report: QualityReport;
    approvalQueue: boolean;
    printQueue: boolean;
    canReviewReports: boolean;
    canApproveReports: boolean;
}>();

const emit = defineEmits<{
    workflow: [action: WorkflowAction];
    print: [];
}>();

const inReview = computed(() =>
    ['submitted', 'under_review'].includes(props.report.status),
);
const canForward = computed(
    () =>
        props.approvalQueue &&
        props.canReviewReports &&
        props.report.status === 'submitted',
);
const canPublish = computed(
    () =>
        props.approvalQueue &&
        props.canApproveReports &&
        props.report.status === 'under_review',
);
const canReturn = computed(
    () =>
        props.approvalQueue &&
        (props.canReviewReports || props.canApproveReports) &&
        inReview.value,
);
const canUnapprove = computed(
    () =>
        props.approvalQueue &&
        props.canApproveReports &&
        props.report.status === 'approved',
);
const canDelete = computed(
    () => props.approvalQueue && props.canApproveReports && inReview.value,
);
const hasMenu = computed(
    () =>
        canReturn.value ||
        canUnapprove.value ||
        canDelete.value ||
        (props.printQueue && Boolean(props.report.downloadUrl)),
);
</script>

<template>
    <div class="flex items-center justify-start gap-1.5 md:justify-end">
        <Button
            v-if="canForward"
            type="button"
            size="sm"
            class="bg-[#007a2f] text-white hover:bg-[#006428]"
            @click="emit('workflow', 'forward')"
        >
            <Check class="h-4 w-4" /> Forward
        </Button>
        <Button
            v-if="canPublish"
            type="button"
            size="sm"
            class="bg-[#007a2f] text-white hover:bg-[#006428]"
            @click="emit('workflow', 'publish')"
        >
            <Check class="h-4 w-4" /> Publish
        </Button>
        <Button
            v-if="printQueue"
            type="button"
            size="sm"
            variant="outline"
            @click="emit('print')"
        >
            <Printer class="h-4 w-4" /> Print
        </Button>

        <Button
            v-if="report.canView"
            size="icon"
            variant="ghost"
            class="size-8"
            as-child
        >
            <Link :href="`/quality/reports/${report.id}`" title="Open report">
                <Eye class="h-4 w-4" />
                <span class="sr-only">Open report</span>
            </Link>
        </Button>

        <DropdownMenu v-if="hasMenu">
            <DropdownMenuTrigger as-child>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    class="size-8"
                    title="More actions"
                >
                    <MoreHorizontal class="h-4 w-4" />
                    <span class="sr-only">More actions</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-48">
                <DropdownMenuItem
                    v-if="printQueue && report.downloadUrl"
                    as-child
                >
                    <a :href="report.downloadUrl">
                        <Download class="h-4 w-4" /> Download
                    </a>
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="canReturn"
                    @select="emit('workflow', 'return')"
                >
                    <RotateCcw class="h-4 w-4" />
                    {{
                        canApproveReports && report.status === 'under_review'
                            ? 'Return to supervisor'
                            : 'Return to officer'
                    }}
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="canUnapprove"
                    class="text-amber-800"
                    @select="emit('workflow', 'unapprove')"
                >
                    <RotateCcw class="h-4 w-4" /> Unapprove
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="canDelete"
                    class="text-red-700"
                    @select="emit('workflow', 'delete')"
                >
                    <Trash2 class="h-4 w-4" /> Delete
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
