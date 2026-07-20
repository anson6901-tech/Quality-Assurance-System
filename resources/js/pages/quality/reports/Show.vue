<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, ExternalLink, Lock } from 'lucide-vue-next';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { QualityReport } from '@/types';

const props = defineProps<{
    report: QualityReport;
    canPushReport: boolean;
}>();

const pdfReaderUrl = computed(() =>
    props.report.fileUrl
        ? `${props.report.fileUrl}#toolbar=0&navpanes=0&scrollbar=1`
        : null,
);

const canOpenForPrinting = computed(() => props.report.status === 'approved');

const reportMeta = computed(
    () =>
        `${props.report.category} - ${props.report.academicYear?.name ?? 'No academic year'} - ${props.report.createdAt}`,
);

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

    <div class="mx-auto max-w-6xl space-y-7">
        <section
            class="overflow-hidden rounded-2xl border border-[#9fd8b8] bg-white shadow-sm ring-1 ring-[#007a2f]/10"
        >
            <div
                class="flex flex-col gap-5 bg-[#eefaf3] px-6 py-6 md:flex-row md:items-start md:justify-between lg:px-8"
            >
                <div class="min-w-0 space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge
                            class="border border-[#9fd8b8] bg-white text-[#063f22]"
                            variant="secondary"
                        >
                            {{ report.location }}
                        </Badge>
                        <Badge
                            class="border border-[#007a2f]/20 bg-[#007a2f] text-white"
                            variant="secondary"
                        >
                            {{ report.status }}
                        </Badge>
                    </div>
                    <div>
                        <h1
                            class="text-2xl font-bold tracking-normal text-[#062b19] md:text-3xl"
                        >
                            {{ report.title }}
                        </h1>
                        <p class="mt-2 text-sm font-medium text-[#436453]">
                            {{ reportMeta }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 md:justify-end">
                    <Button
                        v-if="canOpenForPrinting"
                        variant="outline"
                        class="border-[#007a2f]/30 bg-white text-[#007a2f] hover:bg-[#e3f6eb] hover:text-[#005f27]"
                        as-child
                    >
                        <a
                            :href="report.fileUrl ?? '#'"
                            target="_blank"
                            rel="noopener"
                        >
                            <ExternalLink class="h-4 w-4" />
                            Open PDF
                        </a>
                    </Button>
                    <Button
                        v-if="canOpenForPrinting && report.downloadUrl"
                        variant="outline"
                        class="border-[#007a2f]/30 bg-white text-[#007a2f] hover:bg-[#e3f6eb] hover:text-[#005f27]"
                        as-child
                    >
                        <a :href="report.downloadUrl">
                            <Download class="h-4 w-4" />
                            Download
                        </a>
                    </Button>
                </div>
            </div>

            <div class="grid gap-4 px-6 py-6 lg:grid-cols-4 lg:px-8">
                <div class="rounded-xl border border-[#c8ead6] bg-white p-5">
                    <div class="text-xs font-semibold text-[#5d796b] uppercase">
                        Academic year
                    </div>
                    <div class="mt-2 font-semibold text-[#062b19]">
                        {{ report.academicYear?.name ?? 'Not set' }}
                    </div>
                </div>
                <div class="rounded-xl border border-[#c8ead6] bg-white p-5">
                    <div class="text-xs font-semibold text-[#5d796b] uppercase">
                        School
                    </div>
                    <div class="mt-2 font-semibold text-[#062b19]">
                        {{ report.school?.name ?? 'Not assigned' }}
                    </div>
                </div>
                <div class="rounded-xl border border-[#c8ead6] bg-white p-5">
                    <div class="text-xs font-semibold text-[#5d796b] uppercase">
                        Department
                    </div>
                    <div class="mt-2 font-semibold text-[#062b19]">
                        {{ report.department?.name ?? 'School-wide' }}
                    </div>
                </div>
                <div class="rounded-xl border border-[#c8ead6] bg-white p-5">
                    <div class="text-xs font-semibold text-[#5d796b] uppercase">
                        Lecturer
                    </div>
                    <div class="mt-2 font-semibold text-[#062b19]">
                        {{ report.lecturer?.name ?? 'Not assigned' }}
                    </div>
                    <div class="mt-1 truncate text-sm text-[#5d796b]">
                        {{ report.lecturer?.email }}
                    </div>
                </div>
            </div>
        </section>

        <div
            v-if="report.isLocked"
            class="flex items-center gap-3 rounded-xl border border-[#9fd8b8] bg-[#f3fbf6] px-5 py-4 text-sm text-[#436453] shadow-sm"
        >
            <Lock class="h-4 w-4 text-[#007a2f]" />
            <span>This report has entered the review workflow and is locked from editing.</span>
        </div>

        <section
            class="grid gap-5 rounded-2xl border border-[#9fd8b8] bg-white p-6 shadow-sm ring-1 ring-[#007a2f]/10 lg:grid-cols-[1fr_1.5fr]"
        >
            <div class="space-y-3">
                <h2 class="text-base font-semibold text-[#062b19]">Courses</h2>
                <div class="flex flex-wrap gap-2">
                    <Badge
                        v-for="course in report.courses"
                        :key="course.id"
                        class="border border-[#9fd8b8] bg-[#eefaf3] text-[#063f22]"
                        variant="secondary"
                    >
                        {{ course.code }} - {{ course.title }}
                    </Badge>
                    <span
                        v-if="report.courses.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No courses attached.
                    </span>
                </div>
            </div>

            <div class="space-y-3">
                <h2 class="text-base font-semibold text-[#062b19]">Summary</h2>
                <p
                    class="min-h-24 rounded-xl border border-[#dcefe4] bg-[#fbfefc] p-4 text-sm leading-6 whitespace-pre-wrap text-[#436453]"
                >
                    {{ report.summary || 'No summary was provided.' }}
                </p>
            </div>
        </section>

        <section
            v-if="pdfReaderUrl"
            class="overflow-hidden rounded-2xl border border-[#9fd8b8] bg-white shadow-sm ring-1 ring-[#007a2f]/10"
        >
            <div
                class="flex flex-col gap-4 border-b border-[#c8ead6] bg-[#eefaf3] px-6 py-5 md:flex-row md:items-center md:justify-between"
            >
                <div>
                    <h2 class="text-base font-semibold text-[#062b19]">
                        PDF preview
                    </h2>
                    <p class="mt-1 text-sm text-[#436453]">
                        Read the uploaded report inside the system.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="canOpenForPrinting"
                        variant="outline"
                        class="border-[#007a2f]/30 bg-white text-[#007a2f] hover:bg-[#e3f6eb] hover:text-[#005f27]"
                        as-child
                    >
                        <a
                            :href="report.fileUrl ?? '#'"
                            target="_blank"
                            rel="noopener"
                        >
                            <ExternalLink class="h-4 w-4" />
                            Open for printing
                        </a>
                    </Button>
                    <Button
                        v-if="canOpenForPrinting && report.downloadUrl"
                        variant="outline"
                        class="border-[#007a2f]/30 bg-white text-[#007a2f] hover:bg-[#e3f6eb] hover:text-[#005f27]"
                        as-child
                    >
                        <a :href="report.downloadUrl">
                            <Download class="h-4 w-4" />
                            Download
                        </a>
                    </Button>
                </div>
            </div>

            <div class="bg-[#f7fbf9] p-4 md:p-6">
                <iframe
                    :src="pdfReaderUrl"
                    title="Report PDF preview"
                    class="h-[720px] w-full rounded-xl border border-[#c8ead6] bg-white shadow-sm"
                />
            </div>
        </section>

        <section
            v-else
            class="rounded-2xl border border-[#9fd8b8] bg-white px-6 py-8 text-sm text-[#436453] shadow-sm ring-1 ring-[#007a2f]/10"
        >
            No PDF has been uploaded for this report.
        </section>
    </div>
</template>
