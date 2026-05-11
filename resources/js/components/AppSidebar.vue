<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CalendarRange,
    CheckCircle2,
    ClipboardList,
    BookOpenCheck,
    GraduationCap,
    LayoutGrid,
    LogOut,
    Printer,
    Settings,
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
    {
        title: 'Reports',
        href: '/quality/reports',
        icon: ClipboardList,
    },
    {
        title: 'Approvals',
        href: '/quality/approvals',
        icon: CheckCircle2,
    },
    {
        title: 'Print Queue',
        href: '/quality/reports',
        icon: Printer,
    },
]);

const administrationNavItems = computed<NavItem[]>(() => [
    ...(page.props.qualityProfile?.canManageQualityStructure
        ? [
              {
                  title: 'Users',
                  href: '/quality/users',
                  icon: Users,
              },
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
              {
                  title: 'Courses',
                  href: '/quality/courses',
                  icon: BookOpenCheck,
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
        class="border-r border-white/8 bg-[#002d18]"
    >
        <SidebarHeader class="border-b border-white/8 px-6 py-9">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        class="h-16 p-0 hover:bg-transparent data-[active=true]:bg-transparent"
                    >
                        <Link :href="dashboardUrl">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="bg-[#002d18] py-7">
            <NavMain :items="mainNavItems" />

            <SidebarGroup
                v-if="administrationNavItems.length"
                class="mt-11 px-3"
            >
                <SidebarGroupLabel
                    class="px-3 text-base font-semibold text-white/62 group-data-[collapsible=icon]:sr-only"
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
                            class="h-12 rounded-2xl px-3 text-lg font-semibold text-white/92 hover:bg-white/9 hover:text-white data-[active=true]:bg-[#0b3b22] data-[active=true]:text-white [&>svg]:size-5"
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

        <SidebarFooter class="border-t border-white/8 bg-[#002d18] px-3 py-7">
            <SidebarMenu>
                <SidebarMenuItem
                    v-for="item in footerNavItems"
                    :key="item.title"
                >
                    <SidebarMenuButton
                        as-child
                        class="h-12 rounded-2xl px-3 text-lg font-medium text-white/92 hover:bg-white/9 hover:text-white [&>svg]:size-5"
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
