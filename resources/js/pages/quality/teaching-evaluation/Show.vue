<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChevronRight,
    CircleAlert,
    Download,
    FileText,
    Send,
    Tags,
    FolderInput,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import CoursePicker from '@/components/evaluation/CoursePicker.vue';
import type { CourseOption } from '@/components/evaluation/CoursePicker.vue';
import FrequenciesDialog from '@/components/evaluation/FrequenciesDialog.vue';
import FrequencyOutput from '@/components/evaluation/FrequencyOutput.vue';
import SetReportPreview from '@/components/evaluation/SetReportPreview.vue';
import InputError from '@/components/InputError.vue';
import type { FolderNode } from '@/components/quality/FolderPicker.vue';
import FolderPicker from '@/components/quality/FolderPicker.vue';
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
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    AssignmentOption,
    DataRow,
    FrequencyOptions,
    FrequencyOutput as Output,
    SetSummary,
    Variable,
} from '@/types/evaluation';
import { valueLabel } from '@/types/evaluation';

type Analysis = {
    id: number;
    name: string;
    status: 'analysed' | 'waiting' | 'drafted';
    academicYear: string | null;
    semester: string | null;
    courseId: number | null;
    courseCode: string | null;
    courseTitle: string | null;
    lecturerName: string | null;
    level: string | null;
    schoolLabel: string | null;
    assignmentKey: string | null;
    draftedAt: string | null;
};

const props = defineProps<{
    analysis: Analysis;
    variables: Variable[];
    rows: DataRow[];
    warnings: string[];
    frequencies: { options: FrequencyOptions; output: Output } | null;
    frequencyDefaults: FrequencyOptions;
    summary: SetSummary;
    thresholds: { high: number; acceptable: number };
    assignments: AssignmentOption[];
    courses: CourseOption[];
    schools: { label: string; name: string }[];
    levels: string[];
    canSeeSensitive: boolean;
    documentFolders: FolderNode[] | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Teaching Evaluation',
                href: '/quality/teaching-evaluation',
            },
        ],
    },
});

type Step = 'processing' | 'analysis' | 'report' | 'download';
const step = ref<Step>(props.frequencies ? 'analysis' : 'processing');
const dataView = ref<'data' | 'variables'>('data');
// Answers show as words (Strongly Agree …); the button switches to the codes 5 … 1.
const showValueLabels = ref(true);
const drafted = computed(() => props.analysis.status === 'drafted');
const base = computed(
    () => `/quality/teaching-evaluation/${props.analysis.id}`,
);

// Analyze → Descriptive Statistics → Frequencies…
const frequenciesOpen = ref(false);
const running = ref(false);
const runError = ref<string>();
const runFrequencies = (options: FrequencyOptions) => {
    running.value = true;
    runError.value = undefined;
    router.post(`${base.value}/frequencies`, options, {
        preserveScroll: true,
        onSuccess: () => {
            frequenciesOpen.value = false;
            step.value = 'analysis';
        },
        onError: (errors) => (runError.value = Object.values(errors)[0]),
        onFinish: () => (running.value = false),
    });
};

// Report header
const headerValues = () => ({
    course_id: props.analysis.courseId,
    assignment_key: props.analysis.assignmentKey ?? '',
    lecturer_name: props.analysis.lecturerName ?? '',
    level: props.analysis.level ?? '',
    school_label: props.analysis.schoolLabel ?? '',
});
const details = useForm(headerValues());
// Lecturers assigned to the chosen course this semester (from Course Assignments).
const courseAssignments = computed(() =>
    props.assignments.filter((item) => item.courseId === details.course_id),
);
// Choosing a course brings its assigned lecturer; choosing one fills the name and level.
watch(
    () => details.course_id,
    () => {
        details.assignment_key = courseAssignments.value[0]?.key ?? '';
    },
);
watch(
    () => details.assignment_key,
    (key) => {
        const item = courseAssignments.value.find((entry) => entry.key === key);

        // An assigned lecturer brings the level (and school) of the programme they teach.
        if (item) {
            details.lecturer_name = item.lecturer;
            details.level = item.level ?? details.level;
            details.school_label = item.school ?? details.school_label;
        }
    },
);
// After saving, show what the server filled in (level, school).
watch(
    () => props.analysis,
    () => {
        details.defaults(headerValues());
        details.reset();
    },
);
// Keep the report in Document Storage.
const saveDocs = useForm({
    folder_id: null as number | null,
    formats: ['pdf'] as string[],
});
const toggleFormat = (format: string) => {
    saveDocs.formats = saveDocs.formats.includes(format)
        ? saveDocs.formats.filter((item) => item !== format)
        : [...saveDocs.formats, format];
};
const saveToDocuments = () =>
    saveDocs.post(`${base.value}/save-to-documents`, { preserveScroll: true });

