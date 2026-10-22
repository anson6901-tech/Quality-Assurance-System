<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Building2,
    GraduationCap,
    Landmark,
    Plus,
    UserX,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PageBanner from '@/components/PageBanner.vue';
import OrgMember from '@/components/quality/OrgMember.vue';
import type { OrgPerson } from '@/components/quality/OrgMember.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

type Level = 'university' | 'school' | 'department';
type DepartmentNode = {
    id: number;
    schoolId: number;
    name: string;
    code: string;
    head: OrgPerson | null;
    members: OrgPerson[];
};
type SchoolNode = {
    id: number;
    name: string;
    code: string;
    head: OrgPerson | null;
    members: OrgPerson[];
    departments: DepartmentNode[];
};
type Person = OrgPerson & {
    placement: Level | null;
    placementLabel: string;
};

const props = defineProps<{
    canPlaceAtUniversity: boolean;
    canPlaceAtSchool: boolean;
    actorPlacementLabel: string;
    university: OrgPerson[] | null;
    schools: SchoolNode[];
    unplaced: OrgPerson[];
    people: Person[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Organizational Structure',
                href: '/quality/organization',
            },
        ],
    },
});

const dialogOpen = ref(false);
const form = useForm({
    user_id: '' as number | '',
    placement: '' as Level | '',
    school_id: '' as number | '',
    department_id: '' as number | '',
});

const placeablePeople = computed(() =>
    props.people.filter((person) => !person.isCurrentUser),
);
const selectedPerson = computed(() =>
    props.people.find((person) => person.id === form.user_id),
);
const departmentOptions = computed(
    () =>
        props.schools.find((school) => school.id === form.school_id)
            ?.departments ?? [],
);

watch(
    () => form.school_id,
    () => {
        if (
            !departmentOptions.value.some(
                (department) => department.id === form.department_id,
            )
        ) {
            form.department_id = '';
        }
    },
);

const openPlacement = (
    target: {
        placement?: Level;
        schoolId?: number;
        departmentId?: number;
    } = {},
    person?: OrgPerson,
) => {
    form.clearErrors();
    form.user_id = person?.id ?? '';
    form.placement =
        target.placement ??
        (props.canPlaceAtUniversity
            ? 'university'
            : props.canPlaceAtSchool
              ? 'school'
              : 'department');
    form.school_id = target.schoolId ?? '';
    form.department_id = target.departmentId ?? '';
    dialogOpen.value = true;
};

const movePerson = (person: OrgPerson) => {
    const current = props.people.find((entry) => entry.id === person.id);
    const school = props.schools.find(
        (entry) =>
            entry.members.some((member) => member.id === person.id) ||
            entry.departments.some((department) =>
                department.members.some((member) => member.id === person.id),
            ),
    );
    const department = school?.departments.find((entry) =>
        entry.members.some((member) => member.id === person.id),
    );

    openPlacement(
        {
            placement: current?.placement ?? undefined,
            schoolId: school?.id,
            departmentId: department?.id,
        },
        person,
    );
};

const submitPlacement = () => {
    if (!form.user_id) {
        return;
    }

    form.transform((data) => ({
        placement: data.placement,
        school_id: data.placement === 'university' ? null : data.school_id,
        department_id:
            data.placement === 'department' ? data.department_id : null,
    })).put(`/quality/organization/placements/${form.user_id}`, {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    });
};

const removePerson = (person: OrgPerson) => {
    if (
        window.confirm(
            `Remove ${person.name} from the structure? They keep their role but will only see their own work until placed again.`,
        )
    ) {
        router.delete(`/quality/organization/placements/${person.id}`, {
            preserveScroll: true,
        });
    }
};

const otherMembers = (members: OrgPerson[], head: OrgPerson | null) =>
    members.filter((member) => member.id !== head?.id);
</script>

