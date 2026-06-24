<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Edit, KeyRound, Plus, Save, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
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
    permissions: string[];
};

type DepartmentOption = {
    id: number;
    name: string;
    school: { id: number; name: string };
};

const props = defineProps<{
    users: UserRow[];
    roles: RoleOption[];
    schools: Array<{ id: number; name: string }>;
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
    school_id: '',
    department_id: '',
    password: '',
    password_confirmation: '',
});

const editForm = useForm({
    name: '',
    email: '',
    university_role: 'lecturer',
    school_id: '',
    department_id: '',
});

const passwordForm = useForm({
    password: '',
    password_confirmation: '',
});

const mode = ref<'create' | 'edit' | 'password'>('create');
const selectedUser = ref<UserRow | null>(null);

const selectedRole = computed(() =>
    props.roles.find((role) => role.value === form.university_role),
);

const selectedEditRole = computed(() =>
    props.roles.find((role) => role.value === editForm.university_role),
);

const roleNeedsSchool = (role?: RoleOption) =>
    role?.permissions.includes('view_school_reports');

const roleNeedsDepartment = (role?: RoleOption) =>
    role?.permissions.some((permission) =>
        ['view_department_reports', 'view_own_reports'].includes(permission),
    );

const needsSchool = computed(() => roleNeedsSchool(selectedRole.value));
const needsDepartment = computed(() => roleNeedsDepartment(selectedRole.value));
const editNeedsSchool = computed(() => roleNeedsSchool(selectedEditRole.value));
const editNeedsDepartment = computed(() =>
    roleNeedsDepartment(selectedEditRole.value),
);

const normalizeId = (value: number | undefined) =>
    value === undefined ? '' : String(value);

const showCreate = () => {
    mode.value = 'create';
    selectedUser.value = null;
    editForm.clearErrors();
    passwordForm.reset();
    passwordForm.clearErrors();
};

const startEdit = (user: UserRow) => {
    mode.value = 'edit';
    selectedUser.value = user;
    passwordForm.reset();
    passwordForm.clearErrors();
    editForm.clearErrors();
    editForm.name = user.name;
    editForm.email = user.email;
    editForm.university_role = user.role ?? 'lecturer';
    editForm.school_id = normalizeId(user.school?.id);
    editForm.department_id = normalizeId(user.department?.id);
};

const startPasswordReset = (user: UserRow) => {
    mode.value = 'password';
    selectedUser.value = user;
    editForm.clearErrors();
    passwordForm.reset();
    passwordForm.clearErrors();
};

