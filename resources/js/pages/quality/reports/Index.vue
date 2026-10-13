<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, ChevronDown } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import PageBanner from '@/components/PageBanner.vue';
import CourseList from '@/components/quality/CourseList.vue';
import CourseRow from '@/components/quality/CourseRow.vue';
import CourseScopeCell from '@/components/quality/CourseScopeCell.vue';
import CourseSearch from '@/components/quality/CourseSearch.vue';
import CourseTitleCell from '@/components/quality/CourseTitleCell.vue';
import LecturerCell from '@/components/quality/LecturerCell.vue';
import ReportVariantActions from '@/components/quality/ReportVariantActions.vue';
import SplitMenu from '@/components/quality/SplitMenu.vue';
import StatusPill from '@/components/quality/StatusPill.vue';
import StatusSummary from '@/components/quality/StatusSummary.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useSearchParam } from '@/composables/useSearchParam';
import { matchesSearch } from '@/lib/coursePartitions';
import { toReportStatus } from '@/lib/reportStatus';
import type { ReportStatusKey } from '@/lib/reportStatus';
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

const workflowTargets = ref<QualityReport[]>([]);
const workflowAction = ref<WorkflowAction | null>(null);
const workflowForm = useForm({ note: '' });
const selectedReportIds = ref<number[]>([]);
const bulkForwardOpen = ref(false);
const bulkForwardForm = useForm({
    report_ids: [] as number[],
    note: '',
});

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

const actionableStatuses: Record<WorkflowAction, string[]> = {
    forward: ['submitted'],
    publish: ['under_review'],
    return: ['submitted', 'under_review'],
    unapprove: ['approved'],
    delete: ['submitted', 'under_review'],
};

const openWorkflow = (reports: QualityReport[], action: WorkflowAction) => {
    workflowTargets.value = reports.filter((report) =>
        actionableStatuses[action].includes(report.status),
    );
    workflowAction.value = action;
    workflowForm.reset();
    workflowForm.clearErrors();
};

const closeWorkflow = () => {
    workflowTargets.value = [];
    workflowAction.value = null;
    workflowForm.reset();
};

const submitWorkflow = () => {
    const action = workflowAction.value;
    const queue = [...workflowTargets.value];

    if (!action || queue.length === 0) {
        return;
    }

    const endpoint =
        action === 'forward'
            ? 'forward'
            : action === 'unapprove'
              ? 'unapprove'
              : action === 'publish'
                ? 'approve'
                : 'return';

    const next = () => {
        const report = queue.shift();

        if (!report) {
            closeWorkflow();

            return;
        }

        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: next,
        };

        if (action === 'delete') {
            workflowForm.delete(`/quality/reports/${report.id}`, options);
        } else {
            workflowForm.post(
                `/quality/reports/${report.id}/${endpoint}`,
                options,
            );
        }
    };

    next();
};

const lecturerSearch = ref('');

const activeFilters = computed<ReportFilters>(() => props.filters ?? {});
/** One review slot: a course part taught by one lecturer (may cover several program sections). */
type ReportUnit = {
    key: string;
    label: string;
    reports: QualityReport[];
    lecturer: QualityReport['lecturer'];
    status: ReportStatusKey;
    mixed: boolean;
};

type CourseGroup = {
    key: string;
    code: string;
    title: string;
    first: QualityReport;
    reports: QualityReport[];
    units: ReportUnit[];
    split: boolean;
    programs: Array<{ id: number; name: string; code: string }>;
    sections: Array<{ id: number; name: string }>;
    levels: string[];
};

const statusFilter = ref<ReportStatusKey | ''>('');
const reportLevel = (report: QualityReport) =>
    report.courses.map((course) => course.level).find(Boolean) ?? null;

const unitFor = (key: string, reports: QualityReport[]): ReportUnit => {
    const statuses = new Set(
        reports.map((report) => toReportStatus(report.status)),
    );

    return {
        key,
        label: reports[0].part?.label ?? 'Whole course',
        reports,
        lecturer: reports[0].lecturer,
        status: toReportStatus(reports[0].status),
        mixed: statuses.size > 1,
    };
};

