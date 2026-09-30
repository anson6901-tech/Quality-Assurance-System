<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Combine, Download, Eye, Printer, Split } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useCourseOfferingSplits } from '@/composables/useCourseOfferingSplits';

type CourseRow = {
    key: string;
    code: string;
    title: string;
    level: string | null;
    program: string | null;
    academicYear: string | null;
    academicYearId: number | null;
    semester: string | null;
    semesterId: number | null;
    studentCount: number | null;
    allocatedHours: number | null;
    reports: Array<{
        id: number;
        title: string;
        fileUrl: string;
        downloadUrl: string;
    }>;
};
type CourseGroup = CourseRow & { variants: CourseRow[] };

const props = defineProps<{ courses: CourseRow[] }>();
const courseSearch = ref('');
const yearFilter = ref('');
const semesterFilter = ref('');
const programFilter = ref('');
const levelFilter = ref('');
const { isCourseSplitForPeriod, toggleCourseSplitForPeriod } =
    useCourseOfferingSplits(null, null);

const uniqueOptions = (values: Array<string | null>) =>
    [
        ...new Set(values.filter((value): value is string => Boolean(value))),
    ].sort();
const years = computed(() =>
    uniqueOptions(props.courses.map((course) => course.academicYear)),
);
const semesters = computed(() =>
    uniqueOptions(props.courses.map((course) => course.semester)),
);
const programs = computed(() =>
    uniqueOptions(props.courses.map((course) => course.program)),
);
const levels = computed(() =>
    uniqueOptions(props.courses.map((course) => course.level)).sort(
        (left, right) => Number(left) - Number(right),
    ),
);

const filteredCourses = computed(() => {
    const search = courseSearch.value.trim().toLowerCase();

    return props.courses.filter(
        (course) =>
            (!yearFilter.value || course.academicYear === yearFilter.value) &&
            (!semesterFilter.value ||
                course.semester === semesterFilter.value) &&
            (!programFilter.value || course.program === programFilter.value) &&
            (!levelFilter.value || course.level === levelFilter.value) &&
            (!search ||
                course.code.toLowerCase().includes(search) ||
                course.title.toLowerCase().includes(search)),
    );
});

const courseGroups = computed<CourseGroup[]>(() => {
    const groups = new Map<string, CourseRow[]>();
    filteredCourses.value.forEach((course) => {
        const key = `${course.code}:${course.academicYearId}:${course.semesterId}`;
        groups.set(key, [...(groups.get(key) ?? []), course]);
    });

    return [...groups.values()].map((variants) => ({
        ...variants[0],
        variants,
    }));
});
const isSplit = (group: CourseGroup) =>
    isCourseSplitForPeriod(group.code, group.academicYearId, group.semesterId);
const toggleSplit = (group: CourseGroup) =>
    toggleCourseSplitForPeriod(
        group.code,
        group.academicYearId,
        group.semesterId,
    );
const printPage = () => window.print();
</script>

