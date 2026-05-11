<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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

defineProps<{
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

const submit = () => {
    form.post('/quality/departments', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Departments" />

    <div class="grid gap-8 xl:grid-cols-[1fr_360px]">
        <section class="space-y-6">
            <Heading
                variant="small"
                title="Departments"
                description="Departments sit under schools and scope HOD and lecturer access"
            />

            <div class="overflow-hidden rounded-lg border">
                <div
                    class="grid grid-cols-[1fr_1fr_1fr_90px_90px] gap-4 border-b bg-muted/50 px-4 py-3 text-sm font-medium"
                >
                    <span>Department</span>
                    <span>School</span>
                    <span>HOD</span>
                    <span>Users</span>
                    <span>Reports</span>
                </div>
                <div
                    v-for="department in departments"
                    :key="department.id"
                    class="grid grid-cols-[1fr_1fr_1fr_90px_90px] gap-4 border-b px-4 py-4 text-sm last:border-b-0"
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
                </div>
            </div>
        </section>

        <form class="space-y-4 rounded-lg border p-4" @submit.prevent="submit">
            <Heading variant="small" title="Add department" />

            <div class="grid gap-2">
                <Label for="school">School</Label>
                <select
                    id="school"
                    v-model="form.school_id"
                    class="h-9 rounded-md border bg-background px-3 text-sm"
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
                <Input id="name" v-model="form.name" required />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="code">Code</Label>
                <Input id="code" v-model="form.code" required />
                <InputError :message="form.errors.code" />
            </div>

            <div class="grid gap-2">
                <Label for="hod">Head of Department</Label>
                <select
                    id="hod"
                    v-model="form.hod_id"
                    class="h-9 rounded-md border bg-background px-3 text-sm"
                >
                    <option value="">Assign later</option>
                    <option v-for="hod in hods" :key="hod.id" :value="hod.id">
                        {{ hod.name }} - {{ hod.email }}
                    </option>
                </select>
                <InputError :message="form.errors.hod_id" />
            </div>

            <Button type="submit" :disabled="form.processing">
                <Plus /> Add department
            </Button>
        </form>
    </div>
</template>
