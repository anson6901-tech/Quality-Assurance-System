<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Copy, Plus, Search, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
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
import { Label } from '@/components/ui/label';

type Semester = {
    id: number;
    academic_year_id: number;
    name: string;
    sequence: number;
};
type AcademicYear = {
    id: number;
    name: string;
    status: string;
    semesters: Semester[];
};
type Program = {
    id: number;
    name: string;
    code: string;
    department: string;
    school: string;
    maxLevel: number;
};
type Course = {
    id: number;
    code: string;
    title: string;
    level: string | null;
    courseType: string;
    department: string;
    school: string;
    mountedLevel?: string;
};
type Offering = {
    courseId: number;
    programId: number;
    semesterId: number;
    level: string | null;
};

const props = defineProps<{
    academicYears: AcademicYear[];
    programs: Program[];
    courses: Course[];
    offerings: Offering[];
    assignedOfferings: Offering[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mount Courses', href: '/quality/course-offerings' },
        ],
    },
});

const activeYear =
    props.academicYears.find((year) => year.status === 'active') ??
    props.academicYears[0];
const academicYearId = ref(activeYear ? String(activeYear.id) : '');
const semesterId = ref(
    activeYear?.semesters[0] ? String(activeYear.semesters[0].id) : '',
);
const programId = ref(props.programs[0] ? String(props.programs[0].id) : '');
const programLevel = ref('100');
const search = ref('');
const selectedCourseId = ref<number | null>(null);
const copyOpen = ref(false);
const removingCourseIds = ref<number[]>([]);

const semesters = computed(
    () =>
        props.academicYears.find(
            (year) => year.id === Number(academicYearId.value),
        )?.semesters ?? [],
);
const programCourses = computed(() =>
    props.courses.filter((course) => {
        const program = props.programs.find(
            (item) => item.id === Number(programId.value),
        );

        return (
            !course.level || Number(course.level) <= (program?.maxLevel ?? 0)
        );
    }),
);
const availableLevels = computed(() => {
    return Array.from({ length: 10 }, (_, index) => String((index + 1) * 100));
});
const mountedCourseIdsForPeriod = computed(() =>
    props.offerings
        .filter(
            (offering) =>
                offering.semesterId === Number(semesterId.value) &&
                offering.programId === Number(programId.value),
        )
        .map((offering) => offering.courseId),
);
const filteredCourses = computed(() => {
    const term = search.value.trim().toLowerCase();

    return programCourses.value.filter(
        (course) =>
            !mountedCourseIdsForPeriod.value.includes(course.id) &&
            term.length > 0 &&
            (course.code.toLowerCase().includes(term) ||
                course.title.toLowerCase().includes(term) ||
                course.level?.includes(term)),
    );
});
const mountedCourses = computed(() =>
    programCourses.value
        .filter((course) => form.course_ids.includes(course.id))
        .map((course) => ({
            ...course,
            mountedLevel: form.levels[course.id] ?? course.level ?? '',
        })),
);
const selectedCourse = computed(() =>
    programCourses.value.find((course) => course.id === selectedCourseId.value),
);

const form = useForm({
    program_id: programId.value,
    semester_id: semesterId.value,
    level: programLevel.value,
    course_ids: [] as number[],
    levels: {} as Record<number, string>,
});
const copyForm = useForm({
    program_id: programId.value,
    source_semester_id: semesterId.value,
    target_semester_id: '',
});

const isAssigned = (courseId: number) =>
    props.assignedOfferings.some(
        (assignment) =>
            assignment.courseId === courseId &&
            assignment.programId === Number(programId.value) &&
            assignment.semesterId === Number(semesterId.value),
    );

