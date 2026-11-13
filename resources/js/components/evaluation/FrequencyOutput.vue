<script setup lang="ts">
import { computed } from 'vue';
import FrequencyChart from '@/components/evaluation/FrequencyChart.vue';
import type {
    FrequencyOptions,
    FrequencyOutput,
    FrequencyResult,
} from '@/types/evaluation';
import { formatNumber } from '@/types/evaluation';

const props = defineProps<{
    options: FrequencyOptions;
    output: FrequencyOutput;
}>();

const STAT_LABELS: Record<string, string> = {
    mean: 'Mean',
    semean: 'Std. Error of Mean',
    median: 'Median',
    mode: 'Mode',
    stddev: 'Std. Deviation',
    variance: 'Variance',
    skewness: 'Skewness',
    kurtosis: 'Kurtosis',
    range: 'Range',
    minimum: 'Minimum',
    maximum: 'Maximum',
    sum: 'Sum',
};

const statisticRows = computed(() =>
    Object.keys(STAT_LABELS).filter((key) =>
        props.options.statistics.includes(key),
    ),
);
const percentileKeys = computed(() => {
    const keys = new Set<string>();

    for (const result of props.output.variables) {
        const percentiles = result.statistics.percentiles as
            | Record<string, number | null>
            | undefined;
        Object.keys(percentiles ?? {}).forEach((key) => keys.add(key));
    }

    return [...keys].sort((a, b) => Number(a) - Number(b));
});

const stat = (result: FrequencyResult, key: string) => {
    const value = result.statistics[key];

    if (typeof value !== 'number') {
        return '';
    }

    return (
        formatNumber(value, 2) +
        (key === 'mode' && result.statistics.multipleModes ? 'ᵃ' : '')
    );
};
const percentile = (result: FrequencyResult, key: string) =>
    formatNumber(
        (
            result.statistics.percentiles as
                | Record<string, number | null>
                | undefined
        )?.[key] ?? null,
        2,
    );
const hasMultipleModes = computed(() =>
    props.output.variables.some((result) => result.statistics.multipleModes),
);

const chart = computed(() =>
    props.options.chart === 'none' ? null : props.options.chart,
);
// Histograms only make sense for numbers.
const chartFor = (result: FrequencyResult) =>
    chart.value === 'histogram' &&
    !result.table?.rows.every((row) => typeof row.value === 'number')
        ? null
        : chart.value;

const cell = 'border-b border-[#d6e9de] px-3 py-1 text-right tabular-nums';
const head = 'border-b-2 border-[#9fd8b8] px-3 py-1 font-semibold';
</script>

