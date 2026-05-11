<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Eye, Lock, Plus } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { QualityReport } from '@/types';

defineProps<{
    reports: QualityReport[];
    canUploadReports: boolean;
    approvalQueue: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'QA Reports',
                href: '/quality/reports',
            },
        ],
    },
});
</script>

<template>
    <Head :title="approvalQueue ? 'Approvals' : 'QA Reports'" />

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between gap-4">
            <Heading
                variant="small"
                :title="approvalQueue ? 'Approvals' : 'QA Reports'"
                :description="
                    approvalQueue
                        ? 'Reports pushed by QA officers and ready for approval'
                        : 'Quality assurance reports visible within your UHAS role and scope'
                "
            />

            <Button v-if="canUploadReports && !approvalQueue" as-child>
                <Link href="/quality/reports/create">
                    <Plus /> Upload report
                </Link>
            </Button>
        </div>

        <div class="overflow-hidden rounded-lg border">
            <div
                class="grid grid-cols-[1.5fr_1fr_1fr_auto] gap-4 border-b bg-muted/50 px-4 py-3 text-sm font-medium"
            >
                <span>Report</span>
                <span>Scope</span>
                <span>Status</span>
                <span class="sr-only">Actions</span>
            </div>

            <div
                v-for="report in reports"
                :key="report.id"
                class="grid grid-cols-[1.5fr_1fr_1fr_auto] items-center gap-4 border-b px-4 py-4 last:border-b-0"
            >
                <div class="min-w-0">
                    <div class="truncate font-medium">{{ report.title }}</div>
                    <div class="text-sm text-muted-foreground">
                        {{ report.category }} ·
                        {{ report.academicYear?.name ?? 'No academic year' }}
                        · {{ report.createdAt }}
                    </div>
                </div>

                <div class="min-w-0 text-sm">
                    <div class="truncate">{{ report.school?.name }}</div>
                    <div class="truncate text-muted-foreground">
                        {{
                            report.lecturer
                                ? `${report.lecturer.name} - ${report.department?.name}`
                                : (report.department?.name ?? 'School-wide')
                        }}
                    </div>
                </div>

                <div>
                    <div class="flex items-center gap-2">
                        <Badge variant="secondary">{{ report.status }}</Badge>
                        <Lock
                            v-if="report.isLocked"
                            class="h-4 w-4 text-muted-foreground"
                        />
                    </div>
                </div>

                <Button variant="ghost" size="sm" as-child>
                    <Link :href="`/quality/reports/${report.id}`">
                        <Eye class="h-4 w-4" />
                    </Link>
                </Button>
            </div>
        </div>

        <p
            v-if="reports.length === 0"
            class="py-8 text-center text-muted-foreground"
        >
            {{
                approvalQueue
                    ? 'No reports are waiting for approval.'
                    : 'No QA reports are visible for your current school or department.'
            }}
        </p>
    </div>
</template>
