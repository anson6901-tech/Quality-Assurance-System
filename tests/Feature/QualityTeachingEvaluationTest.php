<?php

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\DocumentFile;
use App\Models\DocumentFolder;
use App\Models\EvaluationAnalysis;
use App\Models\Program;
use App\Models\QualityReport;
use App\Models\School;
use App\Models\User;
use App\Support\Evaluation\SetSummary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The anonymised SOPH132 sample: 70 responses whose official SET results are known.
 */
function setSample(string $name = 'SOPH132 Dispensing.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents(base_path('tests/Fixtures/evaluation/set-sample.csv')));
}

function evaluationSetup(): array
{
    Storage::fake('local');
    Storage::fake('public');

    $school = School::create(['name' => 'School of Pharmacy', 'code' => 'SOP']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Pharmaceutics', 'code' => 'PHC']);
    $program = Program::create(['department_id' => $department->id, 'name' => 'Doctor of Pharmacy', 'code' => 'PHARMD', 'max_level' => 600]);
    $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $semester = $year->semesters()->where('sequence', 2)->firstOrFail();

    $lecturer = User::factory()->create(['name' => 'Dr Antwi Osei Assebey', 'staff_id' => 'SOP-1001', 'university_role' => UniversityRole::Lecturer]);
    $officer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);

    $dispensing = Course::create(['code' => 'SOPH132', 'title' => 'Dispensing', 'level' => '100']);
    $unstaffed = Course::create(['code' => 'SOPH134', 'title' => 'Pharmaceutical Calculations', 'level' => '100']);

    foreach ([$dispensing, $unstaffed] as $course) {
        CourseOffering::create(['course_id' => $course->id, 'program_id' => $program->id, 'semester_id' => $semester->id, 'level' => '100']);
    }

    $assign = fn (Course $course) => DB::table('course_lecturer')->insert([
        'course_id' => $course->id,
        'program_id' => $program->id,
        'semester_id' => $semester->id,
        'lecturer_id' => $lecturer->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $assign($dispensing);

    return compact('school', 'department', 'program', 'year', 'semester', 'lecturer', 'officer', 'dispensing', 'unstaffed', 'assign');
}

function startAnalysis($test, array $data, ?User $user = null): EvaluationAnalysis
{
    $test->actingAs($user ?? $data['officer'])->post('/quality/teaching-evaluation', [
        'academic_year_id' => $data['year']->id,
        'semester_id' => $data['semester']->id,
        'file' => setSample(),
    ])->assertRedirect();

    return EvaluationAnalysis::query()->latest('id')->firstOrFail();
}

it('reproduces the official SOPH132 SET results from the data file', function () {
    $data = evaluationSetup();
    $analysis = startAnalysis($this, $data);

    expect($analysis->course_code)->toBe('SOPH132')
        ->and(collect($analysis->dataset['variables'])->pluck('name')->all())->toBe([
            'TIMESTAMP', 'Q1', 'Q2', 'Q3', 'Q4', 'Q5', 'Q6', 'Q7', 'Q8', 'Q9', 'Q10', 'Q11', 'Q12', 'Q13',
            'LEAK_REASON', 'HARASSMENT', 'HARASSMENT_NATURE', 'COMMENTS', 'GRADE', 'GRADE_BAND',
        ]);

    $this->actingAs($data['officer'])
        ->get("/quality/teaching-evaluation/{$analysis->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/teaching-evaluation/Show')
            ->has('rows', 70)
            ->where('summary.responses', 70)
            ->where('summary.questions.0.positive', 64)
            ->where('summary.harassmentReports', 1)
            ->where('summary.sections.4.section', 'CONFIDENTIALITY')
            ->where('summary.sections.0.rounded', 88)
            ->has('warnings', 6)
            ->etc());

    $summary = SetSummary::for($analysis->dataset);

    expect(array_column($summary['questions'], 'positive'))->toBe([64, 64, 63, 54, 66, 68, 66, 68, 65, 53, 54, 49, 62])
        ->and(array_column($summary['questions'], 'rounded'))->toBe([91, 91, 90, 77, 94, 97, 94, 97, 93, 76, 77, 70, 89])
        ->and(array_column($summary['questions'], 'classification'))->toBe([
            'high', 'high', 'high', 'acceptable', 'high', 'high', 'high', 'high', 'high', 'acceptable', 'acceptable', 'acceptable', 'high',
        ])
        ->and(array_column($summary['grades']['bands'], 'count'))->toBe([53, 13, 1, 2, 0, 0, 0, 0, 1])
        ->and(round($summary['grades']['mean'], 2))->toBe(83.76)
        ->and($summary['grades']['median'])->toBe(85.5)
        ->and($summary['grades']['minimum'])->toEqual(7)
        ->and(round($summary['grades']['stddev'], 2))->toBe(13.71)
        ->and($summary['comments'])->toHaveCount(14);
});

it('runs Frequencies like SPSS with statistics, ordering and charts', function () {
    $data = evaluationSetup();
    $analysis = startAnalysis($this, $data);

    $this->actingAs($data['officer'])
        ->post("/quality/teaching-evaluation/{$analysis->id}/frequencies", [
            'variables' => ['Q1', 'GRADE', 'GRADE_BAND'],
            'displayTables' => true,
            'statistics' => ['mean', 'median', 'mode', 'stddev', 'quartiles'],
            'percentiles' => [],
            'chart' => 'bar',
            'chartValues' => 'frequencies',
            'order' => 'descending_values',
            'multiple' => 'compare',
            'suppressAbove' => null,
        ])
        ->assertRedirect();

    $this->actingAs($data['officer'])
        ->get("/quality/teaching-evaluation/{$analysis->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('frequencies.output.variables.0.table.rows.0.label', 'Strongly Agree')
            ->where('frequencies.output.variables.0.table.rows.0.frequency', 36)
            ->where('frequencies.output.variables.0.table.rows.3.label', 'Strongly Disagree')
            ->where('frequencies.output.variables.0.table.rows.3.cumulativePercent', 100)
            ->where('frequencies.output.variables.1.statistics.median', 85.5)
            ->where('frequencies.output.variables.1.statistics.percentiles', ['25' => 79.5, '50' => 85.5, '75' => 90.5])
            ->where('frequencies.output.variables.2.table.rows.0.label', 'Distinction')
            ->where('frequencies.output.variables.2.table.rows.0.frequency', 53)
            ->etc());

    $this->actingAs($data['officer'])
        ->post("/quality/teaching-evaluation/{$analysis->id}/frequencies", ['variables' => [], 'chart' => 'none', 'chartValues' => 'frequencies', 'order' => 'ascending_values', 'multiple' => 'compare'])
        ->assertSessionHasErrors('variables');
});

it('turns the analysis into PDF and Word reports', function () {
    $data = evaluationSetup();
    $analysis = startAnalysis($this, $data);

    $pdf = $this->actingAs($data['officer'])->get("/quality/teaching-evaluation/{$analysis->id}/pdf?download=1")->assertOk();
    expect($pdf->headers->get('content-type'))->toBe('application/pdf')
        ->and($pdf->getContent())->toStartWith('%PDF-')
        ->and($pdf->headers->get('content-disposition'))->toContain('attachment');

    $word = $this->actingAs($data['officer'])->get("/quality/teaching-evaluation/{$analysis->id}/word")->assertOk();
    expect($word->getContent())->toStartWith('PK');

    // Every part must be valid XML, including headings with "&", or Word refuses the file.
    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, $word->getContent());
    $zip = new ZipArchive;
    $zip->open($path);
    $document = $zip->getFromName('word/document.xml');
    $zip->close();
    unlink($path);

    expect(simplexml_load_string($document))->not->toBeFalse()
        ->and($document)->toContain('OBJECTIVES &amp; OUTCOMES OF LECTURE')
        ->and($document)->toContain('Sample comment 4');
});

it('rejects files that are not evaluation data', function () {
    $data = evaluationSetup();

    $this->actingAs($data['officer'])->post('/quality/teaching-evaluation', [
        'academic_year_id' => $data['year']->id,
        'semester_id' => $data['semester']->id,
        'file' => UploadedFile::fake()->createWithContent('notes.csv', "Name,Score\nAma,12\n"),
    ])->assertSessionHasErrors(['file' => 'No rating questions (Strongly Agree … Strongly Disagree) were found in this file.']);

    $this->actingAs($data['officer'])->post('/quality/teaching-evaluation', [
        'academic_year_id' => $data['year']->id,
        'semester_id' => $data['semester']->id,
        'file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.7'),
    ])->assertSessionHasErrors('file');

    expect(EvaluationAnalysis::query()->count())->toBe(0);
});

