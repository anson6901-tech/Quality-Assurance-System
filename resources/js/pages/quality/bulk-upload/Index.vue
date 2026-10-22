<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    CircleAlert,
    Download,
    FileText,
    FolderUp,
    ListChecks,
    RefreshCw,
    Send,
    Trash2,
    Upload,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PageBanner from '@/components/PageBanner.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { id: number; name: string };
type Semester = Option & { academic_year_id: number };
type ItemStatus = 'ready' | 'needs_choice' | 'error' | 'created';
type Item = {
    id: number;
    name: string;
    size: number;
    status: ItemStatus;
    problem: string | null;
    course: { code: string; title: string } | null;
    lecturer: string | null;
    part: string | null;
    candidates: { key: string; label: string }[];
    reportCount: number;
};
type Batch = {
    id: number;
    academicYearId: number;
    semesterId: number;
    academicYear: string | null;
    semester: string | null;
    category: string;
    items: Item[];
    draftReports: number;
};
type BatchSummary = {
    id: number;
    academicYear: string | null;
    semester: string | null;
    category: string;
    files: number;
    ready: number;
    created: number;
    createdAt: string;
};

const props = defineProps<{
    academicYears: Option[];
    semesters: Semester[];
    batches: BatchSummary[];
    batch: Batch | null;
    supervisors: { id: number; name: string; email: string }[];
    maxFileMegabytes: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Bulk Upload', href: '/quality/bulk-upload' }],
    },
});

const startForm = useForm({
    academic_year_id: (props.academicYears[0]?.id ?? '') as number | '',
    semester_id: '' as number | '',
    category: 'Academic Quality',
});
const yearSemesters = computed(() =>
    props.semesters.filter(
        (semester) => semester.academic_year_id === startForm.academic_year_id,
    ),
);
watch(
    yearSemesters,
    (semesters) => {
        if (
            !semesters.some((semester) => semester.id === startForm.semester_id)
        ) {
            startForm.semester_id = semesters[0]?.id ?? '';
        }
    },
    { immediate: true },
);
const checklistUrl = computed(() =>
    startForm.academic_year_id && startForm.semester_id
        ? `/quality/bulk-upload/checklist?academic_year_id=${startForm.academic_year_id}&semester_id=${startForm.semester_id}`
        : null,
);
const startBatch = () =>
    startForm.post('/quality/bulk-upload/batches', { preserveScroll: true });

// Uploading: files are sent one by one (three at a time) and added to the table as they finish.
const items = ref<Item[]>([]);
watch(
    () => props.batch?.items,
    (next) => (items.value = next ? [...next] : []),
    { immediate: true },
);

type Upload = {
    name: string;
    state: 'waiting' | 'sending' | 'done' | 'failed';
    error?: string;
};
const uploads = ref<Upload[]>([]);
const skipped = ref(0);
const uploading = computed(() =>
    uploads.value.some(
        (upload) => upload.state === 'waiting' || upload.state === 'sending',
    ),
);
const uploadedCount = computed(
    () =>
        uploads.value.filter(
            (upload) => upload.state === 'done' || upload.state === 'failed',
        ).length,
);
const folderInput = ref<HTMLInputElement | null>(null);
const filesInput = ref<HTMLInputElement | null>(null);

const xsrfToken = () =>
    decodeURIComponent(
        document.cookie
            .split('; ')
            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );

const sendFile = async (file: File, upload: Upload) => {
    if (!props.batch) {
        return;
    }

    upload.state = 'sending';
    const body = new FormData();
    body.append('file', file);

    try {
        const response = await fetch(
            `/quality/bulk-upload/batches/${props.batch.id}/files`,
            {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
            },
        );

        if (!response.ok) {
            const payload = await response.json().catch(() => null);
            upload.state = 'failed';
            upload.error =
                response.status === 413
                    ? `Larger than ${props.maxFileMegabytes} MB`
                    : (payload?.errors?.file?.[0] ??
                      payload?.message ??
                      `Upload failed (${response.status})`);

            return;
        }

        const { item } = (await response.json()) as { item: Item };
        items.value = [...items.value, item].sort((a, b) =>
            a.name.localeCompare(b.name),
        );
        upload.state = 'done';
    } catch {
        upload.state = 'failed';
        upload.error = 'Network error. Choose the file again to retry.';
    }
};

