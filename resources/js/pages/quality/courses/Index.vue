<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Download, Pencil, Plus, Trash2, Upload } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageBanner from '@/components/PageBanner.vue';
import CourseList from '@/components/quality/CourseList.vue';
import CourseRow from '@/components/quality/CourseRow.vue';
import CourseSearch from '@/components/quality/CourseSearch.vue';
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
import { Label } from '@/components/ui/label';
import { useSearchParam } from '@/composables/useSearchParam';
import { matchesSearch } from '@/lib/coursePartitions';

type Course = {
    id: number;
    code: string;
    title: string;
    course_type: 'normal' | 'module';
    level: string | null;
    credit_hours: number | null;
};

const props = defineProps<{
    courses: Course[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Courses', href: '/quality/courses' }],
    },
});

const form = useForm({
    code: '',
    title: '',
    course_type: 'normal',
    level: '',
    credit_hours: '',
});
const editForm = useForm({
    id: null as number | null,
    code: '',
    title: '',
    course_type: 'normal',
    level: '',
    credit_hours: '',
});
const importForm = useForm({ courses_file: null as File | null });
const addCourseOpen = ref(false);
const editCourseOpen = ref(false);
const bulkOpen = ref(false);
const levelFilter = ref('');

const availableFilterLevels = Array.from({ length: 10 }, (_, index) =>
    String((index + 1) * 100),
);
const courseSearch = ref(useSearchParam('search'));
const filteredCourses = computed(() =>
    props.courses.filter(
        (course) =>
            (!levelFilter.value || course.level === levelFilter.value) &&
            matchesSearch(courseSearch.value, [course.code, course.title]),
    ),
);

const closeAddCourse = () => {
    addCourseOpen.value = false;
    form.reset();
    form.clearErrors();
};
const submit = () =>
    form.post('/quality/courses', {
        preserveScroll: true,
        onSuccess: closeAddCourse,
    });
