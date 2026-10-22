<?php

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Program;
use App\Models\QualityReport;
use App\Models\QualityRolePermission;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

it('shows active academic years on the report upload page', function () {
    $user = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    AcademicYear::create(['name' => '2024/2025', 'status' => 'archived']);

    $this->actingAs($user)
        ->get('/quality/reports/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/reports/Create')
            ->has('academicYears', 1)
            ->where('academicYears.0.name', '2025/2026'));
});

it('does not show the upload action on the reports list for officers', function () {
    $user = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);

    $this->actingAs($user)
        ->get('/quality/reports')
        ->assertOk()
        ->assertDontSee('Upload report');
});

it('shows both upload and reports sidebar items for roles that can upload and review', function () {
    $user = User::factory()->create(['university_role' => UniversityRole::QaDirector]);

    $this->actingAs($user)
        ->get('/quality/reports')
        ->assertOk()
        ->assertSee('Upload Reports')
        ->assertSee('Reports');
});

it('allows an upload-only role to view course assignments for report planning', function () {
    $user = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);

    $this->actingAs($user)
        ->get('/quality/course-assignments')
        ->assertOk();
});

it('shows an empty course assignment page before academic years are configured', function () {
    $user = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    $this->actingAs($user)
        ->get('/quality/course-assignments')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/course-assignments/Index')
            ->has('academicYears', 0)
            ->has('courses', 0)
            ->where('filters.academicYearId', null)
            ->where('filters.semesterId', null));
});

it('allows an upload-only role to upload reports for courses with assigned lecturers', function () {
    $user = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $course = Course::create([
        'department_id' => $department->id,
        'code' => 'NURS 401',
        'title' => 'Clinical Nursing Practice',
    ]);

    $lecturer->assignedCourses()->attach($course);

    $this->actingAs($user)
        ->get('/quality/reports/create')
        ->assertOk();

    $this->actingAs($user)
        ->post('/quality/reports', [
            'title' => 'Upload-only report',
            'category' => 'Academic Quality',
            'academic_year_id' => $academicYear->id,
            'course_ids' => [$course->id],
        ])
        ->assertRedirect('/quality/reports/create');

    expect(QualityReport::where('title', 'Upload-only report')->exists())->toBeTrue();
});

it('shows only courses with assigned lecturers on the report upload page', function () {
    $user = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);

    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $courseWithLecturer = Course::create([
        'department_id' => $department->id,
        'code' => 'NURS 401',
        'title' => 'Clinical Nursing Practice',
    ]);
    $courseWithoutLecturer = Course::create([
        'department_id' => $department->id,
        'code' => 'NURS 402',
        'title' => 'Nursing Leadership',
    ]);

    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    $program = Program::create(['department_id' => $department->id, 'name' => 'BSc Nursing', 'code' => 'BSN', 'max_level' => 400]);
    $semester = $academicYear->semesters()->firstOrFail();

    foreach ([$courseWithLecturer, $courseWithoutLecturer] as $course) {
        CourseOffering::create(['course_id' => $course->id, 'program_id' => $program->id, 'semester_id' => $semester->id]);
    }

    $lecturer->assignedCourses()->attach($courseWithLecturer, [
        'program_id' => $program->id,
        'semester_id' => $semester->id,
    ]);

    $this->actingAs($user)
        ->get('/quality/reports/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/reports/Create')
            ->has('courses', 1)
            ->where('courses.0.code', 'NURS 401')
            ->where('courses.0.title', 'Clinical Nursing Practice'));
});

it('stores another report for the same lecturer and course in an active academic year', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $course = Course::create([
        'department_id' => $department->id,
        'code' => 'NURS 301',
        'title' => 'Adult Health Nursing',
    ]);

    $lecturer->assignedCourses()->attach($course);

    QualityReport::create([
        'title' => 'First audit',
        'category' => 'Academic Quality',
        'status' => 'submitted',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ])->courses()->attach($course);

    $this->actingAs($qaDirector)
        ->post('/quality/reports', [
            'title' => 'Second audit',
            'category' => 'Academic Quality',
            'academic_year_id' => $academicYear->id,
            'course_ids' => [$course->id],
        ])
        ->assertRedirect('/quality/reports/create');

    expect(QualityReport::where('lecturer_id', $lecturer->id)->count())->toBe(2);
});

