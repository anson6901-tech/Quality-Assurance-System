<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CalendarRange,
    CheckCircle2,
    ClipboardList,
    BookOpenCheck,
    GraduationCap,
    History,
    LayoutGrid,
    LogOut,
    Printer,
    Settings,
    ShieldCheck,
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
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();

const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboardUrl.value,
        icon: LayoutGrid,
    },
    ...(page.props.qualityProfile?.canViewReports ||
    page.props.qualityProfile?.canUploadReports
        ? [
              {
                  title: 'Reports',
                  href: page.props.qualityProfile?.canViewReports
                      ? '/quality/reports'
                      : '/quality/reports/create',
                  icon: ClipboardList,
              },
          ]
        : []),
    ...(page.props.qualityProfile?.canViewApprovalQueue
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
]);

const administrationNavItems = computed<NavItem[]>(() => [
    ...(page.props.qualityProfile?.canManageUsers
        ? [
              {
                  title: 'Users',
                  href: '/quality/users',
                  icon: Users,
              },
              {
                  title: 'Roles & Rights',
                  href: '/quality/roles-rights',
                  icon: ShieldCheck,
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
                  title: 'Courses',
                  href: '/quality/courses',
                  icon: BookOpenCheck,
              },
          ]
        : []),
    ...(page.props.qualityProfile?.canManageQualityStructure ||
    page.props.qualityProfile?.canManageCourseAssignments
        ? [
              {
                  title: 'Course Assignments',
                  href: '/quality/course-assignments',
                  icon: Users,
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
                        <Link :href="dashboardUrl">
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
                            <Link :href="item.href">
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
                            @click="router.post('/logout')"
                        >
                            <component :is="item.icon" />
                            <span>{{ item.title }}</span>
                        </button>
                        <Link v-else :href="item.href">
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
