<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, Eye, Printer } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import PageBanner from '@/components/PageBanner.vue';
import CourseList from '@/components/quality/CourseList.vue';
import CourseRow from '@/components/quality/CourseRow.vue';
import CourseScopeCell from '@/components/quality/CourseScopeCell.vue';
import CourseSearch from '@/components/quality/CourseSearch.vue';
import CourseTitleCell from '@/components/quality/CourseTitleCell.vue';
import PartLabelCell from '@/components/quality/PartLabelCell.vue';
import SplitMenu from '@/components/quality/SplitMenu.vue';
import StatusPill from '@/components/quality/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { useSearchParam } from '@/composables/useSearchParam';
import { matchesSearch } from '@/lib/coursePartitions';
import type { CourseEntry, CoursePart } from '@/types';

type MyPart = CoursePart & {
    studentCount: number | null;
    allocatedHours: number | null;
    reports: Array<{
        id: number;
        title: string;
        fileUrl: string;
        downloadUrl: string;
    }>;
};
type MyCourse = CourseEntry<MyPart> & {
    academicYear: string | null;
    academicYearId: number | null;
    semester: string | null;
};

const props = defineProps<{ courses: MyCourse[] }>();
const courseSearch = ref(useSearchParam('search'));
const yearFilter = ref('');
const semesterFilter = ref('');
const programFilter = ref('');
const levelFilter = ref('');

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
    uniqueOptions(
        props.courses.flatMap((course) =>
            course.programs.map((program) => program.name),
        ),
    ),
);
const levels = computed(() =>
    uniqueOptions(props.courses.flatMap((course) => course.levels)).sort(
        (left, right) => Number(left) - Number(right),
    ),
);

const filteredCourses = computed(() => {
    return props.courses.filter(
        (course) =>
            (!yearFilter.value || course.academicYear === yearFilter.value) &&
            (!semesterFilter.value ||
                course.semester === semesterFilter.value) &&
            (!programFilter.value ||
                course.programs.some(
                    (program) => program.name === programFilter.value,
                )) &&
            (!levelFilter.value || course.levels.includes(levelFilter.value)) &&
            matchesSearch(courseSearch.value, [
                course.code,
                course.title,
                ...course.programs.flatMap((program) => [
                    program.code,
                    program.name,
                ]),
                ...course.parts.map((part) => part.label),
            ]),
    );
});

const isPublished = (part: MyPart) => part.reports.length > 0;
const courseStatus = (course: MyCourse) => {
    const published = course.parts.filter(isPublished).length;

    return {
        status:
            published === course.parts.length ? 'approved' : 'not_published',
        mixed: published > 0 && published < course.parts.length,
    };
};
const showPartRows = (course: MyCourse) => course.parts.length > 1;
const printPage = () => window.print();
</script>