const courseGroups = computed<CourseGroup[]>(() => {
    const groups = new Map<string, QualityReport[]>();

    props.reports.forEach((report) => {
        const key = `${report.courses[0]?.id ?? `r${report.id}`}:${report.semester?.id ?? 'none'}`;
        groups.set(key, [...(groups.get(key) ?? []), report]);
    });

    return [...groups.entries()].map(([key, reports]) => {
        const units = new Map<string, QualityReport[]>();

        reports.forEach((report) => {
            const unitKey = `${report.part?.key ?? 'all'}:${report.lecturer?.id ?? 0}`;
            units.set(unitKey, [...(units.get(unitKey) ?? []), report]);
        });

        const unique = <T extends { id: number }>(
            items: Array<T | null | undefined>,
        ): T[] =>
            items
                .filter((item): item is T => Boolean(item))
                .filter(
                    (item, index, values) =>
                        values.findIndex((value) => value.id === item.id) ===
                        index,
                );

        return {
            key,
            code: reports[0].courses[0]?.code ?? 'No course',
            title: reports[0].courses[0]?.title ?? reports[0].title,
            first: reports[0],
            reports,
            units: [...units.entries()].map(([unitKey, unitReports]) =>
                unitFor(unitKey, unitReports),
            ),
            split: (reports[0].part?.mode ?? 'merged') !== 'merged',
            programs: unique(reports.map((report) => report.program)),
            sections: unique(reports.map((report) => report.section)),
            levels: [
                ...new Set(
                    reports
                        .map(reportLevel)
                        .filter((level): level is string => Boolean(level)),
                ),
            ],
        };
    });
});

const statusCounts = computed(() =>
    courseGroups.value
        .flatMap((group) => group.units)
        .reduce<Partial<Record<ReportStatusKey, number>>>((counts, unit) => {
            counts[unit.status] = (counts[unit.status] ?? 0) + 1;

            return counts;
        }, {}),
);
const courseSearch = ref(useSearchParam('search'));
const displayedGroups = computed(() =>
    courseGroups.value.filter(
        (group) =>
            (!statusFilter.value ||
                group.units.some(
                    (unit) => unit.status === statusFilter.value,
                )) &&
            matchesSearch(courseSearch.value, [
                group.code,
                group.title,
                ...group.programs.flatMap((program) => [
                    program.code,
                    program.name,
                ]),
                ...group.units.map((unit) => unit.lecturer?.name),
            ]),
    ),
);
const groupStatus = (group: CourseGroup) => ({
    status: group.units[0]?.status ?? 'not_uploaded',
    mixed:
        new Set(group.units.map((unit) => unit.status)).size > 1 ||
        group.units.some((unit) => unit.mixed),
});
const showUnitRows = (group: CourseGroup) =>
    group.split || group.units.length > 1;
const unitDate = (unit: ReportUnit) => unit.reports[0].createdAt;

const forwardableReportIds = computed(() =>
    props.reports
        .filter((report) => report.status === 'submitted')
        .map((report) => report.id),
);
const allForwardableSelected = computed(
    () =>
        forwardableReportIds.value.length > 0 &&
        forwardableReportIds.value.every((id) =>
            selectedReportIds.value.includes(id),
        ),
);
const toggleAllForwardable = () => {
    selectedReportIds.value = allForwardableSelected.value
        ? []
        : [...forwardableReportIds.value];
};
const forwardableIds = (reports: QualityReport[]) =>
    reports
        .filter((report) => report.status === 'submitted')
        .map((report) => report.id);
