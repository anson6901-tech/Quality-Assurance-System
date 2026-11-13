<?php

namespace App\Support\Evaluation;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns the raw rows of a SET form export into an SPSS-style dataset: named variables with
 * labels and value labels, answers coded as numbers (Strongly Agree = 5 … Strongly Disagree = 1,
 * YES = 1 / NO = 2), and warnings about responses worth checking.
 *
 * Variables:
 *  - Q1…Qn       Likert items, in form order, with their section (COURSE, ATTENDANCE, …)
 *  - HARASSMENT  YES/NO item (sensitive)
 *  - GRADE       overall grade 0–100, plus GRADE_BAND (the UHAS grade bands)
 *  - text items  COMMENTS, LEAK_REASON and HARASSMENT_NATURE (the last two are sensitive)
 */
class SetDataset
{
    public const LIKERT_LABELS = [5 => 'Strongly Agree', 4 => 'Agree', 3 => 'Moderately Agree', 2 => 'Disagree', 1 => 'Strongly Disagree'];

    private const LIKERT_TEXT = [
        'strongly agree' => 5,
        'agree' => 4,
        'moderately agree' => 3,
        'neutral' => 3,
        'disagree' => 2,
        'strongly disagree' => 1,
        'sa' => 5,
        'a' => 4,
        'ma' => 3,
        'd' => 2,
        'sd' => 1,
    ];