const saveDetails = () =>
    details.patch(`${base.value}/details`, { preserveScroll: true });

// Finish
const finishOpen = ref(false);
const finish = useForm({
    mode: 'waiting' as 'draft' | 'waiting',
    key: '',
    course_id: '' as number | '',
});
const finishCourseFilter = ref<number | ''>('');
// Finish starts from the report header: its course, and its lecturer when assigned.
watch(finishOpen, (open) => {
    if (!open) {
        return;
    }

    const { courseId, assignmentKey } = props.analysis;
    finish.key = assignmentKey ?? '';
    finish.course_id = courseId ?? '';
    finishCourseFilter.value = courseId ?? '';
    finish.mode = assignmentKey
        ? 'draft'
        : courseId || !props.assignments.length
          ? 'waiting'
          : 'draft';
});
const filteredAssignments = computed(() =>
    finishCourseFilter.value
        ? props.assignments.filter(
              (item) => item.courseId === finishCourseFilter.value,
          )
        : props.assignments,
);
const submitFinish = () =>
    finish
        .transform((data) =>
            data.mode === 'draft'
                ? { key: data.key }
                : { course_id: data.course_id || null },
        )
        .post(`${base.value}/finish`, {
            preserveScroll: true,
            onSuccess: () => (finishOpen.value = false),
        });

const variableByName = computed(
    () => new Map(props.variables.map((variable) => [variable.name, variable])),
);
const display = (name: string, value: DataRow[string]) =>
    showValueLabels.value
        ? valueLabel(variableByName.value.get(name), value)
        : value === null
          ? ''
          : String(value);
const typeLabel = (variable: Variable) =>
    variable.type === 'string' ? 'String' : 'Numeric';
const valuesText = (variable: Variable) => {
    const entries = Object.entries(variable.valueLabels ?? {});

    return entries.length
        ? entries.map(([code, label]) => `${code} = ${label}`).join(', ')
        : 'None';
};

const steps: { key: Step; label: string; hint: string }[] = [
    { key: 'processing', label: 'Data Processing', hint: 'Check the data' },
    {
        key: 'analysis',
        label: 'Statistical Analysis',
        hint: 'Analyze → Frequencies',
    },
    { key: 'report', label: 'Report Generation', hint: 'Header and preview' },
    { key: 'download', label: 'Download Report', hint: 'PDF, Word, finish' },
];
const dataTabs: { key: 'data' | 'variables'; label: string }[] = [
    { key: 'data', label: 'Data View' },
    { key: 'variables', label: 'Variable View' },
];
const menuButton =
    'rounded px-3 py-1.5 text-sm font-medium text-[#063f22] hover:bg-[#007a2f]/15 data-[state=open]:bg-[#007a2f] data-[state=open]:text-white';
const later = 'Coming later';
</script>

