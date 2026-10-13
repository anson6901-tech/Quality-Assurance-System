<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { FileText, Send, Trash2, Upload, X } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PageBanner from '@/components/PageBanner.vue';
import CourseList from '@/components/quality/CourseList.vue';
import CourseRow from '@/components/quality/CourseRow.vue';
import CourseScopeCell from '@/components/quality/CourseScopeCell.vue';
import CourseSearch from '@/components/quality/CourseSearch.vue';
import CourseTitleCell from '@/components/quality/CourseTitleCell.vue';
import LecturerCell from '@/components/quality/LecturerCell.vue';
import PartLabelCell from '@/components/quality/PartLabelCell.vue';
import SplitMenu from '@/components/quality/SplitMenu.vue';
import StatusPill from '@/components/quality/StatusPill.vue';
import StatusSummary from '@/components/quality/StatusSummary.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useSearchParam } from '@/composables/useSearchParam';
import { matchesSearch } from '@/lib/coursePartitions';
import { toReportStatus } from '@/lib/reportStatus';
import type { ReportStatusKey } from '@/lib/reportStatus';
import type {
    AcademicYearOption,
    CourseEntry,
    CoursePart,
    PartLecturer,
} from '@/types';

type UploadReport = {
    id: number;
    lecturerId: number;
    programId: number | null;
    sectionId: number | null;
    title: string;
    status: string;
    uploadedAt: string;
    pushedAt: string | null;
};
type UploadPart = CoursePart & { reports: UploadReport[] };
type UploadCourse = CourseEntry<UploadPart>;

/** One report slot: a course part taught by one lecturer. */
type UploadUnit = {
    key: string;
    course: UploadCourse;
    part: UploadPart;
    lecturer: PartLecturer;
    reports: UploadReport[];
    status: ReportStatusKey;
    mixed: boolean;
    missingCells: number;
};

interface Semester {
    id: number;
    name: string;
    academic_year_id: number;
}

const props = defineProps<{
    academicYears: AcademicYearOption[];
    semesters: Semester[];
    departments: Array<{ id: number; name: string }>;
    supervisors: Array<{ id: number; name: string; email: string }>;
    courses: UploadCourse[];
    defaultAcademicYearId?: number;
    defaultSemesterId?: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'QA Reports', href: '/quality/reports' },
            { title: 'Upload', href: '/quality/reports/create' },
        ],
    },
});

const courseCodeSearch = ref(useSearchParam('search'));
const schoolFilter = ref('');
const departmentFilter = ref('');
const programFilter = ref('');
const levelFilter = ref('');
const courseTypeFilter = ref('');
const lecturerFilter = ref('');
const statusFilter = ref<ReportStatusKey | ''>('');
const fileInput = ref<HTMLInputElement | null>(null);
const uploadOpen = ref(false);
const uploadCourseCode = ref('');
const uploadScope = ref<string[]>([]);
const selectedReportIds = ref<number[]>([]);
const supervisorPickerOpen = ref(false);
const pendingPushReportIds = ref<number[]>([]);
const draftToDelete = ref<number[] | null>(null);

const form = useForm({
    title: '',
    category: 'Academic Quality',
    academic_year_id: props.defaultAcademicYearId
        ? String(props.defaultAcademicYearId)
        : '',
    semester_id: props.defaultSemesterId ? String(props.defaultSemesterId) : '',
    cells: [] as Array<{
        program_id: number | null;
        section_id: number | null;
    }>,
    lecturer_id: '',
    course_ids: [] as number[],
    report_file: null as File | null,
    summary: '',
});

const pushForm = useForm({
    supervisor_id: '',
    report_ids: [] as number[],
});

const deleteDraftForm = useForm({});

const semestersForYear = computed(() =>
    props.semesters.filter(
        (semester) =>
            semester.academic_year_id === Number(form.academic_year_id),
    ),
);

const coursesForSemester = computed(() =>
    props.courses.filter(
        (course) => course.semesterId === Number(form.semester_id),
    ),
);
const schoolName = (course: UploadCourse) =>
    course.department?.school?.name ?? '';
