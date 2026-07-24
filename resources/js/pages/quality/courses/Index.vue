<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Download, Pencil, Plus, Trash2, Upload } from 'lucide-vue-next';
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
import { Label } from '@/components/ui/label';

type Department = {
    id: number;
    name: string;
    school: { id: number; name: string };
};
type Course = {
    id: number;
    code: string;
    title: string;
    course_type: 'normal' | 'module';
    level: string | null;
    credit_hours: number | null;
    department: Department;
};

const props = defineProps<{
    courses: Course[];
    departments: Department[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Courses', href: '/quality/courses' }],
    },
});

const form = useForm({
    department_id: '',
    code: '',
    title: '',
    course_type: 'normal',
    level: '',
    credit_hours: '',
});
const editForm = useForm({
    id: null as number | null,
    department_id: '',
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
const filteredCourses = computed(() =>
    levelFilter.value
        ? props.courses.filter((course) => course.level === levelFilter.value)
        : props.courses,
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
    editForm.department_id = String(course.department.id);
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
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <Heading
                    variant="small"
                    title="Courses"
                    description="Manage reusable catalogue courses that can be mounted for any program."
                />
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
            </div>
            <div class="rounded-xl border border-[#9fd8b8] bg-[#edf9f2] p-4">
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
            <div
                class="overflow-x-auto rounded-xl border border-[#9fd8b8] bg-white shadow-sm ring-1 ring-[#007a2f]/10"
            >
                <div
                    class="grid grid-cols-[1.2fr_1fr_1fr_110px_130px_auto] gap-4 border-b border-[#9fd8b8] bg-[#e8f6ee] px-4 py-3 text-sm font-bold text-[#063f22]"
                >
                    <span>Course</span><span>School & department</span
                    ><span>Availability</span><span>Type</span
                    ><span>Credit hours</span><span>Actions</span>
                </div>
                <div
                    v-for="course in filteredCourses"
                    :key="course.id"
                    class="grid grid-cols-[1.2fr_1fr_1fr_110px_130px_auto] items-center gap-4 border-b px-4 py-4 text-sm last:border-b-0"
                >
                    <div>
                        <div class="font-medium">
                            {{ course.code }} — {{ course.title }}
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
                    <span>Any program</span>
                    <span class="capitalize">{{ course.course_type }}</span>
                    <span>{{ course.credit_hours ?? 'Not set' }}</span>
                    <div class="flex justify-end gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="startEdit(course)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="removeCourse(course)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
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
                    Columns: department_code, code, title, course_type, level,
                    credit_hours. Use
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
                    <Label>Department</Label>
                    <select
                        v-model="form.department_id"
                        class="h-10 rounded-md border px-3 text-sm"
                        required
                    >
                        <option value="" disabled>Select department</option>
                        <option
                            v-for="department in props.departments"
                            :key="department.id"
                            :value="department.id"
                        >
                            {{ department.name }} — {{ department.school.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.department_id" />
                </div>
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
                    <Label>Department</Label>
                    <select
                        v-model="editForm.department_id"
                        class="h-10 rounded-md border px-3 text-sm"
                        required
                    >
                        <option
                            v-for="department in props.departments"
                            :key="department.id"
                            :value="department.id"
                        >
                            {{ department.name }} — {{ department.school.name }}
                        </option>
                    </select>
                </div>
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