it('sends the SET report to the assigned lecturer as a draft', function () {
    $data = evaluationSetup();
    $analysis = startAnalysis($this, $data);

    $this->actingAs($data['officer'])
        ->post("/quality/teaching-evaluation/{$analysis->id}/finish", ['key' => 'not-a-real-key'])
        ->assertSessionHasErrors('key');

    $key = $this->actingAs($data['officer'])
        ->get("/quality/teaching-evaluation/{$analysis->id}")
        ->viewData('page')['props']['assignments'][0]['key'];

    $this->actingAs($data['officer'])
        ->post("/quality/teaching-evaluation/{$analysis->id}/finish", ['key' => $key])
        ->assertRedirect('/quality/teaching-evaluation');

    $analysis->refresh();
    $report = QualityReport::query()->sole();

    expect($analysis->status)->toBe(EvaluationAnalysis::DRAFTED)
        ->and($analysis->quality_report_ids)->toBe([$report->id])
        ->and($analysis->level)->toBe('100')
        ->and($analysis->school_label)->toBe('SOP')
        ->and($analysis->lecturer_name)->toBe('Dr Antwi Osei Assebey')
        ->and($report->status)->toBe('draft')
        ->and($report->lecturer_id)->toBe($data['lecturer']->id)
        ->and($report->category)->toBe('Student Evaluation of Teaching (SET)')
        ->and($report->courses()->pluck('code')->all())->toBe(['SOPH132'])
        ->and($report->reviews()->first()->note)->toBe('Teaching evaluation')
        ->and(Storage::disk('public')->get($report->file_path))->toStartWith('%PDF-');

    $this->actingAs($data['officer'])
        ->post("/quality/teaching-evaluation/{$analysis->id}/finish", ['key' => $key])
        ->assertStatus(409);
});