it('uploads one file for every combined program offering with its assigned lecturer', function () {
    Storage::fake('public');

    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $semester = $academicYear->semesters()->firstOrFail();
    $officer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $course = Course::create([
        'department_id' => $department->id,
        'code' => 'NURS 305',
        'title' => 'Combined Nursing Practice',
    ]);
    $programs = collect([
        Program::create(['department_id' => $department->id, 'name' => 'BSc Nursing', 'code' => 'BSCN', 'max_level' => 400]),
        Program::create(['department_id' => $department->id, 'name' => 'BSc Midwifery', 'code' => 'BSCM', 'max_level' => 400]),
    ]);
    $lecturers = collect([
        User::factory()->create(['university_role' => UniversityRole::Lecturer]),
        User::factory()->create(['university_role' => UniversityRole::Lecturer]),
    ]);

    $programs->each(function (Program $program, int $index) use ($course, $semester, $lecturers): void {
        CourseOffering::create([
            'course_id' => $course->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'level' => '300',
        ]);
        DB::table('course_lecturer')->insert([
            'course_id' => $course->id,
            'lecturer_id' => $lecturers[$index]->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($officer)
        ->post('/quality/reports', [
            'title' => 'Combined program report',
            'category' => 'Academic Quality',
            'academic_year_id' => $academicYear->id,
            'semester_id' => $semester->id,
            'program_ids' => $programs->pluck('id')->all(),
            'course_ids' => [$course->id],
            'report_file' => UploadedFile::fake()->create('combined.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect('/quality/reports/create');

    expect(QualityReport::query()->count())->toBe(2)
        ->and(QualityReport::query()->pluck('program_id')->sort()->values()->all())
        ->toBe($programs->pluck('id')->sort()->values()->all())
        ->and(QualityReport::query()->pluck('lecturer_id')->sort()->values()->all())
        ->toBe($lecturers->pluck('id')->sort()->values()->all());
});

it('does not store a report for a course that is not assigned to the lecturer', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $course = Course::create([
        'department_id' => $department->id,
        'code' => 'NURS 401',
        'title' => 'Clinical Nursing Practice',
    ]);

    $this->actingAs($qaOfficer)
        ->post('/quality/reports', [
            'title' => 'Catalog course audit',
            'category' => 'Academic Quality',
            'academic_year_id' => $academicYear->id,
            'course_ids' => [$course->id],
        ])
        ->assertInvalid('course_ids');

    expect(QualityReport::where('title', 'Catalog course audit')->exists())->toBeFalse();
});

it('stores a submitted report with an uploaded PDF file', function () {
    Storage::fake('public');

    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $course = Course::create([
        'department_id' => $department->id,
        'code' => 'MIDW 212',
        'title' => 'Maternal and Newborn Care',
    ]);

    $lecturer->assignedCourses()->attach($course);

    $this->actingAs($qaDirector)
        ->post('/quality/reports', [
            'title' => 'Uploaded PDF report',
            'category' => 'Academic Quality',
            'academic_year_id' => $academicYear->id,
            'course_ids' => [$course->id],
            'summary' => 'Reviewed in the system.',
            'report_file' => UploadedFile::fake()->createWithContent(
                'report.pdf',
                '%PDF-1.4',
            ),
        ])
        ->assertRedirect('/quality/reports/create');

    $report = QualityReport::where('title', 'Uploaded PDF report')->first();

    expect($report)->not->toBeNull()
        ->and($report?->status)->toBe('draft')
        ->and($report?->semester_id)->toBe($academicYear->semesters()->first()->id)
        ->and($report?->file_path)->not->toBeNull();

    Storage::disk('public')->assertExists($report->file_path);
});

it('keeps uploads in draft until the officer pushes them to the supervisor', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $supervisor = User::factory()->create(['university_role' => UniversityRole::QaSupervisor]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $course = Course::create([
        'department_id' => $department->id,
        'code' => 'MIDW 213',
        'title' => 'Community Health Nursing',
    ]);

    $lecturer->assignedCourses()->attach($course);

    $this->actingAs($qaOfficer)
        ->post('/quality/reports', [
            'title' => 'Awaiting review',
            'category' => 'Academic Quality',
            'academic_year_id' => $academicYear->id,
            'course_ids' => [$course->id],
        ])
        ->assertRedirect('/quality/reports/create');

    $report = QualityReport::where('title', 'Awaiting review')->firstOrFail();

    expect($report->status)->toBe('draft')
        ->and($report->reviews()->first()->action)->toBe('uploaded')
        ->and($report->reviews()->first()->actor_id)->toBe($qaOfficer->id);

    $this->actingAs($qaOfficer)
        ->post("/quality/reports/{$report->id}/push", [
            'supervisor_id' => $supervisor->id,
        ])
        ->assertRedirect('/quality/reports');

    expect($report->refresh()->status)->toBe('submitted')
        ->and($report->assigned_supervisor_id)->toBe($supervisor->id)
        ->and($report->reviews()->first()->action)->toBe('submitted_to_supervisor')
        ->and($report->reviews()->first()->actor_id)->toBe($qaOfficer->id);

    $this->actingAs($supervisor)
        ->get('/quality/reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/reports/Index')
            ->has('reports', 1)
            ->where('reports.0.title', 'Awaiting review'));
});

it('does not allow uploads into archived academic years', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2024/2025', 'status' => 'archived']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $course = Course::create([
        'department_id' => $department->id,
        'code' => 'NURS 301',
        'title' => 'Adult Health Nursing',
    ]);

    $lecturer->assignedCourses()->attach($course);

    $this->actingAs($qaDirector)
        ->post('/quality/reports', [
            'title' => 'Archived year audit',
            'category' => 'Academic Quality',
            'academic_year_id' => $academicYear->id,
            'course_ids' => [$course->id],
        ])
        ->assertInvalid('academic_year_id');
});

it('does not allow reports to be uploaded directly into approval', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $course = Course::create([
        'department_id' => $department->id,
        'code' => 'NURS 301',
        'title' => 'Adult Health Nursing',
    ]);

    $lecturer->assignedCourses()->attach($course);

    $this->actingAs($qaOfficer)
        ->post('/quality/reports', [
            'title' => 'Bypassed approval',
            'category' => 'Academic Quality',
            'status' => 'under_review',
            'academic_year_id' => $academicYear->id,
            'course_ids' => [$course->id],
        ])
        ->assertRedirect('/quality/reports/create');

    expect(QualityReport::where('title', 'Bypassed approval')->first()?->status)
        ->toBe('draft');
});

it('submits a draft report to the supervisor queue and locks it', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $supervisor = User::factory()->create(['university_role' => UniversityRole::QaSupervisor]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    $report = QualityReport::create([
        'title' => 'Ready for approval',
        'category' => 'Academic Quality',
        'status' => 'draft',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaOfficer->id,
    ]);

    $this->actingAs($qaOfficer)
        ->post("/quality/reports/{$report->id}/push", [
            'supervisor_id' => $supervisor->id,
        ])
        ->assertRedirect('/quality/reports');

    expect($report->refresh()->status)->toBe('submitted');
    expect($report->assigned_supervisor_id)->toBe($supervisor->id);

    $this->actingAs($supervisor)
        ->get("/quality/reports/{$report->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/reports/Show')
            ->where('canPushReport', false)
            ->where('report.isLocked', true));
});

it('shows director-stage and published reports on the approvals page', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    QualityReport::create([
        'title' => 'Waiting approval',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    QualityReport::create([
        'title' => 'Already approved',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    QualityReport::create([
        'title' => 'Still being prepared',
        'category' => 'Academic Quality',
        'status' => 'submitted',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $this->actingAs($qaDirector)
        ->get('/quality/approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/reports/Index')
            ->where('approvalQueue', true)
            ->where('reviewStage', 'director')
            ->has('reports', 2)
            ->where('reports.0.title', 'Waiting approval')
            ->where('reports.1.title', 'Already approved')
            ->where('reports.0.isLocked', true));
});

it('filters approvals by lecturer academic year school and department', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $otherSchool = School::create(['name' => 'School of Medicine', 'code' => 'SOM']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $otherDepartment = Department::create(['school_id' => $otherSchool->id, 'name' => 'Medicine', 'code' => 'MED']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $otherAcademicYear = AcademicYear::create(['name' => '2026/2027', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $otherLecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $otherSchool->id,
        'department_id' => $otherDepartment->id,
    ]);

    QualityReport::create([
        'title' => 'Filtered report',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    QualityReport::create([
        'title' => 'Other report',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $otherAcademicYear->id,
        'school_id' => $otherSchool->id,
        'department_id' => $otherDepartment->id,
        'lecturer_id' => $otherLecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $this->actingAs($qaDirector)
        ->get('/quality/approvals?'.http_build_query([
            'lecturer_id' => $lecturer->id,
            'academic_year_id' => $academicYear->id,
            'school_id' => $school->id,
            'department_id' => $department->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/reports/Index')
            ->where('filters.lecturer_id', (string) $lecturer->id)
            ->where('filters.academic_year_id', (string) $academicYear->id)
            ->where('filters.school_id', (string) $school->id)
            ->where('filters.department_id', (string) $department->id)
            ->has('filterOptions.lecturers', 2)
            ->has('reports', 1)
            ->where('reports.0.title', 'Filtered report'));
});

it('filters the reports workspace by workflow location', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    QualityReport::create([
        'title' => 'Still in reports',
        'category' => 'Academic Quality',
        'status' => 'submitted',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaOfficer->id,
    ]);

    QualityReport::create([
        'title' => 'At approval',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaOfficer->id,
    ]);

    $this->actingAs($qaOfficer)
        ->get('/quality/reports?status=under_review')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'under_review')
            ->has('reports', 1)
            ->where('reports.0.title', 'At approval')
            ->where('reports.0.location', 'Review Queue'));
});

it('shows uploaded reports across workflow locations in the reports workspace', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    QualityReport::create([
        'title' => 'Waiting approval',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    QualityReport::create([
        'title' => 'Still in reports',
        'category' => 'Academic Quality',
        'status' => 'submitted',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $this->actingAs($qaDirector)
        ->get('/quality/reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/reports/Index')
            ->where('approvalQueue', false)
            ->has('reports', 2)
            ->where('reports.0.title', 'Waiting approval')
            ->where('reports.0.location', 'Review Queue')
            ->where('reports.1.title', 'Still in reports')
            ->where('reports.1.location', 'Review Queue')
            ->has('filterOptions.statuses', 4));
});

it('only shows unpushed reports to the user who uploaded them', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $uploader = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $otherOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    QualityReport::create([
        'title' => 'My working report',
        'category' => 'Academic Quality',
        'status' => 'submitted',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $uploader->id,
    ]);

    $this->actingAs($uploader)
        ->get('/quality/reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 1)
            ->where('reports.0.title', 'My working report'));

    $this->actingAs($otherOfficer)
        ->get('/quality/reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 0));
});

it('controls report viewing and uploading with separate rights', function () {
    $qaOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);

    QualityRolePermission::query()->updateOrCreate(
        ['role' => UniversityRole::QaOfficer->value],
        [
            'label' => UniversityRole::QaOfficer->label(),
            'permissions' => ['view_reports'],
            'is_system' => true,
        ],
    );

    $this->actingAs($qaOfficer)
        ->get('/quality/reports')
        ->assertOk();

    $this->actingAs($qaOfficer)
        ->get('/quality/reports/create')
        ->assertForbidden();

    QualityRolePermission::query()
        ->where('role', UniversityRole::QaOfficer->value)
        ->update(['permissions' => json_encode(['upload_reports'])]);

    $this->actingAs($qaOfficer)
        ->get('/quality/reports')
        ->assertForbidden();

    $this->actingAs($qaOfficer)
        ->get('/quality/reports/create')
        ->assertOk();
});

it('reserves the approvals queue for supervisors and directors', function () {
    $qaOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);

    $this->actingAs($qaOfficer)
        ->get('/quality/approvals')
        ->assertForbidden();

    $this->actingAs($qaDirector)
        ->get('/quality/approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/reports/Index')
            ->where('canApproveReports', true));
});

it('scopes dean approval rights to reports for lecturers in their school', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $otherSchool = School::create(['name' => 'School of Medicine', 'code' => 'SOM']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $otherDepartment = Department::create(['school_id' => $otherSchool->id, 'name' => 'Medicine', 'code' => 'MED']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $dean = User::factory()->create([
        'university_role' => UniversityRole::Dean,
        'school_id' => $school->id,
    ]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $otherLecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $otherSchool->id,
        'department_id' => $otherDepartment->id,
    ]);

    QualityRolePermission::query()->updateOrCreate(
        ['role' => UniversityRole::Dean->value],
        [
            'label' => UniversityRole::Dean->label(),
            'permissions' => ['view_area_reports', 'view_print_queue', 'view_approval_queue', 'approve_reports'],
            'is_system' => true,
        ],
    );

    $schoolLecturerReport = QualityReport::create([
        'title' => 'Dean school lecturer report',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $otherSchool->id,
        'department_id' => $otherDepartment->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $otherSchoolLecturerReport = QualityReport::create([
        'title' => 'Other school lecturer report',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $otherLecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $this->actingAs($dean)
        ->get('/quality/approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('approvalQueue', true)
            ->where('canApproveReports', true)
            ->has('reports', 1)
            ->where('reports.0.title', 'Dean school lecturer report'));

    $this->actingAs($dean)
        ->post("/quality/reports/{$schoolLecturerReport->id}/approve")
        ->assertRedirect('/quality/print-queue');

    $this->actingAs($dean)
        ->post("/quality/reports/{$otherSchoolLecturerReport->id}/approve")
        ->assertForbidden();

    expect($schoolLecturerReport->refresh()->status)->toBe('approved')
        ->and($otherSchoolLecturerReport->refresh()->status)->toBe('under_review');
});

it('does not show the internal approval queue to deans by default', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $otherSchool = School::create(['name' => 'School of Medicine', 'code' => 'SOM']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $otherDepartment = Department::create(['school_id' => $otherSchool->id, 'name' => 'Medicine', 'code' => 'MED']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $dean = User::factory()->create([
        'university_role' => UniversityRole::Dean,
        'school_id' => $school->id,
    ]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $otherLecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $otherSchool->id,
        'department_id' => $otherDepartment->id,
    ]);

    QualityReport::create([
        'title' => 'Dean default approval',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $otherSchool->id,
        'department_id' => $otherDepartment->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    QualityReport::create([
        'title' => 'Other school approval',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $otherLecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $this->actingAs($dean)
        ->get('/quality/approvals')
        ->assertForbidden();
});

it('does not show the internal approval queue to HODs', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $otherDepartment = Department::create(['school_id' => $school->id, 'name' => 'Midwifery', 'code' => 'MIDW']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $hod = User::factory()->create([
        'university_role' => UniversityRole::Hod,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $otherLecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $otherDepartment->id,
    ]);

    QualityRolePermission::query()->updateOrCreate(
        ['role' => UniversityRole::Hod->value],
        [
            'label' => UniversityRole::Hod->label(),
            'permissions' => ['view_area_reports', 'view_approval_queue', 'view_print_queue'],
            'is_system' => true,
        ],
    );

    QualityReport::create([
        'title' => 'HOD department approval',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $otherDepartment->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    QualityReport::create([
        'title' => 'Other department approval',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $otherLecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $this->actingAs($hod)
        ->get('/quality/approvals')
        ->assertForbidden();
});

it('serves uploaded report PDFs through the protected preview route', function () {
    Storage::fake('public');

    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $uploader = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $otherOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    Storage::disk('public')->put('quality-reports/report.pdf', '%PDF-1.4');

    $report = QualityReport::create([
        'title' => 'PDF report',
        'category' => 'Academic Quality',
        'status' => 'submitted',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'file_path' => 'quality-reports/report.pdf',
        'uploaded_by' => $uploader->id,
    ]);

    $this->actingAs($uploader)
        ->get("/quality/reports/{$report->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.fileUrl', url("/quality/reports/{$report->id}/file")));

    $this->actingAs($uploader)
        ->get("/quality/reports/{$report->id}/file")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($otherOfficer)
        ->get("/quality/reports/{$report->id}/file")
        ->assertForbidden();
});

it('approves reports into the print queue', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    $report = QualityReport::create([
        'title' => 'Ready to approve',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $this->actingAs($qaDirector)
        ->post("/quality/reports/{$report->id}/approve")
        ->assertRedirect('/quality/print-queue');

    expect($report->refresh()->status)->toBe('approved');

    $this->actingAs($qaDirector)
        ->get('/quality/print-queue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/reports/Index')
            ->where('printQueue', true)
            ->has('reports', 1)
            ->where('reports.0.title', 'Ready to approve'));
});

it('prevents QA officers from approving reports', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    $report = QualityReport::create([
        'title' => 'View only report',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaOfficer->id,
    ]);

    $this->actingAs($qaOfficer)
        ->post("/quality/reports/{$report->id}/approve")
        ->assertForbidden();

    expect($report->refresh()->status)->toBe('under_review');
});

it('pushes reports back to the QA officer before approval', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $qaOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    $report = QualityReport::create([
        'title' => 'Needs corrections',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaOfficer->id,
    ]);

    $this->actingAs($qaDirector)
        ->post("/quality/reports/{$report->id}/return", [
            'note' => 'The supervisor must request corrections from the officer.',
        ])
        ->assertRedirect('/quality/approvals');

    expect($report->refresh()->status)->toBe('submitted');

    $this->actingAs($qaOfficer)
        ->get('/quality/reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 1)
            ->where('reports.0.title', 'Needs corrections')
            ->where('reports.0.location', 'Review Queue'));
});

it('does not push back approved reports or allow view-only approvers to push back', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $qaOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    $approvedReport = QualityReport::create([
        'title' => 'Approved cannot push back',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $underReviewReport = QualityReport::create([
        'title' => 'View-only cannot push back',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $this->actingAs($qaDirector)
        ->post("/quality/reports/{$approvedReport->id}/return")
        ->assertStatus(409);

    $this->actingAs($qaOfficer)
        ->post("/quality/reports/{$underReviewReport->id}/return")
        ->assertForbidden();

    expect($approvedReport->refresh()->status)->toBe('approved')
        ->and($underReviewReport->refresh()->status)->toBe('under_review');
});

it('deletes reports from the approvals queue before they are approved', function () {
    Storage::fake('public');

    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    Storage::disk('public')->put('quality-reports/delete-me.pdf', '%PDF-1.4');

    $report = QualityReport::create([
        'title' => 'Delete before approval',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'file_path' => 'quality-reports/delete-me.pdf',
        'uploaded_by' => $qaDirector->id,
    ]);

    $this->actingAs($qaDirector)
        ->delete("/quality/reports/{$report->id}", [
            'note' => 'The report was uploaded against the wrong course.',
        ])
        ->assertRedirect('/quality/approvals');

    expect(QualityReport::query()->whereKey($report->id)->exists())->toBeFalse();
    Storage::disk('public')->assertMissing('quality-reports/delete-me.pdf');
});

it('does not delete approved reports or allow view-only approvers to delete', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $qaOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    $approvedReport = QualityReport::create([
        'title' => 'Approved report cannot be deleted',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $underReviewReport = QualityReport::create([
        'title' => 'View-only cannot delete',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $this->actingAs($qaDirector)
        ->delete("/quality/reports/{$approvedReport->id}")
        ->assertStatus(409);

    $this->actingAs($qaOfficer)
        ->delete("/quality/reports/{$underReviewReport->id}")
        ->assertForbidden();

    expect($approvedReport->fresh())->not->toBeNull()
        ->and($underReviewReport->fresh())->not->toBeNull();
});

it('only shows approved reports in the print queue', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    QualityReport::create([
        'title' => 'Approved for print',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    QualityReport::create([
        'title' => 'Still under review',
        'category' => 'Academic Quality',
        'status' => 'under_review',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $this->actingAs($qaDirector)
        ->get('/quality/print-queue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/reports/Index')
            ->where('printQueue', true)
            ->has('reports', 1)
            ->where('reports.0.title', 'Approved for print'));
});

it('shows hods every approved report in the department they are placed in', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $hod = User::factory()->create([
        'university_role' => UniversityRole::Hod,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    foreach ([[$hod, 'HOD approved report'], [$lecturer, 'Lecturer approved report']] as [$owner, $title]) {
        QualityReport::create([
            'title' => $title,
            'category' => 'Academic Quality',
            'status' => 'approved',
            'academic_year_id' => $academicYear->id,
            'school_id' => $school->id,
            'department_id' => $department->id,
            'lecturer_id' => $owner->id,
            'uploaded_by' => $qaDirector->id,
        ]);
    }

    $this->actingAs($hod)
        ->get('/quality/print-queue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 2)
            ->has('filterOptions.departments', 1)
            ->where('filterOptions.departments.0.id', $department->id));
});

it('filters the print queue by lecturer academic year school and department', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $otherSchool = School::create(['name' => 'School of Medicine', 'code' => 'SOM']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $otherDepartment = Department::create(['school_id' => $otherSchool->id, 'name' => 'Medicine', 'code' => 'MED']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $otherAcademicYear = AcademicYear::create(['name' => '2026/2027', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $otherLecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $otherSchool->id,
        'department_id' => $otherDepartment->id,
    ]);

    QualityReport::create([
        'title' => 'Filtered print report',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    QualityReport::create([
        'title' => 'Other print report',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $otherAcademicYear->id,
        'school_id' => $otherSchool->id,
        'department_id' => $otherDepartment->id,
        'lecturer_id' => $otherLecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $this->actingAs($qaDirector)
        ->get('/quality/print-queue?'.http_build_query([
            'lecturer_id' => $lecturer->id,
            'academic_year_id' => $academicYear->id,
            'school_id' => $school->id,
            'department_id' => $department->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/reports/Index')
            ->where('printQueue', true)
            ->where('filters.lecturer_id', (string) $lecturer->id)
            ->has('filterOptions.lecturers', 2)
            ->has('reports', 1)
            ->where('reports.0.title', 'Filtered print report')
            ->where('reports.0.location', 'Published'));
});

it('applies document role-based access to approved reports', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $otherSchool = School::create(['name' => 'School of Medicine', 'code' => 'SOM']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $otherDepartment = Department::create(['school_id' => $otherSchool->id, 'name' => 'Medicine', 'code' => 'MED']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $dean = User::factory()->create([
        'university_role' => UniversityRole::Dean,
        'school_id' => $school->id,
    ]);
    $hod = User::factory()->create([
        'university_role' => UniversityRole::Hod,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $committeeMember = User::factory()->create(['university_role' => UniversityRole::AppointmentCommittee]);
    $ictAdmin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $otherLecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $otherSchool->id,
        'department_id' => $otherDepartment->id,
    ]);

    QualityReport::create([
        'title' => 'Nursing approved report',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $academicYear->id,
        'school_id' => $otherSchool->id,
        'department_id' => $otherDepartment->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    QualityReport::create([
        'title' => 'Medicine approved report',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $otherLecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $this->actingAs($dean)
        ->get('/quality/print-queue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 1)
            ->where('reports.0.title', 'Nursing approved report'));

    $this->actingAs($hod)
        ->get('/quality/print-queue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 1)
            ->where('reports.0.title', 'Nursing approved report'));

    $this->actingAs($lecturer)
        ->get('/quality/print-queue')
        ->assertForbidden();

    $this->actingAs($committeeMember)
        ->get('/quality/print-queue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('reports', 2));

    $this->actingAs($ictAdmin)
        ->get('/quality/print-queue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('reports', 2));

    $this->actingAs($dean)
        ->get('/quality/print-queue?'.http_build_query([
            'school_id' => $school->id,
            'department_id' => $department->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 1)
            ->where('reports.0.title', 'Nursing approved report'));

    $this->actingAs($hod)
        ->get('/quality/print-queue?'.http_build_query([
            'school_id' => $school->id,
            'department_id' => $department->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 1)
            ->where('reports.0.title', 'Nursing approved report'));
});

it('allows scoped users to download only their approved print queue reports', function () {
    Storage::fake('public');

    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $otherSchool = School::create(['name' => 'School of Medicine', 'code' => 'SOM']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $otherDepartment = Department::create(['school_id' => $otherSchool->id, 'name' => 'Medicine', 'code' => 'MED']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $dean = User::factory()->create([
        'university_role' => UniversityRole::Dean,
        'school_id' => $school->id,
    ]);

    Storage::disk('public')->put('quality-reports/nursing.pdf', '%PDF-1.4');
    Storage::disk('public')->put('quality-reports/medicine.pdf', '%PDF-1.4');

    $nursingReport = QualityReport::create([
        'title' => 'Nursing Print Report',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'file_path' => 'quality-reports/nursing.pdf',
        'uploaded_by' => $qaDirector->id,
    ]);

    $medicineReport = QualityReport::create([
        'title' => 'Medicine Print Report',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $academicYear->id,
        'school_id' => $otherSchool->id,
        'department_id' => $otherDepartment->id,
        'file_path' => 'quality-reports/medicine.pdf',
        'uploaded_by' => $qaDirector->id,
    ]);

    $this->actingAs($dean)
        ->get("/quality/reports/{$nursingReport->id}/download")
        ->assertOk()
        ->assertDownload('nursing-print-report.pdf');

    $this->actingAs($dean)
        ->get("/quality/reports/{$medicineReport->id}/download")
        ->assertForbidden();
});

it('shows lecturers reports assigned directly or through their courses', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $courseLecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $otherLecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $course = Course::create([
        'department_id' => $department->id,
        'code' => 'NURS 401',
        'title' => 'Clinical Practice',
    ]);
    $course->lecturers()->attach($courseLecturer);

    QualityRolePermission::query()->updateOrCreate(
        ['role' => UniversityRole::Lecturer->value],
        [
            'label' => UniversityRole::Lecturer->label(),
            'permissions' => ['view_own_reports', 'view_print_queue'],
            'is_system' => true,
        ],
    );

    $directReport = QualityReport::create([
        'title' => 'Direct lecturer report',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $courseReport = QualityReport::create([
        'title' => 'Course responsibility report',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'uploaded_by' => $qaDirector->id,
    ]);
    $courseReport->courses()->attach($course);

    QualityReport::create([
        'title' => 'Other lecturer report',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $otherLecturer->id,
        'uploaded_by' => $qaDirector->id,
    ])->courses()->attach($course);

    $this->actingAs($lecturer)
        ->get('/quality/print-queue?'.http_build_query([
            'lecturer_id' => $otherLecturer->id,
            'academic_year_id' => 999,
            'school_id' => 999,
            'department_id' => 999,
        ]))
        ->assertForbidden();

    $this->actingAs($courseLecturer)
        ->get('/quality/print-queue')
        ->assertForbidden();
});

it('unapproves reports and removes them from the print queue', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    $report = QualityReport::create([
        'title' => 'Approved report',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    $this->actingAs($qaDirector)
        ->post("/quality/reports/{$report->id}/unapprove")
        ->assertRedirect('/quality/approvals');

    expect($report->refresh()->status)->toBe('under_review');

    $this->actingAs($qaDirector)
        ->get('/quality/print-queue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('printQueue', true)
            ->has('reports', 0));
});
