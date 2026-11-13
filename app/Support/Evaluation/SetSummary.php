<?php

namespace App\Support\Evaluation;

/**
 * The UHAS SET results for one lecturer and course: the share of positive answers
 * (Strongly Agree + Agree) for each question and section, the overall grade bands, and
 * the students' comments.
 */
class SetSummary
{
    public const HIGH = 'high';

    public const ACCEPTABLE = 'acceptable';

    public const IMPROVEMENT = 'improvement';

    public const CLASSIFICATION_LABELS = [
        self::HIGH => 'High Quality',
        self::ACCEPTABLE => 'Acceptable Performance',
        self::IMPROVEMENT => 'Improvement Required',
    ];

    /**
     * @param  array<string, mixed>  $dataset
     * @return array<string, mixed>
     */
    public static function for(array $dataset): array
    {
        $variables = collect($dataset['variables']);
        $rows = $dataset['rows'];

        $questions = $variables->where('role', 'likert')->values()->map(function (array $variable, int $index) use ($rows) {
            $values = array_values(array_filter(array_column($rows, $variable['name']), fn ($value) => $value !== null));
            $counts = [];

            foreach ([5, 4, 3, 2, 1] as $code) {
                $counts[$code] = count(array_filter($values, fn ($value) => $value === $code));
            }

            $positive = $counts[5] + $counts[4];
            $percent = $values === [] ? null : $positive / count($values) * 100;

            return [
                'number' => $index + 1,
                'name' => $variable['name'],
                'label' => $variable['label'],
                'shortLabel' => $variable['shortLabel'],
                'section' => $variable['section'],
                'counts' => $counts,
                'valid' => count($values),
                'positive' => $positive,
                'percent' => $percent,
                'rounded' => $percent === null ? null : (int) round($percent),
                'classification' => self::classify($percent),
            ];
        });

        // Section results pool the answers of all its questions.
        $sections = $questions->groupBy('section')->map(function ($items, string $section) {
            $valid = $items->sum('valid');
            $positive = $items->sum('positive');
            $percent = $valid ? $positive / $valid * 100 : null;

            return [
                'section' => $section,
                'questions' => $items->pluck('number')->all(),
                'valid' => $valid,
                'positive' => $positive,
                'percent' => $percent,
                'rounded' => $percent === null ? null : (int) round($percent),
                'classification' => self::classify($percent),
            ];
        })->values();

        $comments = $variables->firstWhere('name', 'COMMENTS');

        return [
            'responses' => count($rows),
            'questions' => $questions->all(),
            'sections' => $sections->all(),
            'grades' => self::grades($rows, $variables->firstWhere('role', 'grade')),
            'comments' => $comments
                ? array_values(array_filter(array_column($rows, 'COMMENTS'), fn ($value) => $value !== null && trim((string) $value) !== ''))
                : [],
            'harassmentReports' => $variables->firstWhere('name', 'HARASSMENT')
                ? count(array_filter(array_column($rows, 'HARASSMENT'), fn ($value) => $value === 1))
                : null,
        ];
    }

    public static function classify(?float $percent): ?string
    {
        if ($percent === null) {
            return null;
        }

        $thresholds = config('evaluation.thresholds');

        return match (true) {
            $percent > $thresholds['high'] => self::HIGH,
            $percent >= $thresholds['acceptable'] => self::ACCEPTABLE,
            default => self::IMPROVEMENT,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $variable
     * @return array<string, mixed>|null
     */
    private static function grades(array $rows, ?array $variable): ?array
    {
        if (! $variable) {
            return null;
        }

        $scores = array_values(array_filter(array_column($rows, $variable['name']), fn ($value) => $value !== null));
        sort($scores);
        $total = count($scores);
        $bands = [];

        foreach (SetDataset::bandLabels() as $code => $label) {
            $count = count(array_filter($scores, fn ($score) => SetDataset::band($score) === $code));
            $bands[] = ['code' => $code, 'label' => $label, 'count' => $count, 'percent' => $total ? $count / $total * 100 : 0];
        }

        return ['bands' => $bands, ...Descriptive::of($scores), 'missing' => count($rows) - $total];
    }
}
