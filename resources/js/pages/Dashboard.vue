<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Check,
    CheckCircle2,
    Clock3,
    FileText,
    TrendingUp,
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import type { Team } from '@/types';

const page = usePage();

const roleGreeting =
    page.props.qualityProfile?.role === 'qa_officer'
        ? 'Director'
        : (page.props.qualityProfile?.roleLabel ?? 'Colleague');

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: props.currentTeam
                    ? dashboard(props.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-10 bg-[#f4f7fb] px-10 py-10">
        <div
            class="rounded-[34px] bg-gradient-to-r from-[#007a2f] to-[#00a64a] px-13 py-14 text-white"
        >
            <div
                class="flex flex-col gap-8 xl:flex-row xl:items-end xl:justify-between"
            >
                <div>
                    <div
                        class="text-2xl tracking-[0.18em] text-white/75 uppercase"
                    >
                        Welcome back
                    </div>
                    <h1 class="mt-4 text-[3.5rem] leading-tight font-extrabold">
                        Good afternoon, {{ roleGreeting }}.
                    </h1>
                    <p class="mt-5 text-2xl text-white/82">
                        7 reports awaiting your approval. 3 schools have not
                        submitted this week.
                    </p>
                </div>

                <div class="flex flex-wrap gap-5">
                    <Button
                        as-child
                        class="h-14 rounded-[18px] bg-[#eef3f8] px-7 text-xl font-bold text-[#071126] hover:bg-white"
                    >
                        <Link href="/quality/reports">View queue</Link>
                    </Button>
                    <Button
                        class="h-14 rounded-[18px] bg-white px-7 text-xl font-bold text-[#007a2f] hover:bg-[#eef3f8]"
                    >
                        Approve all
                        <CheckCircle2 class="ml-3 size-6" />
                    </Button>
                </div>
            </div>
        </div>

        <div class="grid gap-7 xl:grid-cols-4">
            <div
                class="rounded-[26px] border border-[#dbe1e8] bg-white p-8 shadow-[0_2px_8px_rgba(15,23,42,0.12)]"
            >
                <div class="flex items-start justify-between">
                    <span
                        class="flex size-16 items-center justify-center rounded-[18px] bg-[#e8f3ed] text-[#007a2f]"
                    >
                        <FileText class="size-8" />
                    </span>
                    <span
                        class="rounded-full border border-[#dbe1e8] px-4 py-1 text-lg font-bold text-[#071126]"
                    >
                        <ArrowUpRight class="mr-1 inline size-4" /> +12.4%
                    </span>
                </div>
                <div class="mt-8 text-5xl font-extrabold text-[#071126]">
                    1,284
                </div>
                <div class="mt-3 text-2xl text-[#5f6b7d]">Total Reports</div>
            </div>

            <div
                class="rounded-[26px] border border-[#dbe1e8] bg-white p-8 shadow-[0_2px_8px_rgba(15,23,42,0.12)]"
            >
                <div class="flex items-start justify-between">
                    <Check class="mt-4 size-8 text-[#25b977]" />
                    <span
                        class="rounded-full border border-[#dbe1e8] px-4 py-1 text-lg font-bold text-[#071126]"
                    >
                        <ArrowUpRight class="mr-1 inline size-4" /> +8.2%
                    </span>
                </div>
                <div class="mt-8 text-5xl font-extrabold text-[#071126]">
                    1,041
                </div>
                <div class="mt-3 text-2xl text-[#5f6b7d]">Approved</div>
            </div>

            <div
                class="rounded-[26px] border border-[#dbe1e8] bg-white p-8 shadow-[0_2px_8px_rgba(15,23,42,0.12)]"
            >
                <div class="flex items-start justify-between">
                    <Clock3 class="mt-4 size-8 text-[#071126]" />
                    <span
                        class="rounded-full border border-[#dbe1e8] px-4 py-1 text-lg font-bold text-[#071126]"
                    >
                        <ArrowUpRight class="mr-1 inline size-4" /> -3.1%
                    </span>
                </div>
                <div class="mt-8 text-5xl font-extrabold text-[#071126]">
                    187
                </div>
                <div class="mt-3 text-2xl text-[#5f6b7d]">Pending</div>
            </div>

            <div
                class="rounded-[26px] border border-[#dbe1e8] bg-white p-8 shadow-[0_2px_8px_rgba(15,23,42,0.12)]"
            >
                <div class="flex items-start justify-between">
                    <TrendingUp class="mt-4 size-8 text-[#071126]" />
                    <span
                        class="rounded-full border border-[#dbe1e8] px-4 py-1 text-lg font-bold text-[#071126]"
                    >
                        <ArrowUpRight class="mr-1 inline size-4" /> -0.6d
                    </span>
                </div>
                <div class="mt-8 text-5xl font-extrabold text-[#071126]">
                    2.4d
                </div>
                <div class="mt-3 text-2xl text-[#5f6b7d]">Avg. Turnaround</div>
            </div>
        </div>

        <div class="grid gap-7 xl:grid-cols-[2fr_1fr]">
            <section
                class="rounded-[26px] border border-[#dbe1e8] bg-white p-10 shadow-[0_2px_8px_rgba(15,23,42,0.08)]"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-3xl font-extrabold text-[#071126]">
                            Submissions vs Approvals
                        </h2>
                        <p class="mt-2 text-2xl text-[#5f6b7d]">
                            Last 7 months
                        </p>
                    </div>
                    <span class="text-xl font-bold text-[#071126]">
                        This year
                    </span>
                </div>

                <div
                    class="mt-12 h-72 rounded-xl border border-dashed border-[#dbe1e8] p-8"
                >
                    <div class="flex h-full items-end gap-7">
                        <div class="h-[42%] flex-1 rounded-t-xl bg-[#4f7df3]" />
                        <div class="h-[58%] flex-1 rounded-t-xl bg-[#20c887]" />
                        <div class="h-[46%] flex-1 rounded-t-xl bg-[#4f7df3]" />
                        <div class="h-[68%] flex-1 rounded-t-xl bg-[#20c887]" />
                        <div class="h-[72%] flex-1 rounded-t-xl bg-[#4f7df3]" />
                        <div class="h-[82%] flex-1 rounded-t-xl bg-[#20c887]" />
                    </div>
                </div>
            </section>

            <section
                class="rounded-[26px] border border-[#dbe1e8] bg-white p-10 shadow-[0_2px_8px_rgba(15,23,42,0.08)]"
            >
                <h2 class="text-3xl font-extrabold text-[#071126]">
                    By School
                </h2>
                <p class="mt-2 text-2xl text-[#5f6b7d]">Approved reports</p>

                <div class="mt-12 space-y-8">
                    <div class="grid grid-cols-[140px_1fr] items-center gap-4">
                        <span class="text-xl text-[#5f6b7d]">Medicine</span>
                        <div class="h-12 rounded-r-xl bg-[#4f7df3]" />
                    </div>
                    <div class="grid grid-cols-[140px_1fr] items-center gap-4">
                        <span class="text-xl text-[#5f6b7d]">Nursing</span>
                        <div class="h-12 w-4/5 rounded-r-xl bg-[#20c887]" />
                    </div>
                    <div class="grid grid-cols-[140px_1fr] items-center gap-4">
                        <span class="text-xl text-[#5f6b7d]">
                            Public Health
                        </span>
                        <div class="h-12 w-3/5 rounded-r-xl bg-[#ffc857]" />
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