const schools = computed(() => [
    ...new Set(coursesForSemester.value.map(schoolName).filter(Boolean)),
]);
const availableDepartments = computed(() => [
    ...new Set(
        coursesForSemester.value
            .filter(
                (course) =>
                    !schoolFilter.value ||
                    schoolName(course) === schoolFilter.value,
            )
            .map((course) => course.department?.name ?? '')
            .filter(Boolean),
    ),
]);
const programs = computed(() =>
    coursesForSemester.value
        .filter(
            (course) =>
                (!schoolFilter.value ||
                    schoolName(course) === schoolFilter.value) &&
                (!departmentFilter.value ||
                    course.department?.name === departmentFilter.value),
        )
        .flatMap((course) => course.programs)
        .filter(
            (program, index, values) =>
                values.findIndex((value) => value.id === program.id) === index,
        ),
);
const lecturers = computed(() =>
    coursesForSemester.value
        .flatMap((course) => course.parts.flatMap((part) => part.lecturers))
        .filter(
            (lecturer, index, values) =>
                values.findIndex((value) => value.id === lecturer.id) === index,
        )
        .sort((a, b) => a.name.localeCompare(b.name)),
);
const levels = computed(() =>
    [
        ...new Set(coursesForSemester.value.flatMap((course) => course.levels)),
    ].sort((left, right) => Number(left) - Number(right)),
);

const unitsFor = (course: UploadCourse): UploadUnit[] =>
    course.parts.flatMap((part) =>
        part.lecturers.map((lecturer) => {
            const reports = part.reports.filter(
                (report) => report.lecturerId === lecturer.id,
            );
            const statuses = new Set(
                reports.map((report) => toReportStatus(report.status)),
            );
            const missingCells = Math.max(
                part.cells.length - reports.length,
                0,
            );

            if (reports.length && missingCells) {
                statuses.add('not_uploaded');
            }

            return {
                key: `${part.key}:${lecturer.id}`,
                course,
                part,
                lecturer,
                reports,
                status: reports.length
                    ? toReportStatus(reports[0].status)
                    : 'not_uploaded',
                mixed: statuses.size > 1,
                missingCells,
            };
        }),
    );

const filteredCourses = computed(() =>
    coursesForSemester.value.filter((course) => {
        return (
            matchesSearch(courseCodeSearch.value, [
                course.code,
                course.title,
                course.department?.name,
                ...course.programs.flatMap((program) => [
                    program.code,
                    program.name,
                ]),
                ...course.parts.flatMap((part) =>
                    part.lecturers.map((lecturer) => lecturer.name),
                ),
            ]) &&
            (!schoolFilter.value ||
                schoolName(course) === schoolFilter.value) &&
            (!departmentFilter.value ||
                course.department?.name === departmentFilter.value) &&
            (!programFilter.value ||
                course.programs.some(
                    (program) => String(program.id) === programFilter.value,
                )) &&
            (!levelFilter.value || course.levels.includes(levelFilter.value)) &&
            (!courseTypeFilter.value ||
                course.courseType === courseTypeFilter.value) &&
            (!lecturerFilter.value ||
                unitsFor(course).some(
                    (unit) => String(unit.lecturer.id) === lecturerFilter.value,
                ))
        );
    }),
);

const statusCounts = computed(() =>
    filteredCourses.value
        .flatMap(unitsFor)
        .reduce<Partial<Record<ReportStatusKey, number>>>((counts, unit) => {
            counts[unit.status] = (counts[unit.status] ?? 0) + 1;

            return counts;
        }, {}),
);
const displayCourses = computed(() =>
    statusFilter.value
        ? filteredCourses.value.filter((course) =>
              unitsFor(course).some(
                  (unit) => unit.status === statusFilter.value,
              ),
          )
        : filteredCourses.value,
);
const courseStatus = (course: UploadCourse) => {
    const units = unitsFor(course);
    const statuses = new Set(units.map((unit) => unit.status));

    return {
        status: units[0]?.status ?? 'not_uploaded',
        mixed: statuses.size > 1 || units.some((unit) => unit.mixed),
    };
};
const showUnitRows = (course: UploadCourse) =>
    course.splitMode !== 'merged' || unitsFor(course).length > 1;

const draftIds = (unit: UploadUnit) =>
    unit.reports
        .filter((report) => report.status === 'draft')
        .map((report) => report.id);