it('keeps reports waiting for a lecturer until someone moves them to draft', function () {
    $data = evaluationSetup();
    $analysis = startAnalysis($this, $data);

    $this->actingAs($data['officer'])
        ->post("/quality/teaching-evaluation/{$analysis->id}/finish", ['course_id' => $data['unstaffed']->id])
        ->assertRedirect('/quality/teaching-evaluation?folder=waiting');

    expect($analysis->refresh()->status)->toBe(EvaluationAnalysis::WAITING)
        ->and($analysis->course_code)->toBe('SOPH134')
        ->and(QualityReport::query()->count())->toBe(0);

    $this->actingAs($data['officer'])
        ->get('/quality/teaching-evaluation?folder=waiting')
        ->assertInertia(fn (Assert $page) => $page
            ->where('folder', 'waiting')
            ->where('analyses.0.status', 'waiting')
            ->where('analyses.0.assignments', [])
            ->etc());

    // Nothing moves on its own when a lecturer is assigned later.
    $data['assign']($data['unstaffed']);
    expect($analysis->refresh()->status)->toBe(EvaluationAnalysis::WAITING);

    $assignments = $this->actingAs($data['officer'])
        ->get('/quality/teaching-evaluation?folder=waiting')
        ->viewData('page')['props']['analyses'][0]['assignments'];
    expect($assignments)->toHaveCount(1)
        ->and($assignments[0]['course'])->toBe('SOPH134 Pharmaceutical Calculations');

    $this->actingAs($data['officer'])
        ->post("/quality/teaching-evaluation/{$analysis->id}/move-to-draft", ['key' => $assignments[0]['key']])
        ->assertRedirect('/quality/teaching-evaluation');

    expect($analysis->refresh()->status)->toBe(EvaluationAnalysis::DRAFTED)
        ->and(QualityReport::query()->sole()->lecturer_id)->toBe($data['lecturer']->id);
});

it('hides harassment and leak answers from staff below university level', function () {
    $data = evaluationSetup();
    $schoolOfficer = User::factory()->create([
        'university_role' => UniversityRole::QaOfficer,
        'school_id' => $data['school']->id,
    ]);
    $analysis = startAnalysis($this, $data, $schoolOfficer);

    $this->actingAs($schoolOfficer)
        ->get("/quality/teaching-evaluation/{$analysis->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('canSeeSensitive', false)
            ->where('summary.harassmentReports', null)
            ->where('variables', fn ($variables) => collect($variables)->pluck('name')->intersect(['HARASSMENT', 'HARASSMENT_NATURE', 'LEAK_REASON'])->isEmpty())
            ->where('rows.0', fn ($row) => ! collect($row)->has('HARASSMENT'))
            ->etc());

    $this->actingAs($schoolOfficer)
        ->post("/quality/teaching-evaluation/{$analysis->id}/frequencies", ['variables' => ['HARASSMENT'], 'chart' => 'none', 'chartValues' => 'frequencies', 'order' => 'ascending_values', 'multiple' => 'compare'])
        ->assertSessionHasErrors('variables.0');
});

