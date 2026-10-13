<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
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

type Department = {
    id: number;
    name: string;
    school: { id: number; name: string };
};
type Program = {
    id: number;
    name: string;
    code: string;
    max_level: number;
    courses_count: number;
    department: Department;
    sections: Array<{ id: number; name: string }>;
    section_keys: string[];
};

const props = defineProps<{
    programs: Program[];
    departments: Department[];
    sectionOptions: Array<{ value: string; label: string }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Programs', href: '/quality/programs' }],
    },
});

const dialogOpen = ref(false);
const editing = ref<Program | null>(null);
const form = useForm({
    department_id: '',
    name: '',
    code: '',
    max_level: '400',
    sections: [] as string[],
});
const title = computed(() => (editing.value ? 'Edit program' : 'Add program'));

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
};

const openEdit = (program: Program) => {
    editing.value = program;
    form.department_id = String(program.department.id);
    form.name = program.name;
    form.code = program.code;
    form.max_level = String(program.max_level);
    form.sections = [...program.section_keys];
    form.clearErrors();
    dialogOpen.value = true;
};

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
        },
    };

    if (editing.value) {
        form.patch(`/quality/programs/${editing.value.id}`, options);
    } else {
        form.post('/quality/programs', options);
    }
};

const toggleSection = (value: string, checked: boolean) => {
    form.sections = checked
        ? [...new Set([...form.sections, value])]
        : form.sections.filter((section) => section !== value);
};

const remove = (program: Program) => {
    if (window.confirm(`Delete ${program.name}?`)) {
        router.delete(`/quality/programs/${program.id}`, {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head title="Programs" />

    <div class="space-y-6">
        <PageBanner
            title="Programs"
            description="Manage programs under departments and connect their curriculum courses"
        >
            <template #actions>
                <div class="flex flex-wrap gap-2">
                    <BulkImportDialog
                        title="Bulk add programs"
                        description="Use the generated school and department codes to avoid invalid program links."
                        template-url="/quality/bulk/programs/template"
                        import-url="/quality/bulk/programs"
                        columns="school_code, department_code, name, code, max_level"
                    />
                    <Button
                        type="button"
                        class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                        @click="openCreate"
                    >
                        <Plus /> Add program
                    </Button>
                </div>
            </template>
        </PageBanner>

        <div
            class="overflow-hidden rounded-xl border border-[#9fd8b8] bg-white shadow-sm ring-1 ring-[#007a2f]/10"
        >
            <div
                class="grid min-w-[760px] grid-cols-[1fr_1fr_1.2fr_90px_80px_100px] gap-4 border-b border-[#9fd8b8] bg-[#e8f6ee] px-4 py-3 text-sm font-bold text-[#063f22]"
            >
                <span>Program</span><span>Department</span><span>Sections</span
                ><span>Levels</span><span>Courses</span><span>Actions</span>
            </div>
            <div
                v-for="program in props.programs"
                :key="program.id"
                class="grid min-w-[760px] grid-cols-[1fr_1fr_1.2fr_90px_80px_100px] items-center gap-4 border-b px-4 py-4 text-sm last:border-b-0"
            >
                <div>
                    <div class="font-medium">{{ program.name }}</div>
                    <div class="text-muted-foreground">{{ program.code }}</div>
                </div>
                <div>
                    <div>{{ program.department.name }}</div>
                    <div class="text-muted-foreground">
                        {{ program.department.school.name }}
                    </div>
                </div>
                <div class="flex flex-wrap gap-1">
                    <span
                        v-for="section in program.sections"
                        :key="section.id"
                        class="rounded-full border border-[#c8d7de] bg-[#f5f8fa] px-2 py-0.5 text-xs text-[#1d2f37]"
                    >
                        {{ section.name }}
                    </span>
                    <span
                        v-if="program.sections.length === 0"
                        class="text-xs text-muted-foreground"
                    >
                        No sections
                    </span>
                </div>
                <span>100–{{ program.max_level }}</span>
                <span>{{ program.courses_count }}</span>
                <div class="flex gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        @click="openEdit(program)"
                    >
                        <Pencil class="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="text-destructive"
                        @click="remove(program)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </div>
            <p
                v-if="props.programs.length === 0"
                class="px-4 py-10 text-center text-sm text-muted-foreground"
            >
                No programs have been added.
            </p>
        </div>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent
            class="max-h-[calc(100svh-2rem)] w-[calc(100vw-2rem)] max-w-md overflow-x-hidden overflow-y-auto"
        >
            <form class="min-w-0 space-y-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>
                        Link the program to the department that owns it.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="program-department">Department</Label>
                    <select
                        id="program-department"
                        v-model="form.department_id"
                        class="h-10 w-full min-w-0 rounded-md border px-3 text-sm"
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
                    <Label for="program-name">Program name</Label>
                    <Input id="program-name" v-model="form.name" required />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="program-code">Program code</Label>
                    <Input id="program-code" v-model="form.code" required />
                    <InputError :message="form.errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label for="program-max-level">Highest level</Label>
                    <Input
                        id="program-max-level"
                        v-model="form.max_level"
                        type="number"
                        min="100"
                        max="5000"
                        step="100"
                        required
                    />
                    <p class="text-xs text-muted-foreground">
                        Courses can use levels 100 through the selected level.
                    </p>
                    <InputError :message="form.errors.max_level" />
                </div>
                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Sections</legend>
                    <div class="grid grid-cols-2 gap-2">
                        <label
                            v-for="option in props.sectionOptions"
                            :key="option.value"
                            class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                class="h-4 w-4 accent-[#007a2f]"
                                :checked="form.sections.includes(option.value)"
                                @change="
                                    toggleSection(
                                        option.value,
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                            {{ option.label }}
                        </label>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Courses in this program can be split and assigned by
                        section.
                    </p>
                    <InputError :message="form.errors.sections" />
                </fieldset>
                <div class="grid grid-cols-2 gap-2 pt-2 sm:flex sm:justify-end">
                    <Button
                        type="button"
                        variant="secondary"
                        class="w-full sm:w-auto"
                        @click="dialogOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        class="w-full bg-[#007a2f] text-white hover:bg-[#006d2b] sm:w-auto"
                        :disabled="form.processing"
                    >
                        Save program
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
