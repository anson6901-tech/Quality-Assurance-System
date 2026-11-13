<script setup lang="ts">
import {
    ArrowLeft,
    ArrowRight,
    CaseSensitive,
    Hash,
    Ruler,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { FrequencyOptions, Variable } from '@/types/evaluation';

const props = defineProps<{
    open: boolean;
    variables: Variable[];
    options: FrequencyOptions;
    processing: boolean;
    error?: string;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    run: [options: FrequencyOptions];
}>();

type View = 'main' | 'statistics' | 'charts' | 'format';

// Plain copy: structuredClone cannot copy Vue's reactive proxies.
const clone = (options: FrequencyOptions): FrequencyOptions =>
    JSON.parse(JSON.stringify(options));

const draft = ref<FrequencyOptions>(clone(props.options));
const view = ref<View>('main');
const snapshot = ref<FrequencyOptions | null>(null);
const highlightedSource = ref<string[]>([]);
const highlightedTarget = ref<string[]>([]);
const percentileInput = ref('');

watch(
    () => props.open,
    (open) => {
        if (open) {
            draft.value = clone(props.options);
            view.value = 'main';
            highlightedSource.value = [];
            highlightedTarget.value = [];
        }
    },
);

const byName = computed(
    () => new Map(props.variables.map((variable) => [variable.name, variable])),
);
const available = computed(() =>
    props.variables.filter(
        (variable) => !draft.value.variables.includes(variable.name),
    ),
);
const chosen = computed(() =>
    draft.value.variables
        .map((name) => byName.value.get(name))
        .filter((variable): variable is Variable => Boolean(variable)),
);

const toggle = (
    which: 'source' | 'target',
    name: string,
    event: MouseEvent,
) => {
    const list = which === 'source' ? highlightedSource : highlightedTarget;

    if (event.ctrlKey || event.metaKey || event.shiftKey) {
        list.value = list.value.includes(name)
            ? list.value.filter((item) => item !== name)
            : [...list.value, name];
    } else {
        list.value = [name];
    }
};

const moveIn = (names = highlightedSource.value) => {
    draft.value.variables = [
        ...draft.value.variables,
        ...available.value
            .map((variable) => variable.name)
            .filter((name) => names.includes(name)),
    ];
    highlightedSource.value = [];
};
const moveOut = (names = highlightedTarget.value) => {
    draft.value.variables = draft.value.variables.filter(
        (name) => !names.includes(name),
    );
    highlightedTarget.value = [];
};

const openSub = (next: View) => {
    snapshot.value = clone(draft.value);
    view.value = next;
};
const continueSub = () => {
    snapshot.value = null;
    view.value = 'main';
};
const cancelSub = () => {
    if (snapshot.value) {
        draft.value = snapshot.value;
    }

    continueSub();
};

const reset = () => {
    draft.value = {
        variables: [],
        displayTables: true,
        statistics: [],
        percentiles: [],
        chart: 'none',
        chartValues: 'frequencies',
        order: 'ascending_values',
        multiple: 'compare',
        suppressAbove: null,
    };
};

const toggleStatistic = (name: string, checked: boolean) => {
    draft.value.statistics = checked
        ? [...new Set([...draft.value.statistics, name])]
        : draft.value.statistics.filter((item) => item !== name);
};
const addPercentile = () => {
    const value = Number(percentileInput.value);

    if (value > 0 && value < 100 && !draft.value.percentiles.includes(value)) {
        draft.value.percentiles = [...draft.value.percentiles, value].sort(
            (a, b) => a - b,
        );
    }

    percentileInput.value = '';
};

const icon = (variable: Variable) =>
    variable.type === 'string'
        ? CaseSensitive
        : variable.measure === 'scale'
          ? Ruler
          : Hash;

const statisticGroups = [
    {
        title: 'Central Tendency',
        items: [
            ['mean', 'Mean'],
            ['median', 'Median'],
            ['mode', 'Mode'],
            ['sum', 'Sum'],
        ],
    },
    {
        title: 'Dispersion',
        items: [
            ['stddev', 'Std. deviation'],
            ['variance', 'Variance'],
            ['range', 'Range'],
            ['minimum', 'Minimum'],
            ['maximum', 'Maximum'],
            ['semean', 'S.E. mean'],
        ],
    },
    {
        title: 'Distribution',
        items: [
            ['skewness', 'Skewness'],
            ['kurtosis', 'Kurtosis'],
        ],
    },
];

const title = computed(
    () =>
        ({
            main: 'Frequencies',
            statistics: 'Frequencies: Statistics',
            charts: 'Frequencies: Charts',
            format: 'Frequencies: Format',
        })[view.value],
);

const panel = 'rounded-md border border-[#9fd8b8] p-3';
const legend =
    'mb-2 text-xs font-semibold tracking-wide text-[#063f22] uppercase';
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="view === 'main'">
                    Select variables on the left and move them into Variable(s)
                    with the arrow. Ctrl/⌘-click selects several.
                </DialogDescription>
            </DialogHeader>

            <!-- Main dialog -->
            <div v-if="view === 'main'" class="space-y-4">
                <div
                    class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)_auto] items-center gap-3"
                >
                    <ul
                        class="h-72 overflow-y-auto rounded-md border bg-white p-1 text-sm"
                        aria-label="Variables"
                    >
                        <li
                            v-for="variable in available"
                            :key="variable.name"
                            class="flex cursor-default items-center gap-2 rounded px-2 py-1 select-none"
                            :class="
                                highlightedSource.includes(variable.name)
                                    ? 'bg-[#007a2f] text-white'
                                    : 'hover:bg-[#eef8f2]'
                            "
                            :title="variable.label"
                            @click="toggle('source', variable.name, $event)"
                            @dblclick="moveIn([variable.name])"
                        >
                            <component
                                :is="icon(variable)"
                                class="size-4 shrink-0"
                            />
                            <span class="truncate"
                                ><strong>{{ variable.name }}</strong>
                                {{ variable.label }}</span
                            >
                        </li>
                    </ul>
                    <div class="flex flex-col gap-2">
                        <Button
                            type="button"
                            size="icon"
                            variant="outline"
                            aria-label="Move into Variable(s)"
                            :disabled="!highlightedSource.length"
                            @click="moveIn()"
                        >
                            <ArrowRight class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="outline"
                            aria-label="Move back"
                            :disabled="!highlightedTarget.length"
                            @click="moveOut()"
                        >
                            <ArrowLeft class="size-4" />
                        </Button>
                    </div>
                    <div class="grid gap-1">
                        <span class="text-sm font-medium">Variable(s):</span>
                        <ul
                            class="h-64 overflow-y-auto rounded-md border bg-white p-1 text-sm"
                            aria-label="Variable(s)"
                        >
                            <li
                                v-for="variable in chosen"
                                :key="variable.name"
                                class="flex cursor-default items-center gap-2 rounded px-2 py-1 select-none"
                                :class="
                                    highlightedTarget.includes(variable.name)
                                        ? 'bg-[#007a2f] text-white'
                                        : 'hover:bg-[#eef8f2]'
                                "
                                :title="variable.label"
                                @click="toggle('target', variable.name, $event)"
                                @dblclick="moveOut([variable.name])"
                            >
                                <component
                                    :is="icon(variable)"
                                    class="size-4 shrink-0"
                                />
                                <span class="truncate"
                                    ><strong>{{ variable.name }}</strong>
                                    {{ variable.label }}</span
                                >
                            </li>
                        </ul>
                    </div>
                    <div class="flex flex-col gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="openSub('statistics')"
                        >
                            Statistics…
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            @click="openSub('charts')"
                        >
                            Charts…
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            @click="openSub('format')"
                        >
                            Format…
                        </Button>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="draft.displayTables"
                        type="checkbox"
                        class="size-4"
                    />
                    Display frequency tables
                </label>

                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <div class="flex flex-wrap justify-center gap-2 border-t pt-4">
                    <Button
                        type="button"
                        class="min-w-24 bg-[#007a2f] hover:bg-[#006b30]"
                        :disabled="processing || !draft.variables.length"
                        @click="emit('run', draft)"
                    >
                        OK
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        class="min-w-24"
                        @click="reset"
                    >
                        Reset
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        class="min-w-24"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                </div>
            </div>

            <!-- Statistics… -->
            <div v-else-if="view === 'statistics'" class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <fieldset :class="panel">
                        <legend :class="legend">Percentile Values</legend>
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                class="size-4"
                                :checked="
                                    draft.statistics.includes('quartiles')
                                "
                                @change="
                                    toggleStatistic(
                                        'quartiles',
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            />
                            Quartiles
                        </label>
                        <div class="mt-2 flex gap-2">
                            <input
                                v-model="percentileInput"
                                type="number"
                                min="1"
                                max="99"
                                placeholder="Percentile(s)"
                                class="h-9 w-32 rounded-md border px-2 text-sm"
                                @keydown.enter.prevent="addPercentile"
                            />
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="addPercentile"
                            >
                                Add
                            </Button>
                        </div>
                        <ul class="mt-2 flex flex-wrap gap-1 text-xs">
                            <li
                                v-for="percentile in draft.percentiles"
                                :key="percentile"
                                class="flex items-center gap-1 rounded bg-[#eef8f2] px-2 py-0.5"
                            >
                                {{ percentile }}
                                <button
                                    type="button"
                                    class="text-[#8a1c1c]"
                                    :aria-label="`Remove percentile ${percentile}`"
                                    @click="
                                        draft.percentiles =
                                            draft.percentiles.filter(
                                                (item) => item !== percentile,
                                            )
                                    "
                                >
                                    ×
                                </button>
                            </li>
                        </ul>
                    </fieldset>
                    <fieldset
                        v-for="group in statisticGroups"
                        :key="group.title"
                        :class="panel"
                    >
                        <legend :class="legend">{{ group.title }}</legend>
                        <div class="grid grid-cols-2 gap-1">
                            <label
                                v-for="[key, label] in group.items"
                                :key="key"
                                class="flex items-center gap-2 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    class="size-4"
                                    :checked="draft.statistics.includes(key)"
                                    @change="
                                        toggleStatistic(
                                            key,
                                            ($event.target as HTMLInputElement)
                                                .checked,
                                        )
                                    "
                                />
                                {{ label }}
                            </label>
                        </div>
                    </fieldset>
                </div>
                <div class="flex justify-center gap-2 border-t pt-4">
                    <Button
                        type="button"
                        class="min-w-24 bg-[#007a2f] hover:bg-[#006b30]"
                        @click="continueSub"
                    >
                        Continue
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        class="min-w-24"
                        @click="cancelSub"
                    >
                        Cancel
                    </Button>
                </div>
            </div>

            <!-- Charts… -->
            <div v-else-if="view === 'charts'" class="space-y-4">
                <fieldset :class="panel">
                    <legend :class="legend">Chart Type</legend>
                    <div class="grid gap-1 text-sm">
                        <label
                            v-for="[value, label] in [
                                ['none', 'None'],
                                ['bar', 'Bar charts'],
                                ['pie', 'Pie charts'],
                                ['histogram', 'Histograms'],
                            ]"
                            :key="value"
                            class="flex items-center gap-2"
                        >
                            <input
                                v-model="draft.chart"
                                type="radio"
                                :value="value"
                                class="size-4"
                            />
                            {{ label }}
                        </label>
                    </div>
                </fieldset>
                <fieldset
                    :class="panel"
                    :disabled="
                        draft.chart === 'none' || draft.chart === 'histogram'
                    "
                >
                    <legend :class="legend">Chart Values</legend>
                    <div class="flex gap-6 text-sm">
                        <label class="flex items-center gap-2">
                            <input
                                v-model="draft.chartValues"
                                type="radio"
                                value="frequencies"
                                class="size-4"
                            />
                            Frequencies
                        </label>
                        <label class="flex items-center gap-2">
                            <input
                                v-model="draft.chartValues"
                                type="radio"
                                value="percentages"
                                class="size-4"
                            />
                            Percentages
                        </label>
                    </div>
                </fieldset>
                <div class="flex justify-center gap-2 border-t pt-4">
                    <Button
                        type="button"
                        class="min-w-24 bg-[#007a2f] hover:bg-[#006b30]"
                        @click="continueSub"
                    >
                        Continue
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        class="min-w-24"
                        @click="cancelSub"
                    >
                        Cancel
                    </Button>
                </div>
            </div>

            <!-- Format… -->
            <div v-else class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <fieldset :class="panel">
                        <legend :class="legend">Order by</legend>
                        <div class="grid gap-1 text-sm">
                            <label
                                v-for="[value, label] in [
                                    ['ascending_values', 'Ascending values'],
                                    ['descending_values', 'Descending values'],
                                    ['ascending_counts', 'Ascending counts'],
                                    ['descending_counts', 'Descending counts'],
                                ]"
                                :key="value"
                                class="flex items-center gap-2"
                            >
                                <input
                                    v-model="draft.order"
                                    type="radio"
                                    :value="value"
                                    class="size-4"
                                />
                                {{ label }}
                            </label>
                        </div>
                    </fieldset>
                    <fieldset :class="panel">
                        <legend :class="legend">Multiple Variables</legend>
                        <div class="grid gap-1 text-sm">
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="draft.multiple"
                                    type="radio"
                                    value="compare"
                                    class="size-4"
                                />
                                Compare variables
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    v-model="draft.multiple"
                                    type="radio"
                                    value="organize"
                                    class="size-4"
                                />
                                Organize output by variables
                            </label>
                        </div>
                    </fieldset>
                </div>
                <label class="flex flex-wrap items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        class="size-4"
                        :checked="draft.suppressAbove !== null"
                        @change="
                            draft.suppressAbove = (
                                $event.target as HTMLInputElement
                            ).checked
                                ? 10
                                : null
                        "
                    />
                    Suppress tables with many categories — Maximum number of
                    categories:
                    <input
                        v-model.number="draft.suppressAbove"
                        type="number"
                        min="1"
                        class="h-8 w-20 rounded-md border px-2"
                        :disabled="draft.suppressAbove === null"
                    />
                </label>
                <div class="flex justify-center gap-2 border-t pt-4">
                    <Button
                        type="button"
                        class="min-w-24 bg-[#007a2f] hover:bg-[#006b30]"
                        @click="continueSub"
                    >
                        Continue
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        class="min-w-24"
                        @click="cancelSub"
                    >
                        Cancel
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