const visibleDraftReportIds = computed(() => [
    ...new Set(displayCourses.value.flatMap(unitsFor).flatMap(draftIds)),
]);
const unitSelected = (unit: UploadUnit) => {
    const ids = draftIds(unit);

    return (
        ids.length > 0 &&
        ids.every((id) => selectedReportIds.value.includes(id))
    );
};
const toggleUnit = (unit: UploadUnit) => {
    const ids = draftIds(unit);
    selectedReportIds.value = unitSelected(unit)
        ? selectedReportIds.value.filter((id) => !ids.includes(id))
        : [...new Set([...selectedReportIds.value, ...ids])];
};
const allVisibleDraftsSelected = computed(
    () =>
        visibleDraftReportIds.value.length > 0 &&
        visibleDraftReportIds.value.every((id) =>
            selectedReportIds.value.includes(id),
        ),
);
const toggleAllVisibleDrafts = () => {
    selectedReportIds.value = allVisibleDraftsSelected.value
        ? selectedReportIds.value.filter(
              (id) => !visibleDraftReportIds.value.includes(id),
          )
        : [
              ...new Set([
                  ...selectedReportIds.value,
                  ...visibleDraftReportIds.value,
              ]),
          ];
};

const canSubmit = computed(
    () =>
        form.title.trim() !== '' &&
        form.academic_year_id !== '' &&
        form.semester_id !== '' &&
        form.course_ids.length > 0 &&
        form.cells.length > 0 &&
        form.report_file !== null &&
        !form.processing,
);

watch(
    () => form.academic_year_id,
    () => {
        const firstSemester = semestersForYear.value[0];
        form.semester_id = firstSemester ? String(firstSemester.id) : '';
    },
);

const startUpload = async (unit: UploadUnit) => {
    const uploaded = new Set(
        unit.reports.map((report) => `${report.programId}:${report.sectionId}`),
    );
    const cells = unit.part.cells.filter(
        (cell) => !uploaded.has(`${cell.programId}:${cell.sectionId}`),
    );

    uploadCourseCode.value = `${unit.course.code} · ${unit.part.label}`;
    uploadScope.value = cells.map((cell) =>
        [cell.program?.name, cell.section?.name].filter(Boolean).join(' — '),
    );
    form.course_ids = [unit.course.id];
    form.cells = cells.map((cell) => ({
        program_id: cell.programId,
        section_id: cell.sectionId,
    }));
    form.lecturer_id = String(unit.lecturer.id);
    form.title = `Lecture evaluation - ${unit.course.code}`;
    form.clearErrors();

    await nextTick();
    uploadOpen.value = true;
};

const pushSelectedToSupervisor = () => {
    if (selectedReportIds.value.length === 0) {
        return;
    }

    pendingPushReportIds.value = [...selectedReportIds.value];
    pushForm.supervisor_id = '';
    pushForm.clearErrors();
    supervisorPickerOpen.value = true;
};

const pushUnit = (unit: UploadUnit) => {
    pendingPushReportIds.value = draftIds(unit);
    pushForm.supervisor_id = '';
    pushForm.clearErrors();
    supervisorPickerOpen.value = true;
};