<template>
    <Head title="My Courses" />
    <div class="space-y-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="My Courses"
                description="Courses assigned to you and their published evaluation reports"
            />
            <Button
                type="button"
                variant="outline"
                class="print:hidden"
                @click="printPage"
            >
                <Printer class="h-4 w-4" /> Print course list
            </Button>
        </div>

        <section
            class="grid gap-3 border-y border-[#9fd8b8] bg-[#eefaf3] px-4 py-4 shadow-sm md:grid-cols-6 lg:sticky lg:top-24 lg:z-10 print:hidden"
        >
            <Input
                v-model="courseSearch"
                type="search"
                placeholder="Search course"
                class="bg-white md:col-span-2"
            />
            <select v-model="yearFilter" class="filter-select">
                <option value="">All academic years</option>
                <option v-for="year in years" :key="year">{{ year }}</option>
            </select>
            <select v-model="semesterFilter" class="filter-select">
                <option value="">All semesters</option>
                <option v-for="semester in semesters" :key="semester">
                    {{ semester }}
                </option>
            </select>
            <select v-model="programFilter" class="filter-select">
                <option value="">All programs</option>
                <option v-for="program in programs" :key="program">
                    {{ program }}
                </option>
            </select>
            <select v-model="levelFilter" class="filter-select">
                <option value="">All levels</option>
                <option v-for="level in levels" :key="level">
                    Level {{ level }}
                </option>
            </select>
        </section>

        <div v-if="courseGroups.length" class="space-y-4">
            <article
                v-for="(group, index) in courseGroups"
                :key="group.key"
                class="overflow-hidden rounded-lg border border-[#b8ced8] bg-white"
            >
                <header
                    class="grid gap-3 border-b border-[#b8ced8] bg-[#f8fbfc] px-4 py-4 md:grid-cols-[auto_1.5fr_0.7fr_0.8fr_1fr] md:items-center"
                >
                    <div
                        class="flex h-8 w-8 items-center justify-center rounded-md bg-[#d9edf5] font-bold text-[#234d5d]"
                    >
                        {{ index + 1 }}
                    </div>
                    <div>
                        <div class="font-bold text-[#075b2e]">
                            {{ group.code }}
                        </div>
                        <div class="text-sm text-[#243d47]">
                            {{ group.title }}
                        </div>
                    </div>
                    <div class="text-sm">
                        <div class="meta-label">Level</div>
                        {{ group.level ?? '—' }}
                    </div>
                    <div class="text-sm">
                        <div class="meta-label">Programs</div>
                        {{ group.variants.length }}
                    </div>
                    <div class="text-sm">
                        <div class="meta-label">Period</div>
                        {{ group.academicYear ?? '—' }} ·
                        {{ group.semester ?? '—' }}
                    </div>
                </header>

                <div class="px-4 py-4">
                    <div
                        v-if="group.variants.length > 1 && !isSplit(group)"
                        class="flex items-center justify-between border border-[#cbdbe1] bg-[#fbfdfe] px-4 py-5"
                    >
                        <div>
                            <div class="font-medium text-[#243d47]">
                                {{ group.variants.length }} program assignments
                                grouped
                            </div>
                            <div class="mt-1 text-xs text-muted-foreground">
                                Split this course to view each program and its
                                published report.
                            </div>
                        </div>
                        <Split class="h-5 w-5 text-[#087036]" />
                    </div>
                    <div v-else class="overflow-hidden border border-[#cbdbe1]">
                        <div
                            class="hidden grid-cols-[1.4fr_0.65fr_1fr_0.7fr_1fr] bg-[#d9edf5] text-xs font-semibold text-[#294c59] md:grid"
                        >
                            <div class="px-3 py-2">Program</div>
                            <div class="px-3 py-2">Level</div>
                            <div class="px-3 py-2">Workload</div>
                            <div class="px-3 py-2">Status</div>
                            <div class="px-3 py-2">Reports</div>
                        </div>
                        <div
                            v-for="variant in group.variants"
                            :key="variant.key"
                            class="grid gap-3 border-t border-[#cbdbe1] px-3 py-3 text-sm first:border-t-0 md:grid-cols-[1.4fr_0.65fr_1fr_0.7fr_1fr] md:items-center"
                        >
                            <div class="font-medium">
                                {{ variant.program ?? 'Program not set' }}
                            </div>
                            <div>{{ variant.level ?? '—' }}</div>
                            <div class="text-xs">
                                {{ variant.studentCount ?? '—' }} students ·
                                {{ variant.allocatedHours ?? '—' }} hrs
                            </div>
                            <Badge
                                variant="secondary"
                                class="w-fit bg-[#e2f2e8] text-[#075b2e]"
                            >
                                Assigned
                            </Badge>
                            <div class="flex gap-2 print:hidden">
                                <template v-if="variant.reports.length">
                                    <template
                                        v-for="report in variant.reports"
                                        :key="report.id"
                                    >
                                        <Button
                                            size="icon"
                                            variant="outline"
                                            as-child
                                            title="View report"
                                        >
                                            <a
                                                :href="report.fileUrl"
                                                target="_blank"
                                            >
                                                <Eye class="h-4 w-4" />
                                            </a>
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="outline"
                                            as-child
                                            title="Download report"
                                        >
                                            <a :href="report.downloadUrl">
                                                <Download class="h-4 w-4" />
                                            </a>
                                        </Button>
                                    </template>
                                </template>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                >
                                    Not published
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <footer
                    v-if="group.variants.length > 1"
                    class="flex justify-end border-t border-[#cbdbe1] bg-[#f8fbfc] px-4 py-3 print:hidden"
                >
                    <Button
                        size="sm"
                        variant="outline"
                        class="border-[#8bcfaa] text-[#006d2b]"
                        @click="toggleSplit(group)"
                    >
                        <Combine v-if="isSplit(group)" class="h-4 w-4" />
                        <Split v-else class="h-4 w-4" />
                        {{
                            isSplit(group)
                                ? 'Combine programs'
                                : 'Split programs'
                        }}
                    </Button>
                </footer>
            </article>
        </div>
        <div
            v-else
            class="border-y border-[#cbdbe1] py-12 text-center text-muted-foreground"
        >
            No assigned courses match the selected filters.
        </div>
    </div>
</template>

<style scoped>
.filter-select {
    height: 2.5rem;
    border: 1px solid #9fd8b8;
    border-radius: 0.375rem;
    background: white;
    padding: 0 0.75rem;
    font-size: 0.875rem;
}
.meta-label {
    font-size: 0.625rem;
    font-weight: 700;
    color: #66808a;
    text-transform: uppercase;
}
</style>
