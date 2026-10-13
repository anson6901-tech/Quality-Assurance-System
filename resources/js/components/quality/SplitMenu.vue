<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Combine, Loader2, Plus, Scissors } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    previewParts,
    sectionKey,
    sectionOptions,
    splitChoices,
} from '@/lib/coursePartitions';
import type { SplitChoice } from '@/lib/coursePartitions';
import type { CourseCell, SplitMode } from '@/types';

type SemesterOption = {
    id: number;
    label: string;
    mode: SplitMode;
    cells: CourseCell[];
};

const props = withDefaults(
    defineProps<{
        courseId: number;
        courseCode: string;
        /** Semester the page is showing; when missing the dialog lets you pick one. */
        semesterId?: number | null;
        /** Current split, when the page already knows it. */
        mode?: SplitMode;
        /** Course cells, when the page already has them; otherwise they are loaded. */
        cells?: CourseCell[];
        canSplit?: boolean;
        canManageSections?: boolean;
    }>(),
    // Leave these unset when omitted so the user's own permissions are used.
    { canSplit: undefined, canManageSections: undefined },
);

const page = usePage();
const profile = computed(() => page.props.qualityProfile);
const allowed = computed(
    () =>
        props.canSplit ??
        Boolean(
            profile.value?.canManageQualityStructure ||
            profile.value?.canManageCourseAssignments,
        ),
);
const manageSections = computed(
    () =>
        props.canManageSections ??
        Boolean(profile.value?.canManageQualityStructure),
);

const open = ref(false);
const loading = ref(false);
const loadError = ref('');
const semesters = ref<SemesterOption[]>([]);
const semesterChoice = ref<number | null>(null);
const choice = ref<SplitChoice | null>(null);
const saving = ref(false);

const activeSemester = computed(
    () =>
        semesters.value.find(
            (semester) => semester.id === semesterChoice.value,
        ) ?? null,
);
const cells = computed<CourseCell[]>(() =>
    props.cells && props.semesterId && semesterChoice.value === props.semesterId
        ? props.cells
        : (activeSemester.value?.cells ?? []),
);
const currentMode = computed<SplitMode>(
    () => activeSemester.value?.mode ?? props.mode ?? 'merged',
);

const options = computed(() =>
    splitChoices.map((option) => {
        const parts = previewParts(option.value, cells.value);

        return { ...option, parts, usable: parts.length > 1 };
    }),
);
const selected = computed(
    () => options.value.find((option) => option.value === choice.value) ?? null,
);
const hasSections = computed(() => cells.value.some((cell) => cell.section));

const sectionsOpen = ref(false);
const savingProgramId = ref<number | null>(null);
const sectionError = ref('');
const sectionDraft = ref<Record<number, string[]>>({});
const programs = computed(() =>
    cells.value
        .map((cell) => cell.program)
        .filter((program): program is NonNullable<typeof program> =>
            Boolean(program),
        )
        .filter(
            (program, index, values) =>
                values.findIndex((value) => value.id === program.id) === index,
        ),
);
const resetSectionDraft = () => {
    sectionDraft.value = Object.fromEntries(
        programs.value.map((program) => [
            program.id,
            cells.value
                .filter((cell) => cell.programId === program.id && cell.section)
                .map((cell) => sectionKey(cell.section!)),
        ]),
    );
};
watch(cells, resetSectionDraft, { immediate: true });

const pickDefaultChoice = () => {
    choice.value =
        currentMode.value !== 'merged'
            ? (currentMode.value as SplitChoice)
            : (options.value.find((option) => option.usable)?.value ?? null);
    sectionsOpen.value = !options.value.some((option) => option.usable);
};
watch(semesterChoice, pickDefaultChoice);

