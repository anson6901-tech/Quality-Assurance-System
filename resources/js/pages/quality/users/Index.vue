<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type UserRow = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    roleLabel: string | null;
    school: { id: number; name: string } | null;
    department: { id: number; name: string } | null;
};

type RoleOption = {
    value: string;
    label: string;
};

type DepartmentOption = {
    id: number;
    name: string;
    school: { id: number; name: string };
};

defineProps<{
    users: UserRow[];
    roles: RoleOption[];
    departments: DepartmentOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Users',
                href: '/quality/users',
            },
        ],
    },
});

const form = useForm({
    name: '',
    email: '',
    university_role: 'lecturer',
    department_id: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post('/quality/users', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Users" />

    <div class="grid gap-8 xl:grid-cols-[1fr_420px]">
        <section class="space-y-6">
            <Heading
                variant="small"
                title="Users"
                description="Create QA officers, QA directors, and lecturers"
            />

            <div class="overflow-hidden rounded-lg border bg-white">
                <div
                    class="grid grid-cols-[1fr_160px_1fr_1fr] gap-4 border-b bg-muted/50 px-4 py-3 text-sm font-medium"
                >
                    <span>User</span>
                    <span>Role</span>
                    <span>School</span>
                    <span>Department</span>
                </div>

                <div
                    v-for="user in users"
                    :key="user.id"
                    class="grid grid-cols-[1fr_160px_1fr_1fr] gap-4 border-b px-4 py-4 text-sm last:border-b-0"
                >
                    <div>
                        <div class="font-medium">{{ user.name }}</div>
                        <div class="text-muted-foreground">
                            {{ user.email }}
                        </div>
                    </div>
                    <div>
                        <Badge variant="secondary">
                            {{ user.roleLabel ?? 'Unassigned' }}
                        </Badge>
                    </div>
                    <span>{{ user.school?.name ?? 'All schools' }}</span>
                    <span>{{ user.department?.name ?? 'Not assigned' }}</span>
                </div>
            </div>
        </section>

        <form
            class="space-y-4 rounded-lg border bg-white p-4"
            @submit.prevent="submit"
        >
            <Heading variant="small" title="Add user" />

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" v-model="form.name" required />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input id="email" v-model="form.email" type="email" required />
                <InputError :message="form.errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="role">Role</Label>
                <select
                    id="role"
                    v-model="form.university_role"
                    class="h-9 rounded-md border bg-background px-3 text-sm"
                    required
                    @change="form.department_id = ''"
                >
                    <option
                        v-for="role in roles"
                        :key="role.value"
                        :value="role.value"
                    >
                        {{ role.label }}
                    </option>
                </select>
                <InputError :message="form.errors.university_role" />
            </div>

            <div v-if="form.university_role === 'lecturer'" class="grid gap-2">
                <Label for="department">Lecturer department</Label>
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
                <Label for="password">Password</Label>
                <Input
                    id="password"
                    v-model="form.password"
                    type="password"
                    required
                />
                <InputError :message="form.errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password-confirmation">Confirm password</Label>
                <Input
                    id="password-confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    required
                />
            </div>

            <Button type="submit" :disabled="form.processing">
                <Plus /> Add user
            </Button>
        </form>
    </div>
</template>
