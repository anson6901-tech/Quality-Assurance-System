<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import BulkImportDialog from '@/components/BulkImportDialog.vue';
import InputError from '@/components/InputError.vue';
import PageBanner from '@/components/PageBanner.vue';
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

type SchoolRow = {
    id: number;
    name: string;
    code: string;
    dean?: { id: number; name: string; email: string } | null;
    departments_count: number;
    users_count: number;
    quality_reports_count: number;
};

defineProps<{
    schools: SchoolRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Schools',
                href: '/quality/schools',
            },
        ],
    },
});

const form = useForm({
    name: '',
    code: '',
});
const editForm = useForm({
    name: '',
    code: '',
});
const editingSchool = ref<SchoolRow | null>(null);
const editOpen = ref(false);
const addOpen = ref(false);

const submit = () => {
    form.post('/quality/schools', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            addOpen.value = false;
        },
    });
};
const startEdit = (school: SchoolRow) => {
    editingSchool.value = school;
    editForm.name = school.name;
    editForm.code = school.code;
    editForm.clearErrors();
    editOpen.value = true;
};
const updateSchool = () => {
    if (!editingSchool.value) {
        return;
    }

    editForm.patch(`/quality/schools/${editingSchool.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
            editingSchool.value = null;
        },
    });
};
const deleteSchool = (school: SchoolRow) => {
    const impact = [
        `${school.departments_count} department(s)`,
        `${school.quality_reports_count} report(s)`,
    ].join(' and ');

    if (
        window.confirm(
            `Delete ${school.name}? This will also delete ${impact}. User accounts will be kept and unassigned from the school.`,
        )
    ) {
        router.delete(`/quality/schools/${school.id}`, {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head title="Schools" />

    <div class="space-y-8">
        <section class="space-y-6">
            <PageBanner
                title="Schools"
                description="University schools. Deans are placed on the Organizational Structure."
            >
                <template #actions>
                    <div class="flex flex-wrap gap-2">
                        <BulkImportDialog
                            title="Bulk add schools"
                            description="Download the exact school template before importing."
                            template-url="/quality/bulk/schools/template"
                            import-url="/quality/bulk/schools"
                            columns="name, code"
                        />
                        <Button
                            type="button"
                            class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                            @click="addOpen = true"
                        >
                            <Plus /> Add school
                        </Button>
                    </div>
                </template>
            </PageBanner>

            <div
                class="overflow-hidden rounded-xl border border-[#9fd8b8] bg-white shadow-sm ring-1 ring-[#007a2f]/10"
            >
                <div
                    class="grid grid-cols-[1fr_1fr_90px_70px_70px_100px] gap-4 border-b border-[#9fd8b8] bg-[#e8f6ee] px-4 py-3 text-sm font-bold text-[#063f22]"
                >
                    <span>School</span>
                    <span>Dean</span>
                    <span>Departments</span>
                    <span>Users</span>
                    <span>Reports</span>
                    <span>Actions</span>
                </div>
                <div
                    v-for="school in schools"
                    :key="school.id"
                    class="grid grid-cols-[1fr_1fr_90px_70px_70px_100px] items-center gap-4 border-b px-4 py-4 text-sm last:border-b-0"
                >
                    <div>
                        <div class="font-medium">{{ school.name }}</div>
                        <div class="text-muted-foreground">
                            {{ school.code }}
                        </div>
                    </div>
                    <div>
                        <div class="font-medium">
                            {{
                                school.dean?.name ??
                                'Set on Organizational Structure'
                            }}
                        </div>
                        <div class="text-muted-foreground">
                            {{ school.dean?.email }}
                        </div>
                    </div>
                    <span>{{ school.departments_count }}</span>
                    <span>{{ school.users_count }}</span>
                    <span>{{ school.quality_reports_count }}</span>
                    <div class="flex justify-end gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            title="Edit school"
                            @click="startEdit(school)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            title="Delete school"
                            class="text-destructive hover:text-destructive"
                            @click="deleteSchool(school)"
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
                    <DialogTitle>Add school</DialogTitle>
                    <DialogDescription
                        >Create a university school.</DialogDescription
                    >
                </DialogHeader>

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
                        <Plus /> Add school
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="editOpen">
        <DialogContent class="sm:max-w-md">
            <form class="space-y-4" @submit.prevent="updateSchool">
                <DialogHeader>
                    <DialogTitle>Edit school</DialogTitle>
                    <DialogDescription>
                        Update the school name or code. Deans are placed on the
                        Organizational Structure.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="edit-school-name">Name</Label>
                    <Input
                        id="edit-school-name"
                        v-model="editForm.name"
                        required
                    />
                    <InputError :message="editForm.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="edit-school-code">Code</Label>
                    <Input
                        id="edit-school-code"
                        v-model="editForm.code"
                        required
                    />
                    <InputError :message="editForm.errors.code" />
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
