<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    ChevronDown,
    Combine,
    Download,
    Eye,
    Lock,
    Plus,
    Printer,
    RotateCcw,
    Split,
    Trash2,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import type { QualityReport } from '@/types';

type FilterOption = {
    id: number | string;
    name: string;
    schoolId?: number;
    departmentId?: number;
    code?: string;
};

type ReportFilters = {
    lecturer_id?: number | string | null;
    academic_year_id?: number | string | null;
    semester_id?: number | string | null;
    school_id?: number | string | null;
    department_id?: number | string | null;
    program_id?: number | string | null;
    level?: string | null;
    status?: string | null;
};

const props = defineProps<{
    reports: QualityReport[];
    canViewReports: boolean;
    canUploadReports: boolean;
    approvalQueue: boolean;
    printQueue: boolean;
    reviewStage: 'officer' | 'supervisor' | 'director' | 'published';
    queueRoute: string;
    canReviewReports: boolean;
    canApproveReports: boolean;
    filters?: ReportFilters;
    filterOptions?: {
        lecturers: FilterOption[];
        academicYears: FilterOption[];
        semesters: Array<FilterOption & { academicYearId: number }>;
        schools: FilterOption[];
        departments: FilterOption[];
        programs: FilterOption[];
        levels: FilterOption[];
        statuses?: FilterOption[];
    };
}>();

const pageTitle = computed(() => {
    if (props.printQueue) {
        return 'Print Queue';
    }

    if (props.reviewStage === 'supervisor') {
        return 'Supervisor Review';
    }

    return props.reviewStage === 'director' ? 'Director Review' : 'My Reports';
});

const pageDescription = computed(() => {
    if (props.printQueue) {
        return 'Published lecturer reports available to the relevant stakeholders';
    }

    if (props.reviewStage === 'supervisor') {
        return 'Review reports from QA Officers and continue tracking them after they are forwarded to the QA Director';
    }

    if (props.reviewStage === 'director') {
        return 'Complete the final review and continue tracking reports after they are published';
    }

    return 'Track reports you uploaded through the quality workflow';
});

const emptyMessage = computed(() => {
    if (props.printQueue) {
        return 'No approved reports are ready for printing.';
    }

    return props.approvalQueue
        ? 'No reports are waiting at this review stage.'
        : 'No uploaded reports match the current filters.';
});

const filterRoute = computed(() => {
    if (props.printQueue) {
        return '/quality/print-queue';
    }

    return props.queueRoute;
});

const printReport = (report: QualityReport) => {
    window.open(report.fileUrl ?? `/quality/reports/${report.id}`, '_blank');
};

type WorkflowAction = 'forward' | 'return' | 'publish' | 'unapprove' | 'delete';

const workflowTarget = ref<QualityReport | null>(null);
const workflowAction = ref<WorkflowAction | null>(null);
const workflowForm = useForm({ note: '' });

const workflowCopy = computed(() => {
    const copy = {
        forward: {
            title: 'Forward to QA Director',
            description:
                'You may add a review note for the director, or forward without one.',
            label: 'Supervisor note (optional)',
            button: 'Forward report',
        },
        return: {
            title: props.canReviewReports
                ? 'Return to QA Officer'
                : 'Return to QA Supervisor',
            description:
                'You may explain what must be corrected, or return the report without a note.',
            label: 'Reason for return (optional)',
            button: 'Return report',
        },
        publish: {
            title: 'Publish report',
            description:
                'This makes the report available to the assigned lecturer and school dean.',
            label: 'Publication note (optional)',
            button: 'Publish report',
        },
        unapprove: {
            title: 'Unapprove report',
            description:
                'This removes the report from the Print Queue and returns it to Director Review.',
            label: 'Reason for unapproval (optional)',
            button: 'Unapprove report',
        },
        delete: {
            title: 'Delete report',
            description:
                'The report will be removed from active queues while its audit history is retained.',
            label: 'Reason for deletion',
            button: 'Delete report',
        },
    };

    return workflowAction.value ? copy[workflowAction.value] : copy.return;
});

const openWorkflow = (report: QualityReport, action: WorkflowAction) => {
    workflowTarget.value = report;
    workflowAction.value = action;
    workflowForm.reset();
    workflowForm.clearErrors();
};

const closeWorkflow = () => {
    workflowTarget.value = null;
    workflowAction.value = null;
    workflowForm.reset();
};