const loadMountedCourses = () => {
    form.program_id = programId.value;
    form.semester_id = semesterId.value;
    form.level = programLevel.value;
    form.course_ids = props.offerings
        .filter(
            (offering) =>
                offering.semesterId === Number(semesterId.value) &&
                offering.programId === Number(programId.value) &&
                offering.level === programLevel.value &&
                programCourses.value.some(
                    (course) => course.id === offering.courseId,
                ),
        )
        .map((offering) => offering.courseId);
    form.levels = Object.fromEntries(
        props.offerings
            .filter(
                (offering) =>
                    offering.semesterId === Number(semesterId.value) &&
                    offering.programId === Number(programId.value) &&
                    offering.level === programLevel.value,
            )
            .map((offering) => [offering.courseId, offering.level ?? '']),
    );
    form.clearErrors();
    search.value = '';
    selectedCourseId.value = null;
};

watch([programId, semesterId, programLevel], loadMountedCourses, {
    immediate: true,
});
watch(programId, () => {
    if (!availableLevels.value.includes(programLevel.value)) {
        programLevel.value = availableLevels.value[0] ?? '';
    }
});
watch(academicYearId, () => {
    semesterId.value = semesters.value[0] ? String(semesters.value[0].id) : '';
});

const persist = () =>
    form.put('/quality/course-offerings', {
        preserveScroll: true,
    });

const mountSelectedCourse = () => {
    if (!selectedCourseId.value || !programLevel.value) {
        return;
    }

    form.course_ids = [...form.course_ids, selectedCourseId.value];
    form.levels[selectedCourseId.value] = programLevel.value;
    search.value = '';
    selectedCourseId.value = null;
    persist();
};

const removeMountedCourse = (courseId: number) => {
    if (removingCourseIds.value.includes(courseId)) {
        return;
    }

    const course = mountedCourses.value.find((item) => item.id === courseId);
    const warning = isAssigned(courseId)
        ? ' This will also remove its lecturer assignments for this program and semester.'
        : '';

    if (
        !window.confirm(`Unmount ${course?.code ?? 'this course'}?${warning}`)
    ) {
        return;
    }

    removingCourseIds.value = [...removingCourseIds.value, courseId];

    router.delete(`/quality/course-offerings/${courseId}`, {
        data: {
            program_id: Number(programId.value),
            semester_id: Number(semesterId.value),
        },
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            removingCourseIds.value = removingCourseIds.value.filter(
                (id) => id !== courseId,
            );
        },
    });
};

const openCopy = () => {
    copyForm.program_id = programId.value;
    copyForm.source_semester_id = semesterId.value;
    copyForm.target_semester_id = '';
    copyForm.clearErrors();
    copyOpen.value = true;
};

const copyCourses = () =>
    copyForm.post('/quality/course-offerings/copy', {
        preserveScroll: true,
        onSuccess: () => {
            copyOpen.value = false;
        },
    });

const semesterLabel = (semester: Semester) => {
    const year = props.academicYears.find(
        (item) => item.id === semester.academic_year_id,
    );

    return `${year?.name ?? ''} — ${semester.name}`;
};
</script>