<template>
    <div class="space-y-6">
        <h2 class="text-xl font-semibold text-[#063f22]">Frequencies</h2>

        <!-- Statistics, all variables side by side ("Compare variables") -->
        <section v-if="options.multiple === 'compare'" class="overflow-x-auto">
            <table class="min-w-max bg-white text-sm">
                <caption class="pb-2 text-left font-semibold">
                    Statistics
                </caption>
                <thead>
                    <tr>
                        <th :class="head" colspan="2"></th>
                        <th
                            v-for="result in output.variables"
                            :key="result.name"
                            :class="head"
                            class="max-w-48 text-center"
                            :title="result.label"
                        >
                            {{ result.name }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th
                            rowspan="2"
                            class="border-b border-[#d6e9de] px-3 text-left align-top font-medium"
                        >
                            N
                        </th>
                        <th
                            class="border-b border-[#d6e9de] px-3 text-left font-medium"
                        >
                            Valid
                        </th>
                        <td
                            v-for="result in output.variables"
                            :key="result.name"
                            :class="cell"
                        >
                            {{ result.valid }}
                        </td>
                    </tr>
                    <tr>
                        <th
                            class="border-b border-[#d6e9de] px-3 text-left font-medium"
                        >
                            Missing
                        </th>
                        <td
                            v-for="result in output.variables"
                            :key="result.name"
                            :class="cell"
                        >
                            {{ result.missing }}
                        </td>
                    </tr>
                    <tr v-for="key in statisticRows" :key="key">
                        <th
                            colspan="2"
                            class="border-b border-[#d6e9de] px-3 text-left font-medium"
                        >
                            {{ STAT_LABELS[key] }}
                        </th>
                        <td
                            v-for="result in output.variables"
                            :key="result.name"
                            :class="cell"
                        >
                            {{ stat(result, key) }}
                        </td>
                    </tr>
                    <tr v-for="(key, index) in percentileKeys" :key="`p${key}`">
                        <th
                            v-if="index === 0"
                            :rowspan="percentileKeys.length"
                            class="border-b border-[#d6e9de] px-3 text-left align-top font-medium"
                        >
                            Percentiles
                        </th>
                        <th
                            class="border-b border-[#d6e9de] px-3 text-left font-medium"
                        >
                            {{ key }}
                        </th>
                        <td
                            v-for="result in output.variables"
                            :key="result.name"
                            :class="cell"
                        >
                            {{ percentile(result, key) }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-if="hasMultipleModes" class="mt-1 text-xs text-[#555]">
                a. Multiple modes exist. The smallest value is shown.
            </p>
        </section>

        <section
            v-for="result in output.variables"
            :key="result.name"
            class="space-y-3"
        >
            <!-- Per-variable statistics ("Organize output by variables") -->
            <table
                v-if="options.multiple === 'organize'"
                class="bg-white text-sm"
            >
                <caption class="pb-2 text-left font-semibold">
                    Statistics —
                    {{
                        result.name
                    }}
                </caption>
                <tbody>
                    <tr>
                        <th
                            rowspan="2"
                            class="border-b border-[#d6e9de] px-3 text-left align-top font-medium"
                        >
                            N
                        </th>
                        <th
                            class="border-b border-[#d6e9de] px-3 text-left font-medium"
                        >
                            Valid
                        </th>
                        <td :class="cell">{{ result.valid }}</td>
                    </tr>
                    <tr>
                        <th
                            class="border-b border-[#d6e9de] px-3 text-left font-medium"
                        >
                            Missing
                        </th>
                        <td :class="cell">{{ result.missing }}</td>
                    </tr>
                    <tr v-for="key in statisticRows" :key="key">
                        <th
                            colspan="2"
                            class="border-b border-[#d6e9de] px-3 text-left font-medium"
                        >
                            {{ STAT_LABELS[key] }}
                        </th>
                        <td :class="cell">{{ stat(result, key) }}</td>
                    </tr>
                    <tr v-for="key in percentileKeys" :key="`p${key}`">
                        <th
                            colspan="2"
                            class="border-b border-[#d6e9de] px-3 text-left font-medium"
                        >
                            Percentile {{ key }}
                        </th>
                        <td :class="cell">{{ percentile(result, key) }}</td>
                    </tr>
                </tbody>
            </table>

            <div
                v-if="
                    options.displayTables && result.table && !result.suppressed
                "
                class="overflow-x-auto"
            >
                <table class="min-w-max bg-white text-sm">
                    <caption class="pb-2 text-left font-semibold">
                        {{
                            result.name
                        }}
                        <span class="font-normal text-[#555]"
                            >— {{ result.label }}</span
                        >
                    </caption>
                    <thead>
                        <tr>
                            <th :class="head" colspan="2"></th>
                            <th :class="head" class="text-right">Frequency</th>
                            <th :class="head" class="text-right">Percent</th>
                            <th :class="head" class="text-right">
                                Valid Percent
                            </th>
                            <th :class="head" class="text-right">
                                Cumulative Percent
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, index) in result.table.rows"
                            :key="String(row.value)"
                        >
                            <th
                                v-if="index === 0"
                                :rowspan="result.table.rows.length + 1"
                                class="border-b border-[#d6e9de] px-3 text-left align-top font-medium"
                            >
                                Valid
                            </th>
                            <th
                                class="border-b border-[#d6e9de] px-3 text-left font-normal"
                            >
                                {{ row.label }}
                            </th>
                            <td :class="cell">{{ row.frequency }}</td>
                            <td :class="cell">
                                {{ formatNumber(row.percent, 1) }}
                            </td>
                            <td :class="cell">
                                {{ formatNumber(row.validPercent, 1) }}
                            </td>
                            <td :class="cell">
                                {{ formatNumber(row.cumulativePercent, 1) }}
                            </td>
                        </tr>
                        <tr>
                            <th
                                v-if="!result.table.rows.length"
                                class="border-b border-[#d6e9de] px-3 text-left font-medium"
                            >
                                Valid
                            </th>
                            <th
                                class="border-b border-[#d6e9de] px-3 text-left font-semibold"
                            >
                                Total
                            </th>
                            <td :class="cell">{{ result.table.validTotal }}</td>
                            <td :class="cell">
                                {{
                                    formatNumber(
                                        result.table.validTotalPercent,
                                        1,
                                    )
                                }}
                            </td>
                            <td :class="cell">
                                {{ result.table.validTotal ? '100.0' : '' }}
                            </td>
                            <td :class="cell"></td>
                        </tr>
                        <template v-if="result.table.missing">
                            <tr>
                                <th
                                    class="border-b border-[#d6e9de] px-3 text-left font-medium"
                                >
                                    Missing
                                </th>
                                <th
                                    class="border-b border-[#d6e9de] px-3 text-left font-normal"
                                >
                                    System
                                </th>
                                <td :class="cell">
                                    {{ result.table.missing }}
                                </td>
                                <td :class="cell">
                                    {{
                                        formatNumber(
                                            result.table.missingPercent,
                                            1,
                                        )
                                    }}
                                </td>
                                <td :class="cell" colspan="2"></td>
                            </tr>
                            <tr>
                                <th
                                    colspan="2"
                                    class="border-b border-[#d6e9de] px-3 text-left font-semibold"
                                >
                                    Total
                                </th>
                                <td :class="cell">{{ result.table.total }}</td>
                                <td :class="cell">100.0</td>
                                <td :class="cell" colspan="2"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p
                v-else-if="options.displayTables && result.suppressed"
                class="text-sm text-[#555]"
            >
                The frequency table for {{ result.name }} is suppressed: it has
                more than {{ options.suppressAbove }} categories.
            </p>

            <FrequencyChart
                v-if="chartFor(result) && result.table"
                :result="result"
                :chart="chartFor(result)!"
                :values="options.chartValues"
            />
        </section>
    </div>
</template>
