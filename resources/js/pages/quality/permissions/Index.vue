<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BookOpenCheck,
    ClipboardCheck,
    FileCheck2,
    KeyRound,
    LibraryBig,
    Save,
    ScrollText,
    Users,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import PageBanner from '@/components/PageBanner.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

type Role = {
    value: string;
    label: string;
    permissions: string[];
    isSystem: boolean;
};
type Permission = { value: string; label: string };
type PermissionGroup = {
    label: string;
    description: string;
    permissions: Permission[];
};

const props = defineProps<{
    roles: Role[];
    permissionGroups: PermissionGroup[];
    selectedRole: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Permissions', href: '/quality/permissions' }],
    },
});

const roleValue = ref(
    props.roles.some((role) => role.value === props.selectedRole)
        ? props.selectedRole
        : (props.roles[0]?.value ?? ''),
);
const selectedPermissions = ref<string[]>([]);
const saving = ref(false);
const currentRole = computed(() =>
    props.roles.find((role) => role.value === roleValue.value),
);

const moduleIcons = {
    'Upload Reports': FileCheck2,
    'Review Workflow': ClipboardCheck,
    'Published Reports': BookOpenCheck,
    'User Management': Users,
    'Academic Structure': LibraryBig,
    'Course Assignments': KeyRound,
    'Audit Logs': ScrollText,
} as const;

const loadRole = () => {
    selectedPermissions.value = [...(currentRole.value?.permissions ?? [])];
};

watch(roleValue, loadRole, { immediate: true });

const togglePermission = (permission: string, checked: boolean | string) => {
    selectedPermissions.value = checked
        ? Array.from(new Set([...selectedPermissions.value, permission]))
        : selectedPermissions.value.filter((value) => value !== permission);
};

const savePermissions = () => {
    if (!currentRole.value) {
        return;
    }

    saving.value = true;
    router.patch(
        `/quality/permissions/${currentRole.value.value}`,
        {
            role: currentRole.value.value,
            permissions: selectedPermissions.value,
        },
        {
            preserveScroll: true,
            onFinish: () => (saving.value = false),
        },
    );
};
</script>

<template>
    <Head title="Permissions" />

    <section class="space-y-6">
        <PageBanner
            title="Permissions"
            description="Control which modules and actions are available to each role"
        >
            <template #actions>
                <Button
                    as-child
                    variant="outline"
                    class="border-[#89cda6] text-[#076b35]"
                >
                    <Link href="/quality/roles"
                        ><Users class="size-4" /> View roles</Link
                    >
                </Button>
            </template>
        </PageBanner>

        <div
            class="flex flex-col gap-4 rounded-lg border border-[#9fd8b8] bg-[#eefaf3] p-5 shadow-sm sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="grid min-w-0 flex-1 gap-2 sm:max-w-md">
                <Label for="permission-role">Role</Label>
                <select
                    id="permission-role"
                    v-model="roleValue"
                    class="h-10 w-full rounded-md border border-[#9fd8b8] bg-white px-3 text-sm"
                >
                    <option
                        v-for="role in props.roles"
                        :key="role.value"
                        :value="role.value"
                    >
                        {{ role.label }}
                    </option>
                </select>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <Badge variant="secondary">
                    {{ selectedPermissions.length }} permissions selected
                </Badge>
                <Button
                    type="button"
                    class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                    :disabled="saving || !currentRole"
                    @click="savePermissions"
                >
                    <Save class="size-4" /> Save permissions
                </Button>
            </div>
        </div>

        <div
            class="overflow-hidden rounded-lg border border-[#b8ced8] bg-white shadow-sm"
        >
            <div
                class="grid bg-[#d9edf5] px-5 py-3 text-xs font-semibold text-[#294c59] uppercase md:grid-cols-[1fr_190px]"
            >
                <span>Module and actions</span>
                <span class="hidden md:block">Access</span>
            </div>

            <div class="divide-y divide-[#dce6ea]">
                <section
                    v-for="group in props.permissionGroups"
                    :key="group.label"
                    class="grid gap-4 px-5 py-5 md:grid-cols-[1fr_190px]"
                >
                    <div class="flex min-w-0 gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-md bg-[#e4f3ea] text-[#076b35]"
                        >
                            <component
                                :is="
                                    moduleIcons[
                                        group.label as keyof typeof moduleIcons
                                    ]
                                "
                                class="size-5"
                            />
                        </span>
                        <div>
                            <h2 class="font-semibold text-[#071126]">
                                {{ group.label }}
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ group.description }}
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span
                                    v-for="permission in group.permissions"
                                    :key="permission.value"
                                    class="rounded-md bg-[#f1f5f3] px-2 py-1 text-xs text-[#456255]"
                                >
                                    {{ permission.label }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="grid content-start gap-2">
                        <Label
                            v-for="permission in group.permissions"
                            :key="`${roleValue}-${permission.value}`"
                            class="flex cursor-pointer items-start gap-2 rounded-md border border-[#dce6ea] bg-[#fbfdfc] p-3 text-sm font-normal hover:border-[#7acb9c]"
                        >
                            <Checkbox
                                :model-value="
                                    selectedPermissions.includes(
                                        permission.value,
                                    )
                                "
                                @update:model-value="
                                    (checked) =>
                                        togglePermission(
                                            permission.value,
                                            checked,
                                        )
                                "
                            />
                            <span class="leading-5">{{
                                permission.label
                            }}</span>
                        </Label>
                    </div>
                </section>
            </div>
        </div>
    </section>
</template>