<template>
    <Head title="Mount Courses" />

    <div class="space-y-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                variant="small"
                title="Mount Courses"
                description="Choose which catalogue courses a program will run at each level in an academic year and semester."
            />
            <Button
                type="button"
                variant="outline"
                class="border-[#007a2f]/30 bg-white text-[#007a2f] hover:bg-[#e3f6eb]"
                :disabled="!programId || !semesterId"
                @click="openCopy"
            >
                <Copy /> Copy mounted courses
            </Button>
        </div>

        <div
            class="grid min-w-0 gap-4 rounded-xl border border-[#9fd8b8] bg-[#edf9f2] p-4 sm:grid-cols-2 xl:grid-cols-4"
        >
            <div class="grid min-w-0 gap-2">
                <Label for="mount-year">Academic year</Label>
                <select
                    id="mount-year"
                    v-model="academicYearId"
                    class="h-10 w-full min-w-0 rounded-md border bg-white px-3 text-sm"
                >
                    <option
                        v-for="year in props.academicYears"
                        :key="year.id"
                        :value="year.id"
                    >
                        {{ year.name }}
                        {{ year.status === 'active' ? '(Active)' : '' }}
                    </option>
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <Label for="mount-semester">Semester</Label>
                <select
                    id="mount-semester"
                    v-model="semesterId"
                    class="h-10 w-full min-w-0 rounded-md border bg-white px-3 text-sm"
                >
                    <option
                        v-for="semester in semesters"
                        :key="semester.id"
                        :value="semester.id"
                    >
                        {{ semester.name }}
                    </option>
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <Label for="mount-program">Program</Label>
                <select
                    id="mount-program"
                    v-model="programId"
                    class="h-10 w-full min-w-0 truncate rounded-md border bg-white px-3 text-sm"
                >
                    <option
                        v-for="program in props.programs"
                        :key="program.id"
                        :value="program.id"
                    >
                        {{ program.name }} ({{ program.code }})
                    </option>
                </select>
            </div>
            <div class="grid min-w-0 gap-2">
                <Label for="mount-level">Level</Label>
                <select
                    id="mount-level"
                    v-model="programLevel"
                    class="h-10 w-full min-w-0 rounded-md border bg-white px-3 text-sm"
                >
                    <option
                        v-for="level in availableLevels"
                        :key="level"
                        :value="level"
                    >
                        Level {{ level }}
                    </option>
                </select>
            </div>
        </div>

        <div
            v-if="programId && semesterId"
            class="overflow-hidden rounded-xl border border-[#9fd8b8] bg-white shadow-sm"
        >
            <div class="border-b border-[#9fd8b8] bg-[#e8f6ee] p-4">
                <div>
                    <h3 class="text-lg font-semibold text-[#063f22]">
                        Find a course to mount
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        Search the catalogue, choose one course, then mount it
                        for Level {{ programLevel }}.
                    </p>
                </div>
                <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                    <div class="relative flex-1">
                        <Search
                            class="pointer-events-none absolute top-3 left-3 size-4 text-muted-foreground"
                        />
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Search by course code, title, or level"
                            class="h-10 w-full rounded-md border bg-white pr-3 pl-9 text-sm"
                            @input="selectedCourseId = null"
                        />
                    </div>
                    <Button
                        type="button"
                        class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                        :disabled="
                            !selectedCourseId ||
                            !programLevel ||
                            form.processing
                        "
                        @click="mountSelectedCourse"
                    >
                        <Plus /> Mount course
                    </Button>
                </div>
                <div
                    v-if="search.trim()"
                    class="mt-2 max-h-64 overflow-y-auto rounded-lg border bg-white shadow-sm"
                >
                    <button
                        v-for="course in filteredCourses"
                        :key="course.id"
                        type="button"
                        class="flex w-full items-start gap-3 border-b px-4 py-3 text-left last:border-b-0 hover:bg-[#f6fcf8]"
                        :class="
                            selectedCourseId === course.id
                                ? 'bg-[#e3f6eb] ring-1 ring-[#007a2f] ring-inset'
                                : ''
                        "
                        @click="selectedCourseId = course.id"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">
                                {{ course.code }} — {{ course.title }}
                            </span>
                            <span class="text-sm text-muted-foreground">
                                Level {{ course.level ?? 'not set' }} ·
                                {{ course.courseType }} · {{ course.school }} /
                                {{ course.department }}
                            </span>
                        </span>
                        <span
                            v-if="selectedCourseId === course.id"
                            class="text-sm font-semibold text-[#007a2f]"
                        >
                            Selected
                        </span>
                    </button>
                    <p
                        v-if="filteredCourses.length === 0"
                        class="px-4 py-6 text-center text-sm text-muted-foreground"
                    >
                        No unmounted catalogue courses match your search.
                    </p>
                </div>
                <p
                    v-if="selectedCourse"
                    class="mt-2 text-sm font-medium text-[#006d2b]"
                >
                    Ready to mount: {{ selectedCourse.code }} —
                    {{ selectedCourse.title }}
                </p>
                <InputError :message="form.errors.course_ids" class="mt-2" />
            </div>

            <div class="p-4">
                <div class="mb-3">
                    <h3 class="text-lg font-semibold text-[#063f22]">
                        Mounted courses
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        {{ mountedCourses.length }} courses currently mounted
                        for Level {{ programLevel }}
                    </p>
                </div>
                <div class="divide-y rounded-lg border">
                    <div
                        v-for="course in mountedCourses"
                        :key="course.id"
                        class="flex items-start gap-3 px-4 py-4"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">
                                {{ course.code }} — {{ course.title }}
                            </span>
                            <span class="text-sm text-muted-foreground">
                                Level {{ course.mountedLevel || 'not set' }} ·
                                {{ course.courseType }} · {{ course.school }} /
                                {{ course.department }}
                            </span>
                        </span>
                        <span
                            v-if="isAssigned(course.id)"
                            class="rounded-full bg-[#dff3e7] px-2 py-1 text-xs font-medium text-[#006d2b]"
                        >
                            Lecturer assigned
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="text-destructive"
                            :disabled="
                                form.processing ||
                                removingCourseIds.includes(course.id)
                            "
                            @click="removeMountedCourse(course.id)"
                        >
                            <Trash2 class="size-4" />
                            {{
                                removingCourseIds.includes(course.id)
                                    ? 'Removing...'
                                    : 'Remove'
                            }}
                        </Button>
                    </div>
                    <p
                        v-if="mountedCourses.length === 0"
                        class="px-4 py-10 text-center text-sm text-muted-foreground"
                    >
                        No courses are mounted for this program and semester.
                    </p>
                </div>
            </div>
        </div>

        <p
            v-else
            class="rounded-xl border border-dashed p-10 text-center text-muted-foreground"
        >
            Add an academic year and program before mounting courses.
        </p>
    </div>

    <Dialog v-model:open="copyOpen">
        <DialogContent class="sm:max-w-lg">
            <form class="space-y-4" @submit.prevent="copyCourses">
                <DialogHeader>
                    <DialogTitle>Copy mounted courses</DialogTitle>
                    <DialogDescription>
                        Copy this program's mounted course list into another
                        academic year and semester. Existing mounted courses in
                        the destination are kept.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label>Program</Label>
                    <select
                        v-model="copyForm.program_id"
                        class="h-10 rounded-md border px-3 text-sm"
                        required
                    >
                        <option
                            v-for="program in props.programs"
                            :key="program.id"
                            :value="program.id"
                        >
                            {{ program.name }} ({{ program.code }})
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label>Copy from</Label>
                    <select
                        v-model="copyForm.source_semester_id"
                        class="h-10 rounded-md border px-3 text-sm"
                        required
                    >
                        <optgroup
                            v-for="year in props.academicYears"
                            :key="year.id"
                            :label="year.name"
                        >
                            <option
                                v-for="semester in year.semesters"
                                :key="semester.id"
                                :value="semester.id"
                            >
                                {{ semester.name }}
                            </option>
                        </optgroup>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label>Copy to</Label>
                    <select
                        v-model="copyForm.target_semester_id"
                        class="h-10 rounded-md border px-3 text-sm"
                        required
                    >
                        <option value="" disabled>
                            Select destination semester
                        </option>
                        <optgroup
                            v-for="year in props.academicYears"
                            :key="year.id"
                            :label="year.name"
                        >
                            <option
                                v-for="semester in year.semesters"
                                :key="semester.id"
                                :value="semester.id"
                                :disabled="
                                    String(semester.id) ===
                                    String(copyForm.source_semester_id)
                                "
                            >
                                {{ semesterLabel(semester) }}
                            </option>
                        </optgroup>
                    </select>
                    <InputError :message="copyForm.errors.target_semester_id" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="copyOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                        :disabled="copyForm.processing"
                    >
                        <Copy /> Copy courses
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
