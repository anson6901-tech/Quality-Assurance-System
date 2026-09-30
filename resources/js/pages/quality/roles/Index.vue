<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { KeyRound, Plus, ShieldCheck, Trash2, Users } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type RoleSummary = {
    value: string;
    label: string;
    permissions: string[];
    permissionCount: number;
    userCount: number;
    isSystem: boolean;
};

const props = defineProps<{ roles: RoleSummary[] }>();
const form = useForm({ label: '' });

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles', href: '/quality/roles' }],
    },
});

const createRole = () => {
    form.post('/quality/roles', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

const deleteRole = (role: RoleSummary) => {
    if (role.isSystem || role.userCount > 0) {
        return;
    }

    if (!window.confirm(`Delete the ${role.label} role?`)) {
        return;
    }

    router.delete(`/quality/roles/${role.value}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Roles" />

    <section class="space-y-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                variant="small"
                title="Roles"
                description="Create job roles and review how many users and permissions each role has"
            />
            <Button
                as-child
                variant="outline"
                class="border-[#89cda6] text-[#076b35]"
            >
                <Link href="/quality/permissions">
                    <KeyRound class="size-4" /> Manage permissions
                </Link>
            </Button>
        </div>

        <form
            class="flex flex-col gap-4 rounded-lg border border-[#9fd8b8] bg-[#eefaf3] p-5 shadow-sm sm:flex-row sm:items-end"
            @submit.prevent="createRole"
        >
            <div class="grid min-w-0 flex-1 gap-2">
                <Label for="role-name">Role name</Label>
                <Input
                    id="role-name"
                    v-model="form.label"
                    class="border-[#9fd8b8] bg-white"
                    placeholder="Example: Faculty QA Reviewer"
                    required
                />
                <InputError :message="form.errors.label" />
            </div>
            <Button
                type="submit"
                class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                :disabled="form.processing"
            >
                <Plus class="size-4" /> Create role
            </Button>
        </form>

        <div
            class="overflow-x-auto rounded-lg border border-[#b8ced8] bg-white shadow-sm"
        >
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead
                    class="bg-[#d9edf5] text-xs font-semibold text-[#294c59] uppercase"
                >
                    <tr>
                        <th class="px-5 py-3">Role</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3 text-center">Users</th>
                        <th class="px-4 py-3 text-center">Permissions</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#dce6ea]">
                    <tr
                        v-for="role in props.roles"
                        :key="role.value"
                        class="hover:bg-[#f8fbfc]"
                    >
                        <td class="px-5 py-4">
                            <div class="font-semibold text-[#071126]">
                                {{ role.label }}
                            </div>
                            <div class="mt-1 text-xs text-muted-foreground">
                                {{ role.value.replaceAll('_', ' ') }}
                            </div>
                        </td>
                        <td class="px-4 py-4">
                            <Badge
                                :variant="
                                    role.isSystem ? 'secondary' : 'outline'
                                "
                            >
                                {{
                                    role.isSystem
                                        ? 'System role'
                                        : 'Custom role'
                                }}
                            </Badge>
                        </td>
                        <td class="px-4 py-4 text-center">
                            <span
                                class="inline-flex items-center gap-1.5 font-semibold"
                            >
                                <Users class="size-4 text-[#527080]" />
                                {{ role.userCount }}
                            </span>
                        </td>
                        <td class="px-4 py-4 text-center">
                            <span
                                class="inline-flex items-center gap-1.5 font-semibold text-[#076b35]"
                            >
                                <ShieldCheck class="size-4" />
                                {{ role.permissionCount }}
                            </span>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex justify-end gap-2">
                                <Button as-child size="sm" variant="outline">
                                    <Link
                                        :href="`/quality/permissions?role=${role.value}`"
                                    >
                                        <KeyRound class="size-4" /> Permissions
                                    </Link>
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    class="border-red-200 text-red-700 hover:bg-red-50"
                                    :disabled="
                                        role.isSystem || role.userCount > 0
                                    "
                                    :title="
                                        role.isSystem
                                            ? 'System roles are protected and cannot be deleted'
                                            : role.userCount > 0
                                              ? 'Move users to another role before deleting it'
                                              : `Delete ${role.label}`
                                    "
                                    @click="deleteRole(role)"
                                >
                                    <Trash2 class="size-4" />
                                    {{
                                        role.isSystem
                                            ? 'Protected'
                                            : 'Delete role'
                                    }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
