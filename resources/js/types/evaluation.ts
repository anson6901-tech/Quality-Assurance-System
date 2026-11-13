export type Variable = {
    name: string;
    label: string;
    type: 'numeric' | 'string';
    measure: 'scale' | 'ordinal' | 'nominal';
    role: 'likert' | 'yes_no' | 'grade' | 'grade_band' | 'text' | 'timestamp';
    section?: string;
    shortLabel?: string;
    valueLabels: Record<string, string> | [];
    sensitive: boolean;
};

export type DataRow = Record<string, number | string | null>;

export type FrequencyOptions = {
    variables: string[];
    displayTables: boolean;
    statistics: string[];
    percentiles: number[];
    chart: 'none' | 'bar' | 'pie' | 'histogram';
    chartValues: 'frequencies' | 'percentages';
    order:
        | 'ascending_values'
        | 'descending_values'
        | 'ascending_counts'
        | 'descending_counts';
    multiple: 'compare' | 'organize';
    suppressAbove: number | null;
};

export type FrequencyRow = {
    value: number | string;
    label: string;
    frequency: number;
    percent: number;
    validPercent: number;
    cumulativePercent: number;
};

export type FrequencyResult = {
    name: string;
    label: string;
    valid: number;
    missing: number;
    statistics: Record<
        string,
        number | null | boolean | Record<string, number | null>
    >;
    table: {
        rows: FrequencyRow[];
        validTotal: number;
        validTotalPercent: number;
        missing: number;
        missingPercent: number;
        total: number;
    } | null;
    suppressed: boolean;
};

export type FrequencyOutput = { total: number; variables: FrequencyResult[] };

export type Classification = 'high' | 'acceptable' | 'improvement' | null;

export type SetQuestion = {
    number: number;
    name: string;
    label: string;
    shortLabel: string;
    section: string;
    counts: Record<string, number>;
    valid: number;
    positive: number;
    percent: number | null;
    rounded: number | null;
    classification: Classification;
};

export type SetSummary = {
    responses: number;
    questions: SetQuestion[];
    sections: {
        section: string;
        questions: number[];
        valid: number;
        positive: number;
        percent: number | null;
        rounded: number | null;
        classification: Classification;
    }[];
    grades: {
        bands: {
            code: number;
            label: string;
            count: number;
            percent: number;
        }[];
        n: number;
        mean: number | null;
        median: number | null;
        stddev: number | null;
        minimum: number | null;
        maximum: number | null;
        missing: number;
    } | null;
    comments: string[];
    harassmentReports: number | null;
};

export type AssignmentOption = {
    key: string;
    courseId: number;
    course: string;
    part: string;
    lecturer: string;
    level: string | null;
    school: string | null;
    blocked: boolean;
    replacesDraft: boolean;
};

export const CLASSIFICATION_COLORS: Record<string, string> = {
    high: '#00B050',
    acceptable: '#FFC000',
    improvement: '#C00000',
};

export const CLASSIFICATION_LABELS: Record<string, string> = {
    high: 'High Quality',
    acceptable: 'Acceptable Performance',
    improvement: 'Improvement Required',
};

export const formatNumber = (value: number | null | undefined, decimals = 2) =>
    value === null || value === undefined ? '' : value.toFixed(decimals);

export const valueLabel = (variable: Variable | undefined, value: unknown) => {
    if (value === null || value === undefined || !variable) {
        return '';
    }

    const labels = variable.valueLabels as Record<string, string>;

    return labels?.[String(value)] ?? String(value);
};
