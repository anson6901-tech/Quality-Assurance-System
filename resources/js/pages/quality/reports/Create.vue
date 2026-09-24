<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    Combine,
    FileText,
    Send,
    Split,
    Trash2,
    Upload,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { useCourseOfferingSplits } from '@/composables/useCourseOfferingSplits';
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
import type { AcademicYearOption, CourseOption } from '@/types';

interface CourseWithLecturers extends CourseOption {
    offeringId: number;
    courseType: 'normal' | 'module';
    program: { id: number; name: string; code: string } | null;
    lecturerCount: number;
    lecturers: Array<{
        id: number;
        name: string;
        email: string;
        semesterId: number | null;
    }>;
    department?: {
        id: number;
        name: string;
        schoolId: number;
        school: { id: number; name: string } | null;
    };
    reports: Array<{
        id: number;
        lecturerId: number;
        semesterId: number;
        title: string;
        status: string;
        uploadedAt: string;
        pushedAt: string | null;
    }>;
}

type AssignmentRow = {
    course: CourseWithLecturers;
    lecturer: CourseWithLecturers['lecturers'][number];
    lecturers: CourseWithLecturers['lecturers'];
    report: CourseWithLecturers['reports'][number] | undefined;
    programs: Array<{ id: number; name: string; code: string }>;
    levels: string[];
    variants: AssignmentRow[];
    groupKey: string;
    mixedStatus: boolean;
};

interface Department {
    id: number;
    name: string;
}

interface Semester {
    id: number;
    name: string;
    academic_year_id: number;
}

const props = defineProps<{
    academicYears: AcademicYearOption[];
    semesters: Semester[];
    departments: Department[];
    supervisors: Array<{ id: number; name: string; email: string }>;
    courses: CourseWithLecturers[];
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

const courseCodeSearch = ref('');
const schoolFilter = ref('');
const departmentFilter = ref('');
const programFilter = ref('');
const levelFilter = ref('');
const courseTypeFilter = ref('');
const lecturerFilter = ref('');
const fileInput = ref<HTMLInputElement | null>(null);
const uploadOpen = ref(false);
const uploadCourseCode = ref('');
const uploadProgramOptions = ref<
    Array<{ id: number; name: string; code: string }>
>([]);
const selectedReportIds = ref<number[]>([]);
const supervisorPickerOpen = ref(false);
const pendingPushReportIds = ref<number[]>([]);
const draftToDelete = ref<number | null>(null);

const form = useForm({
    title: '',
    category: 'Academic Quality',
    academic_year_id: props.defaultAcademicYearId
        ? String(props.defaultAcademicYearId)
        : '',
    semester_id: props.defaultSemesterId ? String(props.defaultSemesterId) : '',
    program_id: '',
    program_ids: [] as number[],
    lecturer_id: '',
    course_ids: [] as number[],
    report_file: null as File | null,
    summary: '',
});

const {
    splitCourseCodes: splitAssignmentGroups,
    toggleCourseSplit: toggleAssignmentSplit,
} = useCourseOfferingSplits(
    () => form.academic_year_id,
    () => form.semester_id,
);

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
    props.courses
        .map((course) => ({
            ...course,
            lecturers: course.lecturers.filter(
                (lecturer) =>
                    lecturer.semesterId === null ||
                    lecturer.semesterId === Number(form.semester_id),
            ),
        }))
        .filter((course) => course.lecturers.length > 0),
);

const schools = computed(() => [
    ...new Set(
        coursesForSemester.value
            .map((course) => course.department?.school?.name)
            .filter((name): name is string => Boolean(name)),
    ),
]);

const departments = computed(() => [
    ...new Set(
        coursesForSemester.value
            .filter(
                (course) =>
                    !schoolFilter.value ||
                    course.department?.school?.name === schoolFilter.value,
            )
            .map((course) => course.department?.name)
            .filter((name): name is string => Boolean(name)),
    ),
]);

const programs = computed(() => {
    const uniquePrograms = new Map<
        number,
        { id: number; name: string; code: string }
    >();

    coursesForSemester.value
        .filter(
            (course) =>
                (!schoolFilter.value ||
                    course.department?.school?.name === schoolFilter.value) &&
                (!departmentFilter.value ||
                    course.department?.name === departmentFilter.value),
        )
        .forEach((course) => {
            if (course.program) {
                uniquePrograms.set(course.program.id, course.program);
            }
        });

    return [...uniquePrograms.values()];
});