const isSelected = (reports: QualityReport[]) => {
    const ids = forwardableIds(reports);

    return (
        ids.length > 0 &&
        ids.every((id) => selectedReportIds.value.includes(id))
    );
};
const toggleSelection = (reports: QualityReport[]) => {
    const ids = forwardableIds(reports);

    selectedReportIds.value = isSelected(reports)
        ? selectedReportIds.value.filter((id) => !ids.includes(id))
        : [...new Set([...selectedReportIds.value, ...ids])];
};
const openBulkForward = (reportIds = selectedReportIds.value) => {
    if (reportIds.length === 0) {
        return;
    }

    bulkForwardForm.report_ids = [...reportIds];
    bulkForwardForm.note = '';
    bulkForwardForm.clearErrors();
    bulkForwardOpen.value = true;
};
const submitBulkForward = () => {
    bulkForwardForm.post('/quality/reports/forward-selected', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            selectedReportIds.value = [];
            bulkForwardOpen.value = false;
            bulkForwardForm.reset();
        },
    });
};

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
            preserveState: true,
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
        <PageBanner :title="pageTitle" :description="pageDescription" />

        <div
            v-if="filterOptions"
            class="grid gap-3 rounded-xl border border-[#9fd8b8] bg-[#eefaf3] p-5 shadow-sm ring-1 ring-[#007a2f]/10 md:grid-cols-5 lg:sticky lg:top-20 lg:z-20"
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

        <div
            v-if="reports.length"
            class="flex flex-col gap-3 rounded-xl border border-[#9fd8b8] bg-[#eefaf3] px-4 py-3 xl:flex-row xl:items-center xl:justify-between"
        >
            <div
                class="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-5"
            >
                <label
                    v-if="
                        reviewStage === 'supervisor' &&
                        forwardableReportIds.length
                    "
                    class="flex shrink-0 items-center gap-3 text-sm font-medium text-[#063f22]"
                >
                    <input
                        type="checkbox"
                        class="h-4 w-4 accent-[#007a2f]"
                        :checked="allForwardableSelected"
                        @change="toggleAllForwardable"
                    />
                    Select all submitted
                    <span class="font-normal text-muted-foreground">
                        ({{ selectedReportIds.length }} selected)
                    </span>
                </label>
                <StatusSummary v-model="statusFilter" :counts="statusCounts" />
            </div>
            <Button
                v-if="
                    reviewStage === 'supervisor' && forwardableReportIds.length
                "
                type="button"
                size="sm"
                class="shrink-0 bg-[#007a2f] text-white hover:bg-[#006428]"
                :disabled="selectedReportIds.length === 0"
                @click="openBulkForward()"
            >
                <Check class="h-4 w-4" />
                Forward selected to Director
            </Button>
        </div>

        <CourseSearch
            v-if="courseGroups.length"
            v-model="courseSearch"
            :shown="displayedGroups.length"
            :total="courseGroups.length"
        />

        <CourseList
            v-if="displayedGroups.length"
            :headings="[
                '',
                'Course',
                'Programs and sections',
                'Lecturer',
                'Status',
                'Actions',
            ]"
            columns="24px minmax(0,1.6fr) minmax(0,1.4fr) minmax(0,1.5fr) minmax(150px,1fr) minmax(170px,auto)"
        >
            <CourseRow
                v-for="group in displayedGroups"
                :key="group.key"
                :status="groupStatus(group).status"
                :mixed="groupStatus(group).mixed"
            >
                <div class="hidden md:block">
                    <input
                        v-if="
                            reviewStage === 'supervisor' &&
                            forwardableIds(group.reports).length
                        "
                        type="checkbox"
                        class="h-4 w-4 accent-[#007a2f]"
                        :checked="isSelected(group.reports)"
                        :aria-label="`Select ${group.code}`"
                        @change="toggleSelection(group.reports)"
                    />
                </div>
                <CourseTitleCell
                    :code="group.code"
                    :title="group.title"
                    :subtitle="`${group.first.academicYear?.name ?? 'No year'}${group.first.semester ? ` · ${group.first.semester.name}` : ''}`"
                />
                <div class="min-w-0 space-y-2">
                    <CourseScopeCell
                        :programs="group.programs"
                        :levels="group.levels"
                        :sections="group.sections"
                        :mode="group.first.part?.mode ?? 'merged'"
                        :part-count="group.units.length"
                    />
                    <SplitMenu
                        v-if="group.first.courses[0]"
                        :course-id="group.first.courses[0].id"
                        :course-code="group.code"
                        :semester-id="group.first.semester?.id"
                        :mode="group.first.part?.mode ?? 'merged'"
                    />
                </div>
                <LecturerCell
                    :names="[
                        ...new Set(
                            group.units
                                .map((unit) => unit.lecturer?.name)
                                .filter((name): name is string =>
                                    Boolean(name),
                                ),
                        ),
                    ]"
                    :detail="
                        !showUnitRows(group)
                            ? group.first.lecturer?.email
                            : null
                    "
                />
                <div class="space-y-1">
                    <StatusPill
                        :status="groupStatus(group).status"
                        :mixed="groupStatus(group).mixed"
                    />
                    <div
                        v-if="!showUnitRows(group)"
                        class="text-xs text-[#66808a]"
                    >
                        {{ unitDate(group.units[0]) }}
                    </div>
                </div>
                <ReportVariantActions
                    v-if="!showUnitRows(group)"
                    :report="group.first"
                    :approval-queue="approvalQueue"
                    :print-queue="printQueue"
                    :can-review-reports="canReviewReports"
                    :can-approve-reports="canApproveReports"
                    @workflow="openWorkflow(group.reports, $event)"
                    @print="printReport(group.first)"
                />
                <div
                    v-else
                    class="flex items-center justify-start md:justify-end"
                >
                    <Button
                        v-if="
                            reviewStage === 'supervisor' &&
                            forwardableIds(group.reports).length > 1
                        "
                        type="button"
                        size="sm"
                        class="bg-[#007a2f] text-white hover:bg-[#006428]"
                        @click="openBulkForward(forwardableIds(group.reports))"
                    >
                        <Check class="h-4 w-4" />
                        Forward all
                    </Button>
                    <span v-else class="text-xs text-[#66808a]">
                        {{ group.units.length }} parts
                    </span>
                </div>

                <template v-if="showUnitRows(group)" #children>
                    <CourseRow
                        v-for="unit in group.units"
                        :key="unit.key"
                        :status="unit.status"
                        :mixed="unit.mixed"
                        nested
                    >
                        <div class="hidden md:block">
                            <input
                                v-if="
                                    reviewStage === 'supervisor' &&
                                    forwardableIds(unit.reports).length
                                "
                                type="checkbox"
                                class="h-4 w-4 accent-[#007a2f]"
                                :checked="isSelected(unit.reports)"
                                :aria-label="`Select ${group.code} ${unit.label}`"
                                @change="toggleSelection(unit.reports)"
                            />
                        </div>
                        <div class="min-w-0">
                            <div class="font-medium text-[#1d2f37]">
                                {{ unit.label }}
                            </div>
                            <div class="truncate text-xs text-[#66808a]">
                                {{
                                    [
                                        ...new Set(
                                            unit.reports.map((report) =>
                                                [
                                                    report.program?.name,
                                                    report.section?.name,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' — '),
                                            ),
                                        ),
                                    ].join(' · ')
                                }}
                            </div>
                        </div>
                        <div class="hidden md:block" />
                        <LecturerCell
                            :names="unit.lecturer ? [unit.lecturer.name] : []"
                            :detail="unit.lecturer?.email"
                        />
                        <div class="space-y-1">
                            <StatusPill
                                :status="unit.status"
                                :mixed="unit.mixed"
                            />
                            <div class="text-xs text-[#66808a]">
                                {{ unitDate(unit) }}
                            </div>
                        </div>
                        <ReportVariantActions
                            :report="unit.reports[0]"
                            :approval-queue="approvalQueue"
                            :print-queue="printQueue"
                            :can-review-reports="canReviewReports"
                            :can-approve-reports="canApproveReports"
                            @workflow="openWorkflow(unit.reports, $event)"
                            @print="printReport(unit.reports[0])"
                        />
                    </CourseRow>
                </template>
            </CourseRow>
        </CourseList>

        <p
            v-if="displayedGroups.length === 0"
            class="py-8 text-center text-muted-foreground"
        >
            {{ emptyMessage }}
        </p>

        <Dialog :open="bulkForwardOpen" @update:open="bulkForwardOpen = $event">
            <DialogContent class="sm:max-w-md">
                <form class="space-y-5" @submit.prevent="submitBulkForward">
                    <DialogHeader>
                        <DialogTitle
                            >Forward reports to QA Director</DialogTitle
                        >
                        <DialogDescription>
                            {{ bulkForwardForm.report_ids.length }} selected
                            reports will move to Director Review together.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-2">
                        <Label for="bulk-forward-note">
                            Supervisor note (optional)
                        </Label>
                        <textarea
                            id="bulk-forward-note"
                            v-model="bulkForwardForm.note"
                            rows="4"
                            class="rounded-md border border-[#9fd8b8] bg-white px-3 py-2 text-sm"
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="secondary"
                            @click="bulkForwardOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            class="bg-[#007a2f] text-white hover:bg-[#006428]"
                            :disabled="bulkForwardForm.processing"
                        >
                            <Check class="h-4 w-4" />
                            Forward reports
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

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
