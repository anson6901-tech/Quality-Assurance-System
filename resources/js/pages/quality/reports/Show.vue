<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Lock, Send } from 'lucide-vue-next';
import { push } from '@/actions/App/Http/Controllers/Quality/QualityReportController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { QualityReport } from '@/types';

const props = defineProps<{
    report: QualityReport;
    canPushReport: boolean;
}>();

defineOptions({
    layout: (props: { report: QualityReport }) => ({
        breadcrumbs: [
            {
                title: 'QA Reports',
                href: '/quality/reports',
            },
            {
                title: props.report.title,
                href: `/quality/reports/${props.report.id}`,
            },
        ],
    }),
});
</script>

<template>
    <Head :title="props.report.title" />

    <div class="max-w-4xl space-y-8">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="report.title"
                :description="`${report.category} · ${report.academicYear?.name ?? 'No academic year'} · ${report.createdAt}`"
            />
            <div class="flex flex-col items-end gap-3">
                <Badge variant="secondary">{{ report.status }}</Badge>
                <Button
                    v-if="canPushReport"
                    as-child
                    size="sm"
                    class="bg-[#007a2f] hover:bg-[#006428]"
                >
                    <Link :href="push.url(report.id)" method="post" as="button">
                        <Send class="h-4 w-4" />
                        Push
                    </Link>
                </Button>
            </div>
        </div>

        <div
            v-if="report.isLocked"
            class="flex items-center gap-3 rounded-lg border bg-muted/40 px-4 py-3 text-sm text-muted-foreground"
        >
            <Lock class="h-4 w-4" />
            <span>This report has been pushed and is locked from editing.</span>
        </div>

        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border p-4">
                <div class="text-sm text-muted-foreground">Academic year</div>
                <div class="font-medium">
                    {{ report.academicYear?.name ?? 'Not set' }}
                </div>
            </div>
            <div class="rounded-lg border p-4">
                <div class="text-sm text-muted-foreground">School</div>
                <div class="font-medium">{{ report.school?.name }}</div>
            </div>
            <div class="rounded-lg border p-4">
                <div class="text-sm text-muted-foreground">Department</div>
                <div class="font-medium">
                    {{ report.department?.name ?? 'School-wide' }}
                </div>
            </div>
            <div class="rounded-lg border p-4">
                <div class="text-sm text-muted-foreground">Lecturer</div>
                <div class="font-medium">{{ report.lecturer?.name }}</div>
                <div class="text-sm text-muted-foreground">
                    {{ report.lecturer?.email }}
                </div>
            </div>
        </div>

        <section class="space-y-3">
            <h2 class="text-base font-medium">Courses</h2>
            <div class="flex flex-wrap gap-2">
                <Badge
                    v-for="course in report.courses"
                    :key="course.id"
                    variant="secondary"
                >
                    {{ course.code }} - {{ course.title }}
                </Badge>
            </div>
        </section>

        <section class="space-y-2">
            <h2 class="text-base font-medium">Summary</h2>
            <p
                class="text-sm leading-6 whitespace-pre-wrap text-muted-foreground"
            >
                {{ report.summary || 'No summary was provided.' }}
            </p>
        </section>

        <Button v-if="report.filePath" as-child>
            <Link :href="`/storage/${report.filePath}`">Open attachment</Link>
        </Button>
    </div>
</template>