const submitWorkflow = () => {
    if (!workflowTarget.value || !workflowAction.value) {
        return;
    }

    const reportId = workflowTarget.value.id;
    const options = {
        preserveScroll: true,
        onSuccess: closeWorkflow,
    };

    if (workflowAction.value === 'delete') {
        workflowForm.delete(`/quality/reports/${reportId}`, options);
        return;
    }

    const endpoint =
        workflowAction.value === 'forward'
            ? 'forward'
            : workflowAction.value === 'unapprove'
              ? 'unapprove'
              : workflowAction.value === 'publish'
                ? 'approve'
                : 'return';

    workflowForm.post(`/quality/reports/${reportId}/${endpoint}`, options);
};

const lecturerSearch = ref('');
const splitReportGroups = ref<string[]>([]);

const activeFilters = computed<ReportFilters>(() => props.filters ?? {});

type ReportRow = QualityReport & {
    groupKey: string;
    variants: QualityReport[];
};

const reportGroupKey = (report: QualityReport) =>
    `${report.courses[0]?.code ?? report.id}:${report.lecturer?.id ?? 'none'}`;

const reportRows = computed<ReportRow[]>(() => {
    if (props.reviewStage === 'officer') {
        return props.reports.map((report) => ({
            ...report,
            groupKey: String(report.id),
            variants: [report],
        }));
    }

    const groups = new Map<string, QualityReport[]>();

    props.reports.forEach((report) => {
        const key = reportGroupKey(report);
        groups.set(key, [...(groups.get(key) ?? []), report]);
    });

    return [...groups.entries()].map(([groupKey, variants]) => ({
        ...variants[0],
        groupKey,
        variants,
        courses: variants.flatMap((report) => report.courses),
    }));
});

const toggleReportSplit = (groupKey: string) => {
    splitReportGroups.value = splitReportGroups.value.includes(groupKey)
        ? splitReportGroups.value.filter((key) => key !== groupKey)
        : [...splitReportGroups.value, groupKey];
};

const visibleReportVariants = (row: ReportRow) =>
    row.variants.length === 1 ||
    splitReportGroups.value.includes(row.groupKey)
        ? row.variants
        : [];

const selectedLecturerLabel = computed(() => {
    const selectedLecturerId = activeFilters.value.lecturer_id
        ? Number(activeFilters.value.lecturer_id)
        : null;

    if (!selectedLecturerId) {
        return 'All lecturers';
    }

    return (
        props.filterOptions?.lecturers.find(
            (lecturer) => lecturer.id === selectedLecturerId,
        )?.name ?? 'All lecturers'
    );
});

const filteredLecturerOptions = computed(() => {
    const query = lecturerSearch.value.trim().toLowerCase();
    const lecturers = props.filterOptions?.lecturers ?? [];

    if (!query) {
        return lecturers.slice(0, 12);
    }

    return lecturers
        .filter((lecturer) => lecturer.name.toLowerCase().includes(query))
        .slice(0, 12);
});

const filteredDepartments = computed(() => {
    const schoolId = activeFilters.value.school_id
        ? Number(activeFilters.value.school_id)
        : null;

    if (!schoolId) {
        return props.filterOptions?.departments ?? [];
    }

    return (props.filterOptions?.departments ?? []).filter(
        (department) => department.schoolId === schoolId,
    );
});

const filteredPrograms = computed(() => {
    const departmentId = activeFilters.value.department_id
        ? Number(activeFilters.value.department_id)
        : null;

    return departmentId
        ? (props.filterOptions?.programs ?? []).filter(
              (program) => program.departmentId === departmentId,
          )
        : (props.filterOptions?.programs ?? []);
});

const filteredSemesters = computed(() => {
    const academicYearId = activeFilters.value.academic_year_id
        ? Number(activeFilters.value.academic_year_id)
        : null;

    return academicYearId
        ? (props.filterOptions?.semesters ?? []).filter(
              (semester) => semester.academicYearId === academicYearId,
          )
        : (props.filterOptions?.semesters ?? []);
});