<template>
    <Head title="Organizational Structure" />

    <div class="space-y-6">
        <PageBanner
            title="Organizational Structure"
            description="Where a person is placed decides what part of the university they can see. Their role decides what they can do there."
        >
            <template #actions>
                <Button
                    type="button"
                    class="bg-white text-[#007a2f] hover:bg-[#e3f6eb]"
                    @click="openPlacement()"
                >
                    <Plus /> Place a person
                </Button>
            </template>
        </PageBanner>

        <div
            class="grid gap-3 rounded-xl border border-[#9fd8b8] bg-[#edf9f2] p-4 text-sm text-[#1d2f37] sm:grid-cols-3"
        >
            <div class="flex gap-2">
                <Landmark class="mt-0.5 size-4 shrink-0 text-[#007a2f]" />
                <span
                    ><strong>University</strong> sees every school and
                    department.</span
                >
            </div>
            <div class="flex gap-2">
                <GraduationCap class="mt-0.5 size-4 shrink-0 text-[#007a2f]" />
                <span
                    ><strong>School</strong> sees that school and all its
                    departments. The Dean heads it.</span
                >
            </div>
            <div class="flex gap-2">
                <Building2 class="mt-0.5 size-4 shrink-0 text-[#007a2f]" />
                <span
                    ><strong>Department</strong> sees only that department. The
                    HOD heads it.</span
                >
            </div>
        </div>

        <section
            v-if="university"
            class="rounded-xl border border-[#9fd8b8] bg-white p-4 shadow-sm"
        >
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <Landmark class="size-5 text-[#007a2f]" />
                    <h2 class="text-lg font-semibold text-[#063f22]">
                        University
                    </h2>
                    <span class="text-sm text-[#66808a]">sees everything</span>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="border-[#007a2f]/30 text-[#007a2f]"
                    @click="openPlacement({ placement: 'university' })"
                >
                    <Plus /> Add person
                </Button>
            </div>
            <div
                v-if="university.length"
                class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
            >
                <OrgMember
                    v-for="person in university"
                    :key="person.id"
                    :person="person"
                    @move="movePerson"
                    @remove="removePerson"
                />
            </div>
            <p v-else class="text-sm text-[#66808a]">
                Nobody is placed at university level yet.
            </p>
        </section>

        <p
            v-else
            class="rounded-xl border border-dashed border-[#9fd8b8] bg-white px-4 py-3 text-sm text-[#3c5059]"
        >
            You are placed at <strong>{{ actorPlacementLabel }}</strong
            >, so you can only place people inside it.
        </p>

        <section
            v-for="school in schools"
            :key="school.id"
            class="rounded-xl border border-[#9fd8b8] bg-white shadow-sm"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b border-[#d5ece0] bg-[#edf9f2] px-4 py-3 dark:border-white/10"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <GraduationCap class="size-5 shrink-0 text-[#007a2f]" />
                    <h2 class="truncate text-lg font-semibold text-[#063f22]">
                        {{ school.name }}
                    </h2>
                    <span class="text-sm text-[#66808a]">{{
                        school.code
                    }}</span>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="border-[#007a2f]/30 text-[#007a2f]"
                    @click="
                        openPlacement({
                            placement: 'school',
                            schoolId: school.id,
                        })
                    "
                >
                    <Plus /> Add to school
                </Button>
            </div>

            <div class="space-y-4 p-4">
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <OrgMember
                        v-if="school.head"
                        :person="school.head"
                        is-head
                        head-label="Dean"
                        @move="movePerson"
                        @remove="removePerson"
                    />
                    <div
                        v-else
                        class="rounded-lg border border-dashed border-[#e2b26b] bg-[#fff8ec] px-3 py-2 text-sm text-[#8a5a12] dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200"
                    >
                        No Dean placed yet
                    </div>
                    <OrgMember
                        v-for="person in otherMembers(
                            school.members,
                            school.head,
                        )"
                        :key="person.id"
                        :person="person"
                        @move="movePerson"
                        @remove="removePerson"
                    />
                </div>

                <div
                    v-if="school.departments.length"
                    class="space-y-3 border-l-2 border-[#d5ece0] pl-4 dark:border-white/10"
                >
                    <div
                        v-for="department in school.departments"
                        :key="department.id"
                        class="rounded-lg border border-[#e3efe8] bg-[#fbfdfc] p-3 dark:border-white/10"
                    >
                        <div
                            class="mb-2 flex flex-wrap items-center justify-between gap-2"
                        >
                            <div class="flex min-w-0 items-center gap-2">
                                <Building2
                                    class="size-4 shrink-0 text-[#007a2f]"
                                />
                                <h3
                                    class="truncate font-semibold text-[#063f22]"
                                >
                                    {{ department.name }}
                                </h3>
                                <span class="text-xs text-[#66808a]">{{
                                    department.code
                                }}</span>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="text-[#007a2f]"
                                @click="
                                    openPlacement({
                                        placement: 'department',
                                        schoolId: school.id,
                                        departmentId: department.id,
                                    })
                                "
                            >
                                <Plus /> Add
                            </Button>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            <OrgMember
                                v-if="department.head"
                                :person="department.head"
                                is-head
                                head-label="Head of Department"
                                @move="movePerson"
                                @remove="removePerson"
                            />
                            <div
                                v-else
                                class="rounded-lg border border-dashed border-[#e2b26b] bg-[#fff8ec] px-3 py-2 text-sm text-[#8a5a12] dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200"
                            >
                                No HOD placed yet
                            </div>
                            <OrgMember
                                v-for="person in otherMembers(
                                    department.members,
                                    department.head,
                                )"
                                :key="person.id"
                                :person="person"
                                @move="movePerson"
                                @remove="removePerson"
                            />
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-[#66808a]">
                    No departments in this school yet.
                </p>
            </div>
        </section>

        <p
            v-if="!schools.length"
            class="rounded-xl border border-dashed border-[#9fd8b8] bg-white py-10 text-center text-sm text-[#66808a]"
        >
            No schools yet. Add schools and departments first, then place people
            here.
        </p>

        <section
            v-if="unplaced.length"
            class="rounded-xl border border-[#f0d3a4] bg-[#fffaf1] p-4 dark:border-amber-700/60 dark:bg-amber-950/30"
        >
            <div class="mb-3 flex items-center gap-2">
                <UserX class="size-5 text-[#b7791f]" />
                <h2
                    class="text-lg font-semibold text-[#5c3d0b] dark:text-amber-100"
                >
                    Not placed
                </h2>
                <span class="text-sm text-[#8a5a12] dark:text-amber-200"
                    >can only see their own work</span
                >
            </div>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="person in unplaced"
                    :key="person.id"
                    class="flex items-center gap-2 rounded-lg border border-[#f0d3a4] bg-white px-3 py-2 text-sm dark:border-amber-700/60"
                >
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-medium text-[#063f22]">
                            {{ person.name }}
                        </div>
                        <div class="truncate text-xs text-[#66808a]">
                            {{ person.roleLabel }}
                        </div>
                    </div>
                    <Button
                        v-if="!person.isCurrentUser"
                        type="button"
                        size="sm"
                        class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                        @click="openPlacement({}, person)"
                    >
                        Place
                    </Button>
                </div>
            </div>
        </section>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-md">
            <form class="space-y-4" @submit.prevent="submitPlacement">
                <DialogHeader>
                    <DialogTitle>Place a person</DialogTitle>
                    <DialogDescription>
                        Choose where this person sits. They keep their role;
                        this only changes what they can see.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="org-person">Person</Label>
                    <select
                        id="org-person"
                        v-model="form.user_id"
                        class="h-10 rounded-md border px-3 text-sm"
                        required
                    >
                        <option value="" disabled>Select a person</option>
                        <option
                            v-for="person in placeablePeople"
                            :key="person.id"
                            :value="person.id"
                        >
                            {{ person.name }} — {{ person.roleLabel }}
                        </option>
                    </select>
                    <p
                        v-if="selectedPerson"
                        class="text-xs text-muted-foreground"
                    >
                        Currently: {{ selectedPerson.placementLabel }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="org-level">Level</Label>
                    <select
                        id="org-level"
                        v-model="form.placement"
                        class="h-10 rounded-md border px-3 text-sm"
                        required
                    >
                        <option value="" disabled>Select a level</option>
                        <option v-if="canPlaceAtUniversity" value="university">
                            University — sees everything
                        </option>
                        <option v-if="canPlaceAtSchool" value="school">
                            School — sees the whole school
                        </option>
                        <option value="department">
                            Department — sees only the department
                        </option>
                    </select>
                    <InputError :message="form.errors.placement" />
                </div>

                <div
                    v-if="form.placement && form.placement !== 'university'"
                    class="grid gap-2"
                >
                    <Label for="org-school">School</Label>
                    <select
                        id="org-school"
                        v-model="form.school_id"
                        class="h-10 rounded-md border px-3 text-sm"
                        required
                    >
                        <option value="" disabled>Select a school</option>
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

                <div v-if="form.placement === 'department'" class="grid gap-2">
                    <Label for="org-department">Department</Label>
                    <select
                        id="org-department"
                        v-model="form.department_id"
                        class="h-10 rounded-md border px-3 text-sm"
                        :disabled="!form.school_id"
                        required
                    >
                        <option value="" disabled>
                            {{
                                form.school_id
                                    ? 'Select a department'
                                    : 'Select a school first'
                            }}
                        </option>
                        <option
                            v-for="department in departmentOptions"
                            :key="department.id"
                            :value="department.id"
                        >
                            {{ department.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.department_id" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="dialogOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                        :disabled="form.processing || !form.user_id"
                    >
                        Save placement
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
