<script setup lang="ts">
import { computed } from 'vue';
import type { FrequencyResult } from '@/types/evaluation';
import { formatNumber } from '@/types/evaluation';

const props = defineProps<{
    result: FrequencyResult;
    chart: 'bar' | 'pie' | 'histogram';
    values: 'frequencies' | 'percentages';
}>();

const PALETTE = [
    '#0F6E56',
    '#3A8FB7',
    '#E0A100',
    '#B5483A',
    '#7A5BA6',
    '#4E9A3E',
    '#C26AA0',
    '#5F6B73',
    '#2C4F8C',
    '#A3772F',
];

const rows = computed(() => props.result.table?.rows ?? []);
const amount = (row: { frequency: number; percent: number }) =>
    props.values === 'percentages' ? row.percent : row.frequency;
const axisLabel = computed(() =>
    props.values === 'percentages' ? 'Percent' : 'Frequency',
);

// Bar chart
const width = 640;
const height = 300;
const margin = { top: 24, right: 16, bottom: 70, left: 48 };
const plotWidth = width - margin.left - margin.right;
const plotHeight = height - margin.top - margin.bottom;

const niceMax = (value: number) => {
    if (value <= 0) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(value));
    const step =
        [1, 2, 2.5, 5, 10].find((item) => item * magnitude >= value / 5) ?? 10;

    return Math.ceil(value / (step * magnitude)) * step * magnitude;
};

const bars = computed(() => {
    const max = niceMax(Math.max(...rows.value.map(amount), 0));
    const slot = plotWidth / Math.max(rows.value.length, 1);

    return {
        max,
        ticks: [0, 0.25, 0.5, 0.75, 1].map((share) => share * max),
        items: rows.value.map((row, index) => {
            const barHeight = (amount(row) / max) * plotHeight;

            return {
                key: String(row.value),
                label: row.label,
                value: amount(row),
                x: margin.left + index * slot + slot * 0.15,
                width: slot * 0.7,
                y: margin.top + plotHeight - barHeight,
                height: barHeight,
            };
        }),
    };
});

// Histogram of a scale variable, built from its frequency table.
const histogram = computed(() => {
    const points = rows.value
        .map((row) => ({ value: Number(row.value), count: row.frequency }))
        .filter((point) => Number.isFinite(point.value));

    if (!points.length) {
        return null;
    }

    const min = Math.min(...points.map((point) => point.value));
    const max = Math.max(...points.map((point) => point.value));
    const binCount = Math.min(
        12,
        Math.max(1, Math.ceil(Math.sqrt(props.result.valid))),
    );
    const binWidth = max === min ? 1 : (max - min) / binCount;
    const counts = Array.from({ length: binCount }, () => 0);

    for (const point of points) {
        const index = Math.min(
            binCount - 1,
            Math.floor((point.value - min) / binWidth),
        );
        counts[index] += point.count;
    }

    const top = niceMax(Math.max(...counts));
    const slot = plotWidth / binCount;

    return {
        top,
        ticks: [0, 0.25, 0.5, 0.75, 1].map((share) => share * top),
        bins: counts.map((count, index) => ({
            key: index,
            from: min + index * binWidth,
            count,
            x: margin.left + index * slot,
            width: slot,
            y: margin.top + plotHeight - (count / top) * plotHeight,
            height: (count / top) * plotHeight,
        })),
    };
});

// Pie chart
const slices = computed(() => {
    const total = rows.value.reduce((sum, row) => sum + row.frequency, 0) || 1;
    let angle = -Math.PI / 2;

    return rows.value.map((row, index) => {
        const share = row.frequency / total;
        const start = angle;
        angle += share * Math.PI * 2;
        const end = angle;
        const radius = 110;
        const cx = 150;
        const cy = 140;
        const large = end - start > Math.PI ? 1 : 0;
        const path =
            share >= 1
                ? `M ${cx} ${cy - radius} A ${radius} ${radius} 0 1 1 ${cx - 0.01} ${cy - radius} Z`
                : `M ${cx} ${cy} L ${cx + radius * Math.cos(start)} ${cy + radius * Math.sin(start)} A ${radius} ${radius} 0 ${large} 1 ${cx + radius * Math.cos(end)} ${cy + radius * Math.sin(end)} Z`;

        return {
            key: String(row.value),
            label: row.label,
            path,
            color: PALETTE[index % PALETTE.length],
            share,
        };
    });
});

const meanText = computed(() => {
    const stats = props.result.statistics;

    return `N = ${props.result.valid}${typeof stats.mean === 'number' ? `   Mean = ${formatNumber(stats.mean)}` : ''}${typeof stats.stddev === 'number' ? `   Std. Dev. = ${formatNumber(stats.stddev)}` : ''}`;
});
</script>