const queueFiles = async (fileList: FileList | null) => {
    const files = Array.from(fileList ?? []).filter(
        (file) => !file.name.startsWith('.'),
    );
    skipped.value = (fileList?.length ?? 0) - files.length;
    uploads.value = files.map((file) => ({
        name: file.name,
        state: 'waiting',
    }));

    const queue = files.map((file, index) => ({
        file,
        upload: uploads.value[index],
    }));
    const worker = async () => {
        for (let next = queue.shift(); next; next = queue.shift()) {
            await sendFile(next.file, next.upload);
        }
    };

    await Promise.all([worker(), worker(), worker()]);
    router.reload({ only: ['batch', 'batches'] });
};

const onPicked = (event: Event) => {
    const input = event.target as HTMLInputElement;
    queueFiles(input.files);
    input.value = '';
};

// Review
type Filter = 'all' | 'ready' | 'attention' | 'created';
const filter = ref<Filter>('all');
const counts = computed(() => ({
    all: items.value.length,
    ready: items.value.filter((item) => item.status === 'ready').length,
    attention: items.value.filter(
        (item) => item.status === 'error' || item.status === 'needs_choice',
    ).length,
    created: items.value.filter((item) => item.status === 'created').length,
}));
const visibleItems = computed(() =>
    items.value.filter((item) =>
        filter.value === 'all'
            ? true
            : filter.value === 'attention'
              ? item.status === 'error' || item.status === 'needs_choice'
              : item.status === filter.value,
    ),
);
const filters: { key: Filter; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'ready', label: 'Ready' },
    { key: 'attention', label: 'Needs attention' },
    { key: 'created', label: 'Created' },
];

const choices = ref<Record<number, string>>({});
const choose = (item: Item) =>
    props.batch &&
    choices.value[item.id] &&
    router.patch(
        `/quality/bulk-upload/batches/${props.batch.id}/items/${item.id}`,
        { key: choices.value[item.id] },
        { preserveScroll: true },
    );
const removeItem = (item: Item) =>
    props.batch &&
    router.delete(
        `/quality/bulk-upload/batches/${props.batch.id}/items/${item.id}`,
        { preserveScroll: true },
    );
const recheck = () =>
    props.batch &&
    router.post(
        `/quality/bulk-upload/batches/${props.batch.id}/recheck`,
        {},
        { preserveScroll: true },
    );
const creating = ref(false);
const createDrafts = () => {
    if (
        !props.batch ||
        !window.confirm(
            `Create draft reports from ${counts.value.ready} ready file(s)?`,
        )
    ) {
        return;
    }

    router.post(
        `/quality/bulk-upload/batches/${props.batch.id}/drafts`,
        {},
        {
            preserveScroll: true,
            onStart: () => (creating.value = true),
            onFinish: () => (creating.value = false),
        },
    );
};

const submitForm = useForm({ supervisor_id: '' as number | '' });
const submitDrafts = () =>
    props.batch &&
    submitForm.post(`/quality/bulk-upload/batches/${props.batch.id}/submit`, {
        preserveScroll: true,
    });

const statusStyle: Record<ItemStatus, string> = {
    ready: 'border-[#9fd8b8] bg-[#edf9f2] text-[#075b2e]',
    needs_choice:
        'border-[#f0d3a4] bg-[#fffaf1] text-[#8a5a12] dark:border-amber-700/60 dark:bg-amber-950/30 dark:text-amber-200',
    error: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200',
    created:
        'border-[#bcd3f5] bg-[#eef4fd] text-[#1d4f91] dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200',
};
const statusLabel: Record<ItemStatus, string> = {
    ready: 'Ready',
    needs_choice: 'Choose part',
    error: 'Problem',
    created: 'Draft created',
};
const megabytes = (bytes: number) => `${(bytes / 1048576).toFixed(1)} MB`;
</script>

