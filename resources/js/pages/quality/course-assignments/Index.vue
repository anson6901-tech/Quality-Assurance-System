<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Search, UserMinus, Users } from 'lucide-vue-next';
import { computed, ref } from 'vue';
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
import { useSearchParam } from '@/composables/useSearchParam';
import { matchesSearch } from '@/lib/coursePartitions';
import type { CourseEntry, CoursePart } from '@/types';

type Lecturer = { id: number; name: string; email: string };
type AcademicYear = {
    id: number;
    name: string;
    status: string;
    semesters: Array<{ id: number; name: string; sequence: number }>;
};

const props = defineProps<{
    courses: CourseEntry[];
    lecturers: Lecturer[];
    academicYears: AcademicYear[];
    filters: { academicYearId: number | null; semesterId: number | null };
    canManageAssignments: boolean;
    canManageStructure: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Course Assignments',
                href: '/quality/course-assignments',
            },
        ],
    },
});

const assignmentForm = useForm({
    semester_id: '',
    cells: [] as Array<{
        program_id: number | null;
        section_id: number | null;
    }>,
    lecturer_ids: [] as number[],
    assignments: {} as Record<
        number,
        { student_count: string; allocated_hours: string }
    >,
});

const assignmentError = (field: string): string | undefined =>
    (assignmentForm.errors as Record<string, string | undefined>)[field];
const assignmentOpen = ref(false);
const assignmentStep = ref<'lecturers' | 'workload'>('lecturers');
const selectedCourse = ref<CourseEntry | null>(null);
const selectedPart = ref<CoursePart | null>(null);
const lecturerSearch = ref('');
const selectedAcademicYearId = ref(
    props.filters.academicYearId ? String(props.filters.academicYearId) : '',
);
const selectedSemesterId = ref(
    props.filters.semesterId ? String(props.filters.semesterId) : '',
);
const courseCodeSearch = ref(useSearchParam('search'));
const schoolFilter = ref('');
const departmentFilter = ref('');
const programFilter = ref('');
const levelFilter = ref('');
const courseTypeFilter = ref('');
const lecturerFilter = ref('');
const selectedAcademicYear = computed(
    () =>
        props.academicYears.find(
            (year) => year.id === Number(selectedAcademicYearId.value),
        ) ?? null,
);
const schoolName = (course: CourseEntry) =>
    course.department?.school?.name ?? '';
