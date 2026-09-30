<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    BookOpenCheck,
    ArrowRight,
    CheckCircle2,
    ClipboardList,
    FileText,
    ListPlus,
    Upload,
    Users,
    CalendarRange,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import type { Team } from '@/types';

const props = defineProps<{
    stats: {
        publishedReports: number;
        pendingReports: number;
        assignedCourses: number;
        departmentCourses: number;
    };
    academicYearStats: Array<{
        id: number;
        name: string;
        status: string;
        totalReports: number;
        draftReports: number;
        supervisorReview: number;
        directorReview: number;
        publishedReports: number;
        publicationRate: number;
    }>;
}>();
const page = usePage();
const profile = computed(() => page.props.qualityProfile);
const roleGreeting = computed(() => profile.value?.roleLabel ?? 'Colleague');
const isLecturer = computed(() => profile.value?.role === 'lecturer');
const isSystemAdministrator = computed(
    () => profile.value?.role === 'ict_admin',
);

const primaryAction = computed(() => {
    if (profile.value?.canApproveReports) {
        return {
            label: 'Open Director Review',
            href: '/quality/approvals',
            icon: CheckCircle2,
        };
    }

    if (profile.value?.canReviewReports) {
        return {
            label: 'Open Supervisor Review',
            href: '/quality/reports',
            icon: ClipboardList,
        };
    }

    if (profile.value?.canUploadReports) {
        return {
            label: 'Upload Reports',
            href: '/quality/reports/create',
            icon: Upload,
        };
    }

    if (profile.value?.canManageCourseAssignments) {
        return {
            label: 'Assign Courses',
            href: '/quality/course-assignments',
            icon: Users,
        };
    }

    if (profile.value?.permissions.includes('view_own_reports')) {
        return {
            label: 'View My Courses',
            href: '/quality/my-courses',
            icon: BookOpenCheck,
        };
    }

    return {
        label: 'View Published Reports',
        href: '/quality/print-queue',
        icon: FileText,
    };
});