<template>
    <figure class="rounded-md border bg-white p-3">
        <figcaption class="mb-1 text-center text-sm font-semibold">
            {{ result.label }}
        </figcaption>

        <svg
            v-if="chart === 'bar'"
            :viewBox="`0 0 ${width} ${height}`"
            class="w-full max-w-2xl"
            role="img"
            :aria-label="`Bar chart of ${result.name}`"
        >
            <g v-for="tick in bars.ticks" :key="tick">
                <line
                    :x1="margin.left"
                    :x2="width - margin.right"
                    :y1="
                        margin.top + plotHeight - (tick / bars.max) * plotHeight
                    "
                    :y2="
                        margin.top + plotHeight - (tick / bars.max) * plotHeight
                    "
                    stroke="#e5e7eb"
                />
                <text
                    :x="margin.left - 6"
                    :y="
                        margin.top +
                        plotHeight -
                        (tick / bars.max) * plotHeight +
                        4
                    "
                    text-anchor="end"
                    font-size="11"
                    fill="#555"
                >
                    {{ formatNumber(tick, tick % 1 ? 1 : 0) }}
                </text>
            </g>
            <g v-for="bar in bars.items" :key="bar.key">
                <rect
                    :x="bar.x"
                    :y="bar.y"
                    :width="bar.width"
                    :height="bar.height"
                    fill="#0F6E56"
                >
                    <title>
                        {{ bar.label }}:
                        {{
                            formatNumber(
                                bar.value,
                                values === 'percentages' ? 1 : 0,
                            )
                        }}
                    </title>
                </rect>
                <text
                    :x="bar.x + bar.width / 2"
                    :y="bar.y - 4"
                    text-anchor="middle"
                    font-size="11"
                    font-weight="600"
                >
                    {{
                        formatNumber(
                            bar.value,
                            values === 'percentages' ? 1 : 0,
                        )
                    }}
                </text>
                <text
                    :x="bar.x + bar.width / 2"
                    :y="margin.top + plotHeight + 14"
                    text-anchor="end"
                    font-size="11"
                    :transform="`rotate(-30 ${bar.x + bar.width / 2} ${margin.top + plotHeight + 14})`"
                >
                    {{ bar.label }}
                </text>
            </g>
            <line
                :x1="margin.left"
                :x2="width - margin.right"
                :y1="margin.top + plotHeight"
                :y2="margin.top + plotHeight"
                stroke="#555"
            />
            <text
                :x="14"
                :y="margin.top + plotHeight / 2"
                font-size="11"
                text-anchor="middle"
                :transform="`rotate(-90 14 ${margin.top + plotHeight / 2})`"
            >
                {{ axisLabel }}
            </text>
        </svg>

        <svg
            v-else-if="chart === 'histogram' && histogram"
            :viewBox="`0 0 ${width} ${height}`"
            class="w-full max-w-2xl"
            role="img"
            :aria-label="`Histogram of ${result.name}`"
        >
            <g v-for="tick in histogram.ticks" :key="tick">
                <line
                    :x1="margin.left"
                    :x2="width - margin.right"
                    :y1="
                        margin.top +
                        plotHeight -
                        (tick / histogram.top) * plotHeight
                    "
                    :y2="
                        margin.top +
                        plotHeight -
                        (tick / histogram.top) * plotHeight
                    "
                    stroke="#e5e7eb"
                />
                <text
                    :x="margin.left - 6"
                    :y="
                        margin.top +
                        plotHeight -
                        (tick / histogram.top) * plotHeight +
                        4
                    "
                    text-anchor="end"
                    font-size="11"
                    fill="#555"
                >
                    {{ formatNumber(tick, tick % 1 ? 1 : 0) }}
                </text>
            </g>
            <g v-for="bin in histogram.bins" :key="bin.key">
                <rect
                    :x="bin.x"
                    :y="bin.y"
                    :width="bin.width"
                    :height="bin.height"
                    fill="#3A8FB7"
                    stroke="#fff"
                >
                    <title>
                        {{ formatNumber(bin.from, 1) }}+: {{ bin.count }}
                    </title>
                </rect>
                <text
                    :x="bin.x"
                    :y="margin.top + plotHeight + 14"
                    text-anchor="middle"
                    font-size="10"
                >
                    {{ formatNumber(bin.from, 0) }}
                </text>
            </g>
            <line
                :x1="margin.left"
                :x2="width - margin.right"
                :y1="margin.top + plotHeight"
                :y2="margin.top + plotHeight"
                stroke="#555"
            />
            <text
                :x="width / 2"
                :y="height - 20"
                text-anchor="middle"
                font-size="11"
            >
                {{ result.name }}
            </text>
            <text
                :x="width - margin.right"
                :y="14"
                text-anchor="end"
                font-size="11"
            >
                {{ meanText }}
            </text>
        </svg>

        <div
            v-else-if="chart === 'pie'"
            class="flex flex-wrap items-center gap-4"
        >
            <svg
                viewBox="0 0 300 280"
                class="w-64"
                role="img"
                :aria-label="`Pie chart of ${result.name}`"
            >
                <path
                    v-for="slice in slices"
                    :key="slice.key"
                    :d="slice.path"
                    :fill="slice.color"
                    stroke="#fff"
                >
                    <title>
                        {{ slice.label }}:
                        {{ formatNumber(slice.share * 100, 1) }}%
                    </title>
                </path>
            </svg>
            <ul class="grid gap-1 text-sm">
                <li
                    v-for="slice in slices"
                    :key="slice.key"
                    class="flex items-center gap-2"
                >
                    <span
                        class="inline-block size-3 rounded-sm"
                        :style="{ background: slice.color }"
                    />
                    {{ slice.label }} —
                    {{ formatNumber(slice.share * 100, 1) }}%
                </li>
            </ul>
        </div>
    </figure>
</template>