it('keeps each analysis private to the person who started it', function () {
    $data = evaluationSetup();
    $analysis = startAnalysis($this, $data);
    $other = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);

    $this->actingAs($other)->get("/quality/teaching-evaluation/{$analysis->id}")->assertForbidden();
    $this->actingAs($other)->get("/quality/teaching-evaluation/{$analysis->id}/pdf")->assertForbidden();
    $this->actingAs($data['lecturer'])->get('/quality/teaching-evaluation')->assertForbidden();

    $this->actingAs($data['officer'])->delete("/quality/teaching-evaluation/{$analysis->id}")->assertRedirect('/quality/teaching-evaluation');
    expect(EvaluationAnalysis::query()->count())->toBe(0);
});

it('reads Excel files whose answers have numbers attached', function () {
    $data = evaluationSetup();

    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray([
        ['COURSE [Course outline provided]', 'ATTENDANCE [Lecturer was punctual]', 'SEXUAL HARASSMENT [Any sexual advancement?]', 'GRADE'],
        ['Strongly Agree (5)', 5, 'NO (2)', 90],
        ['4 - Agree', '4', 2, 75],
        ['(3) Moderately agree', 'Strongly Disagree', 'Yes', 40],
        ['Strongly Disagree (1)', '2', 'NO', 7],
    ], null, 'A1', true);
    $path = tempnam(sys_get_temp_dir(), 'set').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $this->actingAs($data['officer'])->post('/quality/teaching-evaluation', [
        'academic_year_id' => $data['year']->id,
        'semester_id' => $data['semester']->id,
        'file' => new UploadedFile($path, 'SOPH132.xlsx', test: true),
    ])->assertRedirect();

    $dataset = EvaluationAnalysis::query()->sole()->dataset;

    expect(collect($dataset['variables'])->pluck('role', 'name')->all())->toBe([
        'Q1' => 'likert', 'Q2' => 'likert', 'HARASSMENT' => 'yes_no', 'GRADE' => 'grade', 'GRADE_BAND' => 'grade_band',
    ])
        ->and(array_column($dataset['rows'], 'Q1'))->toBe([5, 4, 3, 1])
        ->and(array_column($dataset['rows'], 'Q2'))->toBe([5, 4, 1, 2])
        ->and(array_column($dataset['rows'], 'HARASSMENT'))->toBe([2, 2, 1, 2])
        ->and($dataset['warnings'])->toHaveCount(1);
});

it('fills the report header from a course chosen in the system', function () {
    $data = evaluationSetup();
    $analysis = startAnalysis($this, $data);

    // The file name "SOPH132 Dispensing.csv" already links the course.
    expect($analysis->course_id)->toBe($data['dispensing']->id)
        ->and($analysis->course_title)->toBe('Dispensing');

    $assignment = $this->actingAs($data['officer'])
        ->get("/quality/teaching-evaluation/{$analysis->id}")
        ->viewData('page')['props']['assignments'][0];
    $key = $assignment['key'];

    // Each assignment carries the level and school of the programme it is for.
    expect($assignment)->level->toBe('100')->school->toBe('SOP');

    // A course with an assigned lecturer brings that lecturer and level.
    $this->actingAs($data['officer'])
        ->patch("/quality/teaching-evaluation/{$analysis->id}/details", [
            'course_id' => $data['dispensing']->id,
            'assignment_key' => $key,
        ])
        ->assertSessionHasNoErrors();

    expect($analysis->refresh())
        ->assignment_key->toBe($key)
        ->lecturer_id->toBe($data['lecturer']->id)
        ->lecturer_name->toBe('Dr Antwi Osei Assebey')
        ->level->toBe('100')
        ->school_label->toBe('SOP');

    // A course with no lecturer keeps the typed name and assigns nobody.
    $this->actingAs($data['officer'])
        ->patch("/quality/teaching-evaluation/{$analysis->id}/details", [
            'course_id' => $data['unstaffed']->id,
            'lecturer_name' => 'Dr Ama Mensah',
            'level' => '200',
        ])
        ->assertSessionHasNoErrors();

    expect($analysis->refresh())
        ->course_code->toBe('SOPH134')
        ->course_title->toBe('Pharmaceutical Calculations')
        ->assignment_key->toBeNull()
        ->lecturer_id->toBeNull()
        ->lecturer_name->toBe('Dr Ama Mensah')
        ->level->toBe('200')
        ->and(DB::table('course_lecturer')->where('course_id', $data['unstaffed']->id)->exists())->toBeFalse();

    // A lecturer key must belong to the chosen course.
    $this->actingAs($data['officer'])
        ->patch("/quality/teaching-evaluation/{$analysis->id}/details", [
            'course_id' => $data['unstaffed']->id,
            'assignment_key' => $key,
        ])
        ->assertSessionHasErrors('assignment_key');

    $this->actingAs($data['officer'])
        ->patch("/quality/teaching-evaluation/{$analysis->id}/details", ['course_id' => 999999])
        ->assertSessionHasErrors('course_id');
});