const confirmDeleteDraft = () => {
    const ids = [...(draftToDelete.value ?? [])];

    const deleteNext = () => {
        const id = ids.shift();

        if (id === undefined) {
            selectedReportIds.value = selectedReportIds.value.filter(
                (reportId) => !(draftToDelete.value ?? []).includes(reportId),
            );
            draftToDelete.value = null;

            return;
        }

        deleteDraftForm.delete(`/quality/reports/${id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: deleteNext,
        });
    };

    deleteNext();
};

const confirmPushToSupervisor = () => {
    if (!pushForm.supervisor_id || pendingPushReportIds.value.length === 0) {
        return;
    }

    const reportIds = [...pendingPushReportIds.value];
    const route =
        reportIds.length === 1
            ? `/quality/reports/${reportIds[0]}/push`
            : '/quality/reports/push-selected';

    pushForm.report_ids = reportIds;
    pushForm.post(route, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            selectedReportIds.value = selectedReportIds.value.filter(
                (reportId) => !reportIds.includes(reportId),
            );
            pendingPushReportIds.value = [];
            supervisorPickerOpen.value = false;
            pushForm.reset();
        },
    });
};

const formatDateTime = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : null;

const activityText = (unit: UploadUnit) => {
    const report = unit.reports[0];

    if (!report) {
        return null;
    }

    if (report.status === 'draft' && report.pushedAt) {
        return 'Returned for changes';
    }

    return report.pushedAt
        ? `Pushed ${formatDateTime(report.pushedAt)}`
        : `Uploaded ${formatDateTime(report.uploadedAt)}`;
};

const closeUpload = () => {
    uploadOpen.value = false;
    uploadCourseCode.value = '';
    uploadScope.value = [];
    form.reset(
        'title',
        'cells',
        'lecturer_id',
        'course_ids',
        'report_file',
        'summary',
    );
    clearFile();
};

const chooseFile = (event: Event) => {
    form.report_file = (event.target as HTMLInputElement).files?.[0] ?? null;
};

const clearFile = () => {
    form.report_file = null;

    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const submit = () => {
    form.post('/quality/reports', {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: closeUpload,
    });
};

const applyFilters = () => {
    router.reload({ only: ['courses'] });
};
</script>

<template>
    <Head title="Upload Report" />

    <div class="w-full min-w-0 space-y-6">
        <div
            class="space-y-5 bg-[#f4f7fb] lg:sticky lg:top-20 lg:z-20 lg:-mt-5 lg:pt-5 lg:pb-4"
        >
            <PageBanner
                title="Upload Reports"
                description="Upload one evaluation report for each course part and lecturer, then push drafts to the QA Supervisor"
            />

            <div
                class="flex flex-wrap items-end gap-4 rounded-2xl border border-[#7acb9c] bg-gradient-to-br from-[#effaf3] to-[#e3f6eb] p-5 shadow-sm ring-1 ring-[#007a2f]/10 [&_input]:border-[#9fd8b8] [&_input]:bg-white [&_select]:w-full [&_select]:border-[#9fd8b8] [&_select]:bg-white [&_select]:text-[#063f22]"
            >
                <div class="grid min-w-[180px] flex-1 grid-cols-1 gap-1">
                    <label for="academic-year" class="text-sm font-medium">
                        Academic year
                    </label>
                    <select
                        id="academic-year"
                        v-model="form.academic_year_id"
                        class="h-10 rounded-md border bg-white px-3 text-sm"
                    >
                        <option value="" disabled>Academic year</option>
                        <option
                            v-for="year in props.academicYears"
                            :key="year.id"
                            :value="String(year.id)"
                        >
                            {{ year.name }}
                        </option>
                    </select>
                </div>
                <div class="grid min-w-[180px] flex-1 grid-cols-1 gap-1">
                    <label for="semester" class="text-sm font-medium">
                        Semester
                    </label>
                    <select
                        id="semester"
                        v-model="form.semester_id"
                        class="h-10 rounded-md border bg-white px-3 text-sm"
                    >
                        <option value="" disabled>Semester</option>
                        <option
                            v-for="semester in semestersForYear"
                            :key="semester.id"
                            :value="String(semester.id)"
                        >
                            {{ semester.name }}
                        </option>
                    </select>
                </div>
                <div class="grid min-w-[180px] flex-1 grid-cols-1 gap-1">
                    <label for="school" class="text-sm font-medium">
                        School
                    </label>
                    <select
                        id="school"
                        v-model="schoolFilter"
                        class="h-10 rounded-md border bg-white px-3 text-sm"
                        @change="
                            departmentFilter = '';
                            programFilter = '';
                        "
                    >
                        <option value="">All schools</option>
                        <option
                            v-for="school in schools"
                            :key="school"
                            :value="school"
                        >
                            {{ school }}
                        </option>
                    </select>
                </div>
                <div class="grid min-w-[180px] flex-1 grid-cols-1 gap-1">
                    <label for="department" class="text-sm font-medium">
                        Department
                    </label>
                    <select
                        id="department"
                        v-model="departmentFilter"
                        class="h-10 rounded-md border bg-white px-3 text-sm"
                        @change="programFilter = ''"
                    >
                        <option value="">All departments</option>
                        <option
                            v-for="department in availableDepartments"
                            :key="department"
                            :value="department"
                        >
                            {{ department }}
                        </option>
                    </select>
                </div>
                <div class="grid min-w-[180px] flex-1 grid-cols-1 gap-1">
                    <label for="program" class="text-sm font-medium">
                        Program
                    </label>
                    <select
                        id="program"
                        v-model="programFilter"
                        class="h-10 rounded-md border bg-white px-3 text-sm"
                    >
                        <option value="">All programs</option>
                        <option
                            v-for="program in programs"
                            :key="program.id"
                            :value="String(program.id)"
                        >
                            {{ program.name }} ({{ program.code }})
                        </option>
                    </select>
                </div>
                <div class="grid min-w-[150px] flex-1 grid-cols-1 gap-1">
                    <label for="level" class="text-sm font-medium">Level</label>
                    <select
                        id="level"
                        v-model="levelFilter"
                        class="h-10 rounded-md border bg-white px-3 text-sm"
                    >
                        <option value="">All levels</option>
                        <option
                            v-for="level in levels"
                            :key="level!"
                            :value="level!"
                        >
                            Level {{ level }}
                        </option>
                    </select>
                </div>
                <div class="grid min-w-[150px] flex-1 grid-cols-1 gap-1">
                    <label for="course-type" class="text-sm font-medium">
                        Course type
                    </label>
                    <select
                        id="course-type"
                        v-model="courseTypeFilter"
                        class="h-10 rounded-md border bg-white px-3 text-sm"
                    >
                        <option value="">All types</option>
                        <option value="normal">Normal</option>
                        <option value="module">Module</option>
                    </select>
                </div>
                <div class="grid min-w-[180px] flex-1 grid-cols-1 gap-1">
                    <label for="lecturer" class="text-sm font-medium">
                        Lecturer
                    </label>
                    <select
                        id="lecturer"
                        v-model="lecturerFilter"
                        class="h-10 rounded-md border bg-white px-3 text-sm"
                    >
                        <option value="">All lecturers</option>
                        <option
                            v-for="lecturer in lecturers"
                            :key="lecturer.id"
                            :value="String(lecturer.id)"
                        >
                            {{ lecturer.name }}
                        </option>
                    </select>
                </div>
                <Button
                    type="button"
                    class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                    :disabled="!form.academic_year_id || !form.semester_id"
                    @click="applyFilters"
                >
                    Apply filters
                </Button>
            </div>

            <div
                class="flex flex-col gap-3 rounded-2xl border border-[#8fd2ab] bg-[#effaf3] p-4 shadow-sm xl:flex-row xl:items-center xl:justify-between"
            >
                <div
                    class="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-5"
                >
                    <label
                        class="flex shrink-0 cursor-pointer items-center gap-3 text-sm font-semibold text-[#064426]"
                    >
                        <input
                            type="checkbox"
                            class="h-5 w-5 rounded border-[#69bd8d] text-[#007a2f] accent-[#007a2f]"
                            :checked="allVisibleDraftsSelected"
                            :disabled="visibleDraftReportIds.length === 0"
                            @change="toggleAllVisibleDrafts"
                        />
                        Select all drafts
                        <span class="font-normal text-[#397253]">
                            ({{ selectedReportIds.length }} selected)
                        </span>
                    </label>
                    <StatusSummary
                        v-model="statusFilter"
                        :counts="statusCounts"
                    />
                </div>
                <Button
                    type="button"
                    class="shrink-0 bg-[#007a2f] text-white hover:bg-[#006d2b]"
                    :disabled="selectedReportIds.length === 0"
                    @click="pushSelectedToSupervisor"
                >
                    <Send class="h-4 w-4" />
                    Push selected to Supervisor
                </Button>
            </div>
        </div>

        <div class="space-y-5">
            <CourseSearch
                v-if="coursesForSemester.length"
                v-model="courseCodeSearch"
                :shown="displayCourses.length"
                :total="coursesForSemester.length"
            />

            <CourseList
                v-if="displayCourses.length"
                :headings="[
                    '',
                    'Course',
                    'Programs and sections',
                    'Lecturer',
                    'Status',
                    'Action',
                ]"
                columns="24px minmax(0,1.6fr) minmax(0,1.4fr) minmax(0,1.5fr) minmax(150px,1fr) minmax(170px,auto)"
            >
                <CourseRow
                    v-for="course in displayCourses"
                    :key="course.key"
                    :status="courseStatus(course).status"
                    :mixed="courseStatus(course).mixed"
                >
                    <template v-if="!showUnitRows(course)">
                        <div class="hidden md:block">
                            <input
                                v-if="
                                    unitsFor(course)[0] &&
                                    draftIds(unitsFor(course)[0]).length
                                "
                                type="checkbox"
                                class="h-4 w-4 accent-[#007a2f]"
                                :checked="unitSelected(unitsFor(course)[0])"
                                :aria-label="`Select ${course.code} draft`"
                                @change="toggleUnit(unitsFor(course)[0])"
                            />
                        </div>
                    </template>
                    <div v-else class="hidden md:block" />
                    <CourseTitleCell
                        :code="course.code"
                        :title="course.title"
                        :course-type="course.courseType"
                        :subtitle="course.department?.name"
                    />
                    <div class="min-w-0 space-y-2">
                        <CourseScopeCell
                            :programs="course.programs"
                            :levels="course.levels"
                            :sections="course.sections"
                            :mode="course.splitMode"
                            :part-count="course.parts.length"
                        />
                        <SplitMenu
                            :course-id="course.id"
                            :course-code="course.code"
                            :semester-id="course.semesterId"
                            :mode="course.splitMode"
                            :cells="course.parts.flatMap((part) => part.cells)"
                        />
                    </div>
                    <template
                        v-if="!showUnitRows(course) && unitsFor(course)[0]"
                    >
                        <LecturerCell
                            :names="[unitsFor(course)[0].lecturer.name]"
                            :detail="unitsFor(course)[0].lecturer.email"
                        />
                        <div class="space-y-1">
                            <StatusPill
                                :status="unitsFor(course)[0].status"
                                :mixed="unitsFor(course)[0].mixed"
                            />
                            <div
                                v-if="activityText(unitsFor(course)[0])"
                                class="text-xs text-[#66808a]"
                            >
                                {{ activityText(unitsFor(course)[0]) }}
                            </div>
                        </div>
                    </template>
                    <template v-else>
                        <LecturerCell
                            :names="[
                                ...new Set(
                                    unitsFor(course).map(
                                        (unit) => unit.lecturer.name,
                                    ),
                                ),
                            ]"
                        />
                        <StatusPill
                            :status="courseStatus(course).status"
                            :mixed="courseStatus(course).mixed"
                        />
                    </template>
                    <div
                        v-if="!showUnitRows(course) && unitsFor(course)[0]"
                        class="flex items-center justify-start gap-2 md:justify-end"
                    >
                        <template
                            v-for="unit in [unitsFor(course)[0]]"
                            :key="unit.key"
                        >
                            <Button
                                v-if="draftIds(unit).length"
                                type="button"
                                size="icon"
                                variant="ghost"
                                class="size-8 text-red-700 hover:bg-red-50 hover:text-red-800"
                                title="Delete draft"
                                @click="draftToDelete = draftIds(unit)"
                            >
                                <Trash2 class="h-4 w-4" />
                                <span class="sr-only">Delete draft</span>
                            </Button>
                            <Button
                                v-if="draftIds(unit).length"
                                type="button"
                                size="sm"
                                class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                                @click="pushUnit(unit)"
                            >
                                <Send class="h-4 w-4" /> Push
                            </Button>
                            <Button
                                v-if="unit.missingCells"
                                type="button"
                                size="sm"
                                variant="outline"
                                class="border-[#8bcfaa] text-[#006d2b] hover:bg-[#e7f6ed]"
                                @click="startUpload(unit)"
                            >
                                <Upload class="h-4 w-4" />
                                {{
                                    unit.reports.length
                                        ? 'Upload missing'
                                        : 'Upload'
                                }}
                            </Button>
                            <span
                                v-if="
                                    !draftIds(unit).length && !unit.missingCells
                                "
                                class="text-xs text-[#66808a]"
                                >No action needed</span
                            >
                        </template>
                    </div>
                    <div v-else class="text-xs text-[#66808a] md:text-right">
                        {{ unitsFor(course).length }} report
                        {{ unitsFor(course).length === 1 ? 'slot' : 'slots' }}
                    </div>

                    <template v-if="showUnitRows(course)" #children>
                        <CourseRow
                            v-for="unit in unitsFor(course)"
                            :key="unit.key"
                            :status="unit.status"
                            :mixed="unit.mixed"
                            nested
                        >
                            <div class="hidden md:block">
                                <input
                                    v-if="draftIds(unit).length"
                                    type="checkbox"
                                    class="h-4 w-4 accent-[#007a2f]"
                                    :checked="unitSelected(unit)"
                                    :aria-label="`Select ${course.code} ${unit.part.label} draft`"
                                    @change="toggleUnit(unit)"
                                />
                            </div>
                            <PartLabelCell
                                :label="unit.part.label"
                                :cells="unit.part.cells"
                            />
                            <div class="hidden md:block" />
                            <LecturerCell
                                :names="[unit.lecturer.name]"
                                :detail="unit.lecturer.email"
                            />
                            <div class="space-y-1">
                                <StatusPill
                                    :status="unit.status"
                                    :mixed="unit.mixed"
                                />
                                <div
                                    v-if="activityText(unit)"
                                    class="text-xs text-[#66808a]"
                                >
                                    {{ activityText(unit) }}
                                </div>
                            </div>
                            <div
                                class="flex items-center justify-start gap-2 md:justify-end"
                            >
                                <Button
                                    v-if="draftIds(unit).length"
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    class="size-8 text-red-700 hover:bg-red-50 hover:text-red-800"
                                    title="Delete draft"
                                    @click="draftToDelete = draftIds(unit)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                    <span class="sr-only">Delete draft</span>
                                </Button>
                                <Button
                                    v-if="draftIds(unit).length"
                                    type="button"
                                    size="sm"
                                    class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                                    @click="pushUnit(unit)"
                                >
                                    <Send class="h-4 w-4" /> Push
                                </Button>
                                <Button
                                    v-if="unit.missingCells"
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    class="border-[#8bcfaa] text-[#006d2b] hover:bg-[#e7f6ed]"
                                    @click="startUpload(unit)"
                                >
                                    <Upload class="h-4 w-4" />
                                    {{
                                        unit.reports.length
                                            ? 'Upload missing'
                                            : 'Upload'
                                    }}
                                </Button>
                                <span
                                    v-if="
                                        !draftIds(unit).length &&
                                        !unit.missingCells
                                    "
                                    class="text-xs text-[#66808a]"
                                    >No action needed</span
                                >
                            </div>
                        </CourseRow>
                    </template>
                </CourseRow>
            </CourseList>

            <div
                v-else
                class="rounded-2xl border border-dashed border-[#9fd8b8] bg-white px-4 py-12 text-center text-sm text-muted-foreground"
            >
                No assigned courses match these filters.
            </div>
            <InputError :message="form.errors.course_ids" />
            <InputError :message="form.errors.lecturer_id" />
            <InputError :message="form.errors.cells" />
        </div>

        <Dialog
            :open="supervisorPickerOpen"
            @update:open="supervisorPickerOpen = $event"
        >
            <DialogContent class="sm:max-w-md">
                <form
                    class="space-y-5"
                    @submit.prevent="confirmPushToSupervisor"
                >
                    <DialogHeader>
                        <DialogTitle>Select QA Supervisor</DialogTitle>
                        <DialogDescription>
                            Only the selected supervisor will receive and review
                            {{
                                pendingPushReportIds.length === 1
                                    ? 'this report'
                                    : `${pendingPushReportIds.length} reports`
                            }}.
                        </DialogDescription>
                    </DialogHeader>

                    <div v-if="supervisors.length" class="grid gap-2">
                        <Label for="push-supervisor">Supervisor</Label>
                        <select
                            id="push-supervisor"
                            v-model="pushForm.supervisor_id"
                            class="h-10 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm"
                            required
                        >
                            <option value="" disabled>Select supervisor</option>
                            <option
                                v-for="supervisor in supervisors"
                                :key="supervisor.id"
                                :value="String(supervisor.id)"
                            >
                                {{ supervisor.name }} - {{ supervisor.email }}
                            </option>
                        </select>
                        <InputError :message="pushForm.errors.supervisor_id" />
                    </div>
                    <p
                        v-else
                        class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
                    >
                        No active QA Supervisors are available. Create or
                        activate a supervisor account first.
                    </p>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="secondary"
                            @click="supervisorPickerOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                            :disabled="
                                !pushForm.supervisor_id ||
                                pushForm.processing ||
                                supervisors.length === 0
                            "
                        >
                            <Send class="h-4 w-4" />
                            Assign and push
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="draftToDelete !== null"
            @update:open="(open) => !open && (draftToDelete = null)"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete draft report?</DialogTitle>
                    <DialogDescription>
                        This removes the uploaded file. You can upload a new
                        report for this course afterward.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="draftToDelete = null"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        class="bg-red-700 text-white hover:bg-red-800"
                        :disabled="deleteDraftForm.processing"
                        @click="confirmDeleteDraft"
                    >
                        <Trash2 class="h-4 w-4" />
                        Delete draft
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="uploadOpen"
            @update:open="(open) => !open && closeUpload()"
        >
            <DialogContent
                class="max-h-[92svh] gap-0 overflow-y-auto border-2 border-[#58bf83] bg-[#f5fcf8] p-0 shadow-2xl sm:max-w-xl [&>button]:top-5 [&>button]:right-5 [&>button]:text-white [&>button]:opacity-80 [&>button:hover]:opacity-100"
            >
                <DialogHeader
                    class="rounded-t-lg bg-gradient-to-r from-[#005f28] to-[#00853a] px-6 py-6 text-left text-white"
                >
                    <DialogTitle class="text-xl text-white">
                        Upload report
                    </DialogTitle>
                    <DialogDescription class="text-white/80">
                        Attach the evaluation report for
                        {{ uploadCourseCode }}.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-5 p-6" @submit.prevent="submit">
                    <div class="flex flex-wrap gap-2">
                        <Badge
                            class="border border-[#8cd2a9] bg-[#dff4e7] text-[#005f28]"
                        >
                            {{ uploadCourseCode }}
                        </Badge>
                        <Badge
                            variant="outline"
                            class="border-[#8cd2a9] bg-white text-[#005f28]"
                        >
                            {{
                                props.semesters.find(
                                    (semester) =>
                                        semester.id ===
                                        Number(form.semester_id),
                                )?.name
                            }}
                        </Badge>
                    </div>

                    <div
                        v-if="uploadScope.length > 1"
                        class="rounded-lg border border-[#9fd8b8] bg-white p-3"
                    >
                        <div class="text-sm font-semibold text-[#075b2e]">
                            One report for this part, covering
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <Badge
                                v-for="scope in uploadScope"
                                :key="scope"
                                variant="outline"
                                class="border-[#9fd8b8] text-[#075b2e]"
                            >
                                {{ scope }}
                            </Badge>
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="category">Category</Label>
                        <Input
                            id="category"
                            v-model="form.category"
                            class="border-[#9fd8b8] bg-white focus-visible:border-[#007a2f] focus-visible:ring-[#007a2f]/20"
                            required
                        />
                        <InputError :message="form.errors.category" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="title">Report title</Label>
                        <Input
                            id="title"
                            v-model="form.title"
                            class="border-[#9fd8b8] bg-white focus-visible:border-[#007a2f] focus-visible:ring-[#007a2f]/20"
                            required
                        />
                        <InputError :message="form.errors.title" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="report-file">PDF report</Label>
                        <input
                            ref="fileInput"
                            id="report-file"
                            type="file"
                            class="sr-only"
                            accept="application/pdf,.pdf"
                            required
                            @change="chooseFile"
                        />
                        <button
                            type="button"
                            class="flex min-h-28 w-full items-center justify-center gap-3 rounded-lg border-2 border-dashed border-[#72c996] bg-[#eaf8f0] px-4 text-sm font-medium text-[#075b2e] transition hover:border-[#007a2f] hover:bg-[#ddf3e6]"
                            @click="fileInput?.click()"
                        >
                            <FileText
                                v-if="form.report_file"
                                class="h-5 w-5 text-emerald-700"
                            />
                            <Upload v-else class="h-5 w-5 text-[#007a2f]" />
                            <span>
                                {{
                                    form.report_file?.name ??
                                    'Choose a PDF file, up to 10 MB'
                                }}
                            </span>
                        </button>
                        <Button
                            v-if="form.report_file"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="w-fit"
                            @click="clearFile"
                        >
                            <X class="h-4 w-4" />
                            Remove file
                        </Button>
                        <InputError :message="form.errors.report_file" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="summary">Summary</Label>
                        <textarea
                            id="summary"
                            v-model="form.summary"
                            rows="4"
                            class="rounded-md border border-[#9fd8b8] bg-white px-3 py-2 text-sm outline-none focus:border-[#007a2f] focus:ring-2 focus:ring-[#007a2f]/20"
                            placeholder="Optional review context or notes"
                        />
                        <InputError :message="form.errors.summary" />
                    </div>

                    <DialogFooter class="border-t border-[#c7ead6] pt-5">
                        <Button
                            type="button"
                            variant="outline"
                            class="border-[#8bcfaa] bg-white text-[#075b2e] hover:bg-[#e7f6ed]"
                            @click="closeUpload"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            class="bg-[#007a2f] text-white shadow-sm hover:bg-[#006d2b]"
                            :disabled="!canSubmit"
                        >
                            <Upload class="h-4 w-4" />
                            {{
                                form.processing
                                    ? 'Uploading...'
                                    : 'Upload report'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