    /** Short column titles used in the official SET report, found by words in the question. */
    private const SHORT_LABELS = [
        'outline' => 'Outline',
        'objective' => 'Objectives & Outcomes of Lecture',
        'mode of assessment' => 'Assessment & Mark Allocation',
        'text book' => 'Textbooks',
        'textbook' => 'Textbooks',
        'punctual' => 'As Scheduled & Punctual',
        'entire lecture' => 'Present All',
        'well-organised' => 'Well Organised & Material Explained',
        'well organised' => 'Well Organised & Material Explained',
        'content was' => 'Content Covered',
        'participation' => 'Student Participation & Responsive',
        'regular intervals' => 'Assignments',
        'marked and returned' => 'Marked and Returned',
        'feedback and comments' => 'Useful Feedback',
        'confidentiality' => 'Strict Confidentiality',
    ];

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     * @return array{variables: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    public static function build(array $headers, array $rows): array
    {
        $variables = [];
        $columns = [];
        $likertCount = 0;
        $warnings = [];

        foreach ($headers as $index => $header) {
            $values = array_column($rows, $index);
            $filled = array_values(array_filter($values, fn (string $value) => $value !== ''));
            $title = trim((string) preg_replace('/\s+/', ' ', $header));

            $variable = match (true) {
                preg_match('/time\s*stamp/i', $title) === 1 => self::text('TIMESTAMP', $title),
                preg_match('/harass/i', $title) === 1 && stripos($title, 'nature') === false && self::share($filled, fn ($value) => self::yesNo($value) !== null) >= 0.8 => self::yesNoVariable($title),
                self::share($filled, fn ($value) => self::likert($value) !== null) >= 0.8 => self::likertVariable(++$likertCount, $title),
                self::share($filled, fn ($value) => in_array(self::yesNo($value), [1, 2], true) && ! is_numeric(trim($value))) >= 0.8 => self::yesNoVariable($title),
                stripos($title, 'grade') !== false || ($filled !== [] && self::share($filled, 'is_numeric') >= 0.9) => self::grade($title),
                default => self::textVariable($title),
            };

            // Keep names unique, like SPSS does.
            $name = $variable['name'];
            $suffix = 1;

            while (isset($variables[$name])) {
                $name = $variable['name'].'_'.(++$suffix);
            }

            $variable['name'] = $name;
            $variables[$name] = $variable;
            $columns[$index] = $name;
        }

        if ($likertCount === 0) {
            throw new RuntimeException(__('No rating questions (Strongly Agree … Strongly Disagree) were found in this file.'));
        }

        $grade = collect($variables)->firstWhere('role', 'grade');

        if ($grade) {
            $variables['GRADE_BAND'] = [
                'name' => 'GRADE_BAND',
                'label' => __('Overall grade band (UHAS)'),
                'type' => 'numeric',
                'measure' => 'ordinal',
                'role' => 'grade_band',
                'valueLabels' => self::bandLabels(),
                'sensitive' => false,
            ];
        }

        $coded = [];

        foreach ($rows as $rowIndex => $row) {
            $line = $rowIndex + 2;
            $record = [];

            foreach ($columns as $index => $name) {
                $raw = $row[$index] ?? '';
                $variable = $variables[$name];

                $record[$name] = match ($variable['role']) {
                    'likert' => self::likert($raw),
                    'yes_no' => self::yesNo($raw),
                    'grade' => self::gradeValue($raw),
                    default => $raw === '' ? null : $raw,
                };

                if ($raw !== '' && $record[$name] === null) {
                    $warnings[] = __('Row :row, :name: ":value" is not a valid answer, so it is treated as missing.', ['row' => $line, 'name' => $name, 'value' => Str::limit($raw, 30)]);
                }
            }

            if ($grade) {
                $value = $record[$grade['name']];
                $record['GRADE_BAND'] = $value === null ? null : self::band($value);

                if ($value !== null && $value < 10) {
                    $warnings[] = __('Row :row: grade :value is counted as Fail. Check it is not a typing mistake (for example 70).', ['row' => $line, 'value' => self::number($value)]);
                }
            }

            $coded[] = $record;
        }

        return [
            'variables' => array_values($variables),
            'rows' => $coded,
            'warnings' => [...$warnings, ...self::responseWarnings($coded, collect($variables))],
        ];
    }

    /**
     * The UHAS grade band (9 = Distinction … 1 = Fail) for a 0–100 score.
     */
    public static function band(float $score): int
    {
        $bands = config('evaluation.grade_bands');

        foreach ($bands as $index => [, $lowest]) {
            if ($score >= $lowest) {
                return count($bands) - $index;
            }
        }

        return 1;
    }

    /**
     * @return array<int, string> band code => label, highest first.
     */
    public static function bandLabels(): array
    {
        $bands = config('evaluation.grade_bands');
        $labels = [];

        foreach ($bands as $index => [$label]) {
            $labels[count($bands) - $index] = $label;
        }

        return $labels;
    }

    /**
     * Reads a rating however the form or spreadsheet wrote it: "Strongly Agree", "Strongly Agree (5)",
     * "5 - Strongly Agree", "(5)", or just the code 5 (5 = Strongly Agree … 1 = Strongly Disagree).
     * When words and a number are both there, the words decide.
     */
    public static function likert(string $value): ?int
    {
        $lower = strtolower($value);
        $words = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z ]+/', ' ', $lower)));

        if ($words !== '') {
            return self::LIKERT_TEXT[$words] ?? null;
        }

        return preg_match('/^\W*([1-5])(?:\.0+)?\W*$/', trim($lower), $match) ? (int) $match[1] : null;
    }

    /**
     * YES = 1 and NO = 2, written as words ("Yes", "NO (2)") or as the codes 1 and 2.
     */
    public static function yesNo(string $value): ?int
    {
        $words = strtoupper(trim((string) preg_replace('/[^A-Za-z]+/', '', $value)));

        return match (true) {
            in_array($words, ['YES', 'Y'], true) => 1,
            in_array($words, ['NO', 'N'], true) => 2,
            $words === '' && preg_match('/^\W*1(?:\.0+)?\W*$/', trim($value)) === 1 => 1,
            $words === '' && preg_match('/^\W*2(?:\.0+)?\W*$/', trim($value)) === 1 => 2,
            default => null,
        };
    }

