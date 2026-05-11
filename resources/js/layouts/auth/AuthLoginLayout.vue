<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { GraduationCap, ShieldCheck } from 'lucide-vue-next';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="grid min-h-svh bg-[#f4f7fb] lg:grid-cols-2">
        <section
            class="relative flex min-h-[44rem] flex-col overflow-hidden bg-[#007a2f] px-8 py-10 text-white sm:px-12 lg:min-h-svh lg:px-20 lg:py-18"
        >
            <div
                class="absolute inset-0 bg-[radial-gradient(circle_at_70%_10%,rgba(0,183,85,0.72),transparent_34%),linear-gradient(135deg,#006927_0%,#008f38_46%,#00a64a_100%)]"
            />

            <div class="relative z-10 flex flex-1 flex-col">
                <Link :href="home()" class="flex items-center gap-5">
                    <span
                        class="flex size-[70px] items-center justify-center rounded-[28px] bg-white/14 ring-1 ring-white/10"
                    >
                        <GraduationCap class="size-9" />
                    </span>
                    <span class="text-3xl font-bold tracking-normal">
                        UHAS QA Insight
                    </span>
                </Link>

                <div class="mt-16 max-w-[46rem] lg:mt-20">
                    <h1
                        class="text-[4.4rem] leading-[1.08] font-extrabold tracking-normal text-white md:text-[5rem] lg:text-[4.7rem] xl:text-[5.2rem]"
                    >
                        Quality assurance, beautifully managed.
                    </h1>

                    <p
                        class="mt-12 max-w-[44rem] text-[1.65rem] leading-[1.55] text-white/82"
                    >
                        A unified workspace for QA officers, deans, HODs and
                        lecturers - from school reports to approved insights.
                    </p>
                </div>

                <div class="mt-auto pt-14">
                    <div class="grid max-w-[46rem] gap-6 sm:grid-cols-3">
                        <div
                            class="rounded-[26px] border border-white/18 bg-white/12 px-6 py-5 shadow-[inset_0_1px_0_rgba(255,255,255,0.18)]"
                        >
                            <div class="text-4xl font-extrabold">1.2k</div>
                            <div
                                class="mt-2 text-sm font-medium tracking-[0.14em] text-white/72 uppercase"
                            >
                                Reports
                            </div>
                        </div>
                        <div
                            class="rounded-[26px] border border-white/18 bg-white/12 px-6 py-5 shadow-[inset_0_1px_0_rgba(255,255,255,0.18)]"
                        >
                            <div class="text-4xl font-extrabold">184</div>
                            <div
                                class="mt-2 text-sm font-medium tracking-[0.14em] text-white/72 uppercase"
                            >
                                Lecturers
                            </div>
                        </div>
                        <div
                            class="rounded-[26px] border border-white/18 bg-white/12 px-6 py-5 shadow-[inset_0_1px_0_rgba(255,255,255,0.18)]"
                        >
                            <div class="text-4xl font-extrabold">12</div>
                            <div
                                class="mt-2 text-sm font-medium tracking-[0.14em] text-white/72 uppercase"
                            >
                                Schools
                            </div>
                        </div>
                    </div>

                    <p class="mt-5 text-2xl text-white/72">
                        (c) 2026 UHAS Office of Quality Assurance
                    </p>
                </div>
            </div>
        </section>

        <main
            class="flex min-h-svh items-center justify-center px-8 py-12 sm:px-12"
        >
            <div class="w-full max-w-[44.8rem]">
                <div
                    class="mb-5 inline-flex items-center gap-2 rounded-full bg-[#e9eef5] px-5 py-2 text-base font-semibold text-[#0f1b33]"
                >
                    <ShieldCheck class="size-5" />
                    Secure sign in
                </div>

                <div class="space-y-4">
                    <h2
                        v-if="title"
                        class="text-[3rem] leading-tight font-extrabold tracking-normal text-[#071126]"
                    >
                        {{ title }}
                    </h2>
                    <p
                        v-if="description"
                        class="text-[1.55rem] leading-relaxed text-[#5f6b7d]"
                    >
                        {{ description }}
                    </p>
                </div>

                <div class="mt-14">
                    <slot />
                </div>
            </div>
        </main>
    </div>
</template>