const startEdit = (course: Course) => {
    editForm.id = course.id;
    editForm.code = course.code;
    editForm.title = course.title;
    editForm.course_type = course.course_type;
    editForm.level = course.level ?? '';
    editForm.credit_hours = course.credit_hours
        ? String(course.credit_hours)
        : '';
    editCourseOpen.value = true;
};
const updateCourse = () =>
    editForm.id &&
    editForm.patch(`/quality/courses/${editForm.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editCourseOpen.value = false;
            editForm.reset();
        },
    });
const importCourses = () =>
    importForm.post('/quality/courses/import', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            importForm.reset();
            bulkOpen.value = false;
        },
    });
const removeCourse = (course: Course) =>
    router.delete(`/quality/courses/${course.id}`, { preserveScroll: true });
</script>

<template>
    <Head title="Courses" />
    <div class="space-y-8">
        <section class="space-y-6">
            <PageBanner
                title="Courses"
                description="Manage reusable catalogue courses that can be mounted for any program."
            >
                <template #actions>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="border-[#007a2f]/30 bg-white text-[#007a2f] hover:bg-[#e3f6eb]"
                            @click="bulkOpen = true"
                        >
                            <Upload /> Bulk add
                        </Button>
                        <Button
                            type="button"
                            class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                            @click="addCourseOpen = true"
                        >
                            <Plus /> Add course
                        </Button>
                    </div>
                </template>
            </PageBanner>
            <div
                class="rounded-xl border border-[#9fd8b8] bg-[#edf9f2] p-4 shadow-sm lg:sticky lg:top-20 lg:z-20"
            >
                <div class="grid max-w-sm gap-2">
                    <Label for="course-level-filter">Level</Label>
                    <select
                        id="course-level-filter"
                        v-model="levelFilter"
                        class="h-10 rounded-md border bg-white px-3 text-sm"
                    >
                        <option value="">All levels</option>
                        <option
                            v-for="level in availableFilterLevels"
                            :key="level"
                            :value="level"
                        >
                            Level {{ level }}
                        </option>
                    </select>
                </div>
            </div>
            <CourseSearch
                v-if="courses.length"
                v-model="courseSearch"
                :shown="filteredCourses.length"
                :total="courses.length"
                placeholder="Search courses by code or title"
            />

            <CourseList
                v-if="filteredCourses.length"
                :headings="[
                    'Course',
                    'Level',
                    'Type',
                    'Credit hours',
                    'Actions',
                ]"
                columns="minmax(0,2fr) 110px 110px 110px minmax(170px,auto)"
            >
                <CourseRow
                    v-for="course in filteredCourses"
                    :key="course.id"
                    :stripe-class="
                        course.course_type === 'module'
                            ? 'border-l-[#7c5cc4]'
                            : 'border-l-[#0f8a3e]'
                    "
                >
                    <div class="min-w-0">
                        <div class="font-semibold text-[#075b2e]">
                            {{ course.code }}
                        </div>
                        <div class="truncate text-sm text-[#3c5059]">
                            {{ course.title }}
                        </div>
                    </div>
                    <div class="text-sm text-[#3c5059]">
                        {{ course.level ? `Level ${course.level}` : 'Not set' }}
                    </div>
                    <div>
                        <span
                            v-if="course.course_type === 'module'"
                            class="rounded border border-[#d6c9f2] bg-[#f4effc] px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-[#56349c] uppercase"
                        >
                            Module
                        </span>
                        <span v-else class="text-sm text-[#3c5059]">
                            Normal
                        </span>
                    </div>
                    <div class="text-sm text-[#3c5059]">
                        {{ course.credit_hours ?? 'Not set' }}
                    </div>
                    <div class="flex justify-start gap-1 md:justify-end">
                        <SplitMenu
                            :course-id="course.id"
                            :course-code="course.code"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            :title="`Edit ${course.code}`"
                            @click="startEdit(course)"
                        >
                            <Pencil class="size-4" />
                            <span class="sr-only">Edit {{ course.code }}</span>
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-8 text-red-700 hover:bg-red-50 hover:text-red-800"
                            :title="`Delete ${course.code}`"
                            @click="removeCourse(course)"
                        >
                            <Trash2 class="size-4" />
                            <span class="sr-only"
                                >Delete {{ course.code }}</span
                            >
                        </Button>
                    </div>
                </CourseRow>
            </CourseList>
            <div
                v-else
                class="rounded-xl border border-dashed border-[#9fd8b8] bg-white py-12 text-center text-sm text-muted-foreground"
            >
                No courses match your search or level.
            </div>
        </section>
    </div>

    <Dialog v-model:open="bulkOpen">
        <DialogContent class="sm:max-w-lg">
            <form class="space-y-4" @submit.prevent="importCourses">
                <DialogHeader>
                    <DialogTitle>Bulk add courses</DialogTitle>
                    <DialogDescription>
                        Download the system template, complete it, then upload
                        the CSV.
                    </DialogDescription>
                </DialogHeader>
                <Button
                    as-child
                    type="button"
                    variant="outline"
                    class="w-full border-[#007a2f]/30 bg-white text-[#007a2f] hover:bg-[#e3f6eb]"
                >
                    <a href="/quality/courses/import-template" download>
                        <Download /> Download CSV template
                    </a>
                </Button>
                <p class="text-xs leading-5 text-muted-foreground">
                    Columns: code, title, course_type, level, credit_hours.
                    Use
                    <strong>normal</strong> or <strong>module</strong> for
                    course_type.
                </p>
                <Input
                    type="file"
                    accept=".csv,text/csv"
                    required
                    @input="
                        importForm.courses_file =
                            ($event.target as HTMLInputElement).files?.[0] ??
                            null
                    "
                />
                <InputError :message="importForm.errors.courses_file" />
                <Button
                    type="submit"
                    class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                    :disabled="
                        !importForm.courses_file || importForm.processing
                    "
                >
                    <Upload /> Import CSV
                </Button>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="addCourseOpen"
        @update:open="
            (open) => (open ? (addCourseOpen = true) : closeAddCourse())
        "
    >
        <DialogContent>
            <form class="space-y-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Add course</DialogTitle>
                    <DialogDescription>
                        Add a reusable course to the catalogue.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label>Level</Label>
                    <select
                        v-model="form.level"
                        class="h-10 rounded-md border px-3 text-sm"
                        required
                    >
                        <option value="" disabled>Select level</option>
                        <option
                            v-for="level in availableFilterLevels"
                            :key="level"
                            :value="level"
                        >
                            Level {{ level }}
                        </option>
                    </select>
                    <InputError :message="form.errors.level" />
                </div>
                <div class="grid gap-2">
                    <Label>Course code</Label
                    ><Input v-model="form.code" required />
                    <InputError :message="form.errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label>Course title</Label
                    ><Input v-model="form.title" required />
                    <InputError :message="form.errors.title" />
                </div>
                <div class="grid gap-2">
                    <Label>Course type</Label>
                    <select
                        v-model="form.course_type"
                        class="h-10 rounded-md border px-3 text-sm"
                    >
                        <option value="normal">Normal - one lecturer</option>
                        <option value="module">
                            Module - multiple lecturers
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label>Credit hours</Label>
                    <Input
                        v-model="form.credit_hours"
                        type="number"
                        min="1"
                        max="12"
                    />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="closeAddCourse"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                        :disabled="form.processing"
                    >
                        Submit course
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="editCourseOpen">
        <DialogContent>
            <form class="space-y-5" @submit.prevent="updateCourse">
                <DialogHeader>
                    <DialogTitle>Edit course</DialogTitle>
                    <DialogDescription>
                        Update this reusable catalogue course.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label>Level</Label>
                    <select
                        v-model="editForm.level"
                        class="h-10 rounded-md border px-3 text-sm"
                        required
                    >
                        <option value="" disabled>Select level</option>
                        <option
                            v-for="level in availableFilterLevels"
                            :key="level"
                            :value="level"
                        >
                            Level {{ level }}
                        </option>
                    </select>
                    <InputError :message="editForm.errors.level" />
                </div>
                <div class="grid gap-2">
                    <Label>Course code</Label
                    ><Input v-model="editForm.code" required />
                    <InputError :message="editForm.errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label>Course title</Label
                    ><Input v-model="editForm.title" required />
                </div>
                <div class="grid gap-2">
                    <Label>Course type</Label>
                    <select
                        v-model="editForm.course_type"
                        class="h-10 rounded-md border px-3 text-sm"
                    >
                        <option value="normal">Normal - one lecturer</option>
                        <option value="module">
                            Module - multiple lecturers
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label>Credit hours</Label>
                    <Input
                        v-model="editForm.credit_hours"
                        type="number"
                        min="1"
                        max="12"
                    />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="editCourseOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                        :disabled="editForm.processing"
                    >
                        Save changes
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