const schools = computed(() => [
    ...new Set(props.courses.map(schoolName).filter(Boolean)),
]);
const departments = computed(() => [
    ...new Set(
        props.courses
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
    props.courses
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
const levels = computed(() =>
    [...new Set(props.courses.flatMap((course) => course.levels))].sort(
        (left, right) => Number(left) - Number(right),
    ),
);
const courseLecturers = (course: CourseEntry) =>
    course.parts.flatMap((part) => part.lecturers);
const displayCourses = computed(() =>
    props.courses.filter(
        (course) =>
            matchesSearch(courseCodeSearch.value, [
                course.code,
                course.title,
                course.department?.name,
                ...course.programs.flatMap((program) => [
                    program.code,
                    program.name,
                ]),
                ...courseLecturers(course).map((lecturer) => lecturer.name),
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
                courseLecturers(course).some(
                    (lecturer) => String(lecturer.id) === lecturerFilter.value,
                )),
    ),
);
const isSplit = (course: CourseEntry) => course.splitMode !== 'merged';
const partStripe = (part: CoursePart) => {
    if (part.unassignedCells === part.cells.length) {
        return 'border-l-[#d98a0b]';
    }

    return part.unassignedCells > 0 || part.mixedLecturers
        ? 'border-l-[#7c5cc4]'
        : 'border-l-[#0f8a3e]';
};
const courseStripe = (course: CourseEntry) => {
    const unassigned = course.parts.filter(
        (part) => part.unassignedCells === part.cells.length,
    ).length;

    if (
        unassigned === 0 &&
        course.parts.every((part) => !part.mixedLecturers)
    ) {
        return 'border-l-[#0f8a3e]';
    }

    return unassigned === course.parts.length
        ? 'border-l-[#d98a0b]'
        : 'border-l-[#7c5cc4]';
};
const assignedPartCount = (course: CourseEntry) =>
    course.parts.filter((part) => part.unassignedCells === 0).length;
const filteredLecturers = computed(() => {
    const search = lecturerSearch.value.trim().toLowerCase();

    return search
        ? props.lecturers.filter((lecturer) =>
              `${lecturer.name} ${lecturer.email}`
                  .toLowerCase()
                  .includes(search),
          )
        : props.lecturers;
});
const openAssignments = (course: CourseEntry, part: CoursePart) => {
    selectedCourse.value = course;
    selectedPart.value = part;
    assignmentForm.semester_id = String(course.semesterId);
    assignmentForm.cells = part.cells.map((cell) => ({
        program_id: cell.programId,
        section_id: cell.sectionId,
    }));
    assignmentForm.lecturer_ids = part.mixedLecturers
        ? []
        : part.lecturers.map((lecturer) => lecturer.id);
    assignmentForm.assignments = Object.fromEntries(
        props.lecturers.map((lecturer) => {
            const assigned = part.lecturers.find(
                (item) => item.id === lecturer.id,
            );

            return [
                lecturer.id,
                {
                    student_count: assigned?.studentCount
                        ? String(assigned.studentCount)
                        : '',
                    allocated_hours: assigned?.allocatedHours
                        ? String(assigned.allocatedHours)
                        : '',
                },
            ];
        }),
    );
    lecturerSearch.value = '';
    assignmentForm.clearErrors();
    assignmentStep.value = 'lecturers';
    assignmentOpen.value = true;
};
const toggleLecturer = (lecturerId: number, checked: boolean) => {
    assignmentForm.lecturer_ids = checked
        ? selectedCourse.value?.courseType === 'normal'
            ? [lecturerId]
            : [...assignmentForm.lecturer_ids, lecturerId]
        : assignmentForm.lecturer_ids.filter((id) => id !== lecturerId);
};
const applyFilters = () =>
    router.get(
        '/quality/course-assignments',
        {
            academic_year_id: selectedAcademicYearId.value,
            semester_id: selectedSemesterId.value,
        },
        { preserveState: false },
    );
const periodQuery = () => ({
    academic_year_id: selectedAcademicYearId.value,
    semester_id: selectedSemesterId.value,
});
const reloadPeriod = () =>
    router.get('/quality/course-assignments', periodQuery(), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
const unassigning = ref(false);
const unassignPart = () => {
    const course = selectedCourse.value;
    const part = selectedPart.value;

    if (!course || !part || part.lecturers.length === 0) {
        return;
    }

    const names = part.lecturers.map((lecturer) => lecturer.name).join(', ');
    const target = isSplit(course)
        ? `${course.code} · ${part.label}`
        : course.code;

    if (
        !window.confirm(
            `Unassign ${names} from ${target}? Reports already uploaded for them are kept.`,
        )
    ) {
        return;
    }

    router.delete(`/quality/course-assignments/${course.id}/lecturers`, {
        data: {
            semester_id: course.semesterId,
            cells: part.cells.map((cell) => ({
                program_id: cell.programId,
                section_id: cell.sectionId,
            })),
        },
        preserveScroll: true,
        preserveState: true,
        onStart: () => (unassigning.value = true),
        onFinish: () => (unassigning.value = false),
        onSuccess: () => {
            assignmentOpen.value = false;
            selectedCourse.value = null;
            selectedPart.value = null;
            window.setTimeout(reloadPeriod, 250);
        },
    });
};
const saveAssignments = () =>
    selectedCourse.value &&
    assignmentForm.put(
        `/quality/course-assignments/${selectedCourse.value.id}/lecturers`,
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                assignmentOpen.value = false;
                assignmentStep.value = 'lecturers';
                selectedCourse.value = null;
                selectedPart.value = null;
                window.setTimeout(reloadPeriod, 250);
            },
        },
    );
</script>

<template>
    <Head title="Course Assignments" />
    <div class="space-y-6">
        <PageBanner
            title="Course Assignments"
            description="Select an academic year and semester, then assign lecturers. Use Split to separate a course by program, level or section."
        />
        <div
            class="flex flex-wrap items-end gap-3 rounded-xl border border-[#9fd8b8] bg-[#eefaf3] p-4 shadow-sm lg:sticky lg:top-20 lg:z-20"
        >
            <div class="grid gap-1">
                <label class="text-sm font-medium">Academic year</label
                ><select
                    v-model="selectedAcademicYearId"
                    :disabled="academicYears.length === 0"
                    class="h-10 rounded-md border bg-white px-3 text-sm"
                    @change="
                        selectedSemesterId = String(
                            selectedAcademicYear?.semesters[0]?.id ?? '',
                        )
                    "
                >
                    <option v-if="academicYears.length === 0" value="">
                        No academic years
                    </option>
                    <option
                        v-for="year in academicYears"
                        :key="year.id"
                        :value="year.id"
                    >
                        {{ year.name }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <label class="text-sm font-medium">Semester</label
                ><select
                    v-model="selectedSemesterId"
                    :disabled="!selectedAcademicYear"
                    class="h-10 rounded-md border bg-white px-3 text-sm"
                >
                    <option
                        v-if="
                            !selectedAcademicYear ||
                            selectedAcademicYear.semesters.length === 0
                        "
                        value=""
                    >
                        No semesters
                    </option>
                    <option
                        v-for="semester in selectedAcademicYear?.semesters ??
                        []"
                        :key="semester.id"
                        :value="semester.id"
                    >
                        {{ semester.name }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <label class="text-sm font-medium">School</label
                ><select
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
            <div class="grid gap-1">
                <label class="text-sm font-medium">Department</label
                ><select
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
            <div class="grid gap-1">
                <label class="text-sm font-medium">Program</label
                ><select
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
            <div class="grid gap-1">
                <label class="text-sm font-medium">Level</label>
                <select
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
            <div class="grid gap-1">
                <label class="text-sm font-medium">Course type</label
                ><select
                    v-model="courseTypeFilter"
                    class="h-10 rounded-md border bg-white px-3 text-sm"
                >
                    <option value="">All types</option>
                    <option value="normal">Normal</option>
                    <option value="module">Module</option>
                </select>
            </div>
            <div class="grid gap-1">
                <label class="text-sm font-medium">Lecturer</label
                ><select
                    v-model="lecturerFilter"
                    class="h-10 rounded-md border bg-white px-3 text-sm"
                >
                    <option value="">All lecturers</option>
                    <option
                        v-for="lecturer in lecturers"
                        :key="lecturer.id"
                        :value="lecturer.id"
                    >
                        {{ lecturer.name }}
                    </option>
                </select>
            </div>
            <Button
                type="button"
                class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                :disabled="!selectedAcademicYearId || !selectedSemesterId"
                @click="applyFilters"
                >Apply filters</Button
            >
        </div>
        <CourseSearch
            v-if="courses.length"
            v-model="courseCodeSearch"
            :shown="displayCourses.length"
            :total="courses.length"
        />

        <CourseList
            v-if="displayCourses.length"
            :headings="[
                'Course',
                'Programs and sections',
                'Lecturers',
                'Workload',
                'Action',
            ]"
            columns="minmax(0,1.6fr) minmax(0,1.5fr) minmax(0,1.6fr) minmax(130px,1fr) minmax(170px,auto)"
        >
            <CourseRow
                v-for="course in displayCourses"
                :key="course.key"
                :stripe-class="courseStripe(course)"
            >
                <CourseTitleCell
                    :code="course.code"
                    :title="course.title"
                    :course-type="course.courseType"
                    :subtitle="course.department?.name"
                />
                <CourseScopeCell
                    :programs="course.programs"
                    :levels="course.levels"
                    :sections="course.sections"
                    :mode="course.splitMode"
                    :part-count="course.parts.length"
                />
                <template v-if="!isSplit(course)">
                    <LecturerCell
                        v-if="course.parts[0].lecturers.length"
                        :names="
                            course.parts[0].lecturers.map(
                                (lecturer) => lecturer.name,
                            )
                        "
                        :detail="
                            course.parts[0].mixedLecturers
                                ? 'Different lecturers per section'
                                : course.parts[0].lecturers.length === 1
                                  ? course.parts[0].lecturers[0].email
                                  : null
                        "
                    />
                    <span
                        v-else
                        class="inline-flex w-fit items-center rounded-full border border-[#f5d48a] bg-[#fdf3d7] px-2.5 py-0.5 text-xs font-medium text-[#8a4b06]"
                    >
                        Not assigned
                    </span>
                    <div class="text-xs text-[#52646c]">
                        <div
                            v-for="lecturer in course.parts[0].lecturers"
                            :key="lecturer.id"
                            class="truncate"
                        >
                            {{ lecturer.studentCount ?? '—' }} students ·
                            {{ lecturer.allocatedHours ?? '—' }} hrs
                        </div>
                        <span
                            v-if="!course.parts[0].lecturers.length"
                            class="text-[#8a9aa1]"
                            >Not set</span
                        >
                    </div>
                </template>
                <template v-else>
                    <div class="text-sm text-[#3c5059]">
                        {{ assignedPartCount(course) }} of
                        {{ course.parts.length }} parts assigned
                    </div>
                    <div class="hidden md:block" />
                </template>
                <div
                    class="flex items-center justify-start gap-1.5 md:justify-end"
                >
                    <Button
                        v-if="!isSplit(course) && canManageAssignments"
                        type="button"
                        size="sm"
                        :variant="
                            course.parts[0].lecturers.length
                                ? 'outline'
                                : 'default'
                        "
                        :class="
                            course.parts[0].lecturers.length
                                ? 'border-[#8bcfaa] text-[#006d2b] hover:bg-[#e7f6ed]'
                                : 'bg-[#007a2f] text-white hover:bg-[#006d2b]'
                        "
                        @click="openAssignments(course, course.parts[0])"
                    >
                        <Users class="h-4 w-4" />
                        {{
                            course.parts[0].lecturers.length
                                ? 'Change'
                                : 'Assign'
                        }}
                    </Button>
                    <SplitMenu
                        :course-id="course.id"
                        :course-code="course.code"
                        :semester-id="course.semesterId"
                        :mode="course.splitMode"
                        :cells="course.parts.flatMap((part) => part.cells)"
                        :can-split="canManageAssignments"
                        :can-manage-sections="canManageStructure"
                    />
                </div>

                <template v-if="isSplit(course)" #children>
                    <CourseRow
                        v-for="part in course.parts"
                        :key="part.key"
                        :stripe-class="partStripe(part)"
                        nested
                    >
                        <PartLabelCell
                            :label="part.label"
                            :cells="part.cells"
                        />
                        <div class="hidden md:block" />
                        <LecturerCell
                            v-if="part.lecturers.length"
                            :names="
                                part.lecturers.map((lecturer) => lecturer.name)
                            "
                            :detail="
                                part.mixedLecturers
                                    ? 'Different lecturers inside this part'
                                    : part.lecturers.length === 1
                                      ? part.lecturers[0].email
                                      : null
                            "
                        />
                        <span
                            v-else
                            class="inline-flex w-fit items-center rounded-full border border-[#f5d48a] bg-[#fdf3d7] px-2.5 py-0.5 text-xs font-medium text-[#8a4b06]"
                        >
                            Not assigned
                        </span>
                        <div class="text-xs text-[#52646c]">
                            <div
                                v-for="lecturer in part.lecturers"
                                :key="lecturer.id"
                            >
                                {{ lecturer.studentCount ?? '—' }} students ·
                                {{ lecturer.allocatedHours ?? '—' }} hrs
                            </div>
                            <span
                                v-if="!part.lecturers.length"
                                class="text-[#8a9aa1]"
                                >Not set</span
                            >
                        </div>
                        <div
                            class="flex items-center justify-start md:justify-end"
                        >
                            <Button
                                v-if="canManageAssignments"
                                type="button"
                                variant="outline"
                                size="sm"
                                class="border-[#8bcfaa] text-[#006d2b] hover:bg-[#e7f6ed]"
                                @click="openAssignments(course, part)"
                            >
                                <Users class="h-4 w-4" />
                                {{
                                    part.lecturers.length ? 'Change' : 'Assign'
                                }}
                            </Button>
                        </div>
                    </CourseRow>
                </template>
            </CourseRow>
        </CourseList>

        <p
            v-else
            class="rounded-xl border border-dashed border-[#9fd8b8] bg-white px-4 py-10 text-center text-sm text-muted-foreground"
        >
            {{
                academicYears.length === 0
                    ? 'Create an academic year before assigning lecturers to courses.'
                    : !selectedSemesterId
                      ? 'Add a semester to the selected academic year before assigning courses.'
                      : courses.length === 0
                        ? 'No courses are mounted for this semester.'
                        : 'No courses match these filters.'
            }}
        </p>
    </div>
    <Dialog :open="assignmentOpen" @update:open="assignmentOpen = $event"
        ><DialogContent class="sm:max-w-2xl"
            ><form class="space-y-5" @submit.prevent="saveAssignments">
                <DialogHeader
                    ><DialogTitle>{{
                        assignmentStep === 'lecturers'
                            ? 'Select lecturers'
                            : 'Set lecturer workload'
                    }}</DialogTitle
                    ><DialogDescription
                        ><span
                            v-if="selectedCourse && selectedPart"
                            class="mb-1 block font-medium text-[#075b2e]"
                            >{{ selectedCourse.code }} ·
                            {{ selectedPart.label }}</span
                        >{{
                            assignmentStep === 'lecturers'
                                ? 'Click lecturer names to select who will teach this part of the course, then continue.'
                                : 'Enter the class size and allocated teaching hours for each selected lecturer.'
                        }}</DialogDescription
                    ></DialogHeader
                ><template v-if="assignmentStep === 'lecturers'"
                    ><div class="relative">
                        <Search
                            class="absolute top-2.5 left-3 h-4 w-4 text-muted-foreground"
                        /><Input
                            v-model="lecturerSearch"
                            class="pl-9"
                            placeholder="Search lecturers"
                        />
                    </div>
                    <div
                        class="max-h-80 space-y-2 overflow-y-auto rounded-lg border p-2"
                    >
                        <button
                            v-for="lecturer in filteredLecturers"
                            :key="lecturer.id"
                            type="button"
                            class="flex w-full items-center gap-3 rounded-md border p-3 text-left hover:bg-[#eefaf3]"
                            :class="
                                assignmentForm.lecturer_ids.includes(
                                    lecturer.id,
                                )
                                    ? 'border-[#007a2f] bg-[#eefaf3]'
                                    : ''
                            "
                            @click="
                                toggleLecturer(
                                    lecturer.id,
                                    !assignmentForm.lecturer_ids.includes(
                                        lecturer.id,
                                    ),
                                )
                            "
                        >
                            <input
                                :checked="
                                    assignmentForm.lecturer_ids.includes(
                                        lecturer.id,
                                    )
                                "
                                type="checkbox"
                                tabindex="-1"
                                class="pointer-events-none h-4 w-4 accent-[#007a2f]"
                            /><span
                                ><span class="block font-medium">{{
                                    lecturer.name
                                }}</span
                                ><span class="text-xs text-muted-foreground">{{
                                    lecturer.email
                                }}</span></span
                            >
                        </button>
                        <p
                            v-if="filteredLecturers.length === 0"
                            class="p-4 text-center text-sm text-muted-foreground"
                        >
                            No lecturers found.
                        </p>
                    </div>
                    <InputError
                        :message="
                            assignmentForm.errors.lecturer_ids
                        " /></template
                ><template v-else
                    ><div class="max-h-80 space-y-3 overflow-y-auto">
                        <div
                            v-for="lecturerId in assignmentForm.lecturer_ids"
                            :key="lecturerId"
                            class="rounded-lg border p-4"
                        >
                            <div class="mb-3 font-medium">
                                {{
                                    lecturers.find(
                                        (lecturer) =>
                                            lecturer.id === lecturerId,
                                    )?.name
                                }}
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="grid gap-1">
                                    <label class="text-xs font-medium"
                                        >Students in class</label
                                    ><Input
                                        v-model="
                                            assignmentForm.assignments[
                                                lecturerId
                                            ].student_count
                                        "
                                        type="number"
                                        min="1"
                                        required
                                    /><InputError
                                        :message="
                                            assignmentError(
                                                `assignments.${lecturerId}.student_count`,
                                            )
                                        "
                                    />
                                </div>
                                <div class="grid gap-1">
                                    <label class="text-xs font-medium"
                                        >Allocated teaching hours</label
                                    ><Input
                                        v-model="
                                            assignmentForm.assignments[
                                                lecturerId
                                            ].allocated_hours
                                        "
                                        type="number"
                                        min="1"
                                        max="168"
                                        required
                                    /><InputError
                                        :message="
                                            assignmentError(
                                                `assignments.${lecturerId}.allocated_hours`,
                                            )
                                        "
                                    />
                                </div>
                            </div>
                        </div></div></template
                ><DialogFooter class="gap-2 sm:justify-between"
                    ><Button
                        v-if="
                            assignmentStep === 'lecturers' &&
                            selectedPart &&
                            selectedPart.lecturers.length
                        "
                        type="button"
                        variant="outline"
                        class="border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800 sm:mr-auto dark:border-red-900 dark:text-red-300"
                        :disabled="unassigning"
                        @click="unassignPart"
                        ><UserMinus class="h-4 w-4" /> Unassign</Button
                    ><span v-else class="hidden sm:block" /><span
                        class="flex flex-col-reverse gap-2 sm:flex-row"
                        ><Button
                            type="button"
                            variant="secondary"
                            @click="
                                assignmentStep === 'lecturers'
                                    ? (assignmentOpen = false)
                                    : (assignmentStep = 'lecturers')
                            "
                            >{{
                                assignmentStep === 'lecturers'
                                    ? 'Cancel'
                                    : 'Back'
                            }}</Button
                        ><Button
                            v-if="assignmentStep === 'lecturers'"
                            type="button"
                            class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                            :disabled="assignmentForm.lecturer_ids.length === 0"
                            @click="assignmentStep = 'workload'"
                            >Next</Button
                        ><Button
                            v-else
                            type="submit"
                            class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                            :disabled="assignmentForm.processing"
                            >Save assignments</Button
                        ></span
                    ></DialogFooter
                >
            </form></DialogContent
        ></Dialog
    >
</template>
