<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, Save } from 'lucide-vue-next';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type RoleRight = {
    value: string;
    label: string;
    permissions: string[];
    isSystem: boolean;
};

type PermissionOption = {
    value: string;
    label: string;
};

const props = defineProps<{
    roles: RoleRight[];
    permissionOptions: PermissionOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Roles & Rights',
                href: '/quality/roles-rights',
            },
        ],
    },
});

const selected = reactive<Record<string, string[]>>(
    Object.fromEntries(
        props.roles.map((role) => [role.value, [...role.permissions]]),
    ),
);

const form = useForm({
    label: '',
    permissions: [] as string[],
});

const togglePermission = (
    role: string,
    permission: string,
    checked: boolean | string,
) => {
    if (checked) {
        selected[role] = Array.from(new Set([...selected[role], permission]));
        return;
    }

    selected[role] = selected[role].filter((value) => value !== permission);
};

const toggleNewRolePermission = (
    permission: string,
    checked: boolean | string,
) => {
    if (checked) {
        form.permissions = Array.from(
            new Set([...form.permissions, permission]),
        );
        return;
    }

    form.permissions = form.permissions.filter((value) => value !== permission);
};

const createRole = () => {
    form.post('/quality/roles-rights', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

const saveRole = (role: RoleRight) => {
    router.patch(
        `/quality/roles-rights/${role.value}`,
        {
            role: role.value,
            permissions: selected[role.value],
        },
        {
            preserveScroll: true,
        },
    );
};
</script>

<template>
    <Head title="Roles & Rights" />

    <section class="space-y-7">
        <Heading
            variant="small"
            title="Roles & Rights"
            description="Define what each user role can see and do in the QA system"
        />

        <form
            class="space-y-5 rounded-xl border border-[#9fd8b8] bg-[#eefaf3] p-6 shadow-sm ring-1 ring-[#007a2f]/10"
            @submit.prevent="createRole"
        >
            <div
                class="grid gap-4 md:grid-cols-[320px_1fr_auto] md:items-start"
            >
                <div class="grid gap-2">
                    <Label for="role-name">New role name</Label>
                    <Input
                        id="role-name"
                        v-model="form.label"
                        class="border-[#9fd8b8] bg-white focus-visible:ring-[#007a2f]/20"
                        placeholder="Example: Faculty QA Reviewer"
                        required
                    />
                    <InputError :message="form.errors.label" />
                </div>

                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <Label
                        v-for="permission in permissionOptions"
                        :key="`new-${permission.value}`"
                        class="flex min-h-14 cursor-pointer items-start gap-3 rounded-lg border border-[#9fd8b8] bg-white p-4 text-sm font-normal text-[#063f22] transition hover:border-[#007a2f] hover:bg-[#f8fffb]"
                    >
                        <Checkbox
                            :model-value="
                                form.permissions.includes(permission.value)
                            "
                            @update:model-value="
                                (checked) =>
                                    toggleNewRolePermission(
                                        permission.value,
                                        checked,
                                    )
                            "
                        />
                        <span class="font-medium">{{ permission.label }}</span>
                    </Label>
                </div>

                <Button
                    type="submit"
                    class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                    :disabled="form.processing"
                >
                    <Plus /> Create role
                </Button>
            </div>
        </form>

        <div class="space-y-5">
            <div
                v-for="role in roles"
                :key="role.value"
                class="rounded-xl border border-[#9fd8b8] bg-white p-6 shadow-sm ring-1 ring-[#007a2f]/10"
            >
                <div
                    class="flex flex-col gap-3 border-b border-[#9fd8b8] pb-5 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-semibold">
                                {{ role.label }}
                            </h2>
                            <Badge variant="secondary">
                                {{ selected[role.value].length }} rights
                            </Badge>
                            <Badge v-if="role.isSystem" variant="outline">
                                System
                            </Badge>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{ role.value.replaceAll('_', ' ') }}
                        </p>
                    </div>

                    <Button
                        type="button"
                        class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                        @click="saveRole(role)"
                    >
                        <Save /> Save rights
                    </Button>
                </div>

                <div class="grid gap-3 pt-4 md:grid-cols-2 xl:grid-cols-3">
                    <Label
                        v-for="permission in permissionOptions"
                        :key="`${role.value}-${permission.value}`"
                        class="flex min-h-16 cursor-pointer items-start gap-3 rounded-lg border border-[#9fd8b8] bg-[#f8fffb] p-4 text-sm font-normal text-[#063f22] transition hover:border-[#007a2f] hover:bg-[#eefaf3]"
                    >
                        <Checkbox
                            :model-value="
                                selected[role.value].includes(permission.value)
                            "
                            @update:model-value="
                                (checked) =>
                                    togglePermission(
                                        role.value,
                                        permission.value,
                                        checked,
                                    )
                            "
                        />
                        <span>
                            <span class="block font-medium">
                                {{ permission.label }}
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ permission.value.replaceAll('_', ' ') }}
                            </span>
                        </span>
                    </Label>
                </div>
            </div>
        </div>
    </section>
</template>