    private static function gradeValue(string $value): ?float
    {
        $value = str_replace('%', '', trim($value));

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $number >= 0 && $number <= 100 ? $number : null;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * @param  array<int, string>  $values
     */
    private static function share(array $values, callable $test): float
    {
        return $values === [] ? 0 : count(array_filter($values, $test)) / count($values);
    }

    /**
     * @return array<string, mixed>
     */
    private static function likertVariable(int $number, string $title): array
    {
        // "SECTION [question]" in Google Forms grid exports.
        preg_match('/^(.*?)\s*\[(.*)\]\s*$/s', $title, $match);
        $section = trim($match[1] ?? '') ?: __('Questions');
        $question = trim($match[2] ?? $title);

        return [
            'name' => 'Q'.$number,
            'label' => $question,
            'type' => 'numeric',
            'measure' => 'ordinal',
            'role' => 'likert',
            'section' => strtoupper(trim(explode('/', $section)[0])),
            'shortLabel' => self::shortLabel($question),
            'valueLabels' => self::LIKERT_LABELS,
            'sensitive' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function yesNoVariable(string $title): array
    {
        $harassment = stripos($title, 'harass') !== false || stripos($title, 'sexual') !== false;
        preg_match('/\[(.*)\]/s', $title, $match);

        return [
            'name' => $harassment ? 'HARASSMENT' : 'YES_NO',
            'label' => trim($match[1] ?? $title),
            'type' => 'numeric',
            'measure' => 'nominal',
            'role' => 'yes_no',
            'valueLabels' => [1 => 'YES', 2 => 'NO'],
            'sensitive' => $harassment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function grade(string $title): array
    {
        return [
            'name' => 'GRADE',
            'label' => Str::limit(trim((string) preg_replace('/^GRADE\s*/i', '', $title)) ?: $title, 120),
            'type' => 'numeric',
            'measure' => 'scale',
            'role' => 'grade',
            'valueLabels' => [],
            'sensitive' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function textVariable(string $title): array
    {
        $lower = strtolower($title);

        [$name, $sensitive] = match (true) {
            str_contains($lower, 'leak') => ['LEAK_REASON', true],
            str_contains($lower, 'nature') && str_contains($lower, 'harass') => ['HARASSMENT_NATURE', true],
            str_contains($lower, 'comment') || str_contains($lower, 'suggestion') => ['COMMENTS', false],
            default => [strtoupper(Str::limit(Str::slug($title, '_'), 20, '')) ?: 'TEXT', false],
        };

        return [...self::text($name, $title), 'sensitive' => $sensitive];
    }

    /**
     * @return array<string, mixed>
     */
    private static function text(string $name, string $title): array
    {
        return [
            'name' => $name,
            'label' => $title,
            'type' => 'string',
            'measure' => 'nominal',
            'role' => $name === 'TIMESTAMP' ? 'timestamp' : 'text',
            'valueLabels' => [],
            'sensitive' => false,
        ];
    }

    private static function shortLabel(string $question): string
    {
        $lower = strtolower($question);

        foreach (self::SHORT_LABELS as $words => $label) {
            if (str_contains($lower, $words)) {
                return $label;
            }
        }

        return Str::limit($question, 40);
    }

    /**
     * Duplicate responses, and responses that strongly disagree with everything.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  Collection<string, array<string, mixed>>  $variables
     * @return array<int, string>
     */
    private static function responseWarnings(array $rows, $variables): array
    {
        $warnings = [];
        $answerNames = $variables->reject(fn ($variable) => $variable['role'] === 'timestamp')->keys()->all();
        $likertNames = $variables->where('role', 'likert')->keys()->all();
        $seen = [];

        foreach ($rows as $index => $row) {
            $key = json_encode(array_map(fn ($name) => $row[$name] ?? null, $answerNames));

            if (isset($seen[$key])) {
                $warnings[] = __('Row :row has exactly the same answers as row :first (possible duplicate). It is still counted.', ['row' => $index + 2, 'first' => $seen[$key] + 2]);
            } else {
                $seen[$key] = $index;
            }

            $likert = array_filter(array_map(fn ($name) => $row[$name] ?? null, $likertNames), fn ($value) => $value !== null);

            if (count($likert) === count($likertNames) && array_unique($likert) === [array_values($likert)[0]] && array_values($likert)[0] === 1 && count($likertNames) > 3) {
                $warnings[] = __('Row :row answered ":answer" to every question. It is still counted.', ['row' => $index + 2, 'answer' => self::LIKERT_LABELS[array_values($likert)[0]]]);
            }
        }

        return $warnings;
    }
}
