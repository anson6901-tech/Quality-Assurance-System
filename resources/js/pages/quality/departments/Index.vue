<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import BulkImportDialog from '@/components/BulkImportDialog.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SchoolOption } from '@/types';

type DepartmentRow = {
    id: number;
    name: string;
    code: string;
    school: { id: number; name: string };
    hod?: { id: number; name: string; email: string } | null;
    users_count: number;
    quality_reports_count: number;
};

const props = defineProps<{
    departments: DepartmentRow[];
    schools: SchoolOption[];
    hods: {
        id: number;
        name: string;
        email: string;
        school_id: number | null;
        department_id: number | null;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Departments',
                href: '/quality/departments',
            },
        ],
    },
});

const form = useForm({
    school_id: '',
    name: '',
    code: '',
    hod_id: '',
});
const editForm = useForm({
    school_id: '',
    name: '',
    code: '',
    hod_id: '',
});
const editingDepartment = ref<DepartmentRow | null>(null);
const editOpen = ref(false);
const addOpen = ref(false);

const submit = () => {
    form.post('/quality/departments', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            addOpen.value = false;
        },
    });
};
const startEdit = (department: DepartmentRow) => {
    editingDepartment.value = department;
    editForm.school_id = String(department.school.id);
    editForm.name = department.name;
    editForm.code = department.code;
    editForm.hod_id = department.hod ? String(department.hod.id) : '';
    editForm.clearErrors();
    editOpen.value = true;
};
const updateDepartment = () => {
    if (!editingDepartment.value) {
        return;
    }

    editForm.patch(`/quality/departments/${editingDepartment.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
            editingDepartment.value = null;
        },
    });
};
const deleteDepartment = (department: DepartmentRow) => {
    if (
        window.confirm(
            `Delete ${department.name}? Its programmes and courses will also be deleted. User accounts and reports will be kept but unassigned from this department.`,
        )
    ) {
        router.delete(`/quality/departments/${department.id}`, {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head title="Departments" />

    <div class="space-y-8">
        <section class="space-y-6">
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
            >
                <Heading
                    variant="small"
                    title="Departments"
                    description="Departments sit under schools and scope HOD and lecturer access"
                />
                <div class="flex flex-wrap gap-2">
                    <BulkImportDialog
                        title="Bulk add departments"
                        description="The template includes valid school codes for reliable department links."
                        template-url="/quality/bulk/departments/template"
                        import-url="/quality/bulk/departments"
                        columns="school_code, name, code"
                    />
                    <Button
                        type="button"
                        class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                        @click="addOpen = true"
                    >
                        <Plus /> Add department
                    </Button>
                </div>
            </div>

            <div
                class="overflow-hidden rounded-xl border border-[#9fd8b8] bg-white shadow-sm ring-1 ring-[#007a2f]/10"
            >
                <div
                    class="grid grid-cols-[1fr_1fr_1fr_70px_70px_100px] gap-4 border-b border-[#9fd8b8] bg-[#e8f6ee] px-4 py-3 text-sm font-bold text-[#063f22]"
                >
                    <span>Department</span>
                    <span>School</span>
                    <span>HOD</span>
                    <span>Users</span>
                    <span>Reports</span>
                    <span>Actions</span>
                </div>
                <div
                    v-for="department in departments"
                    :key="department.id"
                    class="grid grid-cols-[1fr_1fr_1fr_70px_70px_100px] items-center gap-4 border-b px-4 py-4 text-sm last:border-b-0"
                >
                    <div>
                        <div class="font-medium">{{ department.name }}</div>
                        <div class="text-muted-foreground">
                            {{ department.code }}
                        </div>
                    </div>
                    <span>{{ department.school.name }}</span>
                    <div>
                        <div class="font-medium">
                            {{ department.hod?.name ?? 'Not assigned' }}
                        </div>
                        <div class="text-muted-foreground">
                            {{ department.hod?.email }}
                        </div>
                    </div>
                    <span>{{ department.users_count }}</span>
                    <span>{{ department.quality_reports_count }}</span>
                    <div class="flex justify-end gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            title="Edit department"
                            @click="startEdit(department)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            title="Delete department"
                            class="text-destructive hover:text-destructive"
                            @click="deleteDepartment(department)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <Dialog v-model:open="addOpen">
        <DialogContent class="sm:max-w-md">
            <form class="space-y-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Add department</DialogTitle>
                    <DialogDescription
                        >Create a department under a school.</DialogDescription
                    >
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="school">School</Label>
                    <select
                        id="school"
                        v-model="form.school_id"
                        class="h-9 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                        required
                    >
                        <option value="" disabled>Select school</option>
                        <option
                            v-for="school in schools"
                            :key="school.id"
                            :value="school.id"
                        >
                            {{ school.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.school_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                        required
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="code">Code</Label>
                    <Input
                        id="code"
                        v-model="form.code"
                        class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                        required
                    />
                    <InputError :message="form.errors.code" />
                </div>

                <div class="grid gap-2">
                    <Label for="hod">Head of Department</Label>
                    <select
                        id="hod"
                        v-model="form.hod_id"
                        class="h-9 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                    >
                        <option value="">Assign later</option>
                        <option
                            v-for="hod in hods"
                            :key="hod.id"
                            :value="hod.id"
                        >
                            {{ hod.name }} - {{ hod.email }}
                        </option>
                    </select>
                    <InputError :message="form.errors.hod_id" />
                </div>

                <div class="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="addOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                        :disabled="form.processing"
                    >
                        <Plus /> Add department
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="editOpen">
        <DialogContent class="sm:max-w-md">
            <form class="space-y-4" @submit.prevent="updateDepartment">
                <DialogHeader>
                    <DialogTitle>Edit department</DialogTitle>
                    <DialogDescription>
                        Update its school, name, code, or head of department.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="edit-department-school">School</Label>
                    <select
                        id="edit-department-school"
                        v-model="editForm.school_id"
                        class="h-9 rounded-md border px-3 text-sm"
                        required
                    >
                        <option
                            v-for="school in props.schools"
                            :key="school.id"
                            :value="school.id"
                        >
                            {{ school.name }}
                        </option>
                    </select>
                    <InputError :message="editForm.errors.school_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="edit-department-name">Name</Label>
                    <Input
                        id="edit-department-name"
                        v-model="editForm.name"
                        required
                    />
                    <InputError :message="editForm.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="edit-department-code">Code</Label>
                    <Input
                        id="edit-department-code"
                        v-model="editForm.code"
                        required
                    />
                    <InputError :message="editForm.errors.code" />
                </div>

                <div class="grid gap-2">
                    <Label for="edit-department-hod">
                        Head of Department
                    </Label>
                    <select
                        id="edit-department-hod"
                        v-model="editForm.hod_id"
                        class="h-9 rounded-md border px-3 text-sm"
                    >
                        <option value="">Not assigned</option>
                        <option
                            v-for="hod in props.hods"
                            :key="hod.id"
                            :value="hod.id"
                        >
                            {{ hod.name }} - {{ hod.email }}
                        </option>
                    </select>
                    <InputError :message="editForm.errors.hod_id" />
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="editOpen = false"
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
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
