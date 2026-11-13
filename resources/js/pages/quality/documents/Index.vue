<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ChevronRight,
    Download,
    Eye,
    File as FileIcon,
    FileArchive,
    FileImage,
    FileSpreadsheet,
    FileText,
    Folder,
    FolderPlus,
    FolderInput,
    HardDrive,
    LayoutGrid,
    List,
    MoreVertical,
    Pencil,
    Search,
    Trash2,
    Upload,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import FolderPicker from '@/components/quality/FolderPicker.vue';
import type { FolderNode } from '@/components/quality/FolderPicker.vue';
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
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type FolderItem = {
    id: number;
    name: string;
    items: number;
    createdBy: string | null;
    updatedAt: string | null;
    location: string | null;
};
type FileItem = {
    id: number;
    name: string;
    mimeType: string | null;
    size: number;
    uploadedBy: string | null;
    updatedAt: string | null;
    viewable: boolean;
    folderId: number | null;
    location: string | null;
};

const props = defineProps<{
    folder: { id: number; name: string; parentId: number | null } | null;
    breadcrumbs: { id: number; name: string }[];
    search: string;
    folders: FolderItem[];
    files: FileItem[];
    maxFileMegabytes: number;
    maxFiles: number;
    tree: FolderNode[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Document Storage', href: '/quality/documents' },
        ],
    },
});

const searching = computed(() => props.search !== '');
const folderUrl = (id: number | null) =>
    id ? `/quality/documents?folder=${id}` : '/quality/documents';

// View: list or grid, remembered on this device.
const view = ref<'list' | 'grid'>('list');

onMounted(() => {
    try {
        if (window.localStorage.getItem('documents-view') === 'grid') {
            view.value = 'grid';
        }
    } catch {
        // Storage unavailable: keep the list view.
    }
});

watch(view, (value) => {
    try {
        window.localStorage.setItem('documents-view', value);
    } catch {
        // Ignore.
    }
});

// Search across every folder.
const query = ref(props.search);
watch(
    () => props.search,
    (value) => (query.value = value),
);
const runSearch = () =>
    router.get(
        '/quality/documents',
        query.value.trim()
            ? { q: query.value.trim() }
            : props.folder
              ? { folder: props.folder.id }
              : {},
        { preserveState: true },
    );
const clearSearch = () => {
    query.value = '';
    router.get(folderUrl(props.folder?.id ?? null));
};

// New folder
const folderOpen = ref(false);
const folderForm = useForm({ name: '', parent_id: null as number | null });
const openNewFolder = () => {
    folderForm.reset();
    folderForm.clearErrors();
    folderForm.parent_id = props.folder?.id ?? null;
    folderOpen.value = true;
};
const createFolder = () =>
    folderForm.post('/quality/documents/folders', {
        preserveScroll: true,
        onSuccess: () => (folderOpen.value = false),
    });

