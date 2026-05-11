<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Upload } from 'lucide-vue-next';
import { ref } from 'vue';
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
    level: string | null;
    credit_hours: number | null;
    department: Department;
};

defineProps<{
    courses: Course[];
    departments: Department[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Courses',
                href: '/quality/courses',
            },
        ],
    },
});

const form = useForm({
    department_id: '',
    code: '',
    title: '',
    credit_hours: '',
});

const editForm = useForm({
    id: null as number | null,
    department_id: '',
    code: '',
    title: '',
    credit_hours: '',
});

const importForm = useForm({
    courses_file: null as File | null,
});

const addCourseOpen = ref(false);
const editCourseOpen = ref(false);

const closeAddCourse = () => {
    addCourseOpen.value = false;
    form.reset();
    form.clearErrors();
};

const handleAddCourseOpen = (open: boolean) => {
    addCourseOpen.value = open;

    if (!open) {
        form.reset();
        form.clearErrors();
    }
};

const submit = () => {
    form.post('/quality/courses', {
        preserveScroll: true,
        onSuccess: () => closeAddCourse(),
    });
};

const startEdit = (course: Course) => {
    editForm.id = course.id;
    editForm.department_id = String(course.department.id);
    editForm.code = course.code;
    editForm.title = course.title;
    editForm.credit_hours = course.credit_hours
        ? String(course.credit_hours)
        : '';
    editCourseOpen.value = true;
};

const cancelEdit = () => {
    editCourseOpen.value = false;
    editForm.reset();
    editForm.clearErrors();
};

const handleEditCourseOpen = (open: boolean) => {
    editCourseOpen.value = open;

    if (!open) {
        editForm.reset();
        editForm.clearErrors();
    }
};

const updateCourse = () => {
    if (!editForm.id) {
        return;
    }

    editForm.patch(`/quality/courses/${editForm.id}`, {
        preserveScroll: true,
        onSuccess: () => cancelEdit(),
    });
};

const importCourses = () => {
    importForm.post('/quality/courses/import', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => importForm.reset(),
    });
};

const removeCourse = (course: Course) => {
    router.delete(`/quality/courses/${course.id}`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Courses" />

    <div class="grid gap-8 xl:grid-cols-[1fr_420px]">
        <section class="space-y-6">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <Heading
                    variant="small"
                    title="Courses"
                    description="Course catalogue with manual entry, CSV import, and removal"
                />

                <Button type="button" @click="addCourseOpen = true">
                    <Plus /> Add course
                </Button>
            </div>

            <div class="overflow-hidden rounded-lg border bg-white">
                <div
                    class="grid grid-cols-[1fr_1fr_120px_auto] gap-4 border-b bg-muted/50 px-4 py-3 text-sm font-medium"
                >
                    <span>Course</span>
                    <span>Department</span>
                    <span>Credit hours</span>
                    <span>Actions</span>
                </div>

                <div
                    v-for="course in courses"
                    :key="course.id"
                    class="grid grid-cols-[1fr_1fr_120px_auto] items-center gap-4 border-b px-4 py-4 text-sm last:border-b-0"
                >
                    <div>
                        <div class="font-medium">
                            {{ course.code }} - {{ course.title }}
                        </div>
                    </div>
                    <div>
                        <div>{{ course.department.name }}</div>
                        <div class="text-muted-foreground">
                            {{ course.department.school.name }}
                        </div>
                    </div>
                    <span>{{ course.credit_hours ?? 'Not set' }}</span>
                    <div class="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="startEdit(course)"
                        >
                            <Pencil class="h-4 w-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="removeCourse(course)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <div class="space-y-6">
            <form
                class="space-y-4 rounded-lg border bg-white p-4"
                @submit.prevent="importCourses"
            >
                <Heading
                    variant="small"
                    title="Import courses"
                    description="CSV columns: department_code, code, title, credit_hours"
                />

                <div class="grid gap-2">
                    <Label for="courses-file">CSV file</Label>
                    <Input
                        id="courses-file"
                        type="file"
                        accept=".csv,text/csv"
                        required
                        @input="
                            importForm.courses_file =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                    <InputError :message="importForm.errors.courses_file" />
                </div>

                <p class="text-sm text-muted-foreground">
                    Department can be provided as
                    <span class="font-medium">department_code</span>,
                    <span class="font-medium">department</span>, or
                    <span class="font-medium">department_name</span>.
                </p>

                <Button type="submit" :disabled="importForm.processing">
                    <Upload /> Import CSV
                </Button>
            </form>
        </div>
    </div>

    <Dialog :open="addCourseOpen" @update:open="handleAddCourseOpen">
        <DialogContent>
            <form class="space-y-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Add course</DialogTitle>
                    <DialogDescription>
                        Create a course and connect it to its department.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="department">Department</Label>
                    <select
                        id="department"
                        v-model="form.department_id"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                        required
                    >
                        <option value="" disabled>Select department</option>
                        <option
                            v-for="department in departments"
                            :key="department.id"
                            :value="department.id"
                        >
                            {{ department.name }} - {{ department.school.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.department_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="code">Course code</Label>
                    <Input id="code" v-model="form.code" required />
                    <InputError :message="form.errors.code" />
                </div>

                <div class="grid gap-2">
                    <Label for="title">Course title</Label>
                    <Input id="title" v-model="form.title" required />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="grid gap-2">
                    <Label for="credit-hours">Credit hours</Label>
                    <Input
                        id="credit-hours"
                        v-model="form.credit_hours"
                        type="number"
                        min="1"
                        max="12"
                    />
                    <InputError :message="form.errors.credit_hours" />
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="closeAddCourse"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        Submit course
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="editCourseOpen" @update:open="handleEditCourseOpen">
        <DialogContent>
            <form class="space-y-5" @submit.prevent="updateCourse">
                <DialogHeader>
                    <DialogTitle>Edit course</DialogTitle>
                    <DialogDescription>
                        Update the course details and department.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="edit-department">Department</Label>
                    <select
                        id="edit-department"
                        v-model="editForm.department_id"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                        required
                    >
                        <option
                            v-for="department in departments"
                            :key="department.id"
                            :value="department.id"
                        >
                            {{ department.name }} - {{ department.school.name }}
                        </option>
                    </select>
                    <InputError :message="editForm.errors.department_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="edit-code">Course code</Label>
                    <Input id="edit-code" v-model="editForm.code" required />
                    <InputError :message="editForm.errors.code" />
                </div>

                <div class="grid gap-2">
                    <Label for="edit-title">Course title</Label>
                    <Input id="edit-title" v-model="editForm.title" required />
                    <InputError :message="editForm.errors.title" />
                </div>

                <div class="grid gap-2">
                    <Label for="edit-credit-hours">Credit hours</Label>
                    <Input
                        id="edit-credit-hours"
                        v-model="editForm.credit_hours"
                        type="number"
                        min="1"
                        max="12"
                    />
                    <InputError :message="editForm.errors.credit_hours" />
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="cancelEdit"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="editForm.processing">
                        Save changes
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