const lecturers = computed(() => {
    const uniqueLecturers = new Map<
        number,
        { id: number; name: string; email: string }
    >();

    coursesForSemester.value.forEach((course) => {
        course.lecturers.forEach((lecturer) => {
            uniqueLecturers.set(lecturer.id, lecturer);
        });
    });

    return [...uniqueLecturers.values()].sort((a, b) =>
        a.name.localeCompare(b.name),
    );
});

const filteredCourses = computed(() =>
    coursesForSemester.value.filter((course) => {
        const courseTerm = courseCodeSearch.value.trim().toLowerCase();

        return (
            (!courseTerm ||
                `${course.code} ${course.title}`
                    .toLowerCase()
                    .includes(courseTerm)) &&
            (!schoolFilter.value ||
                course.department?.school?.name === schoolFilter.value) &&
            (!departmentFilter.value ||
                course.department?.name === departmentFilter.value) &&
            (!programFilter.value ||
                String(course.program?.id) === programFilter.value) &&
            (!levelFilter.value || course.level === levelFilter.value) &&
            (!courseTypeFilter.value ||
                course.courseType === courseTypeFilter.value) &&
            (!lecturerFilter.value ||
                course.lecturers.some(
                    (lecturer) => String(lecturer.id) === lecturerFilter.value,
                ))
        );
    }),
);
const levels = computed(() =>
    [
        ...new Set(
            coursesForSemester.value
                .map((course) => course.level)
                .filter(Boolean),
        ),
    ].sort((left, right) => Number(left) - Number(right)),
);

const selectedCourses = computed(() =>
    props.courses.filter(
        (course) =>
            form.course_ids.includes(course.id) &&
            String(course.program?.id) === form.program_id,
    ),
);

