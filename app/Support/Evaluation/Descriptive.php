<?php

namespace App\Support\Evaluation;

/**
 * Descriptive statistics with the formulas SPSS uses (sample standard deviation, HAVERAGE
 * percentiles, bias-corrected skewness and kurtosis). All methods expect sorted values.
 */
class Descriptive
{
    /**
     * @param  array<int, float>  $sorted
     * @return array{n: int, mean: float|null, median: float|null, stddev: float|null, variance: float|null, minimum: float|null, maximum: float|null}
     */
    public static function of(array $sorted): array
    {
        $n = count($sorted);

        if ($n === 0) {
            return ['n' => 0, 'mean' => null, 'median' => null, 'stddev' => null, 'variance' => null, 'minimum' => null, 'maximum' => null];
        }

        $mean = array_sum($sorted) / $n;
        $variance = $n > 1 ? array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $sorted)) / ($n - 1) : null;

        return [
            'n' => $n,
            'mean' => $mean,
            'median' => $n % 2 ? $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2,
            'stddev' => $variance === null ? null : sqrt($variance),
            'variance' => $variance,
            'minimum' => $sorted[0],
            'maximum' => $sorted[$n - 1],
        ];
    }

    /**
     * The smallest of the most frequent values, as SPSS reports it.
     *
     * @param  array<int, float>  $sorted
     */
    public static function mode(array $sorted): ?float
    {
        $counts = self::counts($sorted);

        return $counts === [] ? null : (float) array_search(max($counts), $counts, true);
    }

    /**
     * @param  array<int, float>  $sorted
     */
    public static function hasMultipleModes(array $sorted): bool
    {
        $counts = self::counts($sorted);

        return count(array_keys($counts, max($counts), true)) > 1;
    }

    /**
     * Weighted average at (n + 1)p, SPSS's default (HAVERAGE).
     *
     * @param  array<int, float>  $sorted
     */
    public static function percentile(array $sorted, float $percentile): ?float
    {
        $n = count($sorted);

        if ($n === 0) {
            return null;
        }

        $position = ($n + 1) * $percentile / 100;
        $lower = (int) floor($position);
        $fraction = $position - $lower;

        if ($lower < 1) {
            return $sorted[0];
        }

        if ($lower >= $n) {
            return $sorted[$n - 1];
        }

        return $sorted[$lower - 1] + $fraction * ($sorted[$lower] - $sorted[$lower - 1]);
    }

    /**
     * @param  array<int, float>  $sorted
     */
    public static function skewness(array $sorted): ?float
    {
        $n = count($sorted);
        $summary = self::of($sorted);

        if ($n < 3 || ! $summary['stddev']) {
            return null;
        }

        $sum = array_sum(array_map(fn ($x) => (($x - $summary['mean']) / $summary['stddev']) ** 3, $sorted));

        return $n / (($n - 1) * ($n - 2)) * $sum;
    }

    /**
     * @param  array<int, float>  $sorted
     */
    public static function kurtosis(array $sorted): ?float
    {
        $n = count($sorted);
        $summary = self::of($sorted);

        if ($n < 4 || ! $summary['stddev']) {
            return null;
        }

        $sum = array_sum(array_map(fn ($x) => (($x - $summary['mean']) / $summary['stddev']) ** 4, $sorted));

        return $n * ($n + 1) / (($n - 1) * ($n - 2) * ($n - 3)) * $sum
            - 3 * ($n - 1) ** 2 / (($n - 2) * ($n - 3));
    }

    /**
     * @param  array<int, float>  $sorted
     * @return array<string, int>
     */
    private static function counts(array $sorted): array
    {
        $counts = [];

        foreach ($sorted as $value) {
            $key = (string) $value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }
}