const updateFilter = (key: keyof ReportFilters, value: string) => {
    const nextFilters: ReportFilters = {
        ...activeFilters.value,
        [key]: value || undefined,
    };

    if (key === 'school_id') {
        nextFilters.department_id = undefined;
        nextFilters.program_id = undefined;
    }

    if (key === 'department_id') {
        nextFilters.program_id = undefined;
    }

    if (key === 'academic_year_id') {
        nextFilters.semester_id = undefined;
    }

    router.get(filterRoute.value, nextFilters, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

const clearFilters = () => {
    router.get(
        filterRoute.value,
        {},
        {
            preserveScroll: true,
            replace: true,
        },
    );
};

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
    <Head :title="pageTitle" />

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between gap-4">
            <Heading
                variant="small"
                :title="pageTitle"
                :description="pageDescription"
            />
        </div>

        <div
            v-if="filterOptions"
            class="grid gap-3 rounded-xl border border-[#9fd8b8] bg-[#eefaf3] p-5 shadow-sm ring-1 ring-[#007a2f]/10 md:grid-cols-5"
        >
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        type="button"
                        variant="outline"
                        class="h-10 justify-between border-[#9fd8b8] bg-white px-3 font-normal text-[#063f22] hover:bg-[#f8fffb]"
                    >
                        <span class="truncate">{{
                            selectedLecturerLabel
                        }}</span>
                        <ChevronDown class="h-4 w-4 opacity-60" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    class="w-[min(320px,calc(100vw-2rem))] p-2"
                >
                    <Input
                        v-model="lecturerSearch"
                        type="search"
                        placeholder="Search lecturer"
                        class="mb-2 h-10"
                        @keydown.stop
                    />
                    <DropdownMenuItem
                        @select.prevent="updateFilter('lecturer_id', '')"
                    >
                        All lecturers
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-for="lecturer in filteredLecturerOptions"
                        :key="lecturer.id"
                        @select.prevent="
                            updateFilter('lecturer_id', String(lecturer.id))
                        "
                    >
                        {{ lecturer.name }}
                    </DropdownMenuItem>
                    <div
                        v-if="filteredLecturerOptions.length === 0"
                        class="px-2 py-5 text-center text-sm text-muted-foreground"
                    >
                        No lecturers found.
                    </div>
                </DropdownMenuContent>
            </DropdownMenu>

            <select
                v-if="filterOptions.statuses"
                :value="activeFilters.status ?? ''"
                class="h-10 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                @change="
                    updateFilter(
                        'status',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option value="">All locations</option>
                <option
                    v-for="status in filterOptions.statuses"
                    :key="status.id"
                    :value="status.id"
                >
                    {{ status.name }}
                </option>
            </select>

            <select
                :value="activeFilters.academic_year_id ?? ''"
                class="h-10 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                @change="
                    updateFilter(
                        'academic_year_id',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option value="">All academic years</option>
                <option
                    v-for="academicYear in filterOptions.academicYears"
                    :key="academicYear.id"
                    :value="academicYear.id"
                >
                    {{ academicYear.name }}
                </option>
            </select>

            <select
                :value="activeFilters.semester_id ?? ''"
                class="h-10 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                @change="
                    updateFilter(
                        'semester_id',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option value="">All semesters</option>
                <option
                    v-for="semester in filteredSemesters"
                    :key="semester.id"
                    :value="semester.id"
                >
                    {{ semester.name }}
                </option>
            </select>

            <select
                :value="activeFilters.school_id ?? ''"
                class="h-10 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                @change="
                    updateFilter(
                        'school_id',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option value="">All schools</option>
                <option
                    v-for="school in filterOptions.schools"
                    :key="school.id"
                    :value="school.id"
                >
                    {{ school.name }}
                </option>
            </select>

            <select
                :value="activeFilters.department_id ?? ''"
                class="h-10 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                @change="
                    updateFilter(
                        'department_id',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option value="">All departments</option>
                <option
                    v-for="department in filteredDepartments"
                    :key="department.id"
                    :value="department.id"
                >
                    {{ department.name }}
                </option>
            </select>

            <select
                :value="activeFilters.program_id ?? ''"
                class="h-10 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                @change="
                    updateFilter(
                        'program_id',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option value="">All programs</option>
                <option
                    v-for="program in filteredPrograms"
                    :key="program.id"
                    :value="program.id"
                >
                    {{ program.name
                    }}{{ program.code ? ` (${program.code})` : '' }}
                </option>
            </select>

            <select
                :value="activeFilters.level ?? ''"
                class="h-10 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                @change="
                    updateFilter(
                        'level',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option value="">All levels</option>
                <option
                    v-for="level in filterOptions.levels"
                    :key="level.id"
                    :value="level.id"
                >
                    {{ level.name }}
                </option>
            </select>

            <Button
                type="button"
                variant="outline"
                class="h-10 border-[#007a2f]/30 bg-white font-semibold text-[#007a2f] hover:bg-[#e3f6eb] hover:text-[#005f27]"
                @click="clearFilters"
            >
                Clear
            </Button>
        </div>

        <div class="space-y-4">
            <article
                v-for="(report, reportIndex) in reportRows"
                :key="report.groupKey"
                class="overflow-hidden rounded-xl border border-[#b8ced8] bg-white shadow-sm"
            >
                <div
                    class="grid gap-4 border-b border-[#b8ced8] bg-[#f8fbfc] px-4 py-4 md:grid-cols-[auto_1.5fr_0.75fr_0.75fr_1fr_auto] md:items-center"
                >
                    <div
                        class="flex h-8 w-8 items-center justify-center rounded-md bg-[#d9edf5] text-sm font-bold text-[#234d5d]"
                    >
                        {{ reportIndex + 1 }}
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-bold text-[#075b2e]">
                            {{ report.courses[0]?.code ?? 'No course' }}
                        </div>
                        <div class="truncate text-sm text-[#243d47]">
                            {{ report.courses[0]?.title ?? report.title }}
                        </div>
                    </div>
                    <div class="text-sm">
                        <div
                            class="text-[10px] font-bold tracking-wide text-[#66808a] uppercase"
                        >
                            Level
                        </div>
                        {{
                            report.courses
                                .map((course) => course.level)
                                .find(Boolean) ?? '—'
                        }}
                    </div>
                    <div class="text-sm">
                        <div
                            class="text-[10px] font-bold tracking-wide text-[#66808a] uppercase"
                        >
                            Offerings
                        </div>
                        {{ report.variants.length }}
                    </div>
                    <div class="min-w-0">
                        <div
                            class="text-[10px] font-bold tracking-wide text-[#66808a] uppercase"
                        >
                            Lecturer
                        </div>
                        <div class="truncate text-sm font-medium">
                            {{ report.lecturer?.name ?? 'Not assigned' }}
                        </div>
                    </div>
                    <Badge
                        variant="secondary"
                        class="w-fit bg-[#d9edf5] text-[#234d5d]"
                    >
                        {{
                            new Set(
                                report.variants.map((variant) => variant.status),
                            ).size > 1
                                ? 'Mixed status'
                                : report.status
                        }}
                    </Badge>
                </div>

                <div class="px-4 py-4">
                    <div
                        class="overflow-hidden rounded-md border border-[#9fb7c1]"
                    >
                        <div
                            class="hidden grid-cols-[1.6fr_0.65fr_0.55fr_1.05fr_0.75fr_1fr_auto] gap-px bg-[#9fb7c1] text-xs font-semibold text-[#294c59] md:grid"
                        >
                            <div class="bg-[#d9edf5] px-3 py-2">Program</div>
                            <div class="bg-[#d9edf5] px-3 py-2">Code</div>
                            <div class="bg-[#d9edf5] px-3 py-2">Level</div>
                            <div class="bg-[#d9edf5] px-3 py-2">Scope</div>
                            <div class="bg-[#d9edf5] px-3 py-2">Status</div>
                            <div class="bg-[#d9edf5] px-3 py-2">Activity</div>
                            <div class="bg-[#d9edf5] px-3 py-2">Actions</div>
                        </div>
                        <div class="divide-y divide-[#cbdbe1] bg-white">
                            <div
                                v-if="
                                    report.variants.length > 1 &&
                                    !splitReportGroups.includes(report.groupKey)
                                "
                                class="flex items-center justify-between gap-4 bg-[#fbfdfe] px-4 py-5"
                            >
                                <div>
                                    <div class="font-medium text-[#243d47]">
                                        {{ report.variants.length }} program
                                        reports grouped
                                    </div>
                                    <div
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        Split this course to review each
                                        program report and its workflow actions.
                                    </div>
                                </div>
                                <Split class="h-5 w-5 text-[#087036]" />
                            </div>
                            <div
                                v-for="variant in visibleReportVariants(report)"
                                :key="variant.id"
                                class="grid gap-3 px-3 py-3 text-sm md:grid-cols-[1.6fr_0.65fr_0.55fr_1.05fr_0.75fr_1fr_auto] md:items-center"
                            >
                                <div class="font-medium text-[#243d47]">
                                    {{
                                        variant.program?.name ??
                                        'Program not set'
                                    }}
                                </div>
                                <div class="text-muted-foreground">
                                    {{ variant.program?.code ?? '—' }}
                                </div>
                                <div>
                                    {{
                                        variant.courses
                                            .map((course) => course.level)
                                            .find(Boolean) ?? '—'
                                    }}
                                </div>
                                <div class="min-w-0 text-xs">
                                    <div class="truncate">
                                        {{ variant.department?.name ?? '—' }}
                                    </div>
                                    <div
                                        class="truncate text-muted-foreground"
                                    >
                                        {{
                                            variant.academicYear?.name ??
                                            'No year'
                                        }}
                                    </div>
                                </div>
                                <Badge variant="secondary" class="w-fit">
                                    {{ variant.status }}
                                </Badge>
                                <div class="text-xs text-muted-foreground">
                                    {{ variant.createdAt }}
                                    <div>{{ variant.location }}</div>
                                </div>
                                <div class="flex flex-wrap justify-end gap-2">
                                    <Button
                                        v-if="
                                            approvalQueue &&
                                            canReviewReports &&
                                            variant.status === 'submitted'
                                        "
                                        size="sm"
                                        class="bg-[#007a2f] hover:bg-[#006428]"
                                        @click="openWorkflow(variant, 'forward')"
                                    >
                                        <Check class="h-4 w-4" /> Forward
                                    </Button>
                                    <Button
                                        v-if="
                                            approvalQueue &&
                                            canApproveReports &&
                                            variant.status === 'under_review'
                                        "
                                        size="sm"
                                        class="bg-[#007a2f] hover:bg-[#006428]"
                                        @click="openWorkflow(variant, 'publish')"
                                    >
                                        <Check class="h-4 w-4" /> Publish
                                    </Button>
                                    <Button
                                        v-if="
                                            approvalQueue &&
                                            canApproveReports &&
                                            variant.status === 'approved'
                                        "
                                        size="sm"
                                        variant="outline"
                                        class="border-amber-500 text-amber-700"
                                        @click="
                                            openWorkflow(variant, 'unapprove')
                                        "
                                    >
                                        <RotateCcw class="h-4 w-4" /> Unapprove
                                    </Button>
                                    <Button
                                        v-if="
                                            approvalQueue &&
                                            (canReviewReports ||
                                                canApproveReports) &&
                                            ['submitted', 'under_review'].includes(
                                                variant.status,
                                            )
                                        "
                                        size="sm"
                                        variant="outline"
                                        @click="openWorkflow(variant, 'return')"
                                    >
                                        <RotateCcw class="h-4 w-4" /> Return
                                    </Button>
                                    <Button
                                        v-if="
                                            approvalQueue &&
                                            canApproveReports &&
                                            ['submitted', 'under_review'].includes(
                                                variant.status,
                                            )
                                        "
                                        size="sm"
                                        variant="destructive"
                                        @click="openWorkflow(variant, 'delete')"
                                    >
                                        <Trash2 class="h-4 w-4" /> Delete
                                    </Button>
                                    <Button
                                        v-if="printQueue"
                                        size="sm"
                                        variant="outline"
                                        @click="printReport(variant)"
                                    >
                                        <Printer class="h-4 w-4" /> Print
                                    </Button>
                                    <Button
                                        v-if="printQueue && variant.downloadUrl"
                                        size="sm"
                                        variant="outline"
                                        as-child
                                    >
                                        <a :href="variant.downloadUrl">
                                            <Download class="h-4 w-4" />
                                            Download
                                        </a>
                                    </Button>
                                    <Button size="sm" variant="ghost" as-child>
                                        <Link
                                            :href="`/quality/reports/${variant.id}`"
                                        >
                                            <Eye class="h-4 w-4" />
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    v-if="report.variants.length > 1"
                    class="flex justify-end border-t border-[#cbdbe1] bg-[#f8fbfc] px-4 py-3"
                >
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        class="border-[#8bcfaa] text-[#006d2b] hover:bg-[#e7f6ed]"
                        @click="toggleReportSplit(report.groupKey)"
                    >
                        <Combine
                            v-if="splitReportGroups.includes(report.groupKey)"
                            class="h-4 w-4"
                        />
                        <Split v-else class="h-4 w-4" />
                        {{
                            splitReportGroups.includes(report.groupKey)
                                ? 'Combine offerings'
                                : 'Split course offerings'
                        }}
                    </Button>
                </div>
            </article>
        </div>

        <div
            v-if="false"
            class="overflow-hidden rounded-xl border border-[#9fd8b8] bg-white shadow-sm ring-1 ring-[#007a2f]/10"
        >
            <div
                class="grid grid-cols-[1.5fr_1fr_0.75fr_0.75fr_auto] gap-4 border-b border-[#9fd8b8] bg-[#e8f6ee] px-4 py-3 text-sm font-bold text-[#063f22]"
            >
                <span>Report &amp; courses</span>
                <span>Scope</span>
                <span>Location</span>
                <span>Status</span>
                <span class="sr-only">Actions</span>
            </div>

            <div
                v-for="report in reportRows"
                :key="report.id"
                class="grid grid-cols-[1.5fr_1fr_0.75fr_0.75fr_auto] items-center gap-4 border-b px-4 py-4 last:border-b-0"
            >
                <div class="min-w-0">
                    <div class="truncate font-medium">{{ report.title }}</div>
                    <div class="text-sm text-muted-foreground">
                        {{ report.category }} ·
                        {{ report.academicYear?.name ?? 'No academic year' }}
                        · {{ report.createdAt }}
                    </div>
                    <div
                        v-if="report.courses.length"
                        class="mt-2 flex flex-wrap gap-1.5"
                    >
                        <span
                            v-for="course in report.courses"
                            :key="course.id"
                            class="inline-flex max-w-full items-center rounded-md border border-[#9fd8b8] bg-[#eefaf3] px-2 py-1 text-xs font-medium text-[#064426]"
                            :title="`${course.code} - ${course.title}`"
                        >
                            <span class="font-bold">{{ course.code }}</span>
                            <span class="mx-1">—</span>
                            <span class="truncate">{{ course.title }}</span>
                        </span>
                    </div>
                    <div v-else class="mt-2 text-xs text-muted-foreground">
                        No course linked
                    </div>
                    <div
                        v-if="
                            report.program ||
                            report.courses.some((course) => course.level)
                        "
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        {{
                            report.program
                                ? `${report.program?.name} (${report.program?.code})`
                                : 'Program not set'
                        }}
                        <span v-if="report.courses.some((course) => course.level)">
                            · Level
                            {{
                                [
                                    ...new Set(
                                        report.courses
                                            .map((course) => course.level)
                                            .filter(Boolean),
                                    ),
                                ].join(', ')
                            }}
                        </span>
                    </div>
                    <div
                        v-if="
                            report.variants.length > 1 &&
                            !splitReportGroups.includes(report.groupKey)
                        "
                        class="mt-2 rounded-md bg-[#edf6fa] px-2 py-1.5 text-xs font-medium text-[#234d5d]"
                    >
                        {{ report.variants.length }} program reports grouped
                    </div>
                    <div
                        v-for="variant in visibleReportVariants(report)"
                        :key="variant.id"
                        class="mt-2 flex flex-wrap items-center gap-2 rounded-md border border-[#c7dce5] bg-[#f8fbfc] px-2 py-1.5 text-xs"
                    >
                        <span class="font-medium text-[#234d5d]">
                            {{ variant.program?.name ?? 'Program not set' }}
                        </span>
                        <span class="text-muted-foreground">
                            {{ variant.program?.code ?? '—' }}
                        </span>
                        <span class="text-muted-foreground">
                            Level
                            {{
                                variant.courses
                                    .map((course) => course.level)
                                    .find(Boolean) ?? '—'
                            }}
                        </span>
                        <Badge variant="secondary">
                            {{ variant.status }}
                        </Badge>
                        <Link
                            :href="`/quality/reports/${variant.id}`"
                            class="ml-auto font-medium text-[#007a2f] hover:underline"
                        >
                            Open
                        </Link>
                    </div>
                </div>

                <div class="min-w-0 text-sm">
                    <div class="truncate">{{ report.school?.name }}</div>
                    <div class="truncate text-muted-foreground">
                        {{
                            report.lecturer
                                ? `${report.lecturer?.name} - ${report.department?.name}`
                                : (report.department?.name ?? 'School-wide')
                        }}
                    </div>
                </div>

                <div>
                    <Badge
                        class="border border-[#9fd8b8] bg-[#eefaf3] text-[#063f22]"
                        variant="secondary"
                    >
                        {{ report.location }}
                    </Badge>
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

                <div class="flex justify-end gap-2">
                    <Button
                        v-if="
                            report.variants.length > 1
                        "
                        type="button"
                        size="sm"
                        variant="outline"
                        class="border-[#8bcfaa] text-[#006d2b] hover:bg-[#e7f6ed]"
                        @click="toggleReportSplit(report.groupKey)"
                    >
                        <Combine
                            v-if="splitReportGroups.includes(report.groupKey)"
                            class="h-4 w-4"
                        />
                        <Split v-else class="h-4 w-4" />
                        {{
                            splitReportGroups.includes(report.groupKey)
                                ? 'Combine offerings'
                                : 'Split course offerings'
                        }}
                    </Button>

                    <Button
                        v-if="
                            approvalQueue &&
                            canReviewReports &&
                            report.status === 'submitted'
                        "
                        size="sm"
                        class="bg-[#007a2f] hover:bg-[#006428]"
                        type="button"
                        @click="openWorkflow(report, 'forward')"
                    >
                        <Check class="h-4 w-4" />
                        Forward
                    </Button>

                    <Button
                        v-if="
                            approvalQueue &&
                            canApproveReports &&
                            report.status === 'under_review'
                        "
                        size="sm"
                        type="button"
                        class="bg-[#007a2f] hover:bg-[#006428]"
                        @click="openWorkflow(report, 'publish')"
                    >
                        <Check class="h-4 w-4" />
                        Publish
                    </Button>

                    <Button
                        v-if="
                            approvalQueue &&
                            canApproveReports &&
                            report.status === 'approved'
                        "
                        size="sm"
                        variant="outline"
                        type="button"
                        class="border-amber-500 text-amber-700 hover:bg-amber-50 hover:text-amber-800"
                        @click="openWorkflow(report, 'unapprove')"
                    >
                        <RotateCcw class="h-4 w-4" />
                        Unapprove
                    </Button>

                    <Button
                        v-if="
                            approvalQueue &&
                            (canReviewReports || canApproveReports) &&
                            (report.status === 'submitted' ||
                                report.status === 'under_review')
                        "
                        size="sm"
                        variant="outline"
                        type="button"
                        @click="openWorkflow(report, 'return')"
                    >
                        <RotateCcw class="h-4 w-4" />
                        Return
                    </Button>

                    <Button
                        v-if="
                            approvalQueue &&
                            canApproveReports &&
                            ['submitted', 'under_review'].includes(
                                report.status,
                            )
                        "
                        size="sm"
                        variant="destructive"
                        type="button"
                        @click="openWorkflow(report, 'delete')"
                    >
                        <Trash2 class="h-4 w-4" />
                        Delete
                    </Button>

                    <Button
                        v-if="printQueue"
                        variant="outline"
                        size="sm"
                        type="button"
                        @click="printReport(report)"
                    >
                        <Printer class="h-4 w-4" />
                        Print
                    </Button>

                    <Button
                        v-if="printQueue && report.downloadUrl"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <a :href="report.downloadUrl ?? ''">
                            <Download class="h-4 w-4" />
                            Download
                        </a>
                    </Button>

                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="`/quality/reports/${report.id}`">
                            <Eye class="h-4 w-4" />
                        </Link>
                    </Button>
                </div>
            </div>
        </div>

        <p
            v-if="reports.length === 0"
            class="py-8 text-center text-muted-foreground"
        >
            {{ emptyMessage }}
        </p>

        <Dialog
            :open="workflowAction !== null"
            @update:open="(open) => !open && closeWorkflow()"
        >
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ workflowCopy.title }}</DialogTitle>
                    <DialogDescription>
                        {{ workflowCopy.description }}
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submitWorkflow">
                    <div class="grid gap-2">
                        <Label for="workflow-note">{{
                            workflowCopy.label
                        }}</Label>
                        <textarea
                            id="workflow-note"
                            v-model="workflowForm.note"
                            rows="5"
                            class="rounded-md border bg-background px-3 py-2 text-sm"
                            :required="workflowAction === 'delete'"
                        />
                        <p
                            v-if="workflowForm.errors.note"
                            class="text-sm text-destructive"
                        >
                            {{ workflowForm.errors.note }}
                        </p>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="closeWorkflow"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            :variant="
                                workflowAction === 'delete'
                                    ? 'destructive'
                                    : 'default'
                            "
                            :disabled="
                                workflowForm.processing ||
                                (workflowAction === 'delete' &&
                                    !workflowForm.note.trim())
                            "
                        >
                            {{ workflowCopy.button }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
