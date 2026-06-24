<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ChevronDown, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
    academic_year_id: props.academicYears[0]
        ? String(props.academicYears[0].id)
        : '',
    summary: '',
    lecturer_id: '',
    course_ids: [] as number[],
    report_file: null as File | null,
});

const courseSearch = ref('');
const submitErrorSummary = ref<string | null>(null);

const selectedLecturer = computed(
    () =>
        props.lecturers.find(
            (lecturer) => String(lecturer.id) === form.lecturer_id,
        ) ?? null,
);

const selectedCourses = computed(() =>
    props.courses.filter((course) => form.course_ids.includes(course.id)),
);

const formErrorMessages = computed(() =>
    Object.values(form.errors).filter(Boolean),
);

const selectedCourseLabel = computed(() => {
    if (selectedCourses.value.length === 0) {
        return 'Select courses';
    }

    if (selectedCourses.value.length === 1) {
        const [course] = selectedCourses.value;

        return `${course.code} - ${course.title}`;
    }

    return `${selectedCourses.value.length} courses selected`;
});

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

const toggleCourse = (courseId: number) => {
    form.course_ids = form.course_ids.includes(courseId)
        ? form.course_ids.filter((id) => id !== courseId)
        : [...form.course_ids, courseId];
};

const removeCourse = (courseId: number) => {
    form.course_ids = form.course_ids.filter((id) => id !== courseId);
};

const submit = () => {
    submitErrorSummary.value = null;

    form.post('/quality/reports', {
        forceFormData: true,
        onError: () => {
            submitErrorSummary.value =
                'The report was not uploaded. Check the highlighted fields and try again.';
        },
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
                    class="h-11 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
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
                class="grid gap-4 rounded-xl border border-[#9fd8b8] bg-[#eefaf3] p-6 shadow-sm ring-1 ring-[#007a2f]/10 md:grid-cols-3"
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

                <div v-if="selectedCourses.length" class="flex flex-wrap gap-2">
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
