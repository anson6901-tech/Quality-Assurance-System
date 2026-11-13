<script setup lang="ts">
import { computed } from 'vue';
import type { SetSummary } from '@/types/evaluation';
import {
    CLASSIFICATION_COLORS,
    CLASSIFICATION_LABELS,
    formatNumber,
} from '@/types/evaluation';

const props = defineProps<{
    summary: SetSummary;
    thresholds: { high: number; acceptable: number };
    course: string;
    lecturer: string;
}>();

const sections = computed(() => {
    const spans = new Map<string, number>();
    props.summary.questions.forEach((question) =>
        spans.set(question.section, (spans.get(question.section) ?? 0) + 1),
    );

    return [...spans.entries()];
});

const colorStyle = (classification: string | null) => ({
    background: classification ? CLASSIFICATION_COLORS[classification] : '#fff',
    color: classification === 'improvement' ? '#fff' : '#000',
});

const maxBand = computed(() =>
    Math.max(
        1,
        ...(props.summary.grades?.bands.map((band) => band.count) ?? [1]),
    ),
);

const border = 'border border-black px-1 py-1';
</script>

<template>
    <div class="space-y-6">
        <section class="overflow-x-auto">
            <h3 class="mb-2 font-semibold text-[#063f22]">
                Page 1 — SET table ({{ summary.responses }} responses)
            </h3>
            <table
                class="w-full min-w-[900px] border-collapse bg-white text-xs"
            >
                <thead>
                    <tr>
                        <th :class="border" class="w-40">COURSE</th>
                        <th :class="border" class="w-40">LECTURER</th>
                        <th
                            v-for="[name, span] in sections"
                            :key="name"
                            :colspan="span"
                            :class="border"
                        >
                            {{ name }}
                        </th>
                    </tr>
                    <tr>
                        <th :class="border"></th>
                        <th :class="border"></th>
                        <th
                            v-for="question in summary.questions"
                            :key="question.name"
                            :class="border"
                            class="h-44 align-bottom"
                            :title="question.label"
                        >
                            <span
                                class="inline-block rotate-180 text-left text-[10px] font-bold uppercase [writing-mode:vertical-rl]"
                            >
                                {{ question.number }}. {{ question.shortLabel }}
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td :class="border" class="font-semibold uppercase">
                            {{ course || '—' }}
                        </td>
                        <td :class="border" class="font-semibold uppercase">
                            {{ lecturer || '—' }}
                        </td>
                        <td
                            v-for="question in summary.questions"
                            :key="question.name"
                            :class="border"
                            class="h-12 text-center font-serif text-sm font-bold"
                            :style="colorStyle(question.classification)"
                            :title="`${question.positive} of ${question.valid} agreed (${formatNumber(question.percent, 2)}%)`"
                        >
                            {{ question.rounded ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <ul class="mt-2 flex flex-wrap gap-2 text-xs">
                <li
                    v-for="(label, key) in CLASSIFICATION_LABELS"
                    :key="key"
                    class="rounded px-2 py-1"
                    :style="colorStyle(key)"
                >
                    {{ label }} —
                    {{
                        key === 'high'
                            ? `above ${thresholds.high}`
                            : key === 'acceptable'
                              ? `${thresholds.acceptable}–${thresholds.high}`
                              : `below ${thresholds.acceptable}`
                    }}
                </li>
            </ul>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <div>
                <h3 class="mb-2 font-semibold text-[#063f22]">
                    Questions (Strongly Agree + Agree)
                </h3>
                <table class="w-full bg-white text-sm">
                    <thead>
                        <tr class="border-b-2 border-[#9fd8b8] text-left">
                            <th class="px-2 py-1">#</th>
                            <th class="px-2 py-1">Question</th>
                            <th class="px-2 py-1 text-right">Agreed</th>
                            <th class="px-2 py-1 text-right">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="question in summary.questions"
                            :key="question.name"
                            class="border-b border-[#d6e9de]"
                        >
                            <td class="px-2 py-1">{{ question.number }}</td>
                            <td class="px-2 py-1" :title="question.label">
                                {{ question.shortLabel }}
                            </td>
                            <td class="px-2 py-1 text-right tabular-nums">
                                {{ question.positive }} / {{ question.valid }}
                            </td>
                            <td class="px-2 py-1 text-right">
                                <span
                                    class="inline-block min-w-10 rounded px-1 text-center font-semibold"
                                    :style="colorStyle(question.classification)"
                                >
                                    {{ question.rounded ?? '—' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="space-y-6">
                <div>
                    <h3 class="mb-2 font-semibold text-[#063f22]">Sections</h3>
                    <table class="w-full bg-white text-sm">
                        <tbody>
                            <tr
                                v-for="section in summary.sections"
                                :key="section.section"
                                class="border-b border-[#d6e9de]"
                            >
                                <td class="px-2 py-1 font-medium">
                                    {{ section.section }}
                                </td>
                                <td class="px-2 py-1 text-[#555]">
                                    Q{{ section.questions.join(', Q') }}
                                </td>
                                <td class="px-2 py-1 text-right tabular-nums">
                                    {{ section.positive }} / {{ section.valid }}
                                </td>
                                <td class="px-2 py-1 text-right">
                                    <span
                                        class="inline-block min-w-10 rounded px-1 text-center font-semibold"
                                        :style="
                                            colorStyle(section.classification)
                                        "
                                    >
                                        {{ section.rounded ?? '—' }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="summary.harassmentReports !== null"
                    class="rounded-md border border-[#e8b4b4] bg-[#fff6f6] p-3 text-sm"
                >
                    <strong>Confidential:</strong>
                    {{ summary.harassmentReports }} response(s) reported sexual
                    harassment. This is never printed on the report.
                </div>
            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <div>
                <h3 class="mb-2 font-semibold text-[#063f22]">
                    Page 2 — Comments &amp; suggestions ({{
                        summary.comments.length
                    }})
                </h3>
                <ol class="list-decimal space-y-1 pl-6 text-sm">
                    <li
                        v-for="(comment, index) in summary.comments"
                        :key="index"
                    >
                        {{ comment }}
                    </li>
                </ol>
                <p v-if="!summary.comments.length" class="text-sm text-[#555]">
                    No comments were made.
                </p>
            </div>
            <div v-if="summary.grades">
                <h3 class="mb-2 font-semibold text-[#063f22]">
                    Overall grade given by students
                </h3>
                <div
                    class="flex h-48 items-end gap-2 border-b border-[#555] pb-0"
                >
                    <div
                        v-for="band in summary.grades.bands"
                        :key="band.code"
                        class="flex flex-1 flex-col items-center justify-end"
                    >
                        <span class="text-xs font-semibold">{{
                            band.count
                        }}</span>
                        <div
                            class="w-full bg-[#0F6E56]"
                            :style="{
                                height: `${(band.count / maxBand) * 150}px`,
                            }"
                        />
                    </div>
                </div>
                <div class="flex gap-2 pt-1">
                    <span
                        v-for="band in summary.grades.bands"
                        :key="band.code"
                        class="flex-1 text-center text-[10px] leading-tight"
                    >
                        {{ band.label }}
                    </span>
                </div>
                <p class="mt-3 text-xs text-[#555]">
                    Responses {{ summary.grades.n }} · Mean
                    {{ formatNumber(summary.grades.mean) }} · Median
                    {{ formatNumber(summary.grades.median) }} · Lowest
                    {{ formatNumber(summary.grades.minimum) }} · Highest
                    {{ formatNumber(summary.grades.maximum) }} · Std. deviation
                    {{ formatNumber(summary.grades.stddev) }}
                </p>
            </div>
        </section>
    </div>
</template>