const canSubmit = computed(
    () =>
        form.title.trim() !== '' &&
        form.academic_year_id !== '' &&
        form.semester_id !== '' &&
        form.course_ids.length > 0 &&
        (form.program_id !== '' || form.program_ids.length > 0) &&
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

watch(
    () => form.semester_id,
    () => {
        form.course_ids = [];
    },
);

const startUpload = async (course: CourseWithLecturers, lecturerId: number) => {
    uploadCourseCode.value = course.code;
    uploadProgramOptions.value = course.program ? [course.program] : [];
    form.program_ids = [];
    form.course_ids = [course.id];
    form.program_id = course.program ? String(course.program.id) : '';
    form.lecturer_id = String(lecturerId);
    form.title = `Lecture evaluation - ${course.code}`;
    form.clearErrors();

    await nextTick();
    uploadOpen.value = true;
};

const mainUploadableVariants = (row: AssignmentRow) =>
    row.variants.filter((variant) => !variant.report);

const startGroupedUpload = async (row: AssignmentRow) => {
    const variants = mainUploadableVariants(row);

    uploadCourseCode.value = row.course.code;
    uploadProgramOptions.value = [
        ...new Map(
            variants
                .map((variant) => variant.course.program)
                .filter(
                    (
                        program,
                    ): program is { id: number; name: string; code: string } =>
                        Boolean(program),
                )
                .map((program) => [program.id, program]),
        ).values(),
    ];
    form.course_ids = [row.course.id];
    form.program_id = '';
    form.program_ids = uploadProgramOptions.value.map((program) => program.id);
    const lecturerIds = [
        ...new Set(variants.map((variant) => variant.lecturer.id)),
    ];
    form.lecturer_id = lecturerIds.length === 1 ? String(lecturerIds[0]) : '';
    form.title = `Lecture evaluation - ${row.course.code}`;
    form.clearErrors();

    await nextTick();
    uploadOpen.value = true;
};

const assignmentReport = (course: CourseWithLecturers, lecturerId: number) =>
    course.reports.find(
        (report) =>
            report.lecturerId === lecturerId &&
            report.semesterId === Number(form.semester_id),
    );

const baseAssignmentRows = computed(() =>
    filteredCourses.value.flatMap((course) =>
        course.lecturers.map((lecturer) => ({
            course,
            lecturer,
            lecturers: [lecturer],
            report: assignmentReport(course, lecturer.id),
            programs: course.program ? [course.program] : [],
            levels: course.level ? [course.level] : [],
            variants: [] as AssignmentRow[],
            groupKey: course.code,
            mixedStatus: false,
        })),
    ),
);
const groupedAssignmentRows = computed<AssignmentRow[]>(() => {
    const groups = new Map<string, AssignmentRow[]>();

    baseAssignmentRows.value.forEach((row) => {
        groups.set(row.groupKey, [...(groups.get(row.groupKey) ?? []), row]);
    });

    return [...groups.values()].map((variants) => {
        const row = variants[0];
        const statuses = new Set(
            variants.map((variant) => variant.report?.status ?? 'not_uploaded'),
        );

        return {
            ...row,
            lecturers: [
                ...new Map(
                    variants.map((variant) => [
                        variant.lecturer.id,
                        variant.lecturer,
                    ]),
                ).values(),
            ],
            programs: [
                ...new Map(
                    variants
                        .flatMap((variant) => variant.programs)
                        .map((program) => [program.id, program]),
                ).values(),
            ],
            levels: [
                ...new Set(variants.flatMap((variant) => variant.levels)),
            ].sort((left, right) => Number(left) - Number(right)),
            variants,
            mixedStatus: statuses.size > 1,
        };
    });
});
const assignmentRows = computed(() => groupedAssignmentRows.value);
const visibleOfferingRows = (row: AssignmentRow) =>
    row.variants.length === 1 ||
    splitAssignmentGroups.value.includes(row.groupKey)
        ? row.variants
        : [];

const draftReportIdsForRow = (row: AssignmentRow) => [
    ...new Set(
        (row.variants.length ? row.variants : [row])
            .map((variant) => variant.report)
            .filter((report) => report?.status === 'draft')
            .map((report) => report!.id),
    ),
];

const visibleDraftReportIds = computed(() => [
    ...new Set(assignmentRows.value.flatMap(draftReportIdsForRow)),
]);

const rowIsSelected = (row: AssignmentRow) => {
    const ids = draftReportIdsForRow(row);

    return (
        ids.length > 0 &&
        ids.every((reportId) => selectedReportIds.value.includes(reportId))
    );
};

const toggleReportRow = (row: AssignmentRow) => {
    const ids = draftReportIdsForRow(row);

    selectedReportIds.value = rowIsSelected(row)
        ? selectedReportIds.value.filter((reportId) => !ids.includes(reportId))
        : [...new Set([...selectedReportIds.value, ...ids])];
};

const allVisibleDraftsSelected = computed(
    () =>
        visibleDraftReportIds.value.length > 0 &&
        visibleDraftReportIds.value.every((reportId) =>
            selectedReportIds.value.includes(reportId),
        ),
);

const toggleAllVisibleDrafts = () => {
    selectedReportIds.value = allVisibleDraftsSelected.value
        ? selectedReportIds.value.filter(
              (reportId) => !visibleDraftReportIds.value.includes(reportId),
          )
        : [
              ...new Set([
                  ...selectedReportIds.value,
                  ...visibleDraftReportIds.value,
              ]),
          ];
};

const pushSelectedToSupervisor = () => {
    if (selectedReportIds.value.length === 0) return;

    pendingPushReportIds.value = [...selectedReportIds.value];
    pushForm.supervisor_id = '';
    pushForm.clearErrors();
    supervisorPickerOpen.value = true;
};

const pushToSupervisor = (reportId: number) => {
    pendingPushReportIds.value = [reportId];
    pushForm.supervisor_id = '';
    pushForm.clearErrors();
    supervisorPickerOpen.value = true;
};

const confirmDeleteDraft = () => {
    if (!draftToDelete.value) return;

    deleteDraftForm.delete(`/quality/reports/${draftToDelete.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            selectedReportIds.value = selectedReportIds.value.filter(
                (reportId) => reportId !== draftToDelete.value,
            );
            draftToDelete.value = null;
        },
    });
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

const reportStatusLabel = (status?: string) =>
    ({
        draft: 'Draft',
        submitted: 'With Supervisor',
        under_review: 'With Director',
        approved: 'Published',
    })[status ?? ''] ?? 'Not uploaded';

const closeUpload = () => {
    uploadOpen.value = false;
    uploadCourseCode.value = '';
    uploadProgramOptions.value = [];
    form.reset(
        'title',
        'program_id',
        'program_ids',
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
        onSuccess: closeUpload,
    });
};

const applyFilters = () => {
    form.course_ids = [];
};
</script>

<template>
    <Head title="Upload Report" />

    <div class="w-full min-w-0 space-y-6">
        <div
            class="rounded-2xl bg-gradient-to-r from-[#005f28] to-[#00853a] px-6 py-6 shadow-lg shadow-[#005f28]/15 [&_h2]:text-white [&_p]:text-white/75"
        >
            <Heading
                variant="small"
                title="Assigned courses"
                description="Courses with lecturer assignments for the selected academic year and semester"
            />
        </div>

        <div class="space-y-5">
            <div
                class="flex flex-wrap items-end gap-4 rounded-2xl border border-[#7acb9c] bg-gradient-to-br from-[#effaf3] to-[#e3f6eb] p-5 shadow-sm ring-1 ring-[#007a2f]/10 lg:sticky lg:top-24 lg:z-10 [&_input]:border-[#9fd8b8] [&_input]:bg-white [&_select]:border-[#9fd8b8] [&_select]:bg-white [&_select]:text-[#063f22]"
            >
                <div class="grid min-w-[180px] flex-1 gap-1">
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
                <div class="grid min-w-[180px] flex-1 gap-1">
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
                <div class="grid min-w-[160px] flex-1 gap-1">
                    <label for="course-code" class="text-sm font-medium">
                        Course code
                    </label>
                    <Input
                        id="course-code"
                        v-model="courseCodeSearch"
                        class="h-10 bg-white"
                        placeholder="Search course"
                    />
                </div>
                <div class="grid min-w-[180px] flex-1 gap-1">
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
                <div class="grid min-w-[180px] flex-1 gap-1">
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
                            v-for="department in departments"
                            :key="department"
                            :value="department"
                        >
                            {{ department }}
                        </option>
                    </select>
                </div>
                <div class="grid min-w-[180px] flex-1 gap-1">
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
                <div class="grid min-w-[150px] flex-1 gap-1">
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
                <div class="grid min-w-[150px] flex-1 gap-1">
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
                <div class="grid min-w-[180px] flex-1 gap-1">
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
                class="flex flex-col gap-3 rounded-2xl border border-[#8fd2ab] bg-[#effaf3] p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between"
            >
                <label
                    class="flex cursor-pointer items-center gap-3 text-sm font-semibold text-[#064426]"
                >
                    <input
                        type="checkbox"
                        class="h-5 w-5 rounded border-[#69bd8d] text-[#007a2f] accent-[#007a2f]"
                        :checked="allVisibleDraftsSelected"
                        :disabled="visibleDraftReportIds.length === 0"
                        @change="toggleAllVisibleDrafts"
                    />
                    Select all uploaded drafts
                    <span class="font-normal text-[#397253]">
                        ({{ selectedReportIds.length }} selected)
                    </span>
                </label>
                <Button
                    type="button"
                    class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                    :disabled="selectedReportIds.length === 0"
                    @click="pushSelectedToSupervisor"
                >
                    <Send class="h-4 w-4" />
                    Push selected to Supervisor
                </Button>
            </div>

            <div class="space-y-4">
                <article
                    v-for="(row, rowIndex) in assignmentRows"
                    :key="`${row.groupKey}-${row.course.offeringId}`"
                    class="overflow-hidden rounded-xl border border-[#b8ced8] bg-white shadow-sm"
                >
                    <div
                        class="grid gap-4 border-b border-[#b8ced8] bg-[#f8fbfc] px-4 py-4 md:grid-cols-[auto_1.45fr_0.7fr_0.8fr_1fr_auto] md:items-center"
                    >
                        <div
                            class="flex h-8 w-8 items-center justify-center rounded-md bg-[#d9edf5] text-sm font-bold text-[#234d5d]"
                        >
                            {{ rowIndex + 1 }}
                        </div>
                        <div class="flex min-w-0 items-center gap-3">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-[#087036] text-white"
                            >
                                <FileText class="h-5 w-5" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-bold text-[#075b2e]">
                                    {{ row.course.code }}
                                </div>
                                <h3 class="truncate text-sm text-[#243d47]">
                                    {{ row.course.title }}
                                </h3>
                            </div>
                        </div>
                        <div class="text-sm">
                            <div
                                class="text-[10px] font-bold tracking-wide text-[#66808a] uppercase"
                            >
                                Level
                            </div>
                            {{
                                row.levels.length
                                    ? row.levels.join(', ')
                                    : 'Not set'
                            }}
                        </div>
                        <div class="text-sm">
                            <div
                                class="text-[10px] font-bold tracking-wide text-[#66808a] uppercase"
                            >
                                Offerings
                            </div>
                            {{ row.programs.length }}
                        </div>
                        <div class="min-w-0">
                            <div
                                class="text-[10px] font-bold tracking-wide text-[#66808a] uppercase"
                            >
                                Lecturer
                            </div>
                            <div class="truncate text-sm font-medium">
                                {{
                                    row.lecturers.length === 1
                                        ? row.lecturers[0].name
                                        : `${row.lecturers.length} lecturers`
                                }}
                            </div>
                        </div>
                        <div
                            class="flex flex-wrap items-center gap-2 md:justify-end"
                        >
                            <Badge
                                variant="secondary"
                                class="bg-[#d9edf5] text-[#234d5d]"
                            >
                                {{
                                    row.mixedStatus
                                        ? 'Mixed status'
                                        : reportStatusLabel(row.report?.status)
                                }}
                            </Badge>
                        </div>
                    </div>

                    <div class="px-4 py-4">
                        <label
                            v-if="draftReportIdsForRow(row).length"
                            class="mb-3 flex w-fit cursor-pointer items-center gap-2 text-xs font-semibold text-[#087036]"
                        >
                            <input
                                type="checkbox"
                                class="h-5 w-5 rounded border-[#69bd8d] accent-[#007a2f]"
                                :checked="rowIsSelected(row)"
                                @change="toggleReportRow(row)"
                            />
                            Select draft
                        </label>

                        <div
                            class="overflow-hidden rounded-md border border-[#9fb7c1]"
                        >
                            <div
                                class="hidden grid-cols-[minmax(160px,1.6fr)_minmax(90px,0.65fr)_minmax(64px,0.5fr)_minmax(170px,1.2fr)_minmax(120px,0.8fr)_minmax(190px,1.25fr)_minmax(150px,auto)] gap-0 bg-[#9fb7c1] text-xs font-semibold text-[#294c59] md:grid"
                            >
                                <div class="bg-[#d9edf5] px-3 py-2">
                                    Program
                                </div>
                                <div class="bg-[#d9edf5] px-3 py-2">Code</div>
                                <div class="bg-[#d9edf5] px-3 py-2">Level</div>
                                <div class="bg-[#d9edf5] px-3 py-2">
                                    Lecturer
                                </div>
                                <div class="bg-[#d9edf5] px-3 py-2">Status</div>
                                <div class="bg-[#d9edf5] px-3 py-2">
                                    Activity
                                </div>
                                <div class="bg-[#d9edf5] px-3 py-2">Action</div>
                            </div>
                            <div class="divide-y divide-[#cbdbe1] bg-white">
                                <div
                                    v-if="
                                        row.variants.length > 1 &&
                                        !splitAssignmentGroups.includes(
                                            row.groupKey,
                                        )
                                    "
                                    class="flex items-center justify-between gap-4 bg-[#fbfdfe] px-4 py-5"
                                >
                                    <div>
                                        <div class="font-medium text-[#243d47]">
                                            {{ row.variants.length }} course
                                            offerings grouped
                                        </div>
                                        <div
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            Split this course to view program
                                            codes, levels, and individual
                                            actions.
                                        </div>
                                    </div>
                                    <Split
                                        class="h-5 w-5 shrink-0 text-[#087036]"
                                    />
                                </div>
                                <div
                                    v-for="variant in visibleOfferingRows(row)"
                                    :key="variant.course.offeringId"
                                    class="grid gap-3 py-3 text-sm md:grid-cols-[minmax(160px,1.6fr)_minmax(90px,0.65fr)_minmax(64px,0.5fr)_minmax(170px,1.2fr)_minmax(120px,0.8fr)_minmax(190px,1.25fr)_minmax(150px,auto)] md:items-center md:gap-0"
                                >
                                    <div
                                        class="px-3 font-medium text-[#243d47]"
                                    >
                                        {{
                                            variant.course.program?.name ??
                                            'Program not set'
                                        }}
                                    </div>
                                    <div class="px-3 text-muted-foreground">
                                        {{
                                            variant.course.program?.code ?? '—'
                                        }}
                                    </div>
                                    <div class="px-3">
                                        {{ variant.course.level ?? '—' }}
                                    </div>
                                    <div class="min-w-0 px-3 leading-tight">
                                        <div class="font-medium break-words">
                                            {{ variant.lecturer.name }}
                                        </div>
                                        <div
                                            class="mt-1 text-xs break-all text-muted-foreground"
                                        >
                                            {{ variant.lecturer.email }}
                                        </div>
                                    </div>
                                    <Badge
                                        variant="secondary"
                                        class="mx-3 w-fit bg-[#e2f2e8] text-[#075b2e]"
                                    >
                                        {{
                                            reportStatusLabel(
                                                variant.report?.status,
                                            )
                                        }}
                                    </Badge>
                                    <div
                                        v-if="variant.report"
                                        class="px-3 text-xs text-muted-foreground"
                                    >
                                        <div>
                                            Uploaded
                                            {{
                                                formatDateTime(
                                                    variant.report.uploadedAt,
                                                )
                                            }}
                                        </div>
                                        <div v-if="variant.report.pushedAt">
                                            Pushed
                                            {{
                                                formatDateTime(
                                                    variant.report.pushedAt,
                                                )
                                            }}
                                        </div>
                                    </div>
                                    <span
                                        v-else
                                        class="px-3 text-xs text-muted-foreground"
                                    >
                                        Not started
                                    </span>
                                    <div class="flex justify-end gap-2 px-3">
                                        <template
                                            v-if="
                                                variant.report?.status ===
                                                'draft'
                                            "
                                        >
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="outline"
                                                class="border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800"
                                                title="Delete draft"
                                                @click="
                                                    draftToDelete =
                                                        variant.report.id
                                                "
                                            >
                                                <Trash2 class="h-4 w-4" />
                                                <span class="sr-only"
                                                    >Delete draft</span
                                                >
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                                                @click="
                                                    pushToSupervisor(
                                                        variant.report.id,
                                                    )
                                                "
                                            >
                                                <Send class="h-4 w-4" />
                                                Push
                                            </Button>
                                        </template>
                                        <Button
                                            v-else-if="!variant.report"
                                            type="button"
                                            size="sm"
                                            class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                                            @click="
                                                startUpload(
                                                    variant.course,
                                                    variant.lecturer.id,
                                                )
                                            "
                                        >
                                            <Upload class="h-4 w-4" />
                                            Upload
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex flex-col gap-3 border-t border-[#cbdbe1] bg-[#f8fbfc] px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <p class="text-xs text-muted-foreground">
                            {{
                                row.variants.length > 1
                                    ? `${row.variants.length} course offerings are grouped across ${row.lecturers.length} lecturer${row.lecturers.length === 1 ? '' : 's'}.`
                                    : 'One course offering is available.'
                            }}
                        </p>
                        <div class="flex flex-wrap gap-2 sm:justify-end">
                            <Button
                                v-if="
                                    mainUploadableVariants(row).length &&
                                    !splitAssignmentGroups.includes(
                                        row.groupKey,
                                    )
                                "
                                type="button"
                                size="sm"
                                class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                                @click="startGroupedUpload(row)"
                            >
                                <Upload class="h-4 w-4" />
                                {{
                                    mainUploadableVariants(row).length > 1
                                        ? 'Upload once for all programs'
                                        : 'Upload report'
                                }}
                            </Button>
                            <Button
                                v-if="row.variants.length > 1"
                                type="button"
                                size="sm"
                                variant="outline"
                                class="border-[#8bcfaa] text-[#006d2b] hover:bg-[#e7f6ed]"
                                @click="toggleAssignmentSplit(row.groupKey)"
                            >
                                <Combine
                                    v-if="
                                        splitAssignmentGroups.includes(
                                            row.groupKey,
                                        )
                                    "
                                    class="h-4 w-4"
                                />
                                <Split v-else class="h-4 w-4" />
                                {{
                                    splitAssignmentGroups.includes(row.groupKey)
                                        ? 'Combine offerings'
                                        : 'Split course offerings'
                                }}
                            </Button>
                        </div>
                    </div>
                </article>

                <div
                    v-if="assignmentRows.length === 0"
                    class="rounded-2xl border border-dashed border-[#9fd8b8] bg-white px-4 py-12 text-center text-sm text-muted-foreground"
                >
                    No assigned courses match these filters.
                </div>
            </div>
            <InputError :message="form.errors.course_ids" />
            <InputError :message="form.errors.lecturer_id" />
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
                        v-if="form.program_ids.length > 1"
                        class="rounded-lg border border-[#9fd8b8] bg-white p-3"
                    >
                        <div class="text-sm font-semibold text-[#075b2e]">
                            Applies to all program offerings
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <Badge
                                v-for="program in uploadProgramOptions"
                                :key="program.id"
                                variant="outline"
                                class="border-[#9fd8b8] text-[#075b2e]"
                            >
                                {{ program.name }} ({{ program.code }})
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
