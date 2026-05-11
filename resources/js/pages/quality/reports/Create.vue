<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    AcademicYearOption,
    CourseOption,
    LecturerOption,
    SchoolOption,
} from '@/types';

const props = defineProps<{
    schools: SchoolOption[];
    academicYears: AcademicYearOption[];
    lecturers: LecturerOption[];
    courses: CourseOption[];
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

const form = useForm({
    title: '',
    category: 'Academic Quality',
    status: 'submitted',
    academic_year_id: props.academicYears[0]
        ? String(props.academicYears[0].id)
        : '',
    summary: '',
    school_id: '',
    department_id: '',
    lecturer_id: '',
    course_ids: [] as number[],
    report_file: null as File | null,
});

const courseSearch = ref('');

const selectedLecturer = computed(
    () =>
        props.lecturers.find(
            (lecturer) => String(lecturer.id) === form.lecturer_id,
        ) ?? null,
);

const selectedCourses = computed(() =>
    props.courses.filter((course) => form.course_ids.includes(course.id)),
);

const filteredCourses = computed(() => {
    const query = courseSearch.value.trim().toLowerCase();

    if (!query) {
        return props.courses.slice(0, 8);
    }

    return props.courses
        .filter((course) =>
            `${course.code} ${course.title}`.toLowerCase().includes(query),
        )
        .slice(0, 8);
});

watch(selectedLecturer, (lecturer) => {
    if (!lecturer) {
        form.school_id = '';
        form.department_id = '';

        return;
    }

    form.school_id = lecturer.school ? String(lecturer.school.id) : '';
    form.department_id = lecturer.department
        ? String(lecturer.department.id)
        : '';
});

const toggleCourse = (courseId: number) => {
    form.course_ids = form.course_ids.includes(courseId)
        ? form.course_ids.filter((id) => id !== courseId)
        : [...form.course_ids, courseId];
};

const submit = () => {
    form.post('/quality/reports', {
        forceFormData: true,
    });
};
</script>

<template>
    <Head title="Upload QA Report" />

    <div class="max-w-5xl space-y-6">
        <Heading
            variant="small"
            title="Upload lecturer course report"
            description="Select the lecturer, then search for one or more courses by code or title"
        />

        <form class="space-y-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="lecturer">Lecturer</Label>
                <select
                    id="lecturer"
                    v-model="form.lecturer_id"
                    class="h-11 rounded-md border bg-background px-3 text-sm"
                    required
                >
                    <option value="" disabled>Select lecturer</option>
                    <option
                        v-for="lecturer in lecturers"
                        :key="lecturer.id"
                        :value="lecturer.id"
                    >
                        {{ lecturer.name }} - {{ lecturer.department?.name }}
                    </option>
                </select>
                <InputError :message="form.errors.lecturer_id" />
            </div>

            <div
                v-if="selectedLecturer"
                class="grid gap-4 rounded-lg border bg-white p-5 md:grid-cols-3"
            >
                <div>
                    <div class="text-sm text-muted-foreground">Lecturer</div>
                    <div class="font-medium">{{ selectedLecturer.name }}</div>
                    <div class="text-sm text-muted-foreground">
                        {{ selectedLecturer.email }}
                    </div>
                </div>
                <div>
                    <div class="text-sm text-muted-foreground">School</div>
                    <div class="font-medium">
                        {{ selectedLecturer.school?.name }}
                    </div>
                </div>
                <div>
                    <div class="text-sm text-muted-foreground">Department</div>
                    <div class="font-medium">
                        {{ selectedLecturer.department?.name }}
                    </div>
                </div>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="title">Report title</Label>
                    <Input id="title" v-model="form.title" required />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="grid gap-2">
                    <Label for="academic-year">Academic year</Label>
                    <select
                        id="academic-year"
                        v-model="form.academic_year_id"
                        class="h-11 rounded-md border bg-background px-3 text-sm"
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
                <Input id="category" v-model="form.category" required />
                <InputError :message="form.errors.category" />
            </div>

            <div class="grid gap-2">
                <Label for="course-search">Courses</Label>
                <Input
                    id="course-search"
                    v-model="courseSearch"
                    type="search"
                    placeholder="Type a course code or title"
                />

                <div class="grid gap-3 md:grid-cols-2">
                    <button
                        v-for="course in filteredCourses"
                        :key="course.id"
                        type="button"
                        class="rounded-lg border p-4 text-left transition-colors"
                        :class="
                            form.course_ids.includes(course.id)
                                ? 'border-[#007a2f] bg-[#e8f3ed]'
                                : 'bg-white hover:bg-muted/40'
                        "
                        @click="toggleCourse(course.id)"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <div class="font-medium">
                                {{ course.code }} - {{ course.title }}
                            </div>
                            <Badge
                                v-if="form.course_ids.includes(course.id)"
                                variant="secondary"
                            >
                                Selected
                            </Badge>
                        </div>
                        <div class="mt-2 text-sm text-muted-foreground">
                            {{ course.level ?? 'Level not set' }}
                            <span v-if="course.creditHours">
                                · {{ course.creditHours }} credit hours
                            </span>
                            <span v-if="course.department">
                                , {{ course.department.name }}
                            </span>
                        </div>
                    </button>
                </div>
                <p
                    v-if="filteredCourses.length === 0"
                    class="rounded-lg border bg-white p-4 text-sm text-muted-foreground"
                >
                    No courses match that code or title.
                </p>

                <div v-if="selectedCourses.length" class="flex flex-wrap gap-2">
                    <Badge
                        v-for="course in selectedCourses"
                        :key="course.id"
                        variant="secondary"
                    >
                        {{ course.code }} - {{ course.title }}
                    </Badge>
                </div>
                <InputError :message="form.errors.course_ids" />
            </div>

            <div class="grid gap-2 md:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="status">Status</Label>
                    <select
                        id="status"
                        v-model="form.status"
                        class="h-11 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="draft">Draft</option>
                        <option value="submitted">Submitted</option>
                    </select>
                    <InputError :message="form.errors.status" />
                </div>

                <div class="grid gap-2">
                    <Label for="report-file">Report file</Label>
                    <Input
                        id="report-file"
                        type="file"
                        @input="
                            form.report_file =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                    <InputError :message="form.errors.report_file" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="summary">Summary</Label>
                <textarea
                    id="summary"
                    v-model="form.summary"
                    rows="5"
                    class="rounded-md border bg-background px-3 py-2 text-sm"
                />
                <InputError :message="form.errors.summary" />
            </div>

            <input type="hidden" name="school_id" :value="form.school_id" />
            <input
                type="hidden"
                name="department_id"
                :value="form.department_id"
            />

            <Button type="submit" :disabled="form.processing">
                Upload report
            </Button>
        </form>
    </div>
</template>
