<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Archive, Pencil, Plus, RotateCcw } from 'lucide-vue-next';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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

type AcademicYear = {
    id: number;
    name: string;
    starts_on: string | null;
    ends_on: string | null;
    status: 'active' | 'archived';
    quality_reports_count: number;
};

defineProps<{
    academicYears: AcademicYear[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Academic Years',
                href: '/quality/academic-years',
            },
        ],
    },
});

const form = useForm({
    name: '',
    starts_on: '',
    ends_on: '',
    status: 'active',
});

const editForm = useForm({
    id: null as number | null,
    name: '',
    starts_on: '',
    ends_on: '',
    status: 'active',
});

const addOpen = ref(false);
const editOpen = ref(false);

const closeAdd = () => {
    addOpen.value = false;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    form.post('/quality/academic-years', {
        preserveScroll: true,
        onSuccess: () => closeAdd(),
    });
};

const startEdit = (academicYear: AcademicYear) => {
    editForm.id = academicYear.id;
    editForm.name = academicYear.name;
    editForm.starts_on = academicYear.starts_on ?? '';
    editForm.ends_on = academicYear.ends_on ?? '';
    editForm.status = academicYear.status;
    editOpen.value = true;
};

const closeEdit = () => {
    editOpen.value = false;
    editForm.reset();
    editForm.clearErrors();
};

const update = () => {
    if (!editForm.id) {
        return;
    }

    editForm.patch(`/quality/academic-years/${editForm.id}`, {
        preserveScroll: true,
        onSuccess: () => closeEdit(),
    });
};

const setStatus = (academicYear: AcademicYear, status: 'active' | 'archived') => {
    router.patch(
        `/quality/academic-years/${academicYear.id}`,
        {
            name: academicYear.name,
            starts_on: academicYear.starts_on,
            ends_on: academicYear.ends_on,
            status,
        },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Academic Years" />

    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <Heading
                variant="small"
                title="Academic Years"
                description="Create years for report uploads and archive old academic cycles"
            />

            <Button type="button" @click="addOpen = true">
                <Plus /> Add academic year
            </Button>
        </div>

        <div class="overflow-hidden rounded-lg border bg-white">
            <div
                class="grid grid-cols-[1fr_1fr_140px_160px] gap-4 border-b bg-muted/50 px-4 py-3 text-sm font-medium"
            >
                <span>Academic year</span>
                <span>Dates</span>
                <span>Status</span>
                <span>Actions</span>
            </div>

            <div
                v-for="academicYear in academicYears"
                :key="academicYear.id"
                class="grid grid-cols-[1fr_1fr_140px_160px] items-center gap-4 border-b px-4 py-4 text-sm last:border-b-0"
            >
                <div>
                    <div class="font-medium">{{ academicYear.name }}</div>
                    <div class="text-muted-foreground">
                        {{ academicYear.quality_reports_count }} reports
                    </div>
                </div>
                <div>
                    {{
                        academicYear.starts_on && academicYear.ends_on
                            ? `${academicYear.starts_on} to ${academicYear.ends_on}`
                            : 'Dates not set'
                    }}
                </div>
                <div>
                    <Badge variant="secondary">{{ academicYear.status }}</Badge>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="startEdit(academicYear)"
                    >
                        <Pencil class="h-4 w-4" />
                    </Button>
                    <Button
                        v-if="academicYear.status === 'active'"
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="setStatus(academicYear, 'archived')"
                    >
                        <Archive class="h-4 w-4" />
                    </Button>
                    <Button
                        v-else
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="setStatus(academicYear, 'active')"
                    >
                        <RotateCcw class="h-4 w-4" />
                    </Button>
                </div>
            </div>
        </div>
    </div>

    <Dialog :open="addOpen" @update:open="addOpen = $event">
        <DialogContent>
            <form class="space-y-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Add academic year</DialogTitle>
                    <DialogDescription>
                        Active years appear in the report upload form.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input id="name" v-model="form.name" placeholder="2025/2026" required />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="starts-on">Starts on</Label>
                        <Input id="starts-on" v-model="form.starts_on" type="date" />
                        <InputError :message="form.errors.starts_on" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ends-on">Ends on</Label>
                        <Input id="ends-on" v-model="form.ends_on" type="date" />
                        <InputError :message="form.errors.ends_on" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="status">Status</Label>
                    <select
                        id="status"
                        v-model="form.status"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="active">Active</option>
                        <option value="archived">Archived</option>
                    </select>
                    <InputError :message="form.errors.status" />
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="secondary" @click="closeAdd">
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        Save year
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="editOpen" @update:open="editOpen = $event">
        <DialogContent>
            <form class="space-y-5" @submit.prevent="update">
                <DialogHeader>
                    <DialogTitle>Edit academic year</DialogTitle>
                    <DialogDescription>
                        Archive a year when it should no longer be used for new uploads.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="edit-name">Name</Label>
                    <Input id="edit-name" v-model="editForm.name" required />
                    <InputError :message="editForm.errors.name" />
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="edit-starts-on">Starts on</Label>
                        <Input id="edit-starts-on" v-model="editForm.starts_on" type="date" />
                        <InputError :message="editForm.errors.starts_on" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="edit-ends-on">Ends on</Label>
                        <Input id="edit-ends-on" v-model="editForm.ends_on" type="date" />
                        <InputError :message="editForm.errors.ends_on" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="edit-status">Status</Label>
                    <select
                        id="edit-status"
                        v-model="editForm.status"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="active">Active</option>
                        <option value="archived">Archived</option>
                    </select>
                    <InputError :message="editForm.errors.status" />
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="secondary" @click="closeEdit">
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
