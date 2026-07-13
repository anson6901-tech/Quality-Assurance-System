<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Upload } from 'lucide-vue-next';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Department = { id: number; name: string; school: { id: number; name: string } };
type Course = { id: number; code: string; title: string; course_type: 'normal' | 'module'; level: string | null; credit_hours: number | null; department: Department };

defineProps<{ courses: Course[]; departments: Department[] }>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Courses', href: '/quality/courses' }] } });

const form = useForm({ department_id: '', code: '', title: '', course_type: 'normal', credit_hours: '' });
const editForm = useForm({ id: null as number | null, department_id: '', code: '', title: '', course_type: 'normal', credit_hours: '' });
const importForm = useForm({ courses_file: null as File | null });
const addCourseOpen = ref(false);
const editCourseOpen = ref(false);
const closeAddCourse = () => {
    addCourseOpen.value = false;
    form.reset();
    form.clearErrors();
};
const submit = () => form.post('/quality/courses', { preserveScroll: true, onSuccess: closeAddCourse });
const startEdit = (course: Course) => {
    editForm.id = course.id;
    editForm.department_id = String(course.department.id);
    editForm.code = course.code;
    editForm.title = course.title;
    editForm.course_type = course.course_type;
    editForm.credit_hours = course.credit_hours ? String(course.credit_hours) : '';
    editCourseOpen.value = true;
};
const updateCourse = () => editForm.id && editForm.patch(`/quality/courses/${editForm.id}`, { preserveScroll: true, onSuccess: () => {
    editCourseOpen.value = false;
    editForm.reset();
} });
const importCourses = () => importForm.post('/quality/courses/import', { forceFormData: true, preserveScroll: true, onSuccess: () => importForm.reset() });
const removeCourse = (course: Course) => router.delete(`/quality/courses/${course.id}`, { preserveScroll: true });
</script>

<template>
    <Head title="Courses" />
    <div class="grid gap-8 xl:grid-cols-[1fr_400px]">
        <section class="space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><Heading variant="small" title="Courses" description="Manage the course catalogue, school, and department links." /><Button type="button" class="bg-[#007a2f] text-white hover:bg-[#006d2b]" @click="addCourseOpen = true"><Plus /> Add course</Button></div>
            <div class="overflow-hidden rounded-xl border border-[#9fd8b8] bg-white shadow-sm ring-1 ring-[#007a2f]/10"><div class="grid grid-cols-[1.2fr_1fr_110px_130px_auto] gap-4 border-b border-[#9fd8b8] bg-[#e8f6ee] px-4 py-3 text-sm font-bold text-[#063f22]"><span>Course</span><span>School & department</span><span>Type</span><span>Credit hours</span><span>Actions</span></div><div v-for="course in courses" :key="course.id" class="grid grid-cols-[1.2fr_1fr_110px_130px_auto] items-center gap-4 border-b px-4 py-4 text-sm last:border-b-0"><div><div class="font-medium">{{ course.code }} — {{ course.title }}</div><div class="text-muted-foreground">{{ course.level ?? 'Level not set' }}</div></div><div><div>{{ course.department.school.name }}</div><div class="text-muted-foreground">{{ course.department.name }}</div></div><span class="capitalize">{{ course.course_type }}</span><span>{{ course.credit_hours ?? 'Not set' }}</span><div class="flex justify-end gap-1"><Button type="button" variant="ghost" size="sm" @click="startEdit(course)"><Pencil class="h-4 w-4" /></Button><Button type="button" variant="ghost" size="sm" @click="removeCourse(course)"><Trash2 class="h-4 w-4" /></Button></div></div><p v-if="courses.length === 0" class="px-4 py-10 text-center text-sm text-muted-foreground">No courses have been added.</p></div>
        </section>
        <form class="space-y-4 rounded-xl border border-[#9fd8b8] bg-[#eefaf3] p-6 shadow-sm ring-1 ring-[#007a2f]/10" @submit.prevent="importCourses"><Heading variant="small" title="Import courses" description="CSV columns: department_code, code, title, credit_hours" /><Input type="file" accept=".csv,text/csv" required @input="importForm.courses_file = ($event.target as HTMLInputElement).files?.[0] ?? null" /><InputError :message="importForm.errors.courses_file" /><Button type="submit" class="bg-[#007a2f] text-white hover:bg-[#006d2b]" :disabled="importForm.processing"><Upload /> Import CSV</Button></form>
    </div>
    <Dialog :open="addCourseOpen" @update:open="(open) => open ? addCourseOpen = true : closeAddCourse()"><DialogContent><form class="space-y-5" @submit.prevent="submit"><DialogHeader><DialogTitle>Add course</DialogTitle><DialogDescription>Create a course linked to its school and department.</DialogDescription></DialogHeader><div class="grid gap-2"><Label>Department</Label><select v-model="form.department_id" class="h-9 rounded-md border px-3 text-sm" required><option value="" disabled>Select department</option><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }} — {{ department.school.name }}</option></select><InputError :message="form.errors.department_id" /></div><div class="grid gap-2"><Label>Course code</Label><Input v-model="form.code" required /><InputError :message="form.errors.code" /></div><div class="grid gap-2"><Label>Course title</Label><Input v-model="form.title" required /><InputError :message="form.errors.title" /></div><div class="grid gap-2"><Label>Course type</Label><select v-model="form.course_type" class="h-9 rounded-md border px-3 text-sm"><option value="normal">Normal - one lecturer</option><option value="module">Module - multiple lecturers</option></select><InputError :message="form.errors.course_type" /></div><div class="grid gap-2"><Label>Credit hours</Label><Input v-model="form.credit_hours" type="number" min="1" max="12" /><InputError :message="form.errors.credit_hours" /></div><DialogFooter><Button type="button" variant="secondary" @click="closeAddCourse">Cancel</Button><Button type="submit" class="bg-[#007a2f] text-white hover:bg-[#006d2b]" :disabled="form.processing">Submit course</Button></DialogFooter></form></DialogContent></Dialog>
    <Dialog :open="editCourseOpen" @update:open="editCourseOpen = $event"><DialogContent><form class="space-y-5" @submit.prevent="updateCourse"><DialogHeader><DialogTitle>Edit course</DialogTitle><DialogDescription>Update the course and its department.</DialogDescription></DialogHeader><div class="grid gap-2"><Label>Department</Label><select v-model="editForm.department_id" class="h-9 rounded-md border px-3 text-sm" required><option v-for="department in departments" :key="department.id" :value="department.id">{{ department.name }} — {{ department.school.name }}</option></select><InputError :message="editForm.errors.department_id" /></div><div class="grid gap-2"><Label>Course code</Label><Input v-model="editForm.code" required /><InputError :message="editForm.errors.code" /></div><div class="grid gap-2"><Label>Course title</Label><Input v-model="editForm.title" required /><InputError :message="editForm.errors.title" /></div><div class="grid gap-2"><Label>Course type</Label><select v-model="editForm.course_type" class="h-9 rounded-md border px-3 text-sm"><option value="normal">Normal - one lecturer</option><option value="module">Module - multiple lecturers</option></select><InputError :message="editForm.errors.course_type" /></div><div class="grid gap-2"><Label>Credit hours</Label><Input v-model="editForm.credit_hours" type="number" min="1" max="12" /><InputError :message="editForm.errors.credit_hours" /></div><DialogFooter><Button type="button" variant="secondary" @click="editCourseOpen = false">Cancel</Button><Button type="submit" class="bg-[#007a2f] text-white hover:bg-[#006d2b]" :disabled="editForm.processing">Save changes</Button></DialogFooter></form></DialogContent></Dialog>
</template>