defineOptions({
    layout: (layoutProps: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: layoutProps.currentTeam
                    ? dashboard(layoutProps.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="space-y-7">
        <section
            class="overflow-hidden rounded-lg border border-[#075b2e] bg-[#064d2b] text-white shadow-sm"
        >
            <div
                class="flex flex-col gap-8 px-6 py-8 sm:px-8 lg:flex-row lg:items-end lg:justify-between"
            >
                <div>
                    <p class="text-sm font-semibold text-[#a9e8c3]">
                        {{ roleGreeting }}
                    </p>
                    <h1 class="mt-2 text-3xl font-bold text-white">
                        {{
                            isLecturer
                                ? 'Your teaching overview'
                                : 'Quality Assurance workspace'
                        }}
                    </h1>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-white/75">
                        {{
                            isLecturer
                                ? 'Assigned courses, teaching workload, and published evaluation reports for your account.'
                                : 'Records and actions within your assigned quality assurance responsibilities.'
                        }}
                    </p>
                </div>
                <Button
                    as-child
                    class="h-11 border border-white/25 bg-white text-[#064d2b] hover:bg-[#eefaf3]"
                >
                    <Link :href="primaryAction.href">
                        <component :is="primaryAction.icon" class="h-4 w-4" />
                        {{ primaryAction.label }}
                        <ArrowRight class="h-4 w-4" />
                    </Link>
                </Button>
            </div>
            <div
                class="grid border-t border-white/15 bg-[#043d22] sm:grid-cols-2"
            >
                <div class="px-6 py-4 sm:px-8">
                    <div class="text-xs font-semibold text-white/60 uppercase">
                        Account scope
                    </div>
                    <div class="mt-1 text-sm font-medium text-white">
                        {{ roleGreeting }}
                    </div>
                </div>
                <div
                    class="border-t border-white/15 px-6 py-4 sm:border-t-0 sm:border-l sm:px-8"
                >
                    <div class="text-xs font-semibold text-white/60 uppercase">
                        Primary workspace
                    </div>
                    <div class="mt-1 text-sm font-medium text-white">
                        {{ primaryAction.label }}
                    </div>
                </div>
            </div>
        </section>

        <section
            class="grid gap-4 sm:grid-cols-2"
            :class="!isLecturer && 'xl:grid-cols-4'"
        >
            <div
                class="rounded-md border border-[#cbdbe1] bg-white p-5 shadow-sm"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="text-sm font-medium text-muted-foreground">
                            Assigned courses
                        </div>
                        <div class="mt-3 text-3xl font-bold text-[#071126]">
                            {{ props.stats.assignedCourses }}
                        </div>
                    </div>
                    <span
                        class="flex h-10 w-10 items-center justify-center rounded-md bg-[#e2f2e8] text-[#075b2e]"
                        ><BookOpenCheck class="h-5 w-5"
                    /></span>
                </div>
                <div
                    class="mt-5 border-t border-[#e1e8ec] pt-3 text-xs text-muted-foreground"
                >
                    Current teaching assignments
                </div>
            </div>
            <div
                class="rounded-md border border-[#cbdbe1] bg-white p-5 shadow-sm"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="text-sm font-medium text-muted-foreground">
                            Published evaluations
                        </div>
                        <div class="mt-3 text-3xl font-bold text-[#071126]">
                            {{ props.stats.publishedReports }}
                        </div>
                    </div>
                    <span
                        class="flex h-10 w-10 items-center justify-center rounded-md bg-[#d9edf5] text-[#234d5d]"
                        ><FileText class="h-5 w-5"
                    /></span>
                </div>
                <div
                    class="mt-5 border-t border-[#e1e8ec] pt-3 text-xs text-muted-foreground"
                >
                    Reports available from My Courses
                </div>
            </div>
            <template v-if="!isLecturer">
                <div
                    class="rounded-md border border-[#cbdbe1] bg-white p-5 shadow-sm"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div
                                class="text-sm font-medium text-muted-foreground"
                            >
                                Pending action
                            </div>
                            <div class="mt-3 text-3xl font-bold text-[#071126]">
                                {{ props.stats.pendingReports }}
                            </div>
                        </div>
                        <span
                            class="flex h-10 w-10 items-center justify-center rounded-md bg-[#fff3d6] text-[#8a5b00]"
                            ><ClipboardList class="h-5 w-5"
                        /></span>
                    </div>
                    <div
                        class="mt-5 border-t border-[#e1e8ec] pt-3 text-xs text-muted-foreground"
                    >
                        Reports requiring your action
                    </div>
                </div>
                <div
                    class="rounded-md border border-[#cbdbe1] bg-white p-5 shadow-sm"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div
                                class="text-sm font-medium text-muted-foreground"
                            >
                                Department courses
                            </div>
                            <div class="mt-3 text-3xl font-bold text-[#071126]">
                                {{ props.stats.departmentCourses }}
                            </div>
                        </div>
                        <span
                            class="flex h-10 w-10 items-center justify-center rounded-md bg-[#f0e8f7] text-[#69417e]"
                            ><ListPlus class="h-5 w-5"
                        /></span>
                    </div>
                    <div
                        class="mt-5 border-t border-[#e1e8ec] pt-3 text-xs text-muted-foreground"
                    >
                        Courses in your department
                    </div>
                </div>
            </template>
        </section>

        <section
            v-if="isSystemAdministrator"
            class="overflow-hidden rounded-md border border-[#cbdbe1] bg-white shadow-sm"
        >
            <div
                class="flex flex-col gap-3 border-b border-[#dce6e1] bg-[#f3faf6] px-5 py-5 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h2
                        class="flex items-center gap-2 text-lg font-semibold text-[#071126]"
                    >
                        <CalendarRange class="h-5 w-5 text-[#087a3d]" />
                        Academic year statistics
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Report progress across each academic year.
                    </p>
                </div>
                <Button variant="outline" as-child>
                    <Link href="/quality/academic-years">Manage years</Link>
                </Button>
            </div>

            <div v-if="academicYearStats.length" class="overflow-x-auto">
                <table class="w-full min-w-[820px] text-left text-sm">
                    <thead
                        class="bg-[#e4f3ea] text-xs text-[#164d32] uppercase"
                    >
                        <tr>
                            <th class="px-5 py-3 font-semibold">
                                Academic year
                            </th>
                            <th class="px-4 py-3 text-center font-semibold">
                                Total
                            </th>
                            <th class="px-4 py-3 text-center font-semibold">
                                Draft
                            </th>
                            <th class="px-4 py-3 text-center font-semibold">
                                Supervisor
                            </th>
                            <th class="px-4 py-3 text-center font-semibold">
                                Director
                            </th>
                            <th class="px-4 py-3 text-center font-semibold">
                                Published
                            </th>
                            <th class="px-5 py-3 font-semibold">
                                Publication rate
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#e1e8ec]">
                        <tr
                            v-for="year in academicYearStats"
                            :key="year.id"
                            class="hover:bg-[#f8fcfa]"
                        >
                            <td class="px-5 py-4">
                                <div class="font-semibold text-[#071126]">
                                    {{ year.name }}
                                </div>
                                <span
                                    class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                    :class="
                                        year.status === 'active'
                                            ? 'bg-[#dcf4e5] text-[#087a3d]'
                                            : 'bg-[#eef1f3] text-[#58646d]'
                                    "
                                >
                                    {{ year.status }}
                                </span>
                            </td>
                            <td class="px-4 py-4 text-center font-semibold">
                                {{ year.totalReports }}
                            </td>
                            <td class="px-4 py-4 text-center">
                                {{ year.draftReports }}
                            </td>
                            <td class="px-4 py-4 text-center">
                                {{ year.supervisorReview }}
                            </td>
                            <td class="px-4 py-4 text-center">
                                {{ year.directorReview }}
                            </td>
                            <td
                                class="px-4 py-4 text-center font-semibold text-[#087a3d]"
                            >
                                {{ year.publishedReports }}
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex min-w-36 items-center gap-3">
                                    <div
                                        class="h-2 flex-1 overflow-hidden rounded-full bg-[#e4ece8]"
                                    >
                                        <div
                                            class="h-full rounded-full bg-[#087a3d]"
                                            :style="{
                                                width: `${year.publicationRate}%`,
                                            }"
                                        />
                                    </div>
                                    <span
                                        class="w-10 text-right font-semibold text-[#164d32]"
                                    >
                                        {{ year.publicationRate }}%
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-else
                class="px-5 py-10 text-center text-sm text-muted-foreground"
            >
                No academic years have been created yet.
            </div>
        </section>

        <section
            v-if="!isLecturer"
            class="rounded-md border border-[#cbdbe1] bg-white p-5"
        >
            <h2 class="text-lg font-semibold text-[#071126]">
                Available modules
            </h2>
            <div class="mt-4 flex flex-wrap gap-3">
                <Button
                    v-if="profile?.canManageCourseAssignments"
                    variant="outline"
                    as-child
                    ><Link href="/quality/course-assignments"
                        >Course Assignments</Link
                    ></Button
                >
                <Button
                    v-if="profile?.canManageCourseAssignments"
                    variant="outline"
                    as-child
                    ><Link href="/quality/course-offerings"
                        >Mount Courses</Link
                    ></Button
                >
                <Button
                    v-if="profile?.canUploadReports"
                    variant="outline"
                    as-child
                    ><Link href="/quality/reports/create"
                        >Upload Reports</Link
                    ></Button
                >
                <Button
                    v-if="profile?.canReviewReports"
                    variant="outline"
                    as-child
                    ><Link href="/quality/reports"
                        >Supervisor Review</Link
                    ></Button
                >
                <Button
                    v-if="profile?.canApproveReports"
                    variant="outline"
                    as-child
                    ><Link href="/quality/approvals"
                        >Director Review</Link
                    ></Button
                >
                <Button
                    v-if="
                        profile?.canViewPrintQueue &&
                        profile.role !== 'lecturer'
                    "
                    variant="outline"
                    as-child
                    ><Link href="/quality/print-queue"
                        >Published Reports</Link
                    ></Button
                >
                <Button
                    v-if="profile?.permissions.includes('view_own_reports')"
                    variant="outline"
                    as-child
                    ><Link href="/quality/my-courses">My Courses</Link></Button
                >
            </div>
        </section>
    </div>
</template>
