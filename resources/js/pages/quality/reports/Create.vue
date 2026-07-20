<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Upload, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AcademicYearOption, CourseOption } from '@/types';

interface CourseWithLecturers extends CourseOption {
    lecturers: Array<{ id: number; name: string; email: string }>;
    department?: { id: number; name: string; schoolId: number };
}

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
    courses: CourseWithLecturers[];
    defaultAcademicYearId?: number;
    defaultSemesterId?: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'QA Reports',
                href: '/quality/reports',
            },
            {
                title: 'Upload',
                href: '/quality/reports/create',
            },
        ],
    },
});

const selectedAcademicYearId = ref<number | string>(props.defaultAcademicYearId || '');
const selectedSemesterId = ref<number | string>(props.defaultSemesterId || '');
const selectedDepartmentId = ref<number | string>('');
const searchQuery = ref('');
const selectedLecturerId = ref<number | string>('');
const uploadingCourseId = ref<number | null>(null);

const uploadForm = useForm({
    title: '',
    category: 'Academic Quality',
    academic_year_id: selectedAcademicYearId.value,
    course_ids: [] as number[],
    report_file: null as File | null,
    summary: '',
});

const semestersForAcademicYear = computed(() =>
    props.semesters.filter(
        (s) =>
            !selectedAcademicYearId.value ||
            s.academic_year_id === Number(selectedAcademicYearId.value),
    ),
);

const filteredCourses = computed(() =>
    props.courses.filter((course) => {
        if (
            selectedDepartmentId.value &&
            course.department?.id !== Number(selectedDepartmentId.value)
        ) {
            return false;
        }
        if (
            searchQuery.value &&
            !`${course.code} ${course.title}`
                .toLowerCase()
                .includes(searchQuery.value.toLowerCase())
        ) {
            return false;
        }
        if (
            selectedLecturerId.value &&
            !course.lecturers.some((l) => l.id === Number(selectedLecturerId.value))
        ) {
            return false;
        }
        return true;
    }),
);

const allLecturers = computed(() => {
    const lecturers = new Map<number, { id: number; name: string }>();
    props.courses.forEach((course) => {
        course.lecturers.forEach((lecturer) => {
            if (!lecturers.has(lecturer.id)) {
                lecturers.set(lecturer.id, {
                    id: lecturer.id,
                    name: lecturer.name,
                });
            }
        });
    });
    return Array.from(lecturers.values()).sort((a, b) =>
        a.name.localeCompare(b.name),
    );
});

const startUpload = (courseId: number) => {
    uploadingCourseId.value = courseId;
    uploadForm.course_ids = [courseId];
};

const submitUpload = () => {
    const course = filteredCourses.value.find((c) => c.id === uploadingCourseId.value);
    uploadForm.title =
        uploadForm.title || `Report for ${course?.code || 'Course'}`;
    uploadForm.academic_year_id = String(selectedAcademicYearId.value);

    uploadForm.post('/quality/reports', {
        forceFormData: true,
        onSuccess: () => {
            uploadingCourseId.value = null;
            uploadForm.reset();
        },
    });
};

const closeUploadModal = () => {
    uploadingCourseId.value = null;
    uploadForm.reset();
};

const selectedCourse = computed(() =>
    filteredCourses.value.find((c) => c.id === uploadingCourseId.value),
);
</script>

