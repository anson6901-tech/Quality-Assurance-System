<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Check,
    ChevronDown,
    Download,
    Eye,
    Lock,
    Plus,
    Printer,
    RotateCcw,
    Send,
    Trash2,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import type { QualityReport } from '@/types';

type FilterOption = {
    id: number | string;
    name: string;
    schoolId?: number;
};

type ReportFilters = {
    lecturer_id?: number | string | null;
    academic_year_id?: number | string | null;
    school_id?: number | string | null;
    department_id?: number | string | null;
    status?: string | null;
};

const props = defineProps<{
    reports: QualityReport[];
    canViewReports: boolean;
    canUploadReports: boolean;
    approvalQueue: boolean;
    printQueue: boolean;
    canApproveReports: boolean;
    filters?: ReportFilters;
    filterOptions?: {
        lecturers: FilterOption[];
        academicYears: FilterOption[];
        schools: FilterOption[];
        departments: FilterOption[];
        statuses?: FilterOption[];
    };
}>();

const pageTitle = computed(() => {
    if (props.printQueue) {
        return 'Print Queue';
    }

    return props.approvalQueue ? 'Approvals' : 'QA Reports';
});

const pageDescription = computed(() => {
    if (props.printQueue) {
        return 'Approved lecturer reports ready for printing';
    }

    return props.approvalQueue
        ? 'Reports pushed by QA officers and ready for approval'
        : 'Track your reports from upload through approval and printing';
});

const emptyMessage = computed(() => {
    if (props.printQueue) {
        return 'No approved reports are ready for printing.';
    }

    return props.approvalQueue
        ? 'No reports are waiting for approval.'
        : 'No QA reports match the current filters.';
});

const filterRoute = computed(() => {
    if (props.printQueue) {
        return '/quality/print-queue';
    }

    return props.approvalQueue ? '/quality/approvals' : '/quality/reports';
});

const printReport = (report: QualityReport) => {
    window.open(report.fileUrl ?? `/quality/reports/${report.id}`, '_blank');
};

const deleteReport = (report: QualityReport) => {
    router.delete(`/quality/reports/${report.id}`, {
        preserveScroll: true,
    });
};

const lecturerSearch = ref('');

const activeFilters = computed<ReportFilters>(() => props.filters ?? {});

const selectedLecturerLabel = computed(() => {
    const selectedLecturerId = activeFilters.value.lecturer_id
        ? Number(activeFilters.value.lecturer_id)
        : null;

    if (!selectedLecturerId) {
        return 'All lecturers';
    }

    return (
        props.filterOptions?.lecturers.find(
            (lecturer) => lecturer.id === selectedLecturerId,
        )?.name ?? 'All lecturers'
    );
});

const filteredLecturerOptions = computed(() => {
    const query = lecturerSearch.value.trim().toLowerCase();
    const lecturers = props.filterOptions?.lecturers ?? [];

    if (!query) {
        return lecturers.slice(0, 12);
    }

    return lecturers
        .filter((lecturer) => lecturer.name.toLowerCase().includes(query))
        .slice(0, 12);
});

const filteredDepartments = computed(() => {
    const schoolId = activeFilters.value.school_id
        ? Number(activeFilters.value.school_id)
        : null;

    if (!schoolId) {
        return props.filterOptions?.departments ?? [];
    }

    return (props.filterOptions?.departments ?? []).filter(
        (department) => department.schoolId === schoolId,
    );
});

const updateFilter = (key: keyof ReportFilters, value: string) => {
    const nextFilters: ReportFilters = {
        ...activeFilters.value,
        [key]: value || undefined,
    };

    if (key === 'school_id') {
        nextFilters.department_id = undefined;
    }

    router.get(filterRoute.value, nextFilters, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

const clearFilters = () => {
    router.get(
        filterRoute.value,
        {},
        {
            preserveScroll: true,
            replace: true,
        },
    );
};

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'QA Reports',
                href: '/quality/reports',
            },
        ],
    },
});
</script>

