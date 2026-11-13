<script setup lang="ts">
import { Check, Folder, HardDrive } from 'lucide-vue-next';
import { computed } from 'vue';

export type FolderNode = { id: number; name: string; parentId: number | null };

const props = defineProps<{
    tree: FolderNode[];
    modelValue: number | null;
    /** Folders that cannot be chosen (e.g. a folder being moved and those inside it). */
    disabledIds?: number[];
}>();

const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();

// Folders in tree order, each with its depth, for an indented list.
const rows = computed(() => {
    const children = new Map<number | null, FolderNode[]>();

    props.tree.forEach((folder) =>
        children.set(folder.parentId, [
            ...(children.get(folder.parentId) ?? []),
            folder,
        ]),
    );

    const ordered: Array<FolderNode & { depth: number }> = [];
    const walk = (parentId: number | null, depth: number) => {
        (children.get(parentId) ?? [])
            .sort((a, b) => a.name.localeCompare(b.name))
            .forEach((folder) => {
                ordered.push({ ...folder, depth });
                walk(folder.id, depth + 1);
            });
    };

    walk(null, 1);

    return ordered;
});

const disabled = (id: number) => props.disabledIds?.includes(id) ?? false;
const rowClass = (selected: boolean, off = false) =>
    off
        ? 'cursor-not-allowed text-[#9fb0a6]'
        : selected
          ? 'bg-[#007a2f] text-white'
          : 'text-[#1d2f37] hover:bg-[#eef8f2]';
</script>

<template>
    <ul
        class="max-h-72 overflow-y-auto rounded-md border border-[#9fd8b8] bg-white p-1 text-sm"
        role="listbox"
        aria-label="Folders"
    >
        <li role="option" :aria-selected="modelValue === null">
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left font-medium"
                :class="rowClass(modelValue === null)"
                @click="emit('update:modelValue', null)"
            >
                <HardDrive class="size-4 shrink-0" />
                <span class="flex-1">Documents (top level)</span>
                <Check v-if="modelValue === null" class="size-4" />
            </button>
        </li>
        <li
            v-for="row in rows"
            :key="row.id"
            role="option"
            :aria-selected="modelValue === row.id"
            :aria-disabled="disabled(row.id)"
        >
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded py-1.5 pr-2 text-left"
                :class="rowClass(modelValue === row.id, disabled(row.id))"
                :style="{ paddingLeft: `${row.depth * 16 + 8}px` }"
                :disabled="disabled(row.id)"
                @click="emit('update:modelValue', row.id)"
            >
                <Folder
                    class="size-4 shrink-0"
                    :class="
                        modelValue === row.id
                            ? ''
                            : 'fill-[#f6c453] text-[#d9a227]'
                    "
                />
                <span class="min-w-0 flex-1 truncate">{{ row.name }}</span>
                <Check v-if="modelValue === row.id" class="size-4" />
            </button>
        </li>
        <li v-if="!rows.length" class="px-2 py-2 text-xs text-[#66808a]">
            No folders yet. Files will go to the top level.
        </li>
    </ul>
</template>
