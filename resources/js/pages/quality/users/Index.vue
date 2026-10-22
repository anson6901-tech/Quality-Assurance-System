<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    CircleCheck,
    CircleOff,
    Download,
    Edit,
    KeyRound,
    MailCheck,
    Plus,
    Save,
    Trash2,
    Upload,
} from 'lucide-vue-next';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageBanner from '@/components/PageBanner.vue';
import { Badge } from '@/components/ui/badge';
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

type UserRow = {
    id: number;
    name: string;
    firstName: string;
    lastName: string;
    email: string;
    staffId: string | null;
    role: string | null;
    roleLabel: string | null;
    school: { id: number; name: string } | null;
    department: { id: number; name: string } | null;
    placement: 'university' | 'school' | 'department' | null;
    placementLabel: string;
    isActive: boolean;
    isCurrentUser: boolean;
};

type RoleOption = {
    value: string;
    label: string;
    permissions: string[];
};

const props = defineProps<{
    users: UserRow[];
    roles: RoleOption[];
    placesNewUsers: boolean;
    actorPlacementLabel: string;
    emailEnabled: boolean;
    welcomeEmails: boolean;
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
    first_name: '',
    last_name: '',
    email: '',
    staff_id: '',
    university_role: 'lecturer',
    password: '',
    password_confirmation: '',
});

const editForm = useForm({
    first_name: '',
    last_name: '',
    email: '',
    staff_id: '',
    university_role: 'lecturer',
});

const passwordForm = useForm({
    password: '',
    password_confirmation: '',
});
const importForm = useForm({
    users_file: null as File | null,
});

// Placement rules (one Dean per school, one HOD per department) are reported under a
// key that is not one of the form fields.
const placementError = (errors: object) =>
    (errors as Record<string, string | undefined>).placement;

const mode = ref<'create' | 'edit' | 'password'>('create');
const createOpen = ref(false);
const bulkOpen = ref(false);
const selectedUser = ref<UserRow | null>(null);

const showCreate = () => {
    mode.value = 'create';
    selectedUser.value = null;
    editForm.clearErrors();
    passwordForm.reset();
    passwordForm.clearErrors();
};

const openCreate = () => {
    form.reset();
    form.clearErrors();
    createOpen.value = true;
};

const startEdit = (user: UserRow) => {
    mode.value = 'edit';
    selectedUser.value = user;
    passwordForm.reset();
    passwordForm.clearErrors();
    editForm.clearErrors();
    editForm.first_name = user.firstName;
    editForm.last_name = user.lastName;
    editForm.email = user.email;
    editForm.staff_id = user.staffId ?? '';
    editForm.university_role = user.role ?? 'lecturer';
};

const sendResetEmail = (user: UserRow) => {
    if (window.confirm(`Email ${user.email} a link to set a new password?`)) {
        router.post(
            `/quality/users/${user.id}/reset-link`,
            {},
            { preserveScroll: true },
        );
    }
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
        onSuccess: () => {
            form.reset();
            createOpen.value = false;
        },
    });
};