<template>
    <Head :title="pageTitle" />

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between gap-4">
            <Heading
                variant="small"
                :title="pageTitle"
                :description="pageDescription"
            />

            <Button
                v-if="canUploadReports && !approvalQueue && !printQueue"
                as-child
            >
                <Link href="/quality/reports/create">
                    <Plus /> Upload report
                </Link>
            </Button>
        </div>

        <div
            v-if="filterOptions"
            class="grid gap-3 rounded-xl border border-[#9fd8b8] bg-[#eefaf3] p-5 shadow-sm ring-1 ring-[#007a2f]/10 md:grid-cols-5"
        >
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        type="button"
                        variant="outline"
                        class="h-10 justify-between border-[#9fd8b8] bg-white px-3 font-normal text-[#063f22] hover:bg-[#f8fffb]"
                    >
                        <span class="truncate">{{
                            selectedLecturerLabel
                        }}</span>
                        <ChevronDown class="h-4 w-4 opacity-60" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    class="w-[min(320px,calc(100vw-2rem))] p-2"
                >
                    <Input
                        v-model="lecturerSearch"
                        type="search"
                        placeholder="Search lecturer"
                        class="mb-2 h-10"
                        @keydown.stop
                    />
                    <DropdownMenuItem
                        @select.prevent="updateFilter('lecturer_id', '')"
                    >
                        All lecturers
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-for="lecturer in filteredLecturerOptions"
                        :key="lecturer.id"
                        @select.prevent="
                            updateFilter('lecturer_id', String(lecturer.id))
                        "
                    >
                        {{ lecturer.name }}
                    </DropdownMenuItem>
                    <div
                        v-if="filteredLecturerOptions.length === 0"
                        class="px-2 py-5 text-center text-sm text-muted-foreground"
                    >
                        No lecturers found.
                    </div>
                </DropdownMenuContent>
            </DropdownMenu>

            <select
                v-if="filterOptions.statuses"
                :value="activeFilters.status ?? ''"
                class="h-10 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                @change="
                    updateFilter(
                        'status',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option value="">All locations</option>
                <option
                    v-for="status in filterOptions.statuses"
                    :key="status.id"
                    :value="status.id"
                >
                    {{ status.name }}
                </option>
            </select>

            <select
                :value="activeFilters.academic_year_id ?? ''"
                class="h-10 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                @change="
                    updateFilter(
                        'academic_year_id',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option value="">All academic years</option>
                <option
                    v-for="academicYear in filterOptions.academicYears"
                    :key="academicYear.id"
                    :value="academicYear.id"
                >
                    {{ academicYear.name }}
                </option>
            </select>

            <select
                :value="activeFilters.school_id ?? ''"
                class="h-10 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                @change="
                    updateFilter(
                        'school_id',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option value="">All schools</option>
                <option
                    v-for="school in filterOptions.schools"
                    :key="school.id"
                    :value="school.id"
                >
                    {{ school.name }}
                </option>
            </select>

            <select
                :value="activeFilters.department_id ?? ''"
                class="h-10 rounded-md border border-[#9fd8b8] bg-white px-3 text-sm text-[#063f22] focus:border-[#007a2f] focus:ring-[#007a2f]/20"
                @change="
                    updateFilter(
                        'department_id',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option value="">All departments</option>
                <option
                    v-for="department in filteredDepartments"
                    :key="department.id"
                    :value="department.id"
                >
                    {{ department.name }}
                </option>
            </select>

            <Button
                type="button"
                variant="outline"
                class="h-10 border-[#007a2f]/30 bg-white font-semibold text-[#007a2f] hover:bg-[#e3f6eb] hover:text-[#005f27]"
                @click="clearFilters"
            >
                Clear
            </Button>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-[#9fd8b8] bg-white shadow-sm ring-1 ring-[#007a2f]/10"
        >
            <div
                class="grid grid-cols-[1.5fr_1fr_0.75fr_0.75fr_auto] gap-4 border-b border-[#9fd8b8] bg-[#e8f6ee] px-4 py-3 text-sm font-bold text-[#063f22]"
            >
                <span>Report</span>
                <span>Scope</span>
                <span>Location</span>
                <span>Status</span>
                <span class="sr-only">Actions</span>
            </div>

            <div
                v-for="report in reports"
                :key="report.id"
                class="grid grid-cols-[1.5fr_1fr_0.75fr_0.75fr_auto] items-center gap-4 border-b px-4 py-4 last:border-b-0"
            >
                <div class="min-w-0">
                    <div class="truncate font-medium">{{ report.title }}</div>
                    <div class="text-sm text-muted-foreground">
                        {{ report.category }} ·
                        {{ report.academicYear?.name ?? 'No academic year' }}
                        · {{ report.createdAt }}
                    </div>
                </div>

                <div class="min-w-0 text-sm">
                    <div class="truncate">{{ report.school?.name }}</div>
                    <div class="truncate text-muted-foreground">
                        {{
                            report.lecturer
                                ? `${report.lecturer.name} - ${report.department?.name}`
                                : (report.department?.name ?? 'School-wide')
                        }}
                    </div>
                </div>

                <div>
                    <Badge
                        class="border border-[#9fd8b8] bg-[#eefaf3] text-[#063f22]"
                        variant="secondary"
                    >
                        {{ report.location }}
                    </Badge>
                </div>

                <div>
                    <div class="flex items-center gap-2">
                        <Badge variant="secondary">{{ report.status }}</Badge>
                        <Lock
                            v-if="report.isLocked"
                            class="h-4 w-4 text-muted-foreground"
                        />
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <Button
                        v-if="
                            !approvalQueue &&
                            !printQueue &&
                            ['draft', 'submitted'].includes(report.status)
                        "
                        size="sm"
                        class="bg-[#007a2f] text-white hover:bg-[#006428]"
                        as-child
                    >
                        <Link
                            :href="`/quality/reports/${report.id}/push`"
                            method="post"
                            as="button"
                        >
                            <Send class="h-4 w-4" />
                            Push to Director
                        </Link>
                    </Button>

                    <Button
                        v-if="
                            approvalQueue &&
                            canApproveReports &&
                            report.status === 'under_review'
                        "
                        size="sm"
                        class="bg-[#007a2f] hover:bg-[#006428]"
                        as-child
                    >
                        <Link
                            :href="`/quality/reports/${report.id}/approve`"
                            method="post"
                            as="button"
                        >
                            <Check class="h-4 w-4" />
                            Approve
                        </Link>
                    </Button>

                    <Button
                        v-if="
                            approvalQueue &&
                            canApproveReports &&
                            report.status === 'under_review'
                        "
                        size="sm"
                        variant="destructive"
                        type="button"
                        @click="deleteReport(report)"
                    >
                        <Trash2 class="h-4 w-4" />
                        Delete
                    </Button>

                    <Button
                        v-if="
                            approvalQueue &&
                            canApproveReports &&
                            report.status === 'under_review'
                        "
                        size="sm"
                        variant="outline"
                        as-child
                    >
                        <Link
                            :href="`/quality/reports/${report.id}/return`"
                            method="post"
                            as="button"
                        >
                            <RotateCcw class="h-4 w-4" />
                            Push back
                        </Link>
                    </Button>

                    <Button
                        v-if="
                            approvalQueue &&
                            canApproveReports &&
                            report.status === 'approved'
                        "
                        size="sm"
                        variant="outline"
                        as-child
                    >
                        <Link
                            :href="`/quality/reports/${report.id}/unapprove`"
                            method="post"
                            as="button"
                        >
                            <RotateCcw class="h-4 w-4" />
                            Unapprove
                        </Link>
                    </Button>

                    <Button
                        v-if="printQueue"
                        variant="outline"
                        size="sm"
                        type="button"
                        @click="printReport(report)"
                    >
                        <Printer class="h-4 w-4" />
                        Print
                    </Button>

                    <Button
                        v-if="printQueue && report.downloadUrl"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <a :href="report.downloadUrl">
                            <Download class="h-4 w-4" />
                            Download
                        </a>
                    </Button>

                    <Button variant="ghost" size="sm" as-child>
                        <Link :href="`/quality/reports/${report.id}`">
                            <Eye class="h-4 w-4" />
                        </Link>
                    </Button>
                </div>
            </div>
        </div>

        <p
            v-if="reports.length === 0"
            class="py-8 text-center text-muted-foreground"
        >
            {{ emptyMessage }}
        </p>
    </div>
</template>