<template>
    <Head :title="`Analysis · ${analysis.name}`" />

    <div class="space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-2">
            <div class="min-w-0">
                <Link
                    href="/quality/teaching-evaluation"
                    class="inline-flex items-center gap-1 text-sm text-[#4b6b58] hover:text-[#063f22]"
                >
                    <ArrowLeft class="size-4" /> All analyses
                </Link>
                <h1 class="truncate text-xl font-semibold text-[#063f22]">
                    {{ analysis.name }}
                </h1>
                <p class="text-sm text-[#4b6b58]">
                    {{ analysis.academicYear }} · {{ analysis.semester }} ·
                    {{ rows.length }} responses ·
                    {{ variables.length }} variables
                </p>
            </div>
        </header>

        <!-- The four stages -->
        <nav
            aria-label="Stages"
            class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4"
        >
            <button
                v-for="(item, index) in steps"
                :key="item.key"
                type="button"
                class="flex items-center gap-3 rounded-lg border px-3 py-2 text-left text-sm transition"
                :class="
                    step === item.key
                        ? 'border-[#007a2f] bg-[#007a2f] text-white'
                        : 'border-[#9fd8b8] bg-white text-[#063f22] hover:border-[#007a2f]'
                "
                :aria-current="step === item.key ? 'step' : undefined"
                @click="step = item.key"
            >
                <span
                    class="flex size-7 shrink-0 items-center justify-center rounded-full text-sm font-bold"
                    :class="
                        step === item.key
                            ? 'bg-white text-[#007a2f]'
                            : 'bg-[#dff1e6] text-[#007a2f]'
                    "
                    >{{ index + 1 }}</span
                >
                <span class="grid">
                    <span class="font-semibold">{{ item.label }}</span>
                    <span
                        class="text-xs"
                        :class="
                            step === item.key
                                ? 'text-white/80'
                                : 'text-[#4b6b58]'
                        "
                        >{{ item.hint }}</span
                    >
                </span>
            </button>
        </nav>

        <div
            v-if="drafted"
            class="rounded-lg border border-[#9fd8b8] bg-[#eef8f2] p-3 text-sm text-[#063f22]"
        >
            This analysis is a draft report for {{ analysis.lecturerName }}.
            Submit it to a QA Supervisor from
            <Link href="/quality/reports" class="font-semibold underline"
                >Reports</Link
            >.
        </div>
        <div
            v-else-if="analysis.status === 'waiting'"
            class="rounded-lg border border-[#f0d39b] bg-[#fff9ec] p-3 text-sm text-[#5c3d06]"
        >
            This report is in the Waiting for Lecturer folder. Once a lecturer
            is assigned to the course, open
            <Link
                href="/quality/teaching-evaluation?folder=waiting"
                class="font-semibold underline"
                >the folder</Link
            >
            and click Move to draft.
        </div>

        <!-- 1. Data Processing -->
        <section v-if="step === 'processing'" class="space-y-4">
            <div
                v-if="warnings.length"
                class="rounded-lg border border-[#f0d39b] bg-[#fff9ec] p-3 text-sm text-[#5c3d06]"
            >
                <p class="mb-1 flex items-center gap-2 font-semibold">
                    <CircleAlert class="size-4" />
                    {{ warnings.length }} thing(s) to check in this data
                </p>
                <ul class="list-disc space-y-0.5 pl-6">
                    <li v-for="(warning, index) in warnings" :key="index">
                        {{ warning }}
                    </li>
                </ul>
            </div>

            <div
                class="flex flex-wrap items-center gap-1 border-b border-[#9fd8b8]"
                role="tablist"
            >
                <button
                    v-for="item in dataTabs"
                    :key="item.key"
                    type="button"
                    role="tab"
                    :aria-selected="dataView === item.key"
                    class="-mb-px rounded-t-md border px-4 py-2 text-sm font-medium"
                    :class="
                        dataView === item.key
                            ? 'border-[#9fd8b8] border-b-white bg-white text-[#063f22]'
                            : 'border-transparent text-[#4b6b58] hover:text-[#063f22]'
                    "
                    @click="dataView = item.key"
                >
                    {{ item.label }}
                </button>
                <button
                    v-if="dataView === 'data'"
                    type="button"
                    class="mb-1 ml-auto flex items-center gap-1"
                    :class="[
                        menuButton,
                        showValueLabels ? 'bg-[#007a2f] text-white' : '',
                    ]"
                    :aria-pressed="showValueLabels"
                    title="Show answers as words (Strongly Agree …) or as codes (5 … 1)"
                    @click="showValueLabels = !showValueLabels"
                >
                    <Tags class="size-4" />
                    {{ showValueLabels ? 'Showing words' : 'Showing codes' }}
                </button>
            </div>
            <div
                v-if="dataView === 'data'"
                class="max-h-[65vh] overflow-auto rounded-lg border bg-white"
            >
                <table class="min-w-max text-xs">
                    <thead class="sticky top-0 z-10 bg-[#eef8f2]">
                        <tr>
                            <th
                                class="sticky left-0 z-10 border-r border-b bg-[#eef8f2] px-2 py-1 text-right"
                            >
                                #
                            </th>
                            <th
                                v-for="variable in variables"
                                :key="variable.name"
                                class="border-r border-b px-2 py-1 text-left font-semibold"
                                :title="variable.label"
                            >
                                {{ variable.name }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in rows" :key="index">
                            <th
                                class="sticky left-0 border-r border-b bg-[#f4fbf7] px-2 py-0.5 text-right font-normal text-[#4b6b58]"
                            >
                                {{ index + 1 }}
                            </th>
                            <td
                                v-for="variable in variables"
                                :key="variable.name"
                                class="max-w-56 truncate border-r border-b px-2 py-0.5"
                                :class="
                                    variable.type === 'numeric' &&
                                    !(
                                        showValueLabels &&
                                        Object.keys(variable.valueLabels ?? {})
                                            .length
                                    )
                                        ? 'text-right tabular-nums'
                                        : ''
                                "
                                :title="
                                    row[variable.name] === null
                                        ? ''
                                        : String(row[variable.name])
                                "
                            >
                                {{ display(variable.name, row[variable.name]) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="overflow-x-auto rounded-lg border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-[#eef8f2] text-left">
                        <tr>
                            <th class="px-3 py-2">#</th>
                            <th class="px-3 py-2">Name</th>
                            <th class="px-3 py-2">Type</th>
                            <th class="px-3 py-2">Label</th>
                            <th class="px-3 py-2">Values</th>
                            <th class="px-3 py-2">Measure</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(variable, index) in variables"
                            :key="variable.name"
                            class="border-t align-top"
                        >
                            <td class="px-3 py-1.5 text-[#4b6b58]">
                                {{ index + 1 }}
                            </td>
                            <td class="px-3 py-1.5 font-medium">
                                {{ variable.name }}
                            </td>
                            <td class="px-3 py-1.5">
                                {{ typeLabel(variable) }}
                            </td>
                            <td class="max-w-md px-3 py-1.5">
                                <span
                                    v-if="variable.section"
                                    class="mr-1 rounded bg-[#eef8f2] px-1 text-xs"
                                    >{{ variable.section }}</span
                                >
                                {{ variable.label }}
                            </td>
                            <td class="max-w-xs px-3 py-1.5 text-xs">
                                {{ valuesText(variable) }}
                            </td>
                            <td class="px-3 py-1.5 capitalize">
                                {{ variable.measure }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p
                    v-if="!canSeeSensitive"
                    class="border-t p-3 text-xs text-[#555]"
                >
                    Harassment and assessment-leak answers are hidden. Only
                    university-level QA staff can see them.
                </p>
            </div>
            <div class="flex justify-end">
                <Button
                    class="bg-[#007a2f] hover:bg-[#006b30]"
                    @click="step = 'analysis'"
                >
                    Next: Statistical Analysis <ChevronRight class="size-4" />
                </Button>
            </div>
        </section>

        <!-- 2. Statistical Analysis -->
        <section v-else-if="step === 'analysis'" class="space-y-4">
            <nav
                class="flex flex-wrap items-center gap-1 rounded-lg border border-[#9fd8b8] bg-[#f4fbf7] p-1"
                aria-label="Analysis menu"
            >
                <DropdownMenu>
                    <DropdownMenuTrigger :class="menuButton"
                        >Analyze</DropdownMenuTrigger
                    >
                    <DropdownMenuContent align="start" class="w-64">
                        <DropdownMenuItem disabled
                            >Reports
                            <span class="ml-auto text-xs">{{
                                later
                            }}</span></DropdownMenuItem
                        >
                        <DropdownMenuSub>
                            <DropdownMenuSubTrigger
                                >Descriptive Statistics</DropdownMenuSubTrigger
                            >
                            <DropdownMenuSubContent class="w-56">
                                <DropdownMenuItem
                                    @select="frequenciesOpen = true"
                                    >Frequencies…</DropdownMenuItem
                                >
                                <DropdownMenuItem disabled
                                    >Descriptives…
                                    <span class="ml-auto text-xs">{{
                                        later
                                    }}</span></DropdownMenuItem
                                >
                                <DropdownMenuItem disabled
                                    >Explore…
                                    <span class="ml-auto text-xs">{{
                                        later
                                    }}</span></DropdownMenuItem
                                >
                                <DropdownMenuItem disabled
                                    >Crosstabs…
                                    <span class="ml-auto text-xs">{{
                                        later
                                    }}</span></DropdownMenuItem
                                >
                            </DropdownMenuSubContent>
                        </DropdownMenuSub>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem disabled
                            >Compare Means
                            <span class="ml-auto text-xs">{{
                                later
                            }}</span></DropdownMenuItem
                        >
                        <DropdownMenuItem disabled
                            >Correlate
                            <span class="ml-auto text-xs">{{
                                later
                            }}</span></DropdownMenuItem
                        >
                        <DropdownMenuItem disabled
                            >Regression
                            <span class="ml-auto text-xs">{{
                                later
                            }}</span></DropdownMenuItem
                        >
                        <DropdownMenuItem disabled
                            >Nonparametric Tests
                            <span class="ml-auto text-xs">{{
                                later
                            }}</span></DropdownMenuItem
                        >
                    </DropdownMenuContent>
                </DropdownMenu>
                <span class="flex items-center text-xs text-[#4b6b58]">
                    <ChevronRight class="size-3" /> Analyze → Descriptive
                    Statistics → Frequencies…
                </span>
            </nav>
            <div class="rounded-lg border bg-white p-4">
                <FrequencyOutput
                    v-if="frequencies"
                    :options="frequencies.options"
                    :output="frequencies.output"
                />
                <div v-else class="py-10 text-center text-sm text-[#4b6b58]">
                    <p>No output yet.</p>
                    <Button
                        class="mt-3 bg-[#007a2f] hover:bg-[#006b30]"
                        @click="frequenciesOpen = true"
                    >
                        Analyze → Descriptive Statistics → Frequencies…
                    </Button>
                </div>
            </div>
            <div class="flex justify-between">
                <Button variant="outline" @click="step = 'processing'">
                    <ArrowLeft class="size-4" /> Data Processing
                </Button>
                <Button
                    class="bg-[#007a2f] hover:bg-[#006b30]"
                    @click="step = 'report'"
                >
                    Next: Report Generation <ChevronRight class="size-4" />
                </Button>
            </div>
        </section>

        <!-- 3. Report Generation -->
        <section v-else-if="step === 'report'" class="space-y-4">
            <div class="space-y-6 rounded-lg border bg-white p-4">
                <form
                    class="grid gap-4 rounded-md border border-[#9fd8b8] bg-[#f4fbf7] p-4 sm:grid-cols-2 lg:grid-cols-4"
                    @submit.prevent="saveDetails"
                >
                    <p
                        class="text-sm font-semibold text-[#063f22] sm:col-span-2 lg:col-span-4"
                    >
                        Report header
                        <span class="font-normal text-[#4b6b58]"
                            >— choose the course from the system. Its title,
                            level, school and assigned lecturer are filled in
                            for you, and you can change the level and school
                            from their lists.</span
                        >
                    </p>
                    <div class="grid min-w-0 gap-1 sm:col-span-2">
                        <Label for="header-course">Course</Label>
                        <CoursePicker
                            id="header-course"
                            v-model="details.course_id"
                            :courses="courses"
                            :disabled="drafted"
                        />
                        <InputError :message="details.errors.course_id" />
                    </div>
                    <div class="grid min-w-0 gap-1 sm:col-span-2">
                        <Label for="lecturer-name">Lecturer</Label>
                        <template v-if="courseAssignments.length">
                            <select
                                id="lecturer-name"
                                v-model="details.assignment_key"
                                class="h-9 w-full min-w-0 rounded-md border border-input bg-white px-3 text-sm shadow-xs"
                                :disabled="drafted"
                            >
                                <option
                                    v-for="item in courseAssignments"
                                    :key="item.key"
                                    :value="item.key"
                                >
                                    {{ item.lecturer }} · {{ item.part }}
                                </option>
                                <option value="">
                                    Not listed — type the lecturer's name
                                </option>
                            </select>
                            <Input
                                v-if="!details.assignment_key"
                                v-model="details.lecturer_name"
                                :disabled="drafted"
                                placeholder="Lecturer's name"
                                aria-label="Lecturer's name"
                            />
                        </template>
                        <Input
                            v-else
                            id="lecturer-name"
                            v-model="details.lecturer_name"
                            :disabled="drafted"
                            placeholder="Lecturer's name"
                        />
                        <p
                            v-if="details.course_id && !details.assignment_key"
                            class="text-xs text-[#7a4b00]"
                        >
                            {{
                                courseAssignments.length
                                    ? 'This name is only printed on the report.'
                                    : 'No lecturer is assigned to this course yet.'
                            }}
                            The report will wait in Waiting for Lecturer until
                            the course is assigned in Course Assignments.
                        </p>
                        <p
                            v-else-if="details.assignment_key"
                            class="text-xs text-[#0b5a2a]"
                        >
                            Assigned in Course Assignments, so the report can go
                            straight to this lecturer's draft.
                        </p>
                        <InputError
                            :message="
                                details.errors.assignment_key ??
                                details.errors.lecturer_name
                            "
                        />
                    </div>
                    <div class="grid min-w-0 gap-1">
                        <Label for="level">Level</Label>
                        <select
                            id="level"
                            v-model="details.level"
                            class="h-9 w-full min-w-0 rounded-md border border-input bg-white px-3 text-sm shadow-xs"
                            :disabled="drafted"
                        >
                            <option value="">Choose a level</option>
                            <option
                                v-for="level in levels"
                                :key="level"
                                :value="level"
                            >
                                Level {{ level }}
                            </option>
                        </select>
                        <InputError :message="details.errors.level" />
                    </div>
                    <div class="grid min-w-0 gap-1">
                        <Label for="school">School</Label>
                        <select
                            id="school"
                            v-model="details.school_label"
                            class="h-9 w-full min-w-0 rounded-md border border-input bg-white px-3 text-sm shadow-xs"
                            :disabled="drafted"
                        >
                            <option value="">Choose a school</option>
                            <option
                                v-for="school in schools"
                                :key="school.label"
                                :value="school.label"
                            >
                                {{
                                    school.label === school.name
                                        ? school.name
                                        : `${school.label} — ${school.name}`
                                }}
                            </option>
                        </select>
                        <InputError :message="details.errors.school_label" />
                    </div>
                    <div
                        v-if="!drafted"
                        class="flex items-end sm:col-span-2 lg:col-span-2"
                    >
                        <Button
                            type="submit"
                            class="bg-[#007a2f] hover:bg-[#006b30]"
                            :disabled="details.processing || !details.isDirty"
                        >
                            Save header
                        </Button>
                    </div>
                </form>

                <SetReportPreview
                    :summary="summary"
                    :thresholds="thresholds"
                    :course="
                        `${analysis.courseCode ?? ''} ${analysis.courseTitle ?? ''}`.trim()
                    "
                    :lecturer="analysis.lecturerName ?? ''"
                />
            </div>
            <div class="flex justify-between">
                <Button variant="outline" @click="step = 'analysis'">
                    <ArrowLeft class="size-4" /> Statistical Analysis
                </Button>
                <Button
                    class="bg-[#007a2f] hover:bg-[#006b30]"
                    @click="step = 'download'"
                >
                    Next: Download Report <ChevronRight class="size-4" />
                </Button>
            </div>
        </section>

        <!-- 4. Download Report -->
        <section v-else class="space-y-4">
            <div class="grid gap-4 md:grid-cols-3">
                <a
                    :href="`${base}/pdf`"
                    target="_blank"
                    rel="noopener"
                    class="flex items-start gap-3 rounded-lg border border-[#9fd8b8] bg-white p-4 hover:border-[#007a2f]"
                >
                    <FileText class="mt-0.5 size-6 text-[#007a2f]" />
                    <span>
                        <span class="block font-semibold text-[#063f22]"
                            >View PDF</span
                        >
                        <span class="text-sm text-[#4b6b58]"
                            >Open the report in a new tab.</span
                        >
                    </span>
                </a>
                <a
                    :href="`${base}/pdf?download=1`"
                    class="flex items-start gap-3 rounded-lg border border-[#9fd8b8] bg-white p-4 hover:border-[#007a2f]"
                >
                    <Download class="mt-0.5 size-6 text-[#007a2f]" />
                    <span>
                        <span class="block font-semibold text-[#063f22]"
                            >Download PDF</span
                        >
                        <span class="text-sm text-[#4b6b58]"
                            >Page 1 SET table, comments and grades from page
                            2.</span
                        >
                    </span>
                </a>
                <a
                    :href="`${base}/word`"
                    class="flex items-start gap-3 rounded-lg border border-[#9fd8b8] bg-white p-4 hover:border-[#007a2f]"
                >
                    <Download class="mt-0.5 size-6 text-[#007a2f]" />
                    <span>
                        <span class="block font-semibold text-[#063f22]"
                            >Download Word</span
                        >
                        <span class="text-sm text-[#4b6b58]"
                            >The same report as an editable .docx file.</span
                        >
                    </span>
                </a>
            </div>
            <form
                v-if="documentFolders"
                class="grid gap-4 rounded-lg border border-[#9fd8b8] bg-white p-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"
                @submit.prevent="saveToDocuments"
            >
                <div class="space-y-3">
                    <h3
                        class="flex items-center gap-2 font-semibold text-[#063f22]"
                    >
                        <FolderInput class="size-5 text-[#007a2f]" />
                        Save to Document Storage
                    </h3>
                    <p class="text-sm text-[#4b6b58]">
                        Keep a copy of this report in a folder of Document
                        Storage, to find it again later.
                    </p>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <label class="flex items-center gap-2">
                            <input
                                type="checkbox"
                                class="size-4 accent-[#007a2f]"
                                :checked="saveDocs.formats.includes('pdf')"
                                @change="toggleFormat('pdf')"
                            />
                            PDF
                        </label>
                        <label class="flex items-center gap-2">
                            <input
                                type="checkbox"
                                class="size-4 accent-[#007a2f]"
                                :checked="saveDocs.formats.includes('word')"
                                @change="toggleFormat('word')"
                            />
                            Word
                        </label>
                    </div>
                    <InputError
                        :message="
                            saveDocs.errors.formats ?? saveDocs.errors.folder_id
                        "
                    />
                    <Button
                        type="submit"
                        class="bg-[#007a2f] hover:bg-[#006b30]"
                        :disabled="
                            saveDocs.processing || !saveDocs.formats.length
                        "
                    >
                        <FolderInput class="size-4" />
                        {{ saveDocs.processing ? 'Saving…' : 'Save to folder' }}
                    </Button>
                </div>
                <div class="grid gap-1">
                    <span class="text-sm font-medium text-[#063f22]"
                        >Folder</span
                    >
                    <FolderPicker
                        v-model="saveDocs.folder_id"
                        :tree="documentFolders"
                    />
                </div>
            </form>
            <div
                v-if="!drafted"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-[#9fd8b8] bg-[#f4fbf7] p-4"
            >
                <p class="text-sm text-[#063f22]">
                    When the report is right, send it to the lecturer's draft,
                    or keep it waiting for a lecturer.
                </p>
                <Button
                    class="bg-[#007a2f] hover:bg-[#006b30]"
                    @click="finishOpen = true"
                >
                    <Send class="size-4" /> Finish
                </Button>
            </div>
            <div class="flex justify-start">
                <Button variant="outline" @click="step = 'report'">
                    <ArrowLeft class="size-4" /> Report Generation
                </Button>
            </div>
        </section>
    </div>

    <FrequenciesDialog
        v-model:open="frequenciesOpen"
        :variables="variables"
        :options="frequencyDefaults"
        :processing="running"
        :error="runError"
        @run="runFrequencies"
    />

    <Dialog :open="finishOpen" @update:open="finishOpen = $event">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Finish analysis</DialogTitle>
                <DialogDescription>
                    The SET report (PDF: table on page 1, comments and grades
                    from page 2) becomes a draft report for the lecturer, or
                    waits in the Waiting for Lecturer folder.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submitFinish">
                <label
                    class="flex items-start gap-3 rounded-md border p-3"
                    :class="
                        finish.mode === 'draft'
                            ? 'border-[#007a2f] bg-[#f4fbf7]'
                            : ''
                    "
                >
                    <input
                        v-model="finish.mode"
                        type="radio"
                        value="draft"
                        class="mt-1 size-4"
                        :disabled="!assignments.length"
                    />
                    <span class="grid min-w-0 flex-1 gap-2">
                        <span class="font-medium"
                            >Send to the lecturer's draft</span
                        >
                        <span
                            v-if="!assignments.length"
                            class="text-sm text-[#8a5a12]"
                        >
                            No course this semester has a lecturer assigned yet.
                        </span>
                        <template v-else>
                            <select
                                v-model="finishCourseFilter"
                                class="h-10 rounded-md border px-3 text-sm"
                                :disabled="finish.mode !== 'draft'"
                                aria-label="Filter by course"
                            >
                                <option value="">
                                    All courses with a lecturer
                                </option>
                                <option
                                    v-for="course in courses.filter((item) =>
                                        assignments.some(
                                            (assignment) =>
                                                assignment.courseId === item.id,
                                        ),
                                    )"
                                    :key="course.id"
                                    :value="course.id"
                                >
                                    {{ course.code }} {{ course.title }}
                                </option>
                            </select>
                            <select
                                v-model="finish.key"
                                class="h-10 rounded-md border px-3 text-sm"
                                :disabled="finish.mode !== 'draft'"
                                aria-label="Course and lecturer"
                                :required="finish.mode === 'draft'"
                            >
                                <option value="" disabled>
                                    Choose the course and lecturer…
                                </option>
                                <option
                                    v-for="item in filteredAssignments"
                                    :key="item.key"
                                    :value="item.key"
                                    :disabled="item.blocked"
                                >
                                    {{ item.course }} · {{ item.part }} ·
                                    {{ item.lecturer
                                    }}{{
                                        item.blocked
                                            ? ' (report already in review)'
                                            : item.replacesDraft
                                              ? ' (replaces your draft)'
                                              : ''
                                    }}
                                </option>
                            </select>
                            <InputError :message="finish.errors.key" />
                        </template>
                    </span>
                </label>

                <label
                    class="flex items-start gap-3 rounded-md border p-3"
                    :class="
                        finish.mode === 'waiting'
                            ? 'border-[#c98a12] bg-[#fff9ec]'
                            : ''
                    "
                >
                    <input
                        v-model="finish.mode"
                        type="radio"
                        value="waiting"
                        class="mt-1 size-4"
                    />
                    <span class="grid min-w-0 flex-1 gap-2">
                        <span class="font-medium"
                            >Keep it in Waiting for Lecturer</span
                        >
                        <span class="text-sm text-[#4b6b58]">
                            Use this when the course has no lecturer assigned
                            yet, or you are not choosing a course now.
                        </span>
                        <select
                            v-model="finish.course_id"
                            class="h-10 rounded-md border px-3 text-sm"
                            :disabled="finish.mode !== 'waiting'"
                            aria-label="Course (optional)"
                        >
                            <option value="">No course chosen</option>
                            <option
                                v-for="course in courses"
                                :key="course.id"
                                :value="course.id"
                            >
                                {{ course.code }} {{ course.title }}
                            </option>
                        </select>
                    </span>
                </label>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="finishOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        class="bg-[#007a2f] hover:bg-[#006b30]"
                        :disabled="
                            finish.processing ||
                            (finish.mode === 'draft' && !finish.key)
                        "
                    >
                        {{
                            finish.mode === 'draft'
                                ? 'Create draft'
                                : 'Move to Waiting for Lecturer'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