<template>
    <Head title="My Courses" />
    <div class="space-y-6">
        <PageBanner
            title="My Courses"
            description="Courses assigned to you and their published evaluation reports"
        >
            <template #actions>
                <Button
                    type="button"
                    variant="outline"
                    class="print:hidden"
                    @click="printPage"
                >
                    <Printer class="h-4 w-4" /> Print course list
                </Button>
            </template>
        </PageBanner>

        <section
            class="grid gap-3 border-y border-[#9fd8b8] bg-[#eefaf3] px-4 py-4 shadow-sm md:grid-cols-4 lg:sticky lg:top-20 lg:z-20 print:hidden"
        >
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
                <option v-for="level in levels" :key="level" :value="level">
                    Level {{ level }}
                </option>
            </select>
        </section>

        <CourseSearch
            v-if="courses.length"
            v-model="courseSearch"
            class="print:hidden"
            :shown="filteredCourses.length"
            :total="courses.length"
            placeholder="Search your courses by code, title or program"
        />

        <CourseList
            v-if="filteredCourses.length"
            :headings="[
                'Course',
                'Programs and sections',
                'Period',
                'Workload',
                'Report',
            ]"
            columns="minmax(0,1.6fr) minmax(0,1.4fr) minmax(0,1fr) minmax(130px,1fr) minmax(150px,auto)"
        >
            <CourseRow
                v-for="course in filteredCourses"
                :key="course.key"
                :status="courseStatus(course).status"
                :mixed="courseStatus(course).mixed"
            >
                <CourseTitleCell
                    :code="course.code"
                    :title="course.title"
                    :course-type="course.courseType"
                    :subtitle="course.department?.name"
                />
                <div class="min-w-0 space-y-2">
                    <CourseScopeCell
                        :programs="course.programs"
                        :levels="course.levels"
                        :sections="course.sections"
                        :mode="course.splitMode"
                    />
                    <SplitMenu
                        :course-id="course.id"
                        :course-code="course.code"
                        :semester-id="course.semesterId"
                        :mode="course.splitMode"
                    />
                </div>
                <div class="text-sm text-[#3c5059]">
                    {{ course.academicYear ?? '—' }}
                    <div class="text-xs text-[#66808a]">
                        {{ course.semester ?? '—' }}
                    </div>
                </div>
                <div class="text-xs text-[#52646c]">
                    <template v-if="!showPartRows(course)">
                        <div class="font-medium text-[#3c5059]">
                            {{ course.parts[0].label }}
                        </div>
                        {{ course.parts[0].studentCount ?? '—' }} students ·
                        {{ course.parts[0].allocatedHours ?? '—' }} hrs
                    </template>
                    <template v-else>
                        You teach {{ course.parts.length }} parts
                    </template>
                </div>
                <div
                    v-if="!showPartRows(course)"
                    class="flex items-center justify-start gap-1.5 md:justify-end print:hidden"
                >
                    <template v-if="course.parts[0].reports.length">
                        <template
                            v-for="report in course.parts[0].reports.slice(
                                0,
                                1,
                            )"
                            :key="report.id"
                        >
                            <Button size="sm" variant="outline" as-child>
                                <a :href="report.fileUrl" target="_blank">
                                    <Eye class="h-4 w-4" /> View
                                </a>
                            </Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                class="size-8"
                                as-child
                                title="Download report"
                            >
                                <a :href="report.downloadUrl">
                                    <Download class="h-4 w-4" />
                                    <span class="sr-only">Download</span>
                                </a>
                            </Button>
                        </template>
                    </template>
                    <StatusPill v-else status="not_published" />
                </div>
                <div
                    v-else
                    class="flex items-center justify-start md:justify-end"
                >
                    <StatusPill
                        :status="courseStatus(course).status"
                        :mixed="courseStatus(course).mixed"
                    />
                </div>

                <template v-if="showPartRows(course)" #children>
                    <CourseRow
                        v-for="part in course.parts"
                        :key="part.key"
                        :status="
                            isPublished(part) ? 'approved' : 'not_published'
                        "
                        nested
                    >
                        <PartLabelCell
                            :label="part.label"
                            :cells="part.cells"
                        />
                        <div class="hidden md:block" />
                        <div class="hidden md:block" />
                        <div class="text-xs text-[#52646c]">
                            {{ part.studentCount ?? '—' }} students ·
                            {{ part.allocatedHours ?? '—' }} hrs
                        </div>
                        <div
                            class="flex items-center justify-start gap-1.5 md:justify-end print:hidden"
                        >
                            <template v-if="part.reports.length">
                                <Button size="sm" variant="outline" as-child>
                                    <a
                                        :href="part.reports[0].fileUrl"
                                        target="_blank"
                                    >
                                        <Eye class="h-4 w-4" /> View
                                    </a>
                                </Button>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    class="size-8"
                                    as-child
                                    title="Download report"
                                >
                                    <a :href="part.reports[0].downloadUrl">
                                        <Download class="h-4 w-4" />
                                        <span class="sr-only">Download</span>
                                    </a>
                                </Button>
                            </template>
                            <StatusPill v-else status="not_published" />
                        </div>
                    </CourseRow>
                </template>
            </CourseRow>
        </CourseList>
        <div
            v-else
            class="rounded-xl border border-dashed border-[#9fd8b8] bg-white py-12 text-center text-muted-foreground"
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
:global(.dark) .filter-select {
    border-color: hsl(146 30% 25%);
    background: hsl(0 0% 7%);
    color: hsl(0 0% 98%);
}
</style>
