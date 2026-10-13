<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ArrowRight, Loader2, Search } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type FoundCourse = {
    id: number;
    code: string;
    title: string;
    courseType: string;
    level: string | null;
    department: string | null;
    school: string | null;
    programs: string[];
};

const page = usePage();
const open = ref(false);
const query = ref('');
const results = ref<FoundCourse[]>([]);
const loading = ref(false);
const failed = ref(false);
const activeIndex = ref(0);
let timer: number | undefined;
let controller: AbortController | null = null;

const destinations = computed(() => {
    const profile = page.props.qualityProfile;
    const permissions = profile?.permissions ?? [];
    const list: Array<{ label: string; href: string }> = [];

    if (
        profile?.canManageQualityStructure ||
        profile?.canManageCourseAssignments ||
        profile?.canUploadReports
    ) {
        list.push({
            label: 'Course assignments',
            href: '/quality/course-assignments',
        });
    }

    if (profile?.canUploadReports) {
        list.push({ label: 'Upload report', href: '/quality/reports/create' });
    }

    if (profile?.canViewReports) {
        list.push({
            label: profile.canReviewReports
                ? 'Supervisor review'
                : 'My reports',
            href: '/quality/reports',
        });
    }

    if (profile?.canApproveReports) {
        list.push({ label: 'Director review', href: '/quality/approvals' });
    }

    if (profile?.canViewPrintQueue) {
        list.push({ label: 'Print queue', href: '/quality/print-queue' });
    }

    if (permissions.includes('view_own_reports')) {
        list.push({ label: 'My courses', href: '/quality/my-courses' });
    }

    if (profile?.canManageQualityStructure) {
        list.push({ label: 'Course catalogue', href: '/quality/courses' });
    }

    return list;
});

const goTo = (course: FoundCourse, href: string) => {
    open.value = false;
    router.visit(`${href}?search=${encodeURIComponent(course.code)}`);
};

const search = async (term: string) => {
    controller?.abort();

    if (!term.trim()) {
        results.value = [];
        loading.value = false;

        return;
    }

    controller = new AbortController();
    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(
            `/quality/course-search?q=${encodeURIComponent(term)}`,
            {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        );

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        results.value = (await response.json()).courses;
        activeIndex.value = 0;
        loading.value = false;
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            failed.value = true;
            results.value = [];
            loading.value = false;
        }
    }
};

watch(query, (term) => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => search(term), 200);
});

watch(open, (isOpen) => {
    if (!isOpen) {
        query.value = '';
        results.value = [];
    }
});

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = Math.min(
            activeIndex.value + 1,
            results.value.length - 1,
        );
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if (event.key === 'Enter') {
        const course = results.value[activeIndex.value];

        if (course && destinations.value[0]) {
            event.preventDefault();
            goTo(course, destinations.value[0].href);
        }
    }
};

const shortcut = (event: KeyboardEvent) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        open.value = true;
    }
};

onMounted(() => window.addEventListener('keydown', shortcut));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', shortcut);
    window.clearTimeout(timer);
    controller?.abort();
});
</script>

<template>
    <button
        type="button"
        class="flex h-10 items-center gap-2 rounded-xl border border-[#d6efdf] bg-[#f4fbf7] px-3 text-sm text-[#064b27] transition hover:border-[#9fd8b8] hover:bg-[#e8f7ee]"
        title="Search courses (Ctrl+K)"
        @click="open = true"
    >
        <Search class="size-5" />
        <span class="hidden lg:inline">Search courses</span>
        <kbd
            class="hidden rounded border border-[#c8d7de] bg-white px-1.5 text-[10px] font-medium text-[#52646c] xl:inline"
            >Ctrl K</kbd
        >
    </button>

    <Dialog v-model:open="open">
        <DialogContent class="top-[15%] translate-y-0 gap-3 p-0 sm:max-w-2xl">
            <DialogHeader class="px-5 pt-5">
                <DialogTitle>Search courses</DialogTitle>
                <DialogDescription>
                    Type a course code, title or department, then open the
                    course where you need it.
                </DialogDescription>
            </DialogHeader>

            <div class="px-5">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#66808a]"
                    />
                    <input
                        v-model="query"
                        type="text"
                        role="combobox"
                        aria-label="Search courses"
                        aria-controls="global-course-results"
                        :aria-expanded="results.length > 0"
                        autofocus
                        placeholder="e.g. NURS 301, Adult Health, Midwifery"
                        class="h-11 w-full rounded-lg border border-[#c8d7de] bg-white pr-10 pl-10 text-sm text-[#1d2f37] outline-none placeholder:text-[#8a9aa1] focus:border-[#007a2f] focus:ring-2 focus:ring-[#007a2f]/20"
                        @keydown="onKeydown"
                    />
                    <Loader2
                        v-if="loading"
                        class="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-[#66808a]"
                    />
                </div>
            </div>

            <div
                id="global-course-results"
                class="max-h-[55vh] overflow-y-auto border-t border-[#e6edf0]"
                role="listbox"
            >
                <p
                    v-if="!query.trim()"
                    class="px-5 py-8 text-center text-sm text-[#66808a]"
                >
                    Start typing to find a course.
                </p>
                <p
                    v-else-if="failed"
                    class="px-5 py-8 text-center text-sm text-red-700"
                >
                    Search is unavailable right now. Try again.
                </p>
                <p
                    v-else-if="!loading && results.length === 0"
                    class="px-5 py-8 text-center text-sm text-[#66808a]"
                >
                    No courses match “{{ query }}”.
                </p>
                <div
                    v-for="(course, index) in results"
                    :key="course.id"
                    role="option"
                    :aria-selected="index === activeIndex"
                    class="border-b border-[#e6edf0] px-5 py-3 last:border-b-0"
                    :class="index === activeIndex ? 'bg-[#eefaf3]' : ''"
                    @mouseenter="activeIndex = index"
                >
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="font-semibold text-[#075b2e]">
                            {{ course.code }}
                        </span>
                        <span class="text-sm text-[#1d2f37]">
                            {{ course.title }}
                        </span>
                        <span
                            v-if="course.courseType === 'module'"
                            class="rounded border border-[#d6c9f2] bg-[#f4effc] px-1.5 text-[10px] font-semibold tracking-wide text-[#56349c] uppercase"
                        >
                            Module
                        </span>
                    </div>
                    <div class="mt-0.5 text-xs text-[#66808a]">
                        {{ course.department ?? 'No department' }}
                        <template v-if="course.level">
                            · Level {{ course.level }}
                        </template>
                        <template v-if="course.programs.length">
                            · {{ course.programs.join(', ') }}
                        </template>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <button
                            v-for="destination in destinations"
                            :key="destination.href"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-md border border-[#c8d7de] bg-white px-2 py-1 text-xs font-medium text-[#1d2f37] transition hover:border-[#007a2f] hover:text-[#007a2f]"
                            @click="goTo(course, destination.href)"
                        >
                            {{ destination.label }}
                            <ArrowRight class="size-3" />
                        </button>
                    </div>
                </div>
            </div>
            <div
                class="border-t border-[#e6edf0] px-5 py-2 text-xs text-[#66808a]"
            >
                ↑ ↓ to move · Enter opens in
                {{ destinations[0]?.label.toLowerCase() ?? 'the first page' }}
                · Esc to close
            </div>
        </DialogContent>
    </Dialog>
</template>
