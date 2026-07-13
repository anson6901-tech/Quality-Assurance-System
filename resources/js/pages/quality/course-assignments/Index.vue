<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    FileUp,
    RotateCcw,
    Search,
    Trash2,
    Users,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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

type Lecturer = {
    id: number;
    name: string;
    email: string;
    studentCount: number | null;
    allocatedHours: number | null;
};
type AcademicYear = {
    id: number;
    name: string;
    status: string;
    semesters: Array<{ id: number; name: string; sequence: number }>;
};
type ApprovalReport = {
    id: number;
    title: string;
    status: 'under_review' | 'approved';
    lecturer: string | null;
    academicYear: string | null;
};
type Course = {
    id: number;
    code: string;
    title: string;
    courseType: 'normal' | 'module';
    level: string | null;
    department: { name: string; school: { name: string } };
    lecturers: Lecturer[];
    reports: ApprovalReport[];
};

const props = defineProps<{
    courses: Course[];
    lecturers: Lecturer[];
    academicYears: AcademicYear[];
    filters: { academicYearId: number; semesterId: number };
    canUploadReports: boolean;
    canViewApprovals: boolean;
    canApproveReports: boolean;
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
    lecturer_ids: [] as number[],
    assignments: {} as Record<
        number,
        { student_count: string; allocated_hours: string }
    >,
});
const reportForm = useForm({
    title: '',
    category: 'Academic Quality',
    academic_year_id: props.academicYears[0]
        ? String(props.academicYears[0].id)
        : '',
    semester_id: '',
    summary: '',
    lecturer_id: '',
    course_ids: [] as number[],
    report_file: null as File | null,
});
const assignmentOpen = ref(false);
const assignmentStep = ref<'lecturers' | 'workload'>('lecturers');
const reportOpen = ref(false);
const approvalsOpen = ref(false);
const selectedCourse = ref<Course | null>(null);
const reportCourse = ref<Course | null>(null);
const approvalCourse = ref<Course | null>(null);
const lecturerSearch = ref('');
const selectedAcademicYearId = ref(String(props.filters.academicYearId));
const selectedSemesterId = ref(String(props.filters.semesterId));
const courseCodeSearch = ref('');
const schoolFilter = ref('');
const departmentFilter = ref('');
const courseTypeFilter = ref('');
const lecturerFilter = ref('');
const selectedAcademicYear = computed(
    () =>
        props.academicYears.find(
            (year) => year.id === Number(selectedAcademicYearId.value),
        ) ?? null,
);
const schools = computed(() => [
    ...new Set(props.courses.map((course) => course.department.school.name)),
]);
const departments = computed(() =>
    props.courses
        .filter(
            (course) =>
                !schoolFilter.value ||
                course.department.school.name === schoolFilter.value,
        )
        .map((course) => course.department.name)
        .filter((value, index, values) => values.indexOf(value) === index),
);
const filteredCourses = computed(() =>
    props.courses.filter(
        (course) =>
            (!courseCodeSearch.value ||
                `${course.code} ${course.title}`
                    .toLowerCase()
                    .includes(courseCodeSearch.value.toLowerCase())) &&
            (!schoolFilter.value ||
                course.department.school.name === schoolFilter.value) &&
            (!departmentFilter.value ||
                course.department.name === departmentFilter.value) &&
            (!courseTypeFilter.value ||
                course.courseType === courseTypeFilter.value) &&
            (!lecturerFilter.value ||
                course.lecturers.some(
                    (lecturer) => String(lecturer.id) === lecturerFilter.value,
                )),
    ),
);
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
const openAssignments = (course: Course) => {
    selectedCourse.value = course;
    assignmentForm.semester_id = selectedSemesterId.value;
    assignmentForm.lecturer_ids = course.lecturers.map(
        (lecturer) => lecturer.id,
    );
    assignmentForm.assignments = Object.fromEntries(
        props.lecturers.map((lecturer) => {
            const assigned = course.lecturers.find(
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
const saveAssignments = () =>
    selectedCourse.value &&
    assignmentForm.put(
        `/quality/course-assignments/${selectedCourse.value.id}/lecturers`,
        {
            preserveScroll: true,
            onSuccess: () => {
                assignmentOpen.value = false;
                assignmentStep.value = 'lecturers';
                selectedCourse.value = null;
            },
        },
    );
const openReport = (course: Course) => {
    reportCourse.value = course;
    reportForm.reset();
    reportForm.academic_year_id = selectedAcademicYearId.value;
    reportForm.semester_id = selectedSemesterId.value;
    reportForm.course_ids = [course.id];
    reportForm.clearErrors();
    reportOpen.value = true;
};
const uploadReport = () =>
    reportForm.post('/quality/reports', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            reportOpen.value = false;
            reportCourse.value = null;
        },
    });
const openApprovals = (course: Course) => {
    approvalCourse.value = course;
    approvalsOpen.value = true;
};
const approvalAction = (
    report: ApprovalReport,
    action: 'approve' | 'return' | 'unapprove',
) =>
    router.post(
        `/quality/reports/${report.id}/${action}`,
        { from_course_assignments: true },
        {
            preserveScroll: true,
            onSuccess: () => {
                approvalsOpen.value = false;
                approvalCourse.value = null;
            },
        },
    );
const deleteReport = (report: ApprovalReport) => {
    if (window.confirm(`Delete ${report.title}?`)) {
        router.delete(`/quality/reports/${report.id}`, {
            data: { from_course_assignments: true },
            preserveScroll: true,
            onSuccess: () => {
                approvalsOpen.value = false;
                approvalCourse.value = null;
            },
        });
    }
};
</script>

<template>
    <Head title="Course Assignments" />
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Course Assignments"
            description="Select an academic year and semester. Each semester starts with fresh course allocations."
        />
        <div
            class="flex flex-wrap items-end gap-3 rounded-xl border border-[#9fd8b8] bg-[#eefaf3] p-4"
        >
            <div class="grid gap-1">
                <label class="text-sm font-medium">Academic year</label
                ><select
                    v-model="selectedAcademicYearId"
                    class="h-10 rounded-md border bg-white px-3 text-sm"
                    @change="
                        selectedSemesterId = String(
                            selectedAcademicYear?.semesters[0]?.id ?? '',
                        )
                    "
                >
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
                    class="h-10 rounded-md border bg-white px-3 text-sm"
                >
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
            <div class="grid gap-1"><label class="text-sm font-medium">Course code</label><Input v-model="courseCodeSearch" class="h-10 w-40 bg-white" placeholder="Search course" /></div>
            <div class="grid gap-1"><label class="text-sm font-medium">School</label><select v-model="schoolFilter" class="h-10 rounded-md border bg-white px-3 text-sm" @change="departmentFilter = ''"><option value="">All schools</option><option v-for="school in schools" :key="school" :value="school">{{ school }}</option></select></div>
            <div class="grid gap-1"><label class="text-sm font-medium">Department</label><select v-model="departmentFilter" class="h-10 rounded-md border bg-white px-3 text-sm"><option value="">All departments</option><option v-for="department in departments" :key="department" :value="department">{{ department }}</option></select></div>
            <div class="grid gap-1"><label class="text-sm font-medium">Course type</label><select v-model="courseTypeFilter" class="h-10 rounded-md border bg-white px-3 text-sm"><option value="">All types</option><option value="normal">Normal</option><option value="module">Module</option></select></div>
            <div class="grid gap-1"><label class="text-sm font-medium">Lecturer</label><select v-model="lecturerFilter" class="h-10 rounded-md border bg-white px-3 text-sm"><option value="">All lecturers</option><option v-for="lecturer in lecturers" :key="lecturer.id" :value="lecturer.id">{{ lecturer.name }}</option></select></div>
            <Button
                type="button"
                class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                @click="applyFilters"
                >Apply filters</Button
            >
        </div>
        <div
            class="overflow-hidden rounded-xl border border-[#9fd8b8] bg-white shadow-sm ring-1 ring-[#007a2f]/10"
        >
            <div
                class="grid grid-cols-[1.2fr_1fr_110px_1.4fr_auto] gap-4 border-b border-[#9fd8b8] bg-[#e8f6ee] px-4 py-3 text-sm font-bold text-[#063f22]"
            >
                <span>Course</span><span>School & department</span
                ><span>Type</span><span>Assigned lecturers</span
                ><span>Action</span>
            </div>
            <div
                v-for="course in filteredCourses"
                :key="course.id"
                class="grid grid-cols-[1.2fr_1fr_110px_1.4fr_auto] items-center gap-4 border-b px-4 py-4 text-sm last:border-b-0"
            >
                <div>
                    <div class="font-medium">
                        {{ course.code }} - {{ course.title }}
                    </div>
                    <div class="text-muted-foreground">
                        {{ course.level ?? 'Level not set' }}
                    </div>
                </div>
                <div>
                    <div>{{ course.department.school.name }}</div>
                    <div class="text-muted-foreground">
                        {{ course.department.name }}
                    </div>
                </div>
                <span class="capitalize">{{ course.courseType }}</span>
                <div class="text-muted-foreground">
                    <span v-if="course.lecturers.length">{{
                        course.lecturers
                            .map(
                                (lecturer) =>
                                    `${lecturer.name} (${lecturer.studentCount ?? '-'} students, ${lecturer.allocatedHours ?? '-'} hrs)`,
                            )
                            .join(', ')
                    }}</span
                    ><span v-else>No lecturer assigned this semester</span>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="border-[#007a2f]/30 text-[#007a2f] hover:bg-[#e3f6eb]"
                        @click="openAssignments(course)"
                        ><Users class="h-4 w-4" /> Assign</Button
                    ><Button
                        v-if="canUploadReports"
                        type="button"
                        size="sm"
                        class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                        :disabled="course.lecturers.length === 0"
                        @click="openReport(course)"
                        ><FileUp class="h-4 w-4" /> Upload report</Button
                    ><Button
                        v-if="canViewApprovals"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="border-[#007a2f]/30 text-[#007a2f] hover:bg-[#e3f6eb]"
                        @click="openApprovals(course)"
                        ><Check class="h-4 w-4" /> Approvals</Button
                    >
                </div>
            </div>
            <p
                v-if="courses.length === 0"
                class="px-4 py-10 text-center text-sm text-muted-foreground"
            >
                No courses are available for your department.
            </p>
        </div>
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
                    ><DialogDescription>{{
                        assignmentStep === 'lecturers'
                            ? 'Click lecturer names to select who will teach this course, then continue.'
                            : 'Enter the class size and allocated teaching hours for each selected lecturer.'
                    }}</DialogDescription></DialogHeader
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
                                            assignmentForm.errors[
                                                `assignments.${lecturerId}.student_count`
                                            ]
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
                                            assignmentForm.errors[
                                                `assignments.${lecturerId}.allocated_hours`
                                            ]
                                        "
                                    />
                                </div>
                            </div>
                        </div></div></template
                ><DialogFooter
                    ><Button
                        type="button"
                        variant="secondary"
                        @click="
                            assignmentStep === 'lecturers'
                                ? (assignmentOpen = false)
                                : (assignmentStep = 'lecturers')
                        "
                        >{{
                            assignmentStep === 'lecturers' ? 'Cancel' : 'Back'
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
                    ></DialogFooter
                >
            </form></DialogContent
        ></Dialog
    >
    <Dialog :open="reportOpen" @update:open="reportOpen = $event"
        ><DialogContent class="sm:max-w-xl"
            ><form class="space-y-5" @submit.prevent="uploadReport">
                <DialogHeader
                    ><DialogTitle>Upload course report</DialogTitle
                    ><DialogDescription
                        >{{ reportCourse?.code }} - {{ reportCourse?.title }} is
                        already linked to this report.</DialogDescription
                    ></DialogHeader
                >
                <div class="grid gap-2">
                    <label class="text-sm font-medium">Lecturer</label
                    ><select
                        v-model="reportForm.lecturer_id"
                        class="h-10 rounded-md border px-3 text-sm"
                        required
                    >
                        <option value="" disabled>
                            Select assigned lecturer
                        </option>
                        <option
                            v-for="lecturer in reportCourse?.lecturers ?? []"
                            :key="lecturer.id"
                            :value="lecturer.id"
                        >
                            {{ lecturer.name }} - {{ lecturer.email }}
                        </option></select
                    ><InputError :message="reportForm.errors.lecturer_id" />
                </div>
                <div class="grid gap-2">
                    <label class="text-sm font-medium">Report title</label
                    ><Input v-model="reportForm.title" required /><InputError
                        :message="reportForm.errors.title"
                    />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <label class="text-sm font-medium">Academic year</label
                        ><select
                            v-model="reportForm.academic_year_id"
                            class="h-10 rounded-md border px-3 text-sm"
                            required
                        >
                            <option value="" disabled>Select year</option>
                            <option
                                v-for="year in academicYears"
                                :key="year.id"
                                :value="year.id"
                            >
                                {{ year.name }}
                            </option></select
                        ><InputError
                            :message="reportForm.errors.academic_year_id"
                        />
                    </div>
                    <div class="grid gap-2">
                        <label class="text-sm font-medium">Category</label
                        ><Input
                            v-model="reportForm.category"
                            required
                        /><InputError :message="reportForm.errors.category" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <label class="text-sm font-medium">PDF report</label
                    ><Input
                        type="file"
                        accept="application/pdf,.pdf"
                        @input="
                            reportForm.report_file =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    /><InputError :message="reportForm.errors.report_file" />
                </div>
                <div class="grid gap-2">
                    <label class="text-sm font-medium">Summary</label
                    ><textarea
                        v-model="reportForm.summary"
                        rows="4"
                        class="rounded-md border px-3 py-2 text-sm"
                    /><InputError :message="reportForm.errors.summary" />
                </div>
                <InputError
                    :message="reportForm.errors.course_ids"
                /><DialogFooter
                    ><Button
                        type="button"
                        variant="secondary"
                        @click="reportOpen = false"
                        >Cancel</Button
                    ><Button
                        type="submit"
                        class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                        :disabled="reportForm.processing"
                        >{{
                            reportForm.processing
                                ? 'Uploading...'
                                : 'Upload report'
                        }}</Button
                    ></DialogFooter
                >
            </form></DialogContent
        ></Dialog
    >
    <Dialog :open="approvalsOpen" @update:open="approvalsOpen = $event"
        ><DialogContent class="sm:max-w-2xl"
            ><DialogHeader
                ><DialogTitle>Course approvals</DialogTitle
                ><DialogDescription
                    >Reports awaiting approval or already approved for
                    {{ approvalCourse?.code }} -
                    {{ approvalCourse?.title }}.</DialogDescription
                ></DialogHeader
            >
            <div class="max-h-96 space-y-3 overflow-y-auto">
                <div
                    v-for="report in approvalCourse?.reports ?? []"
                    :key="report.id"
                    class="rounded-lg border p-4"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <div class="font-medium">{{ report.title }}</div>
                            <div class="text-sm text-muted-foreground">
                                {{ report.lecturer ?? 'No lecturer' }}
                                <span v-if="report.academicYear"
                                    >- {{ report.academicYear }}</span
                                >
                            </div>
                            <div
                                class="mt-1 text-xs font-semibold uppercase"
                                :class="
                                    report.status === 'approved'
                                        ? 'text-[#007a2f]'
                                        : 'text-amber-700'
                                "
                            >
                                {{ report.status.replace('_', ' ') }}
                            </div>
                        </div>
                        <div
                            v-if="canApproveReports"
                            class="flex flex-wrap gap-2"
                        >
                            <Button
                                v-if="report.status === 'under_review'"
                                size="sm"
                                class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                                @click="approvalAction(report, 'approve')"
                                ><Check class="h-4 w-4" /> Approve</Button
                            ><Button
                                v-if="report.status === 'under_review'"
                                size="sm"
                                variant="outline"
                                @click="approvalAction(report, 'return')"
                                ><RotateCcw class="h-4 w-4" /> Return</Button
                            ><Button
                                v-if="report.status === 'under_review'"
                                size="sm"
                                variant="destructive"
                                @click="deleteReport(report)"
                                ><Trash2 class="h-4 w-4" /> Delete</Button
                            ><Button
                                v-if="report.status === 'approved'"
                                size="sm"
                                variant="outline"
                                @click="approvalAction(report, 'unapprove')"
                                ><RotateCcw class="h-4 w-4" /> Unapprove</Button
                            >
                        </div>
                    </div>
                </div>
                <p
                    v-if="(approvalCourse?.reports.length ?? 0) === 0"
                    class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
                >
                    No reports are currently in this course's approval queue.
                </p>
            </div>
            <DialogFooter
                ><Button
                    type="button"
                    variant="secondary"
                    @click="approvalsOpen = false"
                    >Close</Button
                ></DialogFooter
            ></DialogContent
        ></Dialog
    >
</template>
