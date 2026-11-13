<?php

namespace App\Support\Evaluation;

use App\Models\EvaluationAnalysis;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Cell;
use PhpOffice\PhpWord\Style\ListItem;

/**
 * The SET report for one analysis. Page 1 is the colour-coded SET table in the UHAS layout;
 * from page 2 come the students' comments and the overall grade distribution.
 */
class SetReport
{
    public const COLORS = [
        SetSummary::HIGH => '00B050',
        SetSummary::ACCEPTABLE => 'FFC000',
        SetSummary::IMPROVEMENT => 'C00000',
    ];

    private const NUMBER_WORDS = [1 => 'ONE', 2 => 'TWO', 3 => 'THREE', 4 => 'FOUR'];

    /**
     * Everything the PDF and Word versions print.
     *
     * @return array<string, mixed>
     */
    public static function data(EvaluationAnalysis $analysis): array
    {
        $analysis->loadMissing(['academicYear:id,name', 'semester:id,name,sequence']);
        $summary = SetSummary::for($analysis->dataset);
        $sequence = $analysis->semester?->sequence;

        return [
            'letterhead' => config('evaluation.letterhead'),
            'year' => (string) $analysis->academicYear?->name,
            'semester' => self::NUMBER_WORDS[$sequence] ?? strtoupper((string) $analysis->semester?->name),
            'level' => (string) $analysis->level,
            'school' => strtoupper((string) $analysis->school_label),
            'courseCode' => strtoupper((string) $analysis->course_code),
            'courseTitle' => strtoupper((string) $analysis->course_title),
            'lecturer' => strtoupper((string) $analysis->lecturer_name),
            'summary' => $summary,
            'sections' => collect($summary['questions'])->groupBy('section')->map->count()->all(),
            'colors' => self::COLORS,
            'labels' => SetSummary::CLASSIFICATION_LABELS,
            'thresholds' => config('evaluation.thresholds'),
        ];
    }

    public static function fileName(EvaluationAnalysis $analysis, string $extension): string
    {
        $name = trim(implode(' ', array_filter([$analysis->lecturer_name, $analysis->course_code, $analysis->course_title]))) ?: pathinfo($analysis->original_name, PATHINFO_FILENAME);

        return str($name)->replaceMatches('/[^A-Za-z0-9 ._-]+/', '')->squish()->limit(120, '')->append(" SET.{$extension}")->toString();
    }

    public static function pdf(EvaluationAnalysis $analysis): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(public_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('evaluation.report', [
            ...self::data($analysis),
            'crest' => 'data:image/png;base64,'.base64_encode((string) file_get_contents(public_path('uhas-crest.png'))),
        ])->render());
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public static function docx(EvaluationAnalysis $analysis): string
    {
        $data = self::data($analysis);
        // Escape "&" and "<" in headings and student comments, or Word cannot open the file.
        Settings::setOutputEscapingEnabled(true);
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10);

        self::wordTable($word->addSection(['orientation' => 'landscape', 'marginTop' => 700, 'marginBottom' => 700, 'marginLeft' => 700, 'marginRight' => 700]), $data);
        self::wordComments($word->addSection(['marginTop' => 900, 'marginBottom' => 900]), $data);

