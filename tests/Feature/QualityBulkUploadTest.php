<?php

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\CourseSplit;
use App\Models\Department;
use App\Models\Program;
use App\Models\QualityReport;
use App\Models\ReportImportBatch;
use App\Models\ReportImportItem;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function bulkSetup(): array
{
    Storage::fake('local');
    Storage::fake('public');

    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $nursing = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $midwifery = Department::create(['school_id' => $school->id, 'name' => 'Midwifery', 'code' => 'MIDW']);
    $bscn = Program::create(['department_id' => $nursing->id, 'name' => 'BSc Nursing', 'code' => 'BSCN', 'max_level' => 400]);
    $bscm = Program::create(['department_id' => $midwifery->id, 'name' => 'BSc Midwifery', 'code' => 'BSCM', 'max_level' => 400]);
    $year = AcademicYear::create(['name' => '2026/2027', 'status' => 'active']);
    $semester = $year->semesters()->firstOrFail();

    $grace = User::factory()->create(['name' => 'Grace Appiah', 'staff_id' => 'SON-1001', 'university_role' => UniversityRole::Lecturer]);
    $samuel = User::factory()->create(['name' => 'Samuel Tetteh', 'staff_id' => 'SON-1002', 'university_role' => UniversityRole::Lecturer]);
    $unassigned = User::factory()->create(['name' => 'Daniel Ofori', 'staff_id' => 'SOM-1001', 'university_role' => UniversityRole::Lecturer]);
    $officer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);

    $foundations = Course::create(['code' => 'NURS101', 'title' => 'Foundations of Nursing', 'level' => '100']);
    $anatomy = Course::create(['code' => 'ANAT 102', 'title' => 'Human Anatomy', 'level' => '100']);

    foreach ([[$foundations, $bscn], [$anatomy, $bscn], [$anatomy, $bscm]] as [$course, $program]) {
        CourseOffering::create(['course_id' => $course->id, 'program_id' => $program->id, 'semester_id' => $semester->id, 'level' => '100']);
    }

    // Anatomy is split by program, and Samuel teaches both parts.
    CourseSplit::create(['course_id' => $anatomy->id, 'semester_id' => $semester->id, 'mode' => 'level_program']);

    $assign = fn (Course $course, Program $program, User $lecturer) => DB::table('course_lecturer')->insert([
        'course_id' => $course->id,
        'program_id' => $program->id,
        'semester_id' => $semester->id,
        'lecturer_id' => $lecturer->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $assign($foundations, $bscn, $grace);
    $assign($anatomy, $bscn, $samuel);
    $assign($anatomy, $bscm, $samuel);

    return compact('school', 'nursing', 'midwifery', 'bscn', 'bscm', 'year', 'semester', 'grace', 'samuel', 'unassigned', 'officer', 'foundations', 'anatomy');
}

function pdf(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.7\nfake report\n%%EOF");
}

function newBatch($test, array $data): ReportImportBatch
{
    $test->actingAs($data['officer'])->post('/quality/bulk-upload/batches', [
        'academic_year_id' => $data['year']->id,
        'semester_id' => $data['semester']->id,
        'category' => 'Academic Quality',
    ])->assertRedirect();

    return ReportImportBatch::query()->latest('id')->firstOrFail();
}

function uploadFile($test, User $user, ReportImportBatch $batch, UploadedFile $file): array
{
    return $test->actingAs($user)
        ->postJson("/quality/bulk-upload/batches/{$batch->id}/files", ['file' => $file])
        ->assertOk()
        ->json('item');
}

it('downloads a checklist of the reports each assigned lecturer needs', function () {
    $data = bulkSetup();

    $csv = $this->actingAs($data['officer'])
        ->get("/quality/bulk-upload/checklist?academic_year_id={$data['year']->id}&semester_id={$data['semester']->id}")
        ->assertOk()
        ->streamedContent();

    expect($csv)
        ->toContain('file_name,course_code,course_title,part,lecturer,staff_id,report_exists')
        ->toContain('NURS101_SON-1001.pdf,NURS101,"Foundations of Nursing"')
        ->toContain('ANAT102_BSCM-LEVEL-100_SON-1002.pdf')
        ->toContain('ANAT102_BSCN-LEVEL-100_SON-1002.pdf')
        ->not->toContain('SOM-1001');
});

it('matches files by name and explains the ones it cannot use', function () {
    $data = bulkSetup();
    $batch = newBatch($this, $data);

    expect(uploadFile($this, $data['officer'], $batch, pdf('nurs101_son-1001.pdf')))
        ->status->toBe('ready')
        ->lecturer->toBe('Grace Appiah')
        ->and(uploadFile($this, $data['officer'], $batch, pdf('ANAT102_BSCM-LEVEL-100_SON-1002.pdf')))
        ->status->toBe('ready')
        ->part->toBe('BSCM · Level 100')
        ->and(uploadFile($this, $data['officer'], $batch, pdf('ANAT 102_SON-1002.pdf')))
        ->status->toBe('needs_choice')
        ->candidates->toHaveCount(2)
        ->and(uploadFile($this, $data['officer'], $batch, pdf('NURS101_SOM-1001.pdf')))
        ->status->toBe('error')
        ->problem->toContain('Daniel Ofori is not assigned to NURS101')
        ->and(uploadFile($this, $data['officer'], $batch, pdf('NURS999_SON-1001.pdf')))
        ->problem->toContain('No course with the code NURS999')
        ->and(uploadFile($this, $data['officer'], $batch, pdf('NURS101_XYZ-9.pdf')))
        ->problem->toContain('No staff member with the ID XYZ-9')
        ->and(uploadFile($this, $data['officer'], $batch, pdf('NURS101_SON-1001 (1).pdf')))
        ->problem->toContain('Same course part and lecturer');

    $notPdf = UploadedFile::fake()->createWithContent('NURS101_SON-1001.pdf', 'not really a pdf');
    expect(uploadFile($this, $data['officer'], $batch, $notPdf))
        ->status->toBe('error')
        ->problem->toBe('This file is not a valid PDF.');
});

it('lets the officer choose the part, then creates drafts like a single upload', function () {
    $data = bulkSetup();
    $batch = newBatch($this, $data);
    uploadFile($this, $data['officer'], $batch, pdf('NURS101_SON-1001.pdf'));
    $ambiguous = uploadFile($this, $data['officer'], $batch, pdf('ANAT102_SON-1002.pdf'));

    $bscnKey = ReportImportItem::find($ambiguous['id'])->candidates;
    $bscnKey = collect($bscnKey)->first(fn ($candidate) => str_contains($candidate['partLabel'], 'BSCN'))['key'];

    $this->actingAs($data['officer'])
        ->patch("/quality/bulk-upload/batches/{$batch->id}/items/{$ambiguous['id']}", ['key' => $bscnKey])
        ->assertRedirect();
    expect(ReportImportItem::find($ambiguous['id'])->status)->toBe('ready');

    $this->actingAs($data['officer'])
        ->post("/quality/bulk-upload/batches/{$batch->id}/drafts")
        ->assertRedirect();

    $reports = QualityReport::query()->orderBy('lecturer_id')->get();
    expect($reports)->toHaveCount(2)
        ->and($reports[0])
        ->status->toBe('draft')
        ->lecturer_id->toBe($data['grace']->id)
        ->program_id->toBe($data['bscn']->id)
        ->department_id->toBe($data['nursing']->id)
        ->semester_id->toBe($data['semester']->id)
        ->uploaded_by->toBe($data['officer']->id)
        ->title->toBe('NURS101 Foundations of Nursing — Grace Appiah')
        ->and($reports[0]->courses->pluck('id')->all())->toBe([$data['foundations']->id])
        ->and($reports[1])
        ->lecturer_id->toBe($data['samuel']->id)
        ->program_id->toBe($data['bscn']->id);

    Storage::disk('public')->assertExists($reports[0]->file_path);
    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(ReportImportItem::query()->where('status', 'created')->count())->toBe(2);

    // The same file again is now recognised as already reported.
    expect(uploadFile($this, $data['officer'], newBatch($this, $data), pdf('NURS101_SON-1001.pdf')))
        ->problem->toBe('A report already exists for this course part and lecturer.');
});

it('submits the batch drafts to a supervisor', function () {
    $data = bulkSetup();
    $supervisor = User::factory()->create(['university_role' => UniversityRole::QaSupervisor]);
    $batch = newBatch($this, $data);
    uploadFile($this, $data['officer'], $batch, pdf('NURS101_SON-1001.pdf'));
    $this->actingAs($data['officer'])->post("/quality/bulk-upload/batches/{$batch->id}/drafts");

    $this->actingAs($data['officer'])
        ->get("/quality/bulk-upload?batch={$batch->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/bulk-upload/Index')
            ->where('batch.draftReports', 1)
            ->has('batch.items', 1));

    $this->actingAs($data['officer'])
        ->post("/quality/bulk-upload/batches/{$batch->id}/submit", ['supervisor_id' => $supervisor->id])
        ->assertRedirect();

    expect(QualityReport::first())
        ->status->toBe('submitted')
        ->assigned_supervisor_id->toBe($supervisor->id);
});

it('only matches courses in the uploader area and keeps batches private', function () {
    $data = bulkSetup();
    $midwiferyOfficer = User::factory()->create([
        'university_role' => UniversityRole::QaOfficer,
        'school_id' => $data['school']->id,
        'department_id' => $data['midwifery']->id,
    ]);

    $batch = newBatch($this, [...$data, 'officer' => $midwiferyOfficer]);

    expect(uploadFile($this, $midwiferyOfficer, $batch, pdf('NURS101_SON-1001.pdf')))
        ->problem->toContain('not in a program inside your area')
        ->and(uploadFile($this, $midwiferyOfficer, $batch, pdf('ANAT102_SON-1002.pdf')))
        ->status->toBe('ready')
        ->part->toBe('BSCM · Level 100');

    $this->actingAs($data['officer'])
        ->postJson("/quality/bulk-upload/batches/{$batch->id}/files", ['file' => pdf('NURS101_SON-1001.pdf')])
        ->assertForbidden();

    $unplaced = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $unplaced->forceFill(['placement' => null])->save();
    $this->actingAs($unplaced)->get('/quality/bulk-upload')->assertForbidden();
    $this->actingAs($data['grace'])->get('/quality/bulk-upload')->assertForbidden();
});