const submit = () => {
    form.post('/quality/users', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

const updateUser = () => {
    if (!selectedUser.value) {
        return;
    }

    editForm.patch(`/quality/users/${selectedUser.value.id}`, {
        preserveScroll: true,
        onSuccess: showCreate,
    });
};

const resetPassword = () => {
    if (!selectedUser.value) {
        return;
    }

    passwordForm.patch(`/quality/users/${selectedUser.value.id}/password`, {
        preserveScroll: true,
        onSuccess: showCreate,
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
                description="Create users and assign role-based access"
            />

            <div
                class="overflow-hidden rounded-xl border border-[#9fd8b8] bg-white shadow-sm ring-1 ring-[#007a2f]/10"
            >
                <div
                    class="grid grid-cols-[1fr_150px_1fr_1fr_160px] gap-4 border-b border-[#9fd8b8] bg-[#e8f6ee] px-4 py-3 text-sm font-bold text-[#063f22]"
                >
                    <span>User</span>
                    <span>Role</span>
                    <span>School</span>
                    <span>Department</span>
                    <span>Actions</span>
                </div>

                <div
                    v-for="user in users"
                    :key="user.id"
                    class="grid grid-cols-[1fr_150px_1fr_1fr_160px] gap-4 border-b px-4 py-4 text-sm last:border-b-0"
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
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="startEdit(user)"
                        >
                            <Edit class="size-4" />
                            Edit
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="startPasswordReset(user)"
                        >
                            <KeyRound class="size-4" />
                            Password
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <form
            v-if="mode === 'create'"
            class="space-y-4 rounded-xl border border-[#9fd8b8] bg-[#eefaf3] p-6 shadow-sm ring-1 ring-[#007a2f]/10"
            @submit.prevent="submit"
        >
            <Heading variant="small" title="Add user" />

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
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                    type="email"
                    required
                />
                <InputError :message="form.errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="role">Role</Label>
                <select
                    id="role"
                    v-model="form.university_role"
                    class="h-9 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                    required
                    @change="
                        form.school_id = '';
                        form.department_id = '';
                    "
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

            <div v-if="needsSchool" class="grid gap-2">
                <Label for="school">School scope</Label>
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

            <div v-if="needsDepartment" class="grid gap-2">
                <Label for="department">Department scope</Label>
                <select
                    id="department"
                    v-model="form.department_id"
                    class="h-9 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
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
                    class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
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
                    class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                    type="password"
                    required
                />
            </div>

            <Button
                type="submit"
                class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                :disabled="form.processing"
            >
                <Plus /> Add user
            </Button>
        </form>

        <form
            v-else-if="mode === 'edit'"
            class="space-y-4 rounded-xl border border-[#9fd8b8] bg-[#eefaf3] p-6 shadow-sm ring-1 ring-[#007a2f]/10"
            @submit.prevent="updateUser"
        >
            <div class="flex items-start justify-between gap-4">
                <Heading
                    variant="small"
                    title="Edit user"
                    :description="selectedUser?.email"
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    @click="showCreate"
                >
                    <X class="size-4" />
                </Button>
            </div>

            <div class="grid gap-2">
                <Label for="edit-name">Name</Label>
                <Input
                    id="edit-name"
                    v-model="editForm.name"
                    class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                    required
                />
                <InputError :message="editForm.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="edit-email">Email</Label>
                <Input
                    id="edit-email"
                    v-model="editForm.email"
                    class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                    type="email"
                    required
                />
                <InputError :message="editForm.errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="edit-role">Role</Label>
                <select
                    id="edit-role"
                    v-model="editForm.university_role"
                    class="h-9 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                    required
                    @change="
                        editForm.school_id = '';
                        editForm.department_id = '';
                    "
                >
                    <option
                        v-for="role in roles"
                        :key="role.value"
                        :value="role.value"
                    >
                        {{ role.label }}
                    </option>
                </select>
                <InputError :message="editForm.errors.university_role" />
            </div>

            <div v-if="editNeedsSchool" class="grid gap-2">
                <Label for="edit-school">School scope</Label>
                <select
                    id="edit-school"
                    v-model="editForm.school_id"
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
                <InputError :message="editForm.errors.school_id" />
            </div>

            <div v-if="editNeedsDepartment" class="grid gap-2">
                <Label for="edit-department">Department scope</Label>
                <select
                    id="edit-department"
                    v-model="editForm.department_id"
                    class="h-9 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
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
                <InputError :message="editForm.errors.department_id" />
            </div>

            <Button
                type="submit"
                class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                :disabled="editForm.processing"
            >
                <Save class="size-4" />
                Save changes
            </Button>
        </form>

        <form
            v-else
            class="space-y-4 rounded-xl border border-[#9fd8b8] bg-[#eefaf3] p-6 shadow-sm ring-1 ring-[#007a2f]/10"
            @submit.prevent="resetPassword"
        >
            <div class="flex items-start justify-between gap-4">
                <Heading
                    variant="small"
                    title="Reset password"
                    :description="selectedUser?.email"
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    @click="showCreate"
                >
                    <X class="size-4" />
                </Button>
            </div>

            <div class="grid gap-2">
                <Label for="reset-password">New password</Label>
                <Input
                    id="reset-password"
                    v-model="passwordForm.password"
                    class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                    type="password"
                    required
                />
                <InputError :message="passwordForm.errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="reset-password-confirmation">
                    Confirm new password
                </Label>
                <Input
                    id="reset-password-confirmation"
                    v-model="passwordForm.password_confirmation"
                    class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                    type="password"
                    required
                />
            </div>

            <Button
                type="submit"
                class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                :disabled="passwordForm.processing"
            >
                <KeyRound class="size-4" />
                Reset password
            </Button>
        </form>
    </div>
</template>