const importUsers = () => {
    importForm.post('/quality/users/import', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            importForm.reset();
            bulkOpen.value = false;
        },
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
const toggleStatus = (user: UserRow) => {
    const action = user.isActive ? 'deactivate' : 'activate';

    if (
        window.confirm(
            `${action[0].toUpperCase()}${action.slice(1)} ${user.name}'s account?`,
        )
    ) {
        router.patch(
            `/quality/users/${user.id}/status`,
            { is_active: !user.isActive },
            { preserveScroll: true },
        );
    }
};
const deleteUser = (user: UserRow) => {
    if (
        window.confirm(
            `Delete ${user.name}? This permanently removes the account and data owned by it. This action cannot be undone.`,
        )
    ) {
        router.delete(`/quality/users/${user.id}`, {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head title="Users" />

    <div class="space-y-8">
        <section class="space-y-6">
            <PageBanner
                title="Users"
                description="Create users and assign role-based access"
            >
                <template #actions>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="border-[#007a2f]/30 bg-white text-[#007a2f] hover:bg-[#e3f6eb]"
                            @click="bulkOpen = true"
                        >
                            <Upload class="size-4" />
                            Bulk users
                        </Button>
                        <Button
                            type="button"
                            class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                            @click="openCreate"
                        >
                            <Plus class="size-4" />
                            Add user
                        </Button>
                    </div>
                </template>
            </PageBanner>

            <div
                class="overflow-hidden rounded-xl border border-[#9fd8b8] bg-white shadow-sm ring-1 ring-[#007a2f]/10"
            >
                <div
                    class="grid grid-cols-[1fr_150px_1fr_160px] gap-4 border-b border-[#9fd8b8] bg-[#e8f6ee] px-4 py-3 text-sm font-bold text-[#063f22]"
                >
                    <span>User</span>
                    <span>Role</span>
                    <span>Placement</span>
                    <span>Actions</span>
                </div>

                <div
                    v-for="user in users"
                    :key="user.id"
                    class="grid grid-cols-[1fr_150px_1fr_160px] gap-4 border-b px-4 py-4 text-sm last:border-b-0"
                >
                    <div>
                        <div class="font-medium">{{ user.name }}</div>
                        <div class="text-muted-foreground">
                            {{ user.email }}
                        </div>
                        <div
                            v-if="user.staffId"
                            class="text-xs text-muted-foreground"
                        >
                            Staff ID: {{ user.staffId }}
                        </div>
                    </div>
                    <div>
                        <Badge variant="secondary">
                            {{ user.roleLabel ?? 'Unassigned' }}
                        </Badge>
                        <div class="mt-2">
                            <Badge
                                :variant="
                                    user.isActive ? 'default' : 'destructive'
                                "
                            >
                                {{ user.isActive ? 'Active' : 'Inactive' }}
                            </Badge>
                        </div>
                    </div>
                    <div>
                        <div
                            :class="
                                user.placement
                                    ? 'text-[#1d2f37]'
                                    : 'font-medium text-[#8a5a12]'
                            "
                        >
                            {{ user.placementLabel }}
                        </div>
                        <div
                            v-if="user.placement === 'department'"
                            class="text-xs text-muted-foreground"
                        >
                            {{ user.school?.name }}
                        </div>
                        <Link
                            v-if="!user.placement"
                            href="/quality/organization"
                            class="text-xs text-[#007a2f] underline"
                        >
                            Place on structure
                        </Link>
                    </div>
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
                        <Button
                            v-if="emailEnabled"
                            type="button"
                            variant="outline"
                            size="sm"
                            :title="`Email ${user.email} a link to set a new password`"
                            @click="sendResetEmail(user)"
                        >
                            <MailCheck class="size-4" />
                            Reset email
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            :disabled="user.isCurrentUser"
                            :title="
                                user.isCurrentUser
                                    ? 'You cannot deactivate your own account'
                                    : user.isActive
                                      ? 'Deactivate account'
                                      : 'Activate account'
                            "
                            @click="toggleStatus(user)"
                        >
                            <CircleOff v-if="user.isActive" class="size-4" />
                            <CircleCheck v-else class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            :disabled="user.isCurrentUser"
                            title="Delete user"
                            class="text-destructive hover:text-destructive"
                            @click="deleteUser(user)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <Dialog :open="createOpen" @update:open="createOpen = $event">
            <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
                <form class="space-y-4" @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>Add user</DialogTitle>
                        <DialogDescription>
                            Create an account and assign its role and access
                            scope. The user must choose a personal password on
                            first login.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="first-name">First name</Label>
                            <Input
                                id="first-name"
                                v-model="form.first_name"
                                class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                                autocomplete="given-name"
                                required
                            />
                            <InputError :message="form.errors.first_name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="last-name">Last name</Label>
                            <Input
                                id="last-name"
                                v-model="form.last_name"
                                class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                                autocomplete="family-name"
                                required
                            />
                            <InputError :message="form.errors.last_name" />
                        </div>
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
                        <Label for="staff-id">Staff ID</Label>
                        <Input
                            id="staff-id"
                            v-model="form.staff_id"
                            class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                        />
                        <InputError :message="form.errors.staff_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="role">Role</Label>
                        <select
                            id="role"
                            v-model="form.university_role"
                            class="h-9 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                            required
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

                    <p
                        class="rounded-md bg-[#edf9f2] px-3 py-2 text-xs leading-5 text-[#3c5059]"
                    >
                        {{
                            placesNewUsers
                                ? `New users join ${actorPlacementLabel}.`
                                : 'New users start unplaced. Place them on the Organizational Structure to decide what they can see.'
                        }}
                    </p>
                    <InputError :message="placementError(form.errors)" />

                    <p
                        v-if="welcomeEmails"
                        class="rounded-md bg-[#edf9f2] px-3 py-2 text-xs leading-5 text-[#3c5059]"
                    >
                        The system will create a temporary password and email it
                        to this address. They choose their own password the
                        first time they sign in.
                    </p>

                    <template v-else>
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
                            <Label for="password-confirmation"
                                >Confirm password</Label
                            >
                            <Input
                                id="password-confirmation"
                                v-model="form.password_confirmation"
                                class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                                type="password"
                                required
                            />
                        </div>
                    </template>

                    <div class="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="secondary"
                            @click="createOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                            :disabled="form.processing"
                        >
                            <Plus /> Add user
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="bulkOpen">
            <DialogContent class="sm:max-w-lg">
                <form class="space-y-5" @submit.prevent="importUsers">
                    <DialogHeader>
                        <DialogTitle>Import users</DialogTitle>
                        <DialogDescription>
                            Download the system template first. School and
                            department codes place each user on the
                            Organizational Structure; leave them blank to place
                            users later.
                            <template v-if="welcomeEmails">
                                Leave temporary_password blank to have the
                                system create one and email it to each user.
                            </template>
                        </DialogDescription>
                    </DialogHeader>

                    <Button
                        as-child
                        type="button"
                        variant="outline"
                        class="w-full border-[#007a2f]/30 text-[#007a2f] hover:bg-[#e3f6eb]"
                    >
                        <a href="/quality/users/import-template" download>
                            <Download class="size-4" />
                            Download user CSV template
                        </a>
                    </Button>

                    <div
                        class="rounded-lg border border-[#9fd8b8] bg-[#eefaf3] p-3 text-xs leading-5 text-[#063f22]"
                    >
                        <p class="font-semibold">Accepted role values</p>
                        <p>
                            {{
                                props.roles.map((role) => role.value).join(', ')
                            }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="users-file">Completed CSV file</Label>
                        <Input
                            id="users-file"
                            type="file"
                            accept=".csv,text/csv"
                            required
                            @input="
                                importForm.users_file =
                                    ($event.target as HTMLInputElement)
                                        .files?.[0] ?? null
                            "
                        />
                        <InputError :message="importForm.errors.users_file" />
                    </div>

                    <p class="text-xs leading-5 text-muted-foreground">
                        Every imported account receives the CSV temporary
                        password and must create a personal password on first
                        login. Duplicate or invalid rows are safely skipped.
                    </p>

                    <div class="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            @click="bulkOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                            :disabled="
                                !importForm.users_file || importForm.processing
                            "
                        >
                            <Upload class="size-4" />
                            Import users
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="mode === 'edit'"
            @update:open="(open) => !open && showCreate()"
        >
            <DialogContent class="sm:max-w-lg">
                <form class="space-y-4" @submit.prevent="updateUser">
                    <DialogHeader>
                        <DialogTitle>Edit user</DialogTitle>
                        <DialogDescription>
                            {{ selectedUser?.email }}
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="edit-first-name">First name</Label>
                            <Input
                                id="edit-first-name"
                                v-model="editForm.first_name"
                                autocomplete="given-name"
                                required
                            />
                            <InputError :message="editForm.errors.first_name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-last-name">Last name</Label>
                            <Input
                                id="edit-last-name"
                                v-model="editForm.last_name"
                                autocomplete="family-name"
                                required
                            />
                            <InputError :message="editForm.errors.last_name" />
                        </div>
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
                        <Label for="edit-staff-id">Staff ID</Label>
                        <Input id="edit-staff-id" v-model="editForm.staff_id" />
                        <InputError :message="editForm.errors.staff_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="edit-role">Role</Label>
                        <select
                            id="edit-role"
                            v-model="editForm.university_role"
                            class="h-9 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                            required
                        >
                            <option
                                v-for="role in roles"
                                :key="role.value"
                                :value="role.value"
                            >
                                {{ role.label }}
                            </option>
                        </select>
                        <InputError
                            :message="editForm.errors.university_role"
                        />
                    </div>

                    <InputError :message="placementError(editForm.errors)" />
                    <p class="text-xs text-muted-foreground">
                        Placement: {{ selectedUser?.placementLabel }}. Change it
                        on the
                        <Link
                            href="/quality/organization"
                            class="text-[#007a2f] underline"
                            >Organizational Structure</Link
                        >.
                    </p>

                    <div class="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="secondary"
                            @click="showCreate"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                            :disabled="editForm.processing"
                        >
                            <Save class="size-4" />
                            Save changes
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="mode === 'password'"
            @update:open="(open) => !open && showCreate()"
        >
            <DialogContent class="sm:max-w-md">
                <form class="space-y-4" @submit.prevent="resetPassword">
                    <DialogHeader>
                        <DialogTitle>Reset password</DialogTitle>
                        <DialogDescription>
                            {{ selectedUser?.email }}
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="reset-password">New password</Label>
                        <Input
                            id="reset-password"
                            v-model="passwordForm.password"
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

                    <div class="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="secondary"
                            @click="showCreate"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                            :disabled="passwordForm.processing"
                        >
                            <KeyRound class="size-4" />
                            Reset password
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