<template>
    <Head title="Upload Report" />

    <div class="max-w-5xl space-y-6">
        <Heading
            variant="small"
            title="Upload report"
            description="Select the assigned courses and upload the lecturer report for review"
        />

        <form class="space-y-6" @submit.prevent="submit">
            <div class="rounded-xl border border-[#9fd8b8] bg-[#eefaf3] p-6 shadow-sm ring-1 ring-[#007a2f]/10">
                <div class="font-medium">Assigned course lecturers</div>
                <p class="text-sm text-muted-foreground">
                    Only courses that already have assigned lecturers are available for report upload.
                    If a selected course has more than one assigned lecturer, upload the report separately for each lecturer.
                </p>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="title">Report title</Label>
                    <Input
                        id="title"
                        v-model="form.title"
                        class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                        required
                    />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="grid gap-2">
                    <Label for="academic-year">Academic year</Label>
                    <select
                        id="academic-year"
                        v-model="form.academic_year_id"
                        class="h-11 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                        required
                    >
                        <option value="" disabled>Select academic year</option>
                        <option
                            v-for="academicYear in academicYears"
                            :key="academicYear.id"
                            :value="academicYear.id"
                        >
                            {{ academicYear.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.academic_year_id" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="category">Category</Label>
                <Input
                    id="category"
                    v-model="form.category"
                    class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                    required
                />
                <InputError :message="form.errors.category" />
            </div>

            <div class="grid gap-2">
                <Label for="course-search">Courses</Label>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            id="course-search"
                            type="button"
                            variant="outline"
                            class="h-11 w-full justify-between border-[#9fd8b8] bg-white px-3 font-normal text-[#063f22] hover:bg-[#f8fffb]"
                        >
                            <span class="truncate">{{
                                selectedCourseLabel
                            }}</span>
                            <ChevronDown class="h-4 w-4 opacity-60" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="start"
                        class="w-[min(780px,calc(100vw-2rem))] p-2"
                    >
                        <Input
                            v-model="courseSearch"
                            type="search"
                            placeholder="Search courses by code or title"
                            class="mb-2 h-10 border-[#9fd8b8] focus-visible:ring-[#007a2f]/20"
                            @keydown.stop
                        />

                        <div class="max-h-72 overflow-y-auto">
                            <DropdownMenuCheckboxItem
                                v-for="course in filteredCourses"
                                :key="course.id"
                                :checked="form.course_ids.includes(course.id)"
                                class="items-start py-3"
                                @select.prevent="toggleCourse(course.id)"
                            >
                                <div class="grid gap-1">
                                    <div class="font-medium">
                                        {{ course.code }} - {{ course.title }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ course.level ?? 'Level not set' }}
                                        <span v-if="course.creditHours">
                                            - {{ course.creditHours }} credit
                                            hours
                                        </span>
                                        <span v-if="course.department">
                                            , {{ course.department.name }}
                                        </span>
                                    </div>
                                </div>
                            </DropdownMenuCheckboxItem>

                            <div
                                v-if="filteredCourses.length === 0"
                                class="rounded-md px-3 py-6 text-center text-sm text-muted-foreground"
                            >
                                No courses match that code or title.
                            </div>
                        </div>
                    </DropdownMenuContent>
                </DropdownMenu>

                <div v-if="selectedCourses.length" class="space-y-4">
                    <div class="flex flex-wrap gap-2">
                        <Badge
                            v-for="course in selectedCourses"
                            :key="course.id"
                            variant="secondary"
                            class="gap-1 pr-1"
                        >
                            {{ course.code }} - {{ course.title }}
                            <button
                                type="button"
                                class="rounded-sm p-0.5 hover:bg-black/10"
                                :aria-label="`Remove ${course.code}`"
                                @click="removeCourse(course.id)"
                            >
                                <X class="h-3 w-3" />
                            </button>
                        </Badge>
                    </div>

                    <div
                        v-if="selectedCoursesWithMultipleLecturers.length"
                        class="rounded-xl border border-orange-300 bg-orange-50 p-4 text-sm text-orange-800"
                    >
                        <div class="font-medium">Multiple lecturers assigned</div>
                        <p>
                            The following courses have more than one lecturer assigned. Upload reports separately for each lecturer to avoid ambiguity.
                        </p>
                        <ul class="list-disc pl-5">
                            <li
                                v-for="course in selectedCoursesWithMultipleLecturers"
                                :key="course.id"
                            >
                                <span class="font-medium">{{ course.code }} - {{ course.title }}</span>
                                <span>
                                    ({{ course.lecturerCount }} lecturers)
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>
                <InputError :message="form.errors.course_ids" />
            </div>

            <div class="grid gap-2">
                <Label for="report-file">Report file</Label>
                <Input
                    id="report-file"
                    type="file"
                    class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                    accept="application/pdf,.pdf"
                    @input="
                        form.report_file =
                            ($event.target as HTMLInputElement).files?.[0] ??
                            null
                    "
                    @change="
                        form.report_file =
                            ($event.target as HTMLInputElement).files?.[0] ??
                            null
                    "
                />
                <InputError :message="form.errors.report_file" />
            </div>

            <div class="grid gap-2">
                <Label for="summary">Summary</Label>
                <textarea
                    id="summary"
                    v-model="form.summary"
                    rows="5"
                    class="rounded-md border border-[#9fd8b8] bg-white px-3 py-2 text-sm focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                />
                <InputError :message="form.errors.summary" />
            </div>

            <div class="flex flex-col items-start gap-3">
                <p v-if="submitErrorSummary" class="text-sm text-red-600">
                    {{ submitErrorSummary }}
                </p>
                <ul
                    v-if="formErrorMessages.length"
                    class="list-disc space-y-1 pl-5 text-sm text-red-600"
                >
                    <li v-for="message in formErrorMessages" :key="message">
                        {{ message }}
                    </li>
                </ul>

                <Button
                    type="submit"
                    class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Uploading...' : 'Upload report' }}
                </Button>
            </div>
        </form>
    </div>
</template>