<template>
    <Head title="Bulk Upload" />

    <div class="space-y-6">
        <PageBanner
            title="Bulk Upload"
            description="Upload a whole folder of report PDFs for a semester. Each file is matched to the course part and assigned lecturer from its name, then created as a draft report."
        />

        <section
            class="grid gap-6 rounded-xl border border-[#9fd8b8] bg-white p-5 shadow-sm lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
        >
            <form class="space-y-4" @submit.prevent="startBatch">
                <h2
                    class="flex items-center gap-2 text-lg font-semibold text-[#063f22]"
                >
                    <ListChecks class="size-5 text-[#007a2f]" /> 1. Choose the
                    semester
                </h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="bulk-year">Academic year</Label>
                        <select
                            id="bulk-year"
                            v-model="startForm.academic_year_id"
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
                        <InputError
                            :message="startForm.errors.academic_year_id"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="bulk-semester">Semester</Label>
                        <select
                            id="bulk-semester"
                            v-model="startForm.semester_id"
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
                        <InputError :message="startForm.errors.semester_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="bulk-category">Report category</Label>
                        <Input
                            id="bulk-category"
                            v-model="startForm.category"
                            required
                        />
                        <InputError :message="startForm.errors.category" />
                    </div>
                </div>
                <p v-if="!academicYears.length" class="text-sm text-[#8a5a12]">
                    There is no active academic year. Activate one on Academic
                    Years first.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        as-child
                        variant="outline"
                        class="border-[#007a2f]/30 text-[#007a2f]"
                        :disabled="!checklistUrl"
                    >
                        <a :href="checklistUrl ?? undefined" download>
                            <Download /> Download checklist
                        </a>
                    </Button>
                    <Button
                        type="submit"
                        class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                        :disabled="
                            startForm.processing || !startForm.semester_id
                        "
                    >
                        <FolderUp /> Start a batch
                    </Button>
                </div>
            </form>

            <div class="space-y-2 text-sm text-[#3c5059]">
                <h3 class="font-semibold text-[#063f22]">
                    How to prepare the folder
                </h3>
                <ol class="list-decimal space-y-1 pl-5">
                    <li>
                        Download the checklist. It lists every report the
                        semester needs, from the lecturer assignments.
                    </li>
                    <li>
                        Name each PDF exactly as in its
                        <strong>file_name</strong> column, for example
                        <code class="rounded bg-[#edf9f2] px-1"
                            >NURS101_SON-1001.pdf</code
                        >.
                    </li>
                    <li>
                        Put them all in one folder, then start a batch and
                        choose the folder.
                    </li>
                </ol>
                <p class="text-xs text-muted-foreground">
                    Only lecturers assigned to a course part this semester can
                    receive a report. PDFs up to {{ maxFileMegabytes }} MB.
                </p>
            </div>
        </section>

        <section
            v-if="batches.length"
            class="rounded-xl border border-[#9fd8b8] bg-white p-4 shadow-sm"
        >
            <h2 class="mb-3 text-sm font-semibold text-[#063f22]">
                Your batches
            </h2>
            <div class="flex flex-wrap gap-2">
                <Link
                    v-for="summary in batches"
                    :key="summary.id"
                    :href="`/quality/bulk-upload?batch=${summary.id}`"
                    preserve-scroll
                    class="rounded-lg border px-3 py-2 text-xs"
                    :class="
                        batch?.id === summary.id
                            ? 'border-[#007a2f] bg-[#edf9f2] text-[#063f22]'
                            : 'border-[#d5ece0] text-[#3c5059] hover:bg-[#edf9f2] dark:border-white/10'
                    "
                >
                    <span class="block font-semibold">
                        {{ summary.academicYear }} · {{ summary.semester }}
                    </span>
                    {{ summary.files }} files · {{ summary.created }} created ·
                    {{ new Date(summary.createdAt).toLocaleDateString() }}
                </Link>
            </div>
        </section>

        <template v-if="batch">
            <section
                class="space-y-4 rounded-xl border border-[#9fd8b8] bg-white p-5 shadow-sm"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2
                        class="flex items-center gap-2 text-lg font-semibold text-[#063f22]"
                    >
                        <Upload class="size-5 text-[#007a2f]" /> 2. Upload the
                        PDFs
                        <span class="text-sm font-normal text-muted-foreground">
                            {{ batch.academicYear }} · {{ batch.semester }} ·
                            {{ batch.category }}
                        </span>
                    </h2>
                    <div class="flex flex-wrap gap-2">
                        <input
                            ref="folderInput"
                            type="file"
                            class="hidden"
                            webkitdirectory
                            multiple
                            @change="onPicked"
                        />
                        <input
                            ref="filesInput"
                            type="file"
                            class="hidden"
                            accept="application/pdf,.pdf"
                            multiple
                            @change="onPicked"
                        />
                        <Button
                            type="button"
                            class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                            :disabled="uploading"
                            @click="folderInput?.click()"
                        >
                            <FolderUp /> Choose folder
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            class="border-[#007a2f]/30 text-[#007a2f]"
                            :disabled="uploading"
                            @click="filesInput?.click()"
                        >
                            <FileText /> Choose files
                        </Button>
                    </div>
                </div>

                <div v-if="uploads.length" class="space-y-2">
                    <div class="flex justify-between text-sm text-[#3c5059]">
                        <span>
                            {{ uploading ? 'Uploading' : 'Uploaded' }}
                            {{ uploadedCount }} of {{ uploads.length }} files
                            <template v-if="skipped">
                                · {{ skipped }} hidden files skipped</template
                            >
                        </span>
                        <span
                            >{{
                                Math.round(
                                    (uploadedCount / uploads.length) * 100,
                                )
                            }}%</span
                        >
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-[#edf9f2]">
                        <div
                            class="h-full bg-[#007a2f] transition-all"
                            :style="{
                                width: `${(uploadedCount / uploads.length) * 100}%`,
                            }"
                        />
                    </div>
                    <ul
                        v-if="
                            uploads.some((upload) => upload.state === 'failed')
                        "
                        class="space-y-1 text-xs text-red-700 dark:text-red-300"
                    >
                        <li
                            v-for="upload in uploads.filter(
                                (entry) => entry.state === 'failed',
                            )"
                            :key="upload.name"
                        >
                            {{ upload.name }}: {{ upload.error }}
                        </li>
                    </ul>
                </div>
            </section>

            <section
                class="space-y-4 rounded-xl border border-[#9fd8b8] bg-white p-5 shadow-sm"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2
                        class="flex items-center gap-2 text-lg font-semibold text-[#063f22]"
                    >
                        <CheckCircle2 class="size-5 text-[#007a2f]" /> 3. Review
                        and create
                    </h2>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="!items.length"
                            @click="recheck"
                        >
                            <RefreshCw /> Check again
                        </Button>
                        <Button
                            type="button"
                            class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                            :disabled="!counts.ready || creating || uploading"
                            @click="createDrafts"
                        >
                            <CheckCircle2 /> Create {{ counts.ready }} draft
                            report{{ counts.ready === 1 ? '' : 's' }}
                        </Button>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2" role="tablist">
                    <button
                        v-for="option in filters"
                        :key="option.key"
                        type="button"
                        role="tab"
                        :aria-selected="filter === option.key"
                        class="rounded-full border px-3 py-1 text-xs font-medium"
                        :class="
                            filter === option.key
                                ? 'border-[#007a2f] bg-[#007a2f] text-white'
                                : 'border-[#d5ece0] text-[#3c5059] dark:border-white/10'
                        "
                        @click="filter = option.key"
                    >
                        {{ option.label }} ({{ counts[option.key] }})
                    </button>
                </div>

                <div
                    v-if="visibleItems.length"
                    class="divide-y divide-[#e3efe8] dark:divide-white/10"
                >
                    <div
                        v-for="item in visibleItems"
                        :key="item.id"
                        class="grid gap-3 py-3 text-sm md:grid-cols-[minmax(0,1.4fr)_minmax(0,2fr)_auto] md:items-start"
                    >
                        <div class="min-w-0">
                            <div
                                class="truncate font-medium text-[#063f22]"
                                :title="item.name"
                            >
                                {{ item.name }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ megabytes(item.size) }}
                            </div>
                        </div>
                        <div class="min-w-0 space-y-1">
                            <div v-if="item.course" class="text-[#1d2f37]">
                                <strong>{{ item.course.code }}</strong>
                                {{ item.course.title }}
                                <span
                                    v-if="item.part"
                                    class="text-muted-foreground"
                                    >· {{ item.part }}</span
                                >
                                <span
                                    v-if="item.lecturer"
                                    class="block text-xs text-[#3c5059]"
                                >
                                    Lecturer: {{ item.lecturer }}
                                </span>
                            </div>
                            <p
                                v-if="item.problem"
                                class="text-xs text-red-700 dark:text-red-300"
                            >
                                {{ item.problem }}
                            </p>
                            <p
                                v-if="item.status === 'created'"
                                class="text-xs text-[#1d4f91] dark:text-blue-200"
                            >
                                {{ item.reportCount }} draft report{{
                                    item.reportCount === 1 ? '' : 's'
                                }}
                                created.
                            </p>
                            <div
                                v-if="item.status === 'needs_choice'"
                                class="flex flex-wrap items-center gap-2"
                            >
                                <select
                                    v-model="choices[item.id]"
                                    class="h-9 min-w-0 flex-1 rounded-md border px-2 text-xs"
                                    :aria-label="`Course part for ${item.name}`"
                                >
                                    <option :value="undefined" disabled>
                                        Choose the part
                                    </option>
                                    <option
                                        v-for="candidate in item.candidates"
                                        :key="candidate.key"
                                        :value="candidate.key"
                                    >
                                        {{ candidate.label }}
                                    </option>
                                </select>
                                <Button
                                    type="button"
                                    size="sm"
                                    class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                                    :disabled="!choices[item.id]"
                                    @click="choose(item)"
                                >
                                    Use this part
                                </Button>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 md:justify-end">
                            <span
                                class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold"
                                :class="statusStyle[item.status]"
                            >
                                <CheckCircle2
                                    v-if="
                                        item.status === 'ready' ||
                                        item.status === 'created'
                                    "
                                    class="size-3.5"
                                />
                                <CircleAlert
                                    v-else-if="item.status === 'needs_choice'"
                                    class="size-3.5"
                                />
                                <XCircle v-else class="size-3.5" />
                                {{ statusLabel[item.status] }}
                            </span>
                            <Button
                                v-if="item.status !== 'created'"
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-8 text-red-700 hover:bg-red-50 hover:text-red-800"
                                :title="`Remove ${item.name}`"
                                @click="removeItem(item)"
                            >
                                <Trash2 class="size-4" />
                                <span class="sr-only"
                                    >Remove {{ item.name }}</span
                                >
                            </Button>
                        </div>
                    </div>
                </div>
                <p
                    v-else
                    class="rounded-lg border border-dashed border-[#9fd8b8] py-8 text-center text-sm text-muted-foreground"
                >
                    {{
                        items.length
                            ? 'No files in this view.'
                            : 'No files uploaded yet. Choose a folder above.'
                    }}
                </p>
            </section>

            <form
                v-if="batch.draftReports"
                class="flex flex-wrap items-end gap-3 rounded-xl border border-[#9fd8b8] bg-white p-5 shadow-sm"
                @submit.prevent="submitDrafts"
            >
                <div class="grid min-w-0 flex-1 gap-2">
                    <h2
                        class="flex items-center gap-2 text-lg font-semibold text-[#063f22]"
                    >
                        <Send class="size-5 text-[#007a2f]" /> 4. Submit to a QA
                        Supervisor
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ batch.draftReports }} draft report{{
                            batch.draftReports === 1 ? '' : 's'
                        }}
                        from this batch are waiting. You can also open them on
                        Upload Reports first.
                    </p>
                    <select
                        v-model="submitForm.supervisor_id"
                        class="h-10 rounded-md border px-3 text-sm"
                        aria-label="QA Supervisor"
                        required
                    >
                        <option value="" disabled>
                            Choose a QA Supervisor
                        </option>
                        <option
                            v-for="supervisor in supervisors"
                            :key="supervisor.id"
                            :value="supervisor.id"
                        >
                            {{ supervisor.name }} — {{ supervisor.email }}
                        </option>
                    </select>
                    <InputError :message="submitForm.errors.supervisor_id" />
                </div>
                <Button
                    type="submit"
                    class="bg-[#007a2f] text-white hover:bg-[#006d2b]"
                    :disabled="
                        submitForm.processing || !submitForm.supervisor_id
                    "
                >
                    <Send /> Submit {{ batch.draftReports }} report{{
                        batch.draftReports === 1 ? '' : 's'
                    }}
                </Button>
            </form>
        </template>
    </div>
</template>
