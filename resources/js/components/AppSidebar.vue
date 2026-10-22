<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CalendarRange,
    CheckCircle2,
    ClipboardList,
    FolderUp,
    BookOpenCheck,
    BookMarked,
    GraduationCap,
    History,
    KeyRound,
    LayoutGrid,
    Layers3,
    Network,
    ListPlus,
    LogOut,
    Mail,
    Printer,
    Settings,
    ShieldCheck,
    Upload,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();
const { setOpenMobile } = useSidebar();

const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);

const showUploadReportItem = computed(() =>
    Boolean(page.props.qualityProfile?.canUploadReports),
);

const showReportsItem = computed(() =>
    Boolean(
        page.props.qualityProfile?.canViewReports ||
        page.props.qualityProfile?.canApproveReports,
    ),
);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboardUrl.value,
        icon: LayoutGrid,
    },
    ...(showUploadReportItem.value
        ? [
              {
                  title: 'Upload Reports',
                  href: '/quality/reports/create',
                  icon: Upload,
              },
              {
                  title: 'Bulk Upload',
                  href: '/quality/bulk-upload',
                  icon: FolderUp,
              },
          ]
        : []),
    ...(showReportsItem.value
        ? [
              {
                  title: page.props.qualityProfile?.canReviewReports
                      ? 'Supervisor Review'
                      : 'My Reports',
                  href: '/quality/reports',
                  icon: ClipboardList,
              },
          ]
        : []),
    ...(page.props.qualityProfile?.canApproveReports
        ? [
              {
                  title: 'Approvals',
                  href: '/quality/approvals',
                  icon: CheckCircle2,
              },
          ]
        : []),
    ...(page.props.qualityProfile?.canViewPrintQueue
        ? [
              {
                  title: 'Print Queue',
                  href: '/quality/print-queue',
                  icon: Printer,
              },
          ]
        : []),
    ...(page.props.qualityProfile?.permissions.includes('view_own_reports')
        ? [
              {
                  title: 'My Courses',
                  href: '/quality/my-courses',
                  icon: BookMarked,
              },
          ]
        : []),
    ...(page.props.qualityProfile?.canManageQualityStructure ||
    page.props.qualityProfile?.canManageCourseAssignments ||
    page.props.qualityProfile?.canUploadReports
        ? [
              {
                  title: 'Course Assignments',
                  href: '/quality/course-assignments',
                  icon: Users,
              },
          ]
        : []),
]);

const administrationNavItems = computed<NavItem[]>(() => [
    ...(page.props.qualityProfile?.canManageUsers
        ? [
              {
                  title: 'Organizational Structure',
                  href: '/quality/organization',
                  icon: Network,
              },
              {
                  title: 'Users',
                  href: '/quality/users',
                  icon: Users,
              },
          ]
        : []),
    ...(page.props.qualityProfile?.canManageRoles
        ? [
              {
                  title: 'Roles',
                  href: '/quality/roles',
                  icon: ShieldCheck,
              },
              {
                  title: 'Permissions',
                  href: '/quality/permissions',
                  icon: KeyRound,
              },
          ]
        : []),
    ...(page.props.qualityProfile?.canManageQualityStructure
        ? [
              {
                  title: 'Schools',
                  href: '/quality/schools',
                  icon: GraduationCap,
              },
              {
                  title: 'Departments',
                  href: '/quality/departments',
                  icon: Building2,
              },
              {
                  title: 'Programs',
                  href: '/quality/programs',
                  icon: Layers3,
              },
              {
                  title: 'Academic Years',
                  href: '/quality/academic-years',
                  icon: CalendarRange,
              },
          ]
        : []),
    ...(page.props.qualityProfile?.canManageQualityStructure ||
    page.props.qualityProfile?.canManageCourseAssignments
        ? [
              {
                  title: 'Mount Courses',
                  href: '/quality/course-offerings',
                  icon: ListPlus,
              },
          ]
        : []),
    ...(page.props.qualityProfile?.canManageQualityStructure
        ? [
              {
                  title: 'Courses',
                  href: '/quality/courses',
                  icon: BookOpenCheck,
              },
          ]
        : []),
    ...(page.props.qualityProfile?.canManageEmailSettings
        ? [
              {
                  title: 'Email Settings',
                  href: '/quality/email-settings',
                  icon: Mail,
              },
          ]
        : []),
    ...(page.props.qualityProfile?.canViewAuditLogs
        ? [
              {
                  title: 'Audit Logs',
                  href: '/quality/audit-logs',
                  icon: History,
              },
          ]
        : []),
]);

const footerNavItems: NavItem[] = [
    {
        title: 'Settings',
        href: '/settings/profile',
        icon: Settings,
    },
    {
        title: 'Sign out',
        href: '/logout',
        icon: LogOut,
    },
];
</script>

<template>
    <Sidebar
        collapsible="icon"
        variant="sidebar"
        class="border-r border-white/8 bg-[#002d18] shadow-[12px_0_30px_rgba(0,45,24,0.08)]"
    >
        <SidebarHeader class="border-b border-white/8 px-5 py-6">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        class="h-18 p-0 hover:bg-transparent data-[active=true]:bg-transparent"
                    >
                        <Link
                            :href="dashboardUrl"
                            @click="setOpenMobile(false)"
                        >
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="bg-[#002d18] py-6">
            <NavMain :items="mainNavItems" />

            <SidebarGroup
                v-if="administrationNavItems.length"
                class="mt-9 px-3"
            >
                <SidebarGroupLabel
                    class="px-3 text-xs font-bold tracking-[0.16em] text-white/50 uppercase group-data-[collapsible=icon]:sr-only"
                >
                    Administration
                </SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuItem
                        v-for="item in administrationNavItems"
                        :key="item.title"
                    >
                        <SidebarMenuButton
                            as-child
                            :tooltip="item.title"
                            class="h-11 rounded-xl px-3 text-base font-semibold text-white/88 hover:bg-white/9 hover:text-white data-[active=true]:bg-[#0b3b22] data-[active=true]:text-white [&>svg]:size-5"
                        >
                            <Link
                                :href="item.href"
                                @click="setOpenMobile(false)"
                            >
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>

        <SidebarFooter class="border-t border-white/8 bg-[#002d18] px-3 py-6">
            <SidebarMenu>
                <SidebarMenuItem
                    v-for="item in footerNavItems"
                    :key="item.title"
                >
                    <SidebarMenuButton
                        as-child
                        class="h-11 rounded-xl px-3 text-base font-medium text-white/88 hover:bg-white/9 hover:text-white [&>svg]:size-5"
                    >
                        <button
                            v-if="item.title === 'Sign out'"
                            type="button"
                            class="flex w-full items-center gap-2"
                            @click="
                                setOpenMobile(false);
                                router.post('/logout');
                            "
                        >
                            <component :is="item.icon" />
                            <span>{{ item.title }}</span>
                        </button>
                        <Link
                            v-else
                            :href="item.href"
                            @click="setOpenMobile(false)"
                        >
                            <component :is="item.icon" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
