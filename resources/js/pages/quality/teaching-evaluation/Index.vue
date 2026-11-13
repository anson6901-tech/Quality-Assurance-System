<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    FolderClock,
    Play,
    Search,
    SlidersHorizontal,
    Trash2,
    X,
} from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
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
import type { AssignmentOption } from '@/types/evaluation';

type Option = { id: number; name: string };
type Analysis = {
    id: number;
    name: string;
    status: 'analysed' | 'waiting' | 'drafted';
    academicYear: string | null;
    semester: string | null;
    course: string | null;
    lecturer: string | null;
    responses: number;
    createdAt: string;
    draftedAt: string | null;
    assignments: AssignmentOption[];
};

const props = defineProps<{
    analyses: Analysis[];
    academicYears: Option[];
    semesters: (Option & { academic_year_id: number })[];
    maxFileMegabytes: number;
    folder: 'all' | 'waiting';
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

const folder = ref<'all' | 'waiting'>(props.folder);
const waiting = computed(() =>
    props.analyses.filter((analysis) => analysis.status === 'waiting'),
);
const inFolder = computed(() =>
    folder.value === 'waiting' ? waiting.value : props.analyses,
);

// Filters
const filters = reactive({
    file: '',
    semester: '',
    course: '',
    lecturer: '',
    minResponses: '' as number | '',
    maxResponses: '' as number | '',
    status: '' as Analysis['status'] | '',
});
const semesterLabel = (analysis: Analysis) =>
    `${analysis.academicYear ?? ''} · ${analysis.semester ?? ''}`;
const semesterOptions = computed(() =>
    [...new Set(props.analyses.map(semesterLabel))].sort(),
);
const contains = (value: string | null, search: string) =>
    (value ?? '').toLowerCase().includes(search.trim().toLowerCase());
const filtering = computed(() =>
    Object.values(filters).some((value) => value !== ''),
);
const shown = computed(() =>
    inFolder.value.filter(
        (analysis) =>
            contains(analysis.name, filters.file) &&
            (!filters.semester ||
                semesterLabel(analysis) === filters.semester) &&
            contains(analysis.course, filters.course) &&
            contains(analysis.lecturer, filters.lecturer) &&
            (filters.minResponses === '' ||
                analysis.responses >= filters.minResponses) &&
            (filters.maxResponses === '' ||
                analysis.responses <= filters.maxResponses) &&
            (!filters.status || analysis.status === filters.status),
    ),
);
const clearFilters = () =>
    Object.assign(filters, {
        file: '',
        semester: '',
        course: '',
        lecturer: '',
        minResponses: '',
        maxResponses: '',
        status: '',
    });

// Start Analysis
const startOpen = ref(false);
const start = useForm({
    academic_year_id: (props.academicYears[0]?.id ?? '') as number | '',
    semester_id: '' as number | '',
    file: null as File | null,
});
const yearSemesters = computed(() =>
    props.semesters.filter(
        (semester) => semester.academic_year_id === start.academic_year_id,
    ),
);
watch(
    yearSemesters,
    (semesters) => {
        if (!semesters.some((semester) => semester.id === start.semester_id)) {
            start.semester_id = semesters.at(-1)?.id ?? '';
        }
    },
    { immediate: true },
);
const submitStart = () =>
    start.post('/quality/teaching-evaluation', { forceFormData: true });

// Waiting for Lecturer → Move to draft
const chosen = reactive<Record<number, string>>({});
const moving = ref<number | null>(null);
const moveErrors = reactive<Record<number, string | undefined>>({});
const moveToDraft = (analysis: Analysis) => {
    moving.value = analysis.id;
    moveErrors[analysis.id] = undefined;
    router.post(
        `/quality/teaching-evaluation/${analysis.id}/move-to-draft`,
        { key: chosen[analysis.id] },
        {
            preserveScroll: true,
            onError: (errors) =>
                (moveErrors[analysis.id] = Object.values(errors)[0]),
            onFinish: () => (moving.value = null),
        },
    );
};

// Delete, confirmed in the app's own dialog (browser pop-ups can be blocked).
const deleting = ref<Analysis | null>(null);
const deleteBusy = ref(false);
const confirmDelete = () => {
    if (!deleting.value) {
        return;
    }

    deleteBusy.value = true;
    router.delete(`/quality/teaching-evaluation/${deleting.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (deleting.value = null),
        onFinish: () => (deleteBusy.value = false),
    });
};

const STATUS: Record<Analysis['status'], { label: string; class: string }> = {
    analysed: { label: 'In analysis', class: 'bg-[#e8f0fb] text-[#1f4b8f]' },
    waiting: {
        label: 'Waiting for lecturer',
        class: 'bg-[#fff1d6] text-[#7a4b00]',
    },
    drafted: { label: 'Draft created', class: 'bg-[#dff1e6] text-[#0b5a2a]' },
};
</script>

<template>
    <Head title="Teaching Evaluation" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-center gap-2" role="tablist">
            <Button
                role="tab"
                :aria-selected="folder === 'all'"
                :variant="folder === 'all' ? 'default' : 'outline'"
                :class="
                    folder === 'all' ? 'bg-[#007a2f] hover:bg-[#006b30]' : ''
                "
                @click="folder = 'all'"
            >
                All analyses ({{ analyses.length }})
            </Button>
            <Button
                role="tab"
                :aria-selected="folder === 'waiting'"
                :variant="folder === 'waiting' ? 'default' : 'outline'"
                :class="
                    folder === 'waiting'
                        ? 'bg-[#c98a12] hover:bg-[#b37a0e]'
                        : ''
                "
                @click="folder = 'waiting'"
            >
                <FolderClock class="size-4" /> Waiting for Lecturer ({{
                    waiting.length
                }})
            </Button>
            <Button
                class="ml-auto bg-[#007a2f] hover:bg-[#006b30]"
                @click="startOpen = true"
            >
                <Play class="size-4" /> Start Analysis
            </Button>
        </div>

        <section
            class="rounded-xl border border-[#9fd8b8] bg-white shadow-sm"
            aria-label="Filter analyses"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b border-[#d6e9de] px-4 py-3"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-[#063f22]"
                >
                    <SlidersHorizontal class="size-4 text-[#007a2f]" />
                    Filter analyses
                </h2>
                <div class="flex items-center gap-3 text-sm text-[#4b6b58]">
                    <span>
                        Showing {{ shown.length }} of {{ inFolder.length }}
                    </span>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="!filtering"
                        @click="clearFilters"
                    >
                        <X class="size-4" /> Clear
                    </Button>
                </div>
            </div>
            <div
                class="grid grid-cols-1 gap-x-4 gap-y-3 p-4 sm:grid-cols-2 xl:grid-cols-4"
            >
                <div class="grid min-w-0 gap-1.5 sm:col-span-2">
                    <label
                        for="filter-file"
                        class="text-xs font-semibold tracking-wide text-[#4b6b58] uppercase"
                        >Data file</label
                    >
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#4b6b58]"
                        />
                        <input
                            id="filter-file"
                            v-model="filters.file"
                            type="search"
                            placeholder="Search by file name"
                            class="h-9 w-full min-w-0 rounded-md border border-input bg-white px-3 pl-9 text-sm shadow-xs focus-visible:border-[#007a2f] focus-visible:ring-2 focus-visible:ring-[#007a2f]/20 focus-visible:outline-none"
                        />
                    </div>
                </div>
                <div class="grid min-w-0 gap-1.5">
                    <label
                        for="filter-semester"
                        class="text-xs font-semibold tracking-wide text-[#4b6b58] uppercase"
                        >Semester</label
                    >
                    <select
                        id="filter-semester"
                        v-model="filters.semester"
                        class="h-9 w-full min-w-0 rounded-md border border-input bg-white px-3 text-sm shadow-xs focus-visible:border-[#007a2f] focus-visible:ring-2 focus-visible:ring-[#007a2f]/20 focus-visible:outline-none"
                    >
                        <option value="">All semesters</option>
                        <option
                            v-for="option in semesterOptions"
                            :key="option"
                            :value="option"
                        >
                            {{ option }}
                        </option>
                    </select>
                </div>
                <div class="grid min-w-0 gap-1.5">
                    <label
                        for="filter-status"
                        class="text-xs font-semibold tracking-wide text-[#4b6b58] uppercase"
                        >Status</label
                    >
                    <select
                        id="filter-status"
                        v-model="filters.status"
                        class="h-9 w-full min-w-0 rounded-md border border-input bg-white px-3 text-sm shadow-xs focus-visible:border-[#007a2f] focus-visible:ring-2 focus-visible:ring-[#007a2f]/20 focus-visible:outline-none"
                    >
                        <option value="">All statuses</option>
                        <option
                            v-for="(item, key) in STATUS"
                            :key="key"
                            :value="key"
                        >
                            {{ item.label }}
                        </option>
                    </select>
                </div>
                <div class="grid min-w-0 gap-1.5">
                    <label
                        for="filter-course"
                        class="text-xs font-semibold tracking-wide text-[#4b6b58] uppercase"
                        >Course</label
                    >
                    <input
                        id="filter-course"
                        v-model="filters.course"
                        type="search"
                        placeholder="Code or title"
                        class="h-9 w-full min-w-0 rounded-md border border-input bg-white px-3 text-sm shadow-xs focus-visible:border-[#007a2f] focus-visible:ring-2 focus-visible:ring-[#007a2f]/20 focus-visible:outline-none"
                    />
                </div>
                <div class="grid min-w-0 gap-1.5">
                    <label
                        for="filter-lecturer"
                        class="text-xs font-semibold tracking-wide text-[#4b6b58] uppercase"
                        >Lecturer</label
                    >
                    <input
                        id="filter-lecturer"
                        v-model="filters.lecturer"
                        type="search"
                        placeholder="Lecturer name"
                        class="h-9 w-full min-w-0 rounded-md border border-input bg-white px-3 text-sm shadow-xs focus-visible:border-[#007a2f] focus-visible:ring-2 focus-visible:ring-[#007a2f]/20 focus-visible:outline-none"
                    />
                </div>
                <fieldset class="grid min-w-0 gap-1.5 sm:col-span-2">
                    <legend
                        class="mb-1.5 text-xs font-semibold tracking-wide text-[#4b6b58] uppercase"
                    >
                        Responses
                    </legend>
                    <div class="flex min-w-0 items-center gap-2">
                        <input
                            v-model.number="filters.minResponses"
                            type="number"
                            min="0"
                            placeholder="From"
                            aria-label="Fewest responses"
                            class="h-9 w-full min-w-0 rounded-md border border-input bg-white px-3 text-sm shadow-xs focus-visible:border-[#007a2f] focus-visible:ring-2 focus-visible:ring-[#007a2f]/20 focus-visible:outline-none"
                        />
                        <span class="shrink-0 text-sm text-[#4b6b58]">to</span>
                        <input
                            v-model.number="filters.maxResponses"
                            type="number"
                            min="0"
                            placeholder="To"
                            aria-label="Most responses"
                            class="h-9 w-full min-w-0 rounded-md border border-input bg-white px-3 text-sm shadow-xs focus-visible:border-[#007a2f] focus-visible:ring-2 focus-visible:ring-[#007a2f]/20 focus-visible:outline-none"
                        />
                    </div>
                </fieldset>
            </div>
        </section>

        <section
            class="overflow-x-auto rounded-xl border border-[#9fd8b8] bg-white shadow-sm"
        >
            <table class="min-w-full text-sm">
                <thead class="bg-[#eef8f2] text-left text-[#063f22]">
                    <tr>
                        <th class="px-4 py-3">Data file</th>
                        <th class="px-4 py-3">Semester</th>
                        <th class="px-4 py-3">Course</th>
                        <th class="px-4 py-3">Lecturer</th>
                        <th class="px-4 py-3 text-right">Responses</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="analysis in shown"
                        :key="analysis.id"
                        class="border-t align-top"
                    >
                        <td class="px-4 py-3 font-medium">
                            <Link
                                :href="`/quality/teaching-evaluation/${analysis.id}`"
                                class="text-[#006b30] hover:underline"
                            >
                                {{ analysis.name }}
                            </Link>
                            <div class="text-xs text-[#4b6b58]">
                                {{
                                    new Date(
                                        analysis.createdAt,
                                    ).toLocaleDateString()
                                }}
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            {{ analysis.academicYear }} ·
                            {{ analysis.semester }}
                        </td>
                        <td class="px-4 py-3">{{ analysis.course ?? '—' }}</td>
                        <td class="px-4 py-3">
                            {{ analysis.lecturer ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ analysis.responses }}
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="STATUS[analysis.status].class"
                            >
                                {{ STATUS[analysis.status].label }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div
                                class="flex flex-wrap items-start justify-end gap-2"
                            >
                                <div
                                    v-if="analysis.status === 'waiting'"
                                    class="grid min-w-64 gap-1"
                                >
                                    <template
                                        v-if="analysis.assignments.length"
                                    >
                                        <select
                                            v-model="chosen[analysis.id]"
                                            class="h-9 rounded-md border px-2 text-sm"
                                            :aria-label="`Lecturer for ${analysis.name}`"
                                        >
                                            <option :value="undefined" disabled>
                                                Choose the lecturer…
                                            </option>
                                            <option
                                                v-for="item in analysis.assignments"
                                                :key="item.key"
                                                :value="item.key"
                                                :disabled="item.blocked"
                                            >
                                                {{ item.course }} ·
                                                {{ item.part }} ·
                                                {{ item.lecturer }}
                                            </option>
                                        </select>
                                        <Button
                                            size="sm"
                                            class="bg-[#007a2f] hover:bg-[#006b30]"
                                            :disabled="
                                                !chosen[analysis.id] ||
                                                moving === analysis.id
                                            "
                                            @click="moveToDraft(analysis)"
                                        >
                                            Move to draft
                                        </Button>
                                    </template>
                                    <p v-else class="text-xs text-[#7a4b00]">
                                        {{
                                            analysis.course
                                                ? 'No lecturer is assigned to this course yet.'
                                                : 'No course chosen and no lecturer assigned yet.'
                                        }}
                                    </p>
                                    <InputError
                                        :message="moveErrors[analysis.id]"
                                    />
                                </div>
                                <Button as-child size="sm" variant="outline">
                                    <Link
                                        :href="`/quality/teaching-evaluation/${analysis.id}`"
                                        >Open</Link
                                    >
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="text-[#8a1c1c]"
                                    :aria-label="`Delete ${analysis.name}`"
                                    @click="deleting = analysis"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!shown.length">
                        <td
                            colspan="7"
                            class="px-4 py-10 text-center text-[#4b6b58]"
                        >
                            {{
                                filtering && inFolder.length
                                    ? 'No analyses match these filters.'
                                    : folder === 'waiting'
                                      ? 'Nothing is waiting for a lecturer.'
                                      : 'No analyses yet. Click Start Analysis to upload an evaluation data file.'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>

    <Dialog :open="startOpen" @update:open="startOpen = $event">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Start Analysis</DialogTitle>
                <DialogDescription>
                    Upload the evaluation responses for one lecturer and course
                    (CSV or Excel, up to {{ maxFileMegabytes }} MB).
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submitStart">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="eval-year">Academic year</Label>
                        <select
                            id="eval-year"
                            v-model="start.academic_year_id"
                            class="h-10 rounded-md border px-3 text-sm"
                            required
                        >
                            <option
                                v-for="year in academicYears"
                                :key="year.id"
                                :value="year.id"
                            >
                                {{ year.name }}
                            </option>
                        </select>
                        <InputError :message="start.errors.academic_year_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="eval-semester">Semester</Label>
                        <select
                            id="eval-semester"
                            v-model="start.semester_id"
                            class="h-10 rounded-md border px-3 text-sm"
                            required
                        >
                            <option
                                v-for="semester in yearSemesters"
                                :key="semester.id"
                                :value="semester.id"
                            >
                                {{ semester.name }}
                            </option>
                        </select>
                        <InputError :message="start.errors.semester_id" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="eval-file">Data file</Label>
                    <input
                        id="eval-file"
                        type="file"
                        accept=".csv,.xlsx,.xls"
                        class="rounded-md border p-2 text-sm"
                        required
                        @change="
                            start.file =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                    <InputError :message="start.errors.file" />
                </div>
                <p v-if="!academicYears.length" class="text-sm text-[#8a5a12]">
                    There is no active academic year. Activate one on Academic
                    Years first.
                </p>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="startOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        class="bg-[#007a2f] hover:bg-[#006b30]"
                        :disabled="start.processing || !start.file"
                    >
                        {{
                            start.processing
                                ? 'Reading…'
                                : 'Open in Data Editor'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="deleting !== null"
        @update:open="(open) => !open && (deleting = null)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Delete this analysis?</DialogTitle>
                <DialogDescription>
                    "{{ deleting?.name }}" and its data file will be removed.
                    Draft reports already created from it are kept.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="deleting = null">
                    Cancel
                </Button>
                <Button
                    class="bg-[#b42318] text-white hover:bg-[#9a1d13]"
                    :disabled="deleteBusy"
                    @click="confirmDelete"
                >
                    <Trash2 class="size-4" /> Delete
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
