<script setup lang="ts">
import { Check, ChevronsUpDown, Search, X } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';

export type CourseOption = {
    id: number;
    code: string;
    title: string;
    offered: boolean;
};

const props = defineProps<{
    courses: CourseOption[];
    modelValue: number | null;
    disabled?: boolean;
    id?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();

const open = ref(false);
const query = ref('');
const searchBox = ref<HTMLInputElement | null>(null);

const selected = computed(
    () =>
        props.courses.find((course) => course.id === props.modelValue) ?? null,
);

// Every word must appear in the code or title; courses offered this semester come first.
const matches = computed(() => {
    const words = query.value.toLowerCase().split(/\s+/).filter(Boolean);

    return props.courses
        .filter((course) => {
            const text = `${course.code} ${course.title}`.toLowerCase();

            return words.every((word) => text.includes(word));
        })
        .sort((a, b) => Number(b.offered) - Number(a.offered))
        .slice(0, 50);
});

const toggle = async () => {
    if (props.disabled) {
        return;
    }

    open.value = !open.value;

    if (open.value) {
        query.value = '';
        await nextTick();
        searchBox.value?.focus();
    }
};

const choose = (course: CourseOption | null) => {
    emit('update:modelValue', course?.id ?? null);
    open.value = false;
};
</script>

<template>
    <div class="relative" @keydown.esc="open = false">
        <button
            :id="id"
            type="button"
            class="flex h-9 w-full min-w-0 items-center gap-2 rounded-md border border-input bg-white px-3 text-left text-sm shadow-xs disabled:opacity-60"
            :disabled="disabled"
            :aria-expanded="open"
            aria-haspopup="listbox"
            @click="toggle"
        >
            <span v-if="selected" class="min-w-0 flex-1 truncate">
                <strong>{{ selected.code }}</strong> {{ selected.title }}
            </span>
            <span v-else class="flex-1 text-[#6b7f73]">
                Search and select a course…
            </span>
            <ChevronsUpDown class="size-4 shrink-0 text-[#4b6b58]" />
        </button>

        <div
            v-if="open"
            class="absolute z-30 mt-1 w-full min-w-72 rounded-md border border-[#9fd8b8] bg-white shadow-lg"
        >
            <div class="relative border-b border-[#d6e9de]">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#4b6b58]"
                />
                <input
                    ref="searchBox"
                    v-model="query"
                    type="search"
                    placeholder="Type a course code or title"
                    class="h-10 w-full bg-transparent pr-3 pl-9 text-sm outline-none"
                    aria-label="Search courses"
                />
            </div>
            <ul class="max-h-64 overflow-y-auto py-1 text-sm" role="listbox">
                <li v-if="selected">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[#8a1c1c] hover:bg-[#fff6f6]"
                        @click="choose(null)"
                    >
                        <X class="size-4" /> Clear course
                    </button>
                </li>
                <li
                    v-for="course in matches"
                    :key="course.id"
                    role="option"
                    :aria-selected="course.id === modelValue"
                >
                    <button
                        type="button"
                        class="flex w-full items-start gap-2 px-3 py-1.5 text-left hover:bg-[#eef8f2]"
                        @click="choose(course)"
                    >
                        <Check
                            class="mt-0.5 size-4 shrink-0"
                            :class="
                                course.id === modelValue
                                    ? 'text-[#007a2f]'
                                    : 'invisible'
                            "
                        />
                        <span class="min-w-0 flex-1">
                            <strong>{{ course.code }}</strong>
                            {{ course.title }}
                        </span>
                        <span
                            v-if="course.offered"
                            class="shrink-0 rounded bg-[#dff1e6] px-1.5 text-xs text-[#0b5a2a]"
                            >This semester</span
                        >
                    </button>
                </li>
                <li
                    v-if="!matches.length"
                    class="px-3 py-3 text-center text-[#4b6b58]"
                >
                    No course matches "{{ query }}".
                </li>
            </ul>
        </div>
    </div>
</template>