// Upload (button or drag and drop)
const fileInput = ref<HTMLInputElement | null>(null);
const uploadForm = useForm({
    folder_id: null as number | null,
    files: [] as File[],
});
const uploadError = computed(
    () =>
        uploadForm.errors.files ??
        Object.entries(uploadForm.errors).find(([key]) =>
            key.startsWith('files.'),
        )?.[1],
);
const upload = (list: FileList | File[] | null) => {
    const files = Array.from(list ?? []);

    if (!files.length) {
        return;
    }

    uploadForm.folder_id = props.folder?.id ?? null;
    uploadForm.files = files;
    uploadForm.post('/quality/documents/files', {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
};
const dragging = ref(false);
const onDrop = (event: DragEvent) => {
    dragging.value = false;

    if (!searching.value) {
        upload(event.dataTransfer?.files ?? null);
    }
};

// Rename
type Target = { kind: 'folder' | 'file'; id: number; name: string };
const renaming = ref<Target | null>(null);
const renameForm = useForm({ name: '' });
const openRename = (target: Target) => {
    renaming.value = target;
    renameForm.name = target.name;
    renameForm.clearErrors();
};
const rename = () => {
    if (!renaming.value) {
        return;
    }

    renameForm.patch(
        `/quality/documents/${renaming.value.kind === 'folder' ? 'folders' : 'files'}/${renaming.value.id}`,
        { preserveScroll: true, onSuccess: () => (renaming.value = null) },
    );
};

// Menu actions. They run after the menu has closed, so the dialog opens cleanly.
const askRename = (kind: Target['kind'], item: { id: number; name: string }) =>
    setTimeout(() => openRename({ kind, id: item.id, name: item.name }));
const askDelete = (
    kind: Target['kind'],
    item: { id: number; name: string; items?: number },
) =>
    setTimeout(() => {
        deleting.value = {
            kind,
            id: item.id,
            name: item.name,
            items: item.items,
        };
    });

// Selection: tick folders and files, then delete them together.
const selectedFolders = ref<number[]>([]);
const selectedFiles = ref<number[]>([]);
const selectedCount = computed(
    () => selectedFolders.value.length + selectedFiles.value.length,
);
const allSelected = computed(
    () =>
        props.folders.length + props.files.length > 0 &&
        selectedFolders.value.length === props.folders.length &&
        selectedFiles.value.length === props.files.length,
);
const toggle = (list: typeof selectedFolders, id: number) => {
    list.value = list.value.includes(id)
        ? list.value.filter((item) => item !== id)
        : [...list.value, id];
};
const toggleFolder = (id: number) => toggle(selectedFolders, id);
const toggleFile = (id: number) => toggle(selectedFiles, id);
const toggleAll = () => {
    const select = !allSelected.value;
    selectedFolders.value = select ? props.folders.map((item) => item.id) : [];
    selectedFiles.value = select ? props.files.map((item) => item.id) : [];
};
const clearSelection = () => {
    selectedFolders.value = [];
    selectedFiles.value = [];
};
// Opening another folder or searching starts a fresh selection.
watch(() => [props.folder?.id, props.search], clearSelection);
const askDeleteSelected = () => {
    deleting.value = {
        kind: 'selection',
        id: 0,
        name: '',
        items: selectedCount.value,
    };
};

// Move: one item from its menu, or everything selected.
const moving = ref<{
    folderIds: number[];
    fileIds: number[];
    label: string;
} | null>(null);
const moveForm = useForm({
    folder_ids: [] as number[],
    file_ids: [] as number[],
    target_id: null as number | null,
});
const openMove = (folderIds: number[], fileIds: number[], label: string) => {
    moveForm.clearErrors();
    moveForm.target_id = props.folder?.id ?? null;
    moving.value = { folderIds, fileIds, label };
};
const askMove = (kind: Target['kind'], item: { id: number; name: string }) =>
    setTimeout(() =>
        openMove(
            kind === 'folder' ? [item.id] : [],
            kind === 'file' ? [item.id] : [],
            `"${item.name}"`,
        ),
    );
const askMoveSelected = () =>
    openMove(
        [...selectedFolders.value],
        [...selectedFiles.value],
        `${selectedCount.value} selected ${selectedCount.value === 1 ? 'item' : 'items'}`,
    );
// A folder cannot go into itself or a folder inside it.
const blockedTargets = computed(() => {
    const blocked = new Set(moving.value?.folderIds ?? []);
    let grew = true;

    while (grew) {
        grew = false;
        props.tree.forEach((folder) => {
            if (
                folder.parentId !== null &&
                blocked.has(folder.parentId) &&
                !blocked.has(folder.id)
            ) {
                blocked.add(folder.id);
                grew = true;
            }
        });
    }

    return [...blocked];
});
const move = () => {
    if (!moving.value) {
        return;
    }

    moveForm.folder_ids = moving.value.folderIds;
    moveForm.file_ids = moving.value.fileIds;
    moveForm.post('/quality/documents/move-selected', {
        preserveScroll: true,
        onSuccess: () => {
            moving.value = null;
            clearSelection();
        },
    });
};

// Delete
const deleting = ref<
    | (Omit<Target, 'kind'> & {
          kind: Target['kind'] | 'selection';
          items?: number;
      })
    | null
>(null);
const deleteBusy = ref(false);
const confirmDelete = () => {
    if (!deleting.value) {
        return;
    }

    deleteBusy.value = true;

    if (deleting.value.kind === 'selection') {
        router.post(
            '/quality/documents/delete-selected',
            {
                folder_ids: selectedFolders.value,
                file_ids: selectedFiles.value,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    deleting.value = null;
                    clearSelection();
                },
                onFinish: () => (deleteBusy.value = false),
            },
        );

        return;
    }

    router.delete(
        `/quality/documents/${deleting.value.kind === 'folder' ? 'folders' : 'files'}/${deleting.value.id}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                deleting.value = null;
                clearSelection();
            },
            onFinish: () => (deleteBusy.value = false),
        },
    );
};

const fileIcon = (file: FileItem) => {
    const type = file.mimeType ?? '';
    const name = file.name.toLowerCase();

    if (type.startsWith('image/')) {
        return FileImage;
    }

    if (/spreadsheet|excel|csv/.test(type) || /\.(xlsx?|csv)$/.test(name)) {
        return FileSpreadsheet;
    }

    if (/zip|compressed|tar|rar/.test(type)) {
        return FileArchive;
    }

    if (/pdf|word|document|text|presentation/.test(type)) {
        return FileText;
    }

    return FileIcon;
};
const fileColor = (file: FileItem) => {
    const type = file.mimeType ?? '';

    if (type.includes('pdf')) {
        return 'text-[#b42318]';
    }

    if (/spreadsheet|excel|csv/.test(type)) {
        return 'text-[#0f7b3f]';
    }

    if (/word|document/.test(type)) {
        return 'text-[#1f5fbf]';
    }

    if (type.startsWith('image/')) {
        return 'text-[#7a4bc2]';
    }

    return 'text-[#4b6b58]';
};
const size = (bytes: number) =>
    bytes < 1024
        ? `${bytes} B`
        : bytes < 1048576
          ? `${(bytes / 1024).toFixed(0)} KB`
          : `${(bytes / 1048576).toFixed(1)} MB`;
const date = (value: string | null) =>
    value ? new Date(value).toLocaleDateString() : '';
const isEmpty = computed(() => !props.folders.length && !props.files.length);
</script>

<template>
    <Head title="Document Storage" />

    <div class="space-y-4">
        <!-- Toolbar -->
        <div
            class="flex flex-wrap items-center gap-2 rounded-xl border border-[#9fd8b8] bg-white p-3 shadow-sm"
        >
            <form
                class="flex min-w-0 flex-1 basis-72 items-center gap-2"
                role="search"
                @submit.prevent="runSearch"
            >
                <div class="relative min-w-0 flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#4b6b58]"
                    />
                    <Input
                        v-model="query"
                        type="search"
                        class="pl-9"
                        placeholder="Search all folders and files by name"
                        aria-label="Search documents"
                    />
                </div>
                <Button type="submit" class="bg-[#007a2f] hover:bg-[#006b30]">
                    <Search class="size-4" /> Search
                </Button>
            </form>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    variant="outline"
                    :disabled="searching"
                    @click="openNewFolder"
                >
                    <FolderPlus class="size-4" /> New folder
                </Button>
                <Button
                    class="bg-[#007a2f] hover:bg-[#006b30]"
                    :disabled="searching || uploadForm.processing"
                    @click="fileInput?.click()"
                >
                    <Upload class="size-4" />
                    {{ uploadForm.processing ? 'Uploading…' : 'Upload files' }}
                </Button>
                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    class="hidden"
                    @change="upload(($event.target as HTMLInputElement).files)"
                />
                <div
                    class="flex rounded-md border border-[#9fd8b8] p-0.5"
                    role="group"
                    aria-label="View"
                >
                    <button
                        type="button"
                        class="rounded p-1.5"
                        :class="
                            view === 'list'
                                ? 'bg-[#007a2f] text-white'
                                : 'text-[#4b6b58]'
                        "
                        :aria-pressed="view === 'list'"
                        title="List view"
                        @click="view = 'list'"
                    >
                        <List class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="rounded p-1.5"
                        :class="
                            view === 'grid'
                                ? 'bg-[#007a2f] text-white'
                                : 'text-[#4b6b58]'
                        "
                        :aria-pressed="view === 'grid'"
                        title="Grid view"
                        @click="view = 'grid'"
                    >
                        <LayoutGrid class="size-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Where we are -->
        <nav
            v-if="!searching"
            class="flex flex-wrap items-center gap-1 text-sm"
            aria-label="Folder path"
        >
            <Link
                href="/quality/documents"
                class="flex items-center gap-1.5 rounded px-1.5 py-0.5 font-medium text-[#063f22] hover:bg-[#eef8f2]"
            >
                <HardDrive class="size-4 text-[#007a2f]" /> Documents
            </Link>
            <template v-for="crumb in breadcrumbs" :key="crumb.id">
                <ChevronRight class="size-4 text-[#9fb8a8]" />
                <Link
                    :href="folderUrl(crumb.id)"
                    class="rounded px-1.5 py-0.5 hover:bg-[#eef8f2]"
                    :class="
                        crumb.id === folder?.id
                            ? 'font-semibold text-[#063f22]'
                            : 'text-[#4b6b58]'
                    "
                >
                    {{ crumb.name }}
                </Link>
            </template>
        </nav>
        <div
            v-else
            class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-[#9fd8b8] bg-[#f4fbf7] px-4 py-2 text-sm text-[#063f22]"
        >
            <span>
                Search results for <strong>"{{ search }}"</strong> —
                {{ folders.length }} folders, {{ files.length }} files
            </span>
            <Button size="sm" variant="outline" @click="clearSearch">
                <X class="size-4" /> Clear search
            </Button>
        </div>

        <div
            v-if="selectedCount"
            class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-[#f0c7c2] bg-[#fff6f5] px-4 py-2 text-sm text-[#5c1d16]"
        >
            <span>
                <strong>{{ selectedCount }}</strong>
                {{ selectedCount === 1 ? 'item' : 'items' }} selected
            </span>
            <div class="flex gap-2">
                <Button size="sm" variant="outline" @click="clearSelection">
                    <X class="size-4" /> Clear selection
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    class="border-[#007a2f]/40 text-[#007a2f]"
                    @click="askMoveSelected"
                >
                    <FolderInput class="size-4" /> Move selected
                </Button>
                <Button
                    size="sm"
                    class="bg-[#b42318] text-white hover:bg-[#9a1d13]"
                    @click="askDeleteSelected"
                >
                    <Trash2 class="size-4" /> Delete selected
                </Button>
            </div>
        </div>

        <p v-if="uploadError" class="text-sm text-red-600">
            {{ uploadError }}
        </p>
        <div
            v-if="uploadForm.progress"
            class="h-1.5 overflow-hidden rounded bg-[#dff1e6]"
        >
            <div
                class="h-full bg-[#007a2f] transition-all"
                :style="{ width: `${uploadForm.progress.percentage}%` }"
            />
        </div>

        <!-- Contents (drop files here to upload) -->
        <section
            class="relative min-h-72 rounded-xl border bg-white shadow-sm transition"
            :class="
                dragging
                    ? 'border-2 border-dashed border-[#007a2f] bg-[#f4fbf7]'
                    : 'border-[#9fd8b8]'
            "
            @dragover.prevent="!searching && (dragging = true)"
            @dragleave.self="dragging = false"
            @drop.prevent="onDrop"
        >
            <div
                v-if="isEmpty"
                class="flex flex-col items-center justify-center gap-2 px-4 py-16 text-center text-sm text-[#4b6b58]"
            >
                <Folder class="size-12 text-[#9fd8b8]" />
                <template v-if="searching">
                    Nothing matches "{{ search }}".
                </template>
                <template v-else>
                    <p class="font-medium text-[#063f22]">
                        This folder is empty
                    </p>
                    <p>
                        Create a folder, click Upload files, or drag files here.
                        Up to {{ maxFiles }} files at a time,
                        {{ maxFileMegabytes }} MB each.
                    </p>
                </template>
            </div>

            <!-- List view -->
            <table v-else-if="view === 'list'" class="w-full text-sm">
                <thead
                    class="border-b border-[#d6e9de] text-left text-[#4b6b58]"
                >
                    <tr>
                        <th class="w-10 py-2.5 pl-4">
                            <input
                                type="checkbox"
                                class="size-4 accent-[#007a2f]"
                                :checked="allSelected"
                                aria-label="Select everything shown"
                                @change="toggleAll"
                            />
                        </th>
                        <th class="px-4 py-2.5 font-medium">Name</th>
                        <th
                            class="hidden px-4 py-2.5 font-medium md:table-cell"
                        >
                            {{ searching ? 'Location' : 'Added by' }}
                        </th>
                        <th
                            class="hidden px-4 py-2.5 font-medium sm:table-cell"
                        >
                            Size
                        </th>
                        <th
                            class="hidden px-4 py-2.5 font-medium lg:table-cell"
                        >
                            Modified
                        </th>
                        <th class="w-24 px-2 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#eef3f0]">
                    <tr
                        v-for="item in folders"
                        :key="`folder-${item.id}`"
                        class="hover:bg-[#f7fbf9]"
                        :class="
                            selectedFolders.includes(item.id)
                                ? 'bg-[#fff6f5]'
                                : ''
                        "
                    >
                        <td class="py-2 pl-4">
                            <input
                                type="checkbox"
                                class="size-4 accent-[#007a2f]"
                                :checked="selectedFolders.includes(item.id)"
                                :aria-label="`Select ${item.name}`"
                                @change="toggleFolder(item.id)"
                            />
                        </td>
                        <td class="px-4 py-2">
                            <Link
                                :href="folderUrl(item.id)"
                                class="flex min-w-0 items-center gap-3 font-medium text-[#1d2f37] hover:text-[#007a2f]"
                            >
                                <Folder
                                    class="size-5 shrink-0 fill-[#f6c453] text-[#d9a227]"
                                />
                                <span class="truncate">{{ item.name }}</span>
                            </Link>
                        </td>
                        <td
                            class="hidden truncate px-4 py-2 text-[#66808a] md:table-cell"
                        >
                            {{ searching ? item.location : item.createdBy }}
                        </td>
                        <td
                            class="hidden px-4 py-2 text-[#66808a] sm:table-cell"
                        >
                            {{ item.items }} items
                        </td>
                        <td
                            class="hidden px-4 py-2 text-[#66808a] lg:table-cell"
                        >
                            {{ date(item.updatedAt) }}
                        </td>
                        <td class="px-2 py-2 text-right whitespace-nowrap">
                            <Button
                                size="icon"
                                variant="ghost"
                                class="size-8 text-[#b42318] hover:bg-[#fff1f0] hover:text-[#9a1d13]"
                                :aria-label="`Delete ${item.name}`"
                                title="Delete"
                                @click="askDelete('folder', item)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="size-8"
                                        :aria-label="`Actions for ${item.name}`"
                                    >
                                        <MoreVertical class="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" class="w-44">
                                    <DropdownMenuItem as-child>
                                        <Link :href="folderUrl(item.id)">
                                            <Folder class="size-4" /> Open
                                        </Link>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        @select="askRename('folder', item)"
                                    >
                                        <Pencil class="size-4" /> Rename
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        @select="askMove('folder', item)"
                                    >
                                        <FolderInput class="size-4" /> Move to…
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        class="text-red-700"
                                        @select="askDelete('folder', item)"
                                    >
                                        <Trash2 class="size-4" /> Delete
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </td>
                    </tr>
                    <tr
                        v-for="item in files"
                        :key="`file-${item.id}`"
                        class="hover:bg-[#f7fbf9]"
                        :class="
                            selectedFiles.includes(item.id)
                                ? 'bg-[#fff6f5]'
                                : ''
                        "
                    >
                        <td class="py-2 pl-4">
                            <input
                                type="checkbox"
                                class="size-4 accent-[#007a2f]"
                                :checked="selectedFiles.includes(item.id)"
                                :aria-label="`Select ${item.name}`"
                                @change="toggleFile(item.id)"
                            />
                        </td>
                        <td class="px-4 py-2">
                            <a
                                :href="`/quality/documents/files/${item.id}`"
                                :target="item.viewable ? '_blank' : undefined"
                                rel="noopener"
                                class="flex min-w-0 items-center gap-3 text-[#1d2f37] hover:text-[#007a2f]"
                            >
                                <component
                                    :is="fileIcon(item)"
                                    class="size-5 shrink-0"
                                    :class="fileColor(item)"
                                />
                                <span class="truncate">{{ item.name }}</span>
                            </a>
                        </td>
                        <td
                            class="hidden truncate px-4 py-2 text-[#66808a] md:table-cell"
                        >
                            <Link
                                v-if="searching"
                                :href="folderUrl(item.folderId)"
                                class="hover:text-[#007a2f] hover:underline"
                                >{{ item.location }}</Link
                            >
                            <template v-else>{{ item.uploadedBy }}</template>
                        </td>
                        <td
                            class="hidden px-4 py-2 text-[#66808a] sm:table-cell"
                        >
                            {{ size(item.size) }}
                        </td>
                        <td
                            class="hidden px-4 py-2 text-[#66808a] lg:table-cell"
                        >
                            {{ date(item.updatedAt) }}
                        </td>
                        <td class="px-2 py-2 text-right whitespace-nowrap">
                            <Button
                                size="icon"
                                variant="ghost"
                                class="size-8 text-[#b42318] hover:bg-[#fff1f0] hover:text-[#9a1d13]"
                                :aria-label="`Delete ${item.name}`"
                                title="Delete"
                                @click="askDelete('file', item)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="size-8"
                                        :aria-label="`Actions for ${item.name}`"
                                    >
                                        <MoreVertical class="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" class="w-44">
                                    <DropdownMenuItem
                                        v-if="item.viewable"
                                        as-child
                                    >
                                        <a
                                            :href="`/quality/documents/files/${item.id}`"
                                            target="_blank"
                                            rel="noopener"
                                        >
                                            <Eye class="size-4" /> Open
                                        </a>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem as-child>
                                        <a
                                            :href="`/quality/documents/files/${item.id}/download`"
                                        >
                                            <Download class="size-4" /> Download
                                        </a>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="searching && item.folderId"
                                        as-child
                                    >
                                        <Link :href="folderUrl(item.folderId)">
                                            <Folder class="size-4" /> Open its
                                            folder
                                        </Link>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        @select="askRename('file', item)"
                                    >
                                        <Pencil class="size-4" /> Rename
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        @select="askMove('file', item)"
                                    >
                                        <FolderInput class="size-4" /> Move to…
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        class="text-red-700"
                                        @select="askDelete('file', item)"
                                    >
                                        <Trash2 class="size-4" /> Delete
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Grid view -->
            <div
                v-else
                class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6"
            >
                <div
                    v-for="item in folders"
                    :key="`folder-${item.id}`"
                    class="group relative rounded-lg border border-transparent px-3 pt-7 pb-3 text-center hover:border-[#9fd8b8] hover:bg-[#f7fbf9]"
                >
                    <Link :href="folderUrl(item.id)" class="block">
                        <Folder
                            class="mx-auto size-14 fill-[#f6c453] text-[#d9a227]"
                        />
                        <span
                            class="mt-1 block truncate text-sm font-medium text-[#1d2f37]"
                            :title="item.name"
                            >{{ item.name }}</span
                        >
                        <span class="block text-xs text-[#66808a]"
                            >{{ item.items }} items</span
                        >
                    </Link>
                    <input
                        type="checkbox"
                        class="absolute top-2 left-2 size-4 accent-[#007a2f]"
                        :checked="selectedFolders.includes(item.id)"
                        :aria-label="`Select ${item.name}`"
                        @change="toggleFolder(item.id)"
                    />
                    <div class="absolute top-1 right-1 flex gap-0.5">
                        <button
                            type="button"
                            class="rounded p-1 text-[#4b6b58] hover:bg-white"
                            :aria-label="`Rename ${item.name}`"
                            title="Rename"
                            @click="askRename('folder', item)"
                        >
                            <Pencil class="size-3.5" />
                        </button>
                        <button
                            type="button"
                            class="rounded p-1 text-[#b42318] hover:bg-white"
                            :aria-label="`Delete ${item.name}`"
                            title="Delete"
                            @click="askDelete('folder', item)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </div>
                </div>
                <div
                    v-for="item in files"
                    :key="`file-${item.id}`"
                    class="group relative rounded-lg border border-transparent px-3 pt-7 pb-3 text-center hover:border-[#9fd8b8] hover:bg-[#f7fbf9]"
                >
                    <a
                        :href="`/quality/documents/files/${item.id}`"
                        :target="item.viewable ? '_blank' : undefined"
                        rel="noopener"
                        class="block"
                    >
                        <component
                            :is="fileIcon(item)"
                            class="mx-auto size-14 stroke-[1.25]"
                            :class="fileColor(item)"
                        />
                        <span
                            class="mt-1 block truncate text-sm text-[#1d2f37]"
                            :title="item.name"
                            >{{ item.name }}</span
                        >
                        <span class="block text-xs text-[#66808a]">{{
                            size(item.size)
                        }}</span>
                    </a>
                    <input
                        type="checkbox"
                        class="absolute top-2 left-2 size-4 accent-[#007a2f]"
                        :checked="selectedFiles.includes(item.id)"
                        :aria-label="`Select ${item.name}`"
                        @change="toggleFile(item.id)"
                    />
                    <div class="absolute top-1 right-1 flex gap-0.5">
                        <a
                            :href="`/quality/documents/files/${item.id}/download`"
                            class="rounded p-1 text-[#4b6b58] hover:bg-white"
                            :aria-label="`Download ${item.name}`"
                            title="Download"
                        >
                            <Download class="size-3.5" />
                        </a>
                        <button
                            type="button"
                            class="rounded p-1 text-[#b42318] hover:bg-white"
                            :aria-label="`Delete ${item.name}`"
                            title="Delete"
                            @click="askDelete('file', item)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-if="dragging"
                class="pointer-events-none absolute inset-0 flex items-center justify-center rounded-xl bg-[#f4fbf7]/80 text-sm font-medium text-[#007a2f]"
            >
                <Upload class="mr-2 size-5" /> Drop files to upload them here
            </div>
        </section>
    </div>

    <!-- New folder -->
    <Dialog :open="folderOpen" @update:open="folderOpen = $event">
        <DialogContent class="sm:max-w-md">
            <form class="space-y-4" @submit.prevent="createFolder">
                <DialogHeader>
                    <DialogTitle>New folder</DialogTitle>
                    <DialogDescription>
                        Inside {{ folder ? `"${folder.name}"` : 'Documents' }}.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="folder-name">Folder name</Label>
                    <Input
                        id="folder-name"
                        v-model="folderForm.name"
                        maxlength="150"
                        required
                        autofocus
                    />
                    <InputError :message="folderForm.errors.name" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="folderOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        class="bg-[#007a2f] hover:bg-[#006b30]"
                        :disabled="folderForm.processing"
                    >
                        <FolderPlus class="size-4" /> Create folder
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Rename -->
    <Dialog
        :open="renaming !== null"
        @update:open="(open) => !open && (renaming = null)"
    >
        <DialogContent class="sm:max-w-md">
            <form class="space-y-4" @submit.prevent="rename">
                <DialogHeader>
                    <DialogTitle>
                        Rename
                        {{ renaming?.kind === 'folder' ? 'folder' : 'file' }}
                    </DialogTitle>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="rename-name">New name</Label>
                    <Input
                        id="rename-name"
                        v-model="renameForm.name"
                        maxlength="200"
                        required
                        autofocus
                    />
                    <InputError :message="renameForm.errors.name" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="renaming = null"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        class="bg-[#007a2f] hover:bg-[#006b30]"
                        :disabled="renameForm.processing"
                    >
                        Save
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Move -->
    <Dialog
        :open="moving !== null"
        @update:open="(open) => !open && (moving = null)"
    >
        <DialogContent class="sm:max-w-md">
            <form class="space-y-4" @submit.prevent="move">
                <DialogHeader>
                    <DialogTitle>Move {{ moving?.label }}</DialogTitle>
                    <DialogDescription>
                        Choose the folder to move to.
                    </DialogDescription>
                </DialogHeader>
                <FolderPicker
                    v-model="moveForm.target_id"
                    :tree="tree"
                    :disabled-ids="blockedTargets"
                />
                <InputError
                    :message="
                        moveForm.errors.target_id ??
                        (moveForm.errors as Record<string, string>).selection
                    "
                />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="moving = null"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        class="bg-[#007a2f] hover:bg-[#006b30]"
                        :disabled="moveForm.processing"
                    >
                        <FolderInput class="size-4" /> Move here
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Delete -->
    <Dialog
        :open="deleting !== null"
        @update:open="(open) => !open && (deleting = null)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>
                    {{
                        deleting?.kind === 'selection'
                            ? `Delete ${deleting.items} selected ${deleting.items === 1 ? 'item' : 'items'}?`
                            : `Delete ${deleting?.kind === 'folder' ? 'folder' : 'file'}?`
                    }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="deleting?.kind === 'selection'">
                        The selected files, and the selected folders with
                        everything inside them, will be deleted for everyone.
                        This cannot be undone.
                    </template>
                    <template v-else-if="deleting?.kind === 'folder'">
                        "{{ deleting?.name }}" and everything inside it ({{
                            deleting?.items ?? 0
                        }}
                        items, including subfolders) will be deleted for
                        everyone. This cannot be undone.
                    </template>
                    <template v-else>
                        "{{ deleting?.name }}" will be deleted for everyone.
                        This cannot be undone.
                    </template>
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