it('takes the header school and level from the lists in the system', function () {
    $data = evaluationSetup();
    $analysis = startAnalysis($this, $data);

    $props = $this->actingAs($data['officer'])
        ->get("/quality/teaching-evaluation/{$analysis->id}")
        ->viewData('page')['props'];

    expect($props['schools'])->toBe([['label' => 'SOP', 'name' => 'School of Pharmacy']])
        ->and($props['levels'])->toBe(['100', '200', '300', '400', '500', '600', '700', '800', '900', '1000']);

    $this->actingAs($data['officer'])
        ->patch("/quality/teaching-evaluation/{$analysis->id}/details", ['level' => '300', 'school_label' => 'SOP'])
        ->assertSessionHasNoErrors();

    expect($analysis->refresh())->level->toBe('300')->school_label->toBe('SOP');

    $this->actingAs($data['officer'])
        ->patch("/quality/teaching-evaluation/{$analysis->id}/details", ['level' => '150', 'school_label' => 'Made-up School'])
        ->assertSessionHasErrors(['level', 'school_label']);
});

it('offers archived academic years when starting an analysis', function () {
    $data = evaluationSetup();
    $archived = AcademicYear::create(['name' => '2024/2025', 'status' => 'archived']);

    $this->actingAs($data['officer'])
        ->get('/quality/teaching-evaluation')
        ->assertInertia(fn (Assert $page) => $page
            ->where('academicYears.0.name', '2025/2026')
            ->where('academicYears.1.name', '2024/2025 (archived)'));

    $this->actingAs($data['officer'])->post('/quality/teaching-evaluation', [
        'academic_year_id' => $archived->id,
        'semester_id' => $archived->semesters()->firstOrFail()->id,
        'file' => setSample(),
    ])->assertRedirect();

    expect(EvaluationAnalysis::query()->sole()->academic_year_id)->toBe($archived->id);
});

it('saves the SET report into a Document Storage folder', function () {
    $data = evaluationSetup();
    $analysis = startAnalysis($this, $data);
    $folder = DocumentFolder::create(['name' => 'SET reports 2025-2026']);

    $this->actingAs($data['officer'])
        ->get("/quality/teaching-evaluation/{$analysis->id}")
        ->assertInertia(fn (Assert $page) => $page->has('documentFolders', 1));

    $this->actingAs($data['officer'])
        ->post("/quality/teaching-evaluation/{$analysis->id}/save-to-documents", ['folder_id' => $folder->id, 'formats' => ['pdf', 'word']])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $files = DocumentFile::query()->where('folder_id', $folder->id)->orderBy('id')->get();

    expect($files->pluck('name')->all())->toBe(['SOPH132 Dispensing SET.pdf', 'SOPH132 Dispensing SET.docx'])
        ->and(Storage::disk('local')->get($files[0]->path))->toStartWith('%PDF-')
        ->and(Storage::disk('local')->get($files[1]->path))->toStartWith('PK');

    $this->actingAs($data['officer'])
        ->post("/quality/teaching-evaluation/{$analysis->id}/save-to-documents", ['formats' => []])
        ->assertSessionHasErrors('formats');
});
