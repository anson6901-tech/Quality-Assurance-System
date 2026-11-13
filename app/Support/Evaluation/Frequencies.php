<?php

namespace App\Support\Evaluation;

/**
 * Analyze → Descriptive Statistics → Frequencies, computed the way SPSS does: a Statistics
 * table (N valid/missing plus the chosen statistics) and one frequency table per variable
 * with Frequency, Percent, Valid Percent and Cumulative Percent.
 */
class Frequencies
{
    public const STATISTICS = [
        'mean', 'median', 'mode', 'sum',
        'stddev', 'variance', 'range', 'minimum', 'maximum', 'semean',
        'quartiles', 'skewness', 'kurtosis',
    ];

    public const ORDERS = ['ascending_values', 'descending_values', 'ascending_counts', 'descending_counts'];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'variables' => [],
            'displayTables' => true,
            'statistics' => [],
            'percentiles' => [],
            'chart' => 'none',
            'chartValues' => 'frequencies',
            'order' => 'ascending_values',
            'multiple' => 'compare',
            'suppressAbove' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function run(array $dataset, array $options): array
    {
        $options = [...self::defaults(), ...$options];
        $variables = collect($dataset['variables'])->keyBy('name');
        $total = count($dataset['rows']);
        $results = [];

        foreach ($options['variables'] as $name) {
            $variable = $variables->get($name);

            if (! $variable) {
                continue;
            }

            $values = array_values(array_filter(
                array_column($dataset['rows'], $name),
                fn ($value) => $value !== null && $value !== '',
            ));
            $numeric = $variable['type'] === 'numeric';

            $results[] = [
                'name' => $name,
                'label' => $variable['label'],
                'valid' => count($values),
                'missing' => $total - count($values),
                'statistics' => $numeric ? self::statistics($values, $options) : [],
                'table' => self::table($values, $total, $variable, $options),
                'suppressed' => $options['displayTables'] && $options['suppressAbove']
                    && count(array_unique($values)) > (int) $options['suppressAbove'],
            ];
        }

        return ['total' => $total, 'variables' => $results];
    }

    /**
     * @param  array<int, int|float|string>  $values
     * @param  array<string, mixed>  $variable
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function table(array $values, int $total, array $variable, array $options): array
    {
        $counts = [];

        foreach ($values as $value) {
            $key = (string) $value;
            $counts[$key] ??= ['value' => $value, 'count' => 0];
            $counts[$key]['count']++;
        }

        $rows = array_values($counts);
        $numeric = $variable['type'] === 'numeric';

        usort($rows, fn (array $a, array $b) => match ($options['order']) {
            'descending_values' => self::compare($b['value'], $a['value'], $numeric),
            'ascending_counts' => [$a['count'], self::compare($a['value'], $b['value'], $numeric)] <=> [$b['count'], 0],
            'descending_counts' => [$b['count'], self::compare($a['value'], $b['value'], $numeric)] <=> [$a['count'], 0],
            default => self::compare($a['value'], $b['value'], $numeric),
        });

        $valid = count($values);
        $cumulative = 0;
        $labels = $variable['valueLabels'] ?? [];

        $rows = array_map(function (array $row) use ($total, $valid, &$cumulative, $labels) {
            $validPercent = $valid ? $row['count'] / $valid * 100 : 0;
            $cumulative += $row['count'];

            return [
                'value' => $row['value'],
                'label' => $labels[(string) $row['value']] ?? $labels[(int) $row['value']] ?? (string) $row['value'],
                'frequency' => $row['count'],
                'percent' => $total ? $row['count'] / $total * 100 : 0,
                'validPercent' => $validPercent,
                'cumulativePercent' => $valid ? $cumulative / $valid * 100 : 0,
            ];
        }, $rows);

        return [
            'rows' => $rows,
            'validTotal' => $valid,
            'validTotalPercent' => $total ? $valid / $total * 100 : 0,
            'missing' => $total - $valid,
            'missingPercent' => $total ? ($total - $valid) / $total * 100 : 0,
            'total' => $total,
        ];
    }

    private static function compare(mixed $a, mixed $b, bool $numeric): int
    {
        return $numeric ? ((float) $a <=> (float) $b) : strcasecmp((string) $a, (string) $b);
    }

    /**
     * @param  array<int, int|float>  $values
     * @param  array<string, mixed>  $options
     * @return array<string, float|int|null|array<string, float|null>>
     */
    private static function statistics(array $values, array $options): array
    {
        $chosen = array_intersect(self::STATISTICS, $options['statistics']);
        $percentiles = array_map('floatval', $options['percentiles'] ?? []);

        if (in_array('quartiles', $chosen, true)) {
            $percentiles = [...$percentiles, 25, 50, 75];
        }

        $percentiles = array_values(array_unique($percentiles));
        sort($percentiles);

        $n = count($values);
        $sorted = array_map('floatval', $values);
        sort($sorted);
        $summary = Descriptive::of($sorted);
        $result = [];

        foreach ($chosen as $statistic) {
            if ($statistic === 'quartiles') {
                continue;
            }

            $result[$statistic] = $n === 0 ? null : match ($statistic) {
                'mean' => $summary['mean'],
                'median' => $summary['median'],
                'mode' => Descriptive::mode($sorted),
                'sum' => array_sum($sorted),
                'stddev' => $summary['stddev'],
                'variance' => $summary['variance'],
                'range' => $sorted[$n - 1] - $sorted[0],
                'minimum' => $sorted[0],
                'maximum' => $sorted[$n - 1],
                'semean' => $summary['stddev'] === null ? null : $summary['stddev'] / sqrt($n),
                'skewness' => Descriptive::skewness($sorted),
                'kurtosis' => Descriptive::kurtosis($sorted),
            };
        }

        if (in_array('mode', $chosen, true) && $n > 0) {
            $result['multipleModes'] = Descriptive::hasMultipleModes($sorted);
        }

        if ($percentiles !== []) {
            $result['percentiles'] = [];

            foreach ($percentiles as $percentile) {
                $result['percentiles'][self::label($percentile)] = $n === 0 ? null : Descriptive::percentile($sorted, $percentile);
            }
        }

        return $result;
    }

    private static function label(float $percentile): string
    {
        return rtrim(rtrim(number_format($percentile, 2, '.', ''), '0'), '.');
    }
}