const loadOptions = async () => {
    loading.value = true;
    loadError.value = '';

    try {
        const query = props.semesterId
            ? `?semester_id=${props.semesterId}`
            : '';
        const response = await fetch(
            `/quality/course-assignments/${props.courseId}/split-options${query}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            throw new Error(
                response.status === 403
                    ? 'You can only split courses your department manages.'
                    : 'The course parts could not be loaded.',
            );
        }

        semesters.value = (await response.json()).semesters;

        if (
            !semesters.value.some(
                (semester) => semester.id === semesterChoice.value,
            )
        ) {
            semesterChoice.value = semesters.value[0]?.id ?? null;
        }

        pickDefaultChoice();
    } catch (error) {
        loadError.value = (error as Error).message;
    } finally {
        loading.value = false;
    }
};

const toggleSection = (programId: number, value: string, checked: boolean) => {
    const current = sectionDraft.value[programId] ?? [];
    sectionDraft.value = {
        ...sectionDraft.value,
        [programId]: checked
            ? [...new Set([...current, value])]
            : current.filter((item) => item !== value),
    };
};
const saveSections = (programId: number) => {
    savingProgramId.value = programId;
    sectionError.value = '';
    router.put(
        `/quality/programs/${programId}/sections`,
        { sections: sectionDraft.value[programId] ?? [] },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => loadOptions(),
            onError: (errors) => {
                sectionError.value =
                    errors.sections ?? 'Sections could not be saved.';
            },
            onFinish: () => {
                savingProgramId.value = null;
            },
        },
    );
};

const openDialog = () => {
    semesterChoice.value = props.semesterId ?? semesterChoice.value;
    open.value = true;
    loadOptions();
};

// Let the dialog finish closing before the page reloads, otherwise its
// overlay can be left behind and block every click on the page.
const afterClose = (action: () => void) => {
    open.value = false;
    window.setTimeout(action, 250);
};

const confirm = () => {
    if (!selected.value?.usable || !semesterChoice.value) {
        return;
    }

    const mode = selected.value.value;
    const semesterId = semesterChoice.value;

    saving.value = true;
    afterClose(() =>
        router.post(
            `/quality/course-assignments/${props.courseId}/split`,
            { semester_id: semesterId, mode },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    saving.value = false;
                },
            },
        ),
    );
};

const merge = (semesterId: number | null | undefined) => {
    if (
        !semesterId ||
        !window.confirm(
            `Merge ${props.courseCode} back into one course? Lecturer assignments on each part are kept.`,
        )
    ) {
        return;
    }

    afterClose(() =>
        router.delete(`/quality/course-assignments/${props.courseId}/split`, {
            data: { semester_id: semesterId },
            preserveScroll: true,
            preserveState: true,
        }),
    );
};
</script>

<template>
    <div v-if="allowed" class="flex items-center gap-1.5">
        <Button
            v-if="mode && mode !== 'merged' && semesterId"
            type="button"
            size="sm"
            variant="outline"
            class="border-[#c8d7de] text-[#1d2f37] hover:bg-[#eefaf3]"
            title="Merge the parts back into one course"
            @click="merge(semesterId)"
        >
            <Combine class="h-4 w-4" /> Merge
        </Button>
        <Button
            type="button"
            size="sm"
            variant="outline"
            class="border-[#c8d7de] text-[#1d2f37] hover:bg-[#eefaf3]"
            :title="
                !mode || mode === 'merged'
                    ? 'Split this course'
                    : 'Change how this course is split'
            "
            @click="openDialog"
        >
            <Scissors class="h-4 w-4" />
            {{ !mode || mode === 'merged' ? 'Split' : 'Re-split' }}
        </Button>

        <Dialog v-model:open="open">
            <DialogContent class="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Split {{ courseCode }}</DialogTitle>
                    <DialogDescription>
                        Choose how to separate this course. Each part can then
                        be assigned its own lecturer, and it stays split until
                        you merge it back.
                    </DialogDescription>
                </DialogHeader>

                <div
                    v-if="loading"
                    class="flex items-center gap-2 py-6 text-sm text-[#52646c]"
                >
                    <Loader2 class="size-4 animate-spin" /> Loading course
                    parts…
                </div>
                <p v-else-if="loadError" class="py-4 text-sm text-red-700">
                    {{ loadError }}
                </p>
                <p
                    v-else-if="semesters.length === 0"
                    class="py-4 text-sm text-[#8a4b06]"
                >
                    {{ courseCode }} is not mounted in any semester yet. Mount
                    it for a program first, then split it.
                </p>
                <template v-else>
                    <div
                        v-if="!semesterId || semesters.length > 1"
                        class="grid gap-1"
                    >
                        <label class="text-sm font-medium" for="split-semester"
                            >Semester</label
                        >
                        <select
                            id="split-semester"
                            v-model="semesterChoice"
                            class="h-10 rounded-md border border-[#c8d7de] bg-white px-3 text-sm"
                        >
                            <option
                                v-for="semester in semesters"
                                :key="semester.id"
                                :value="semester.id"
                            >
                                {{ semester.label }}
                                {{
                                    semester.mode !== 'merged' ? '(split)' : ''
                                }}
                            </option>
                        </select>
                    </div>
                    <div
                        class="grid gap-2"
                        role="radiogroup"
                        aria-label="Split by"
                    >
                        <button
                            v-for="option in options"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="choice === option.value"
                            :disabled="!option.usable"
                            class="rounded-lg border p-3 text-left transition disabled:cursor-not-allowed disabled:opacity-55"
                            :class="
                                choice === option.value
                                    ? 'border-[#007a2f] bg-[#eefaf3] ring-1 ring-[#007a2f]'
                                    : 'border-[#d5dee3] bg-white hover:border-[#8bcfaa]'
                            "
                            @click="choice = option.value"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <span class="font-medium text-[#1d2f37]">
                                    Split by {{ option.label.toLowerCase() }}
                                </span>
                                <span
                                    class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        option.usable
                                            ? 'bg-[#e1f5e8] text-[#0b6130]'
                                            : 'bg-[#f5f8fa] text-[#52646c]'
                                    "
                                >
                                    {{ option.parts.length }}
                                    {{
                                        option.parts.length === 1
                                            ? 'part'
                                            : 'parts'
                                    }}
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-[#52646c]">
                                {{ option.description }}
                            </p>
                            <p
                                v-if="!option.usable"
                                class="mt-1 text-xs text-[#8a4b06]"
                            >
                                Nothing to separate with this option.
                            </p>
                        </button>
                    </div>

                    <div
                        v-if="selected?.usable"
                        class="rounded-lg border border-[#d5e2e8] bg-[#f7fafb] p-3"
                    >
                        <div
                            class="mb-2 text-xs font-semibold text-[#3c5059] uppercase"
                        >
                            Parts after splitting
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <span
                                v-for="part in selected.parts"
                                :key="part"
                                class="rounded-md border border-[#c8d7de] bg-white px-2 py-1 text-xs text-[#1d2f37]"
                            >
                                {{ part }}
                            </span>
                        </div>
                    </div>
                    <div
                        v-if="!options.some((option) => option.usable)"
                        class="rounded-lg border border-[#f5d48a] bg-[#fdf3d7] p-3 text-sm text-[#8a4b06]"
                    >
                        {{ courseCode }} is offered in one program
                        {{
                            hasSections
                                ? 'with one section'
                                : 'without sections'
                        }}, so there is nothing to separate yet.
                        <template v-if="manageSections">
                            Add the sections that take this course (for example
                            Undergraduate and Sandwich) below, then split it.
                        </template>
                        <template v-else>
                            Ask the ICT Administrator or QA Director to add the
                            program's sections, then split it.
                        </template>
                    </div>

                    <div
                        v-if="manageSections"
                        class="rounded-lg border border-[#d5e2e8]"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center justify-between px-3 py-2 text-left text-sm font-medium text-[#1d2f37]"
                            :aria-expanded="sectionsOpen"
                            @click="sectionsOpen = !sectionsOpen"
                        >
                            <span class="flex items-center gap-2">
                                <Plus class="h-4 w-4" /> Add or change program
                                sections
                            </span>
                            <span class="text-xs text-[#66808a]">
                                {{ sectionsOpen ? 'Hide' : 'Show' }}
                            </span>
                        </button>
                        <div
                            v-if="sectionsOpen"
                            class="max-h-72 space-y-4 overflow-y-auto border-t border-[#e6edf0] p-3"
                        >
                            <div v-for="program in programs" :key="program.id">
                                <div
                                    class="mb-2 text-sm font-semibold text-[#075b2e]"
                                >
                                    {{ program.name }} ({{ program.code }})
                                </div>
                                <div class="grid grid-cols-2 gap-1.5">
                                    <label
                                        v-for="option in sectionOptions"
                                        :key="option.value"
                                        class="flex cursor-pointer items-center gap-2 rounded-md border px-2 py-1.5 text-xs"
                                    >
                                        <input
                                            type="checkbox"
                                            class="h-3.5 w-3.5 accent-[#007a2f]"
                                            :checked="
                                                (
                                                    sectionDraft[program.id] ??
                                                    []
                                                ).includes(option.value)
                                            "
                                            @change="
                                                toggleSection(
                                                    program.id,
                                                    option.value,
                                                    (
                                                        $event.target as HTMLInputElement
                                                    ).checked,
                                                )
                                            "
                                        />
                                        {{ option.label }}
                                    </label>
                                </div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    class="mt-2 border-[#8bcfaa] text-[#006d2b] hover:bg-[#e7f6ed]"
                                    :disabled="savingProgramId === program.id"
                                    @click="saveSections(program.id)"
                                >
                                    {{
                                        savingProgramId === program.id
                                            ? 'Saving…'
                                            : `Save ${program.code} sections`
                                    }}
                                </Button>
                            </div>
                            <p v-if="sectionError" class="text-xs text-red-700">
                                {{ sectionError }}
                            </p>
                        </div>
                    </div>
                </template>

                <DialogFooter class="gap-2 sm:justify-between">
                    <Button
                        v-if="currentMode !== 'merged' && !loading"
                        type="button"
                        variant="outline"
                        class="border-[#c8d7de]"
                        @click="merge(semesterChoice)"
                    >
                        <Combine class="h-4 w-4" /> Merge back
                    </Button>
                    <span v-else />
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            @click="open = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                            :disabled="
                                loading ||
                                saving ||
                                !selected?.usable ||
                                selected.value === currentMode
                            "
                            @click="confirm"
                        >
                            <Scissors class="h-4 w-4" /> Split course
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