        $path = tempnam(sys_get_temp_dir(), 'set');
        IOFactory::createWriter($word, 'Word2007')->save($path);
        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function wordHeading(Section $section, array $data): void
    {
        $center = ['alignment' => Jc::CENTER, 'spaceAfter' => 0];
        $section->addText($data['letterhead']['university'], ['bold' => true, 'size' => 13], $center);
        $section->addText($data['letterhead']['directorate'], ['bold' => true, 'size' => 12], $center);
        $section->addImage(public_path('uhas-crest.png'), ['height' => 48, 'alignment' => Jc::CENTER]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function wordTable(Section $section, array $data): void
    {
        self::wordHeading($section, $data);
        $section->addText("REPORT OF STUDENTS' EVALUATION OF TEACHING (SET)", ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);

        foreach (['SEMESTER' => $data['semester'], 'LEVEL' => $data['level'], 'YEAR' => $data['year']] as $label => $value) {
            $run = $section->addTextRun(['spaceAfter' => 0]);
            $run->addText($label.":\t", ['bold' => true]);
            $run->addText($value, ['bold' => true]);
        }

        $section->addTextBreak();
        $questions = $data['summary']['questions'];
        $border = ['borderSize' => 6, 'borderColor' => '000000', 'cellMargin' => 40];
        $table = $section->addTable($border + ['alignment' => Jc::CENTER]);
        $bold = ['bold' => true, 'size' => 8];
        $center = ['alignment' => Jc::CENTER, 'spaceAfter' => 0];
        $middle = ['valign' => 'center'];

        $table->addRow();
        $table->addCell(4400, ['gridSpan' => 2] + $middle)->addText($data['school'], $bold, $center);
        $table->addCell(count($questions) * 700, ['gridSpan' => count($questions)] + $middle)->addText('EVALUATION POINTS', ['bold' => true, 'size' => 14], $center);

        $table->addRow();
        $table->addCell(2200)->addText('COURSE ID/ COURSES', $bold, $center);
        $table->addCell(2200)->addText("LECTURER'S NAME", $bold, $center);

        foreach ($data['sections'] as $name => $span) {
            $table->addCell($span * 700, ['gridSpan' => $span])->addText($name, $bold, $center);
        }

        $table->addRow(2600);
        $table->addCell(2200, ['textDirection' => Cell::TEXT_DIR_BTLR] + $middle)->addText('COURSE CODES/TITLES', $bold, $center);
        $table->addCell(2200, ['textDirection' => Cell::TEXT_DIR_BTLR] + $middle)->addText('NAME OF PART-TIME/FULL-TIME LECTURERS', $bold, $center);

        foreach ($questions as $question) {
            $table->addCell(700, ['textDirection' => Cell::TEXT_DIR_BTLR, 'valign' => 'bottom'])
                ->addText($question['number'].'. '.strtoupper($question['shortLabel']), $bold);
        }

        $table->addRow(900);
        $table->addCell(2200)->addText(trim($data['courseCode'].' '.$data['courseTitle']), $bold);
        $table->addCell(2200)->addText($data['lecturer'], $bold);

        foreach ($questions as $question) {
            $color = self::COLORS[$question['classification']] ?? 'FFFFFF';
            $table->addCell(700, ['bgColor' => $color, 'valign' => 'center'])
                ->addText((string) ($question['rounded'] ?? '—'), ['bold' => true, 'size' => 11, 'name' => 'Cambria'], $center);
        }

        $section->addTextBreak();
        $legend = $section->addTable(['alignment' => Jc::CENTER, 'cellMargin' => 30]);
        $keys = [
            SetSummary::HIGH => 'ABOVE '.$data['thresholds']['high'],
            SetSummary::ACCEPTABLE => 'BETWEEN '.$data['thresholds']['acceptable'].'-'.$data['thresholds']['high'],
            SetSummary::IMPROVEMENT => 'LESS THAN '.$data['thresholds']['acceptable'],
        ];
        $legend->addRow();
        $legend->addCell(4200)->addText('INDEX', ['bold' => true], $center);
        $legend->addCell(2400);
        $legend->addCell(3000)->addText('KEY', ['bold' => true], $center);

        foreach ($keys as $classification => $key) {
            $text = ['size' => 9, 'color' => $classification === SetSummary::IMPROVEMENT ? 'FFFFFF' : '000000'];
            $legend->addRow();
            $legend->addCell(4200, ['bgColor' => self::COLORS[$classification]])->addText(strtoupper($data['labels'][$classification]), $text, $center);
            $legend->addCell(2400);
            $legend->addCell(3000, ['bgColor' => self::COLORS[$classification]])->addText($key, $text, $center);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function wordComments(Section $section, array $data): void
    {
        $letterhead = $data['letterhead'];
        self::wordHeading($section, $data);
        $section->addText(sprintf('Tel: %s   ·   Email: %s   ·   %s', $letterhead['phone'], $letterhead['email'], implode(', ', $letterhead['address'])), ['size' => 8], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $section->addText('Ref. No: '.$letterhead['reference'], ['size' => 8], ['alignment' => Jc::CENTER]);
        $section->addText($data['year'].' ACADEMIC YEAR', ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $section->addText('COMMENTS / SUGGESTIONS MADE BY STUDENTS DURING THE EVALUATION EXERCISE', ['bold' => true, 'size' => 11], ['alignment' => Jc::CENTER]);

        foreach ([
            'SCHOOL' => $data['school'],
            'NAME OF LECTURER' => $data['lecturer'],
            'COURSE CODE AND TITLE' => trim($data['courseCode'].' '.$data['courseTitle']),
            'LEVEL' => $data['level'].'          SEMESTER: '.$data['semester'],
        ] as $label => $value) {
            $run = $section->addTextRun(['spaceAfter' => 60]);
            $run->addText($label.':  ', ['bold' => true]);
            $run->addText($value);
        }

        $section->addText('COMMENTS & SUGGESTIONS:', ['bold' => true], ['spaceBefore' => 120]);

        if ($data['summary']['comments'] === []) {
            $section->addText('No comments were made.', ['italic' => true]);
        }

        foreach ($data['summary']['comments'] as $comment) {
            $section->addListItem((string) $comment, 0, null, ['listType' => ListItem::TYPE_NUMBER]);
        }

        $grades = $data['summary']['grades'];

        if (! $grades) {
            return;
        }

        $section->addTextBreak();
        $section->addText('OVERALL GRADE GIVEN BY STUDENTS', ['bold' => true], ['spaceAfter' => 60]);
        $section->addChart('column', array_column($grades['bands'], 'label'), array_column($grades['bands'], 'count'), [
            'width' => 6000000,
            'height' => 3000000,
            'title' => 'Frequency',
            'showLegend' => false,
            'showVal' => true,
            'gridY' => true,
            'colors' => ['0F6E56'],
        ]);

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 50]);
        $table->addRow();

        foreach (['Grade band', 'Score range', 'Frequency', 'Percent'] as $heading) {
            $table->addCell(2200, ['bgColor' => 'E8F3EF'])->addText($heading, ['bold' => true, 'size' => 9]);
        }

        foreach (self::bandRanges() as $index => $range) {
            $band = $grades['bands'][$index];
            $table->addRow();
            $table->addCell(2200)->addText($band['label'], ['size' => 9]);
            $table->addCell(2200)->addText($range, ['size' => 9]);
            $table->addCell(2200)->addText((string) $band['count'], ['size' => 9]);
            $table->addCell(2200)->addText(number_format($band['percent'], 1).'%', ['size' => 9]);
        }

        $section->addText(self::gradeLine($grades), ['size' => 9], ['spaceBefore' => 120]);
    }

    /**
     * "80–100", "70–<80", … for each grade band, highest first.
     *
     * @return array<int, string>
     */
    public static function bandRanges(): array
    {
        $bands = config('evaluation.grade_bands');

        return array_map(fn (int $index) => $index === 0
            ? $bands[0][1].'–100'
            : $bands[$index][1].'–<'.$bands[$index - 1][1], array_keys($bands));
    }

    /**
     * @param  array<string, mixed>  $grades
     */
    public static function gradeLine(array $grades): string
    {
        $format = fn (?float $value) => $value === null ? '—' : rtrim(rtrim(number_format($value, 2), '0'), '.');

        return sprintf(
            'Responses: %d   ·   Mean: %s   ·   Median: %s   ·   Lowest: %s   ·   Highest: %s   ·   Std. deviation: %s',
            $grades['n'],
            $format($grades['mean']),
            $format($grades['median']),
            $format($grades['minimum']),
            $format($grades['maximum']),
            $format($grades['stddev']),
        );
    }
}
