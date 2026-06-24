<?php

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Department;
use App\Models\QualityReport;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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

it('shows courses from the course catalog on the report upload page', function () {
    $user = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);

    AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    Course::create([
        'department_id' => $department->id,
        'code' => 'NURS 401',
        'title' => 'Clinical Nursing Practice',
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
            'school_id' => $school->id,
            'department_id' => $department->id,
            'lecturer_id' => $lecturer->id,
            'course_ids' => [$course->id],
        ])
        ->assertRedirect('/quality/reports');

    expect(QualityReport::where('lecturer_id', $lecturer->id)->count())->toBe(2);
});

it('stores a report with a catalog course that is not assigned to the lecturer', function () {
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
            'school_id' => $school->id,
            'department_id' => $department->id,
            'lecturer_id' => $lecturer->id,
            'course_ids' => [$course->id],
        ])
        ->assertRedirect('/quality/reports');

    expect(QualityReport::where('title', 'Catalog course audit')->first()?->courses)
        ->toHaveCount(1);
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

    $this->actingAs($qaDirector)
        ->post('/quality/reports', [
            'title' => 'Uploaded PDF report',
            'category' => 'Academic Quality',
            'academic_year_id' => $academicYear->id,
            'lecturer_id' => $lecturer->id,
            'course_ids' => [$course->id],
            'summary' => 'Reviewed in the system.',
            'report_file' => UploadedFile::fake()->createWithContent(
                'report.pdf',
                '%PDF-1.4',
            ),
        ])
        ->assertRedirect('/quality/reports');

    $report = QualityReport::where('title', 'Uploaded PDF report')->first();

    expect($report)->not->toBeNull()
        ->and($report?->status)->toBe('submitted')
        ->and($report?->file_path)->not->toBeNull();

    Storage::disk('public')->assertExists($report->file_path);
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
            'school_id' => $school->id,
            'department_id' => $department->id,
            'lecturer_id' => $lecturer->id,
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
            'school_id' => $school->id,
            'department_id' => $department->id,
            'lecturer_id' => $lecturer->id,
            'course_ids' => [$course->id],
        ])
        ->assertRedirect('/quality/reports');

    expect(QualityReport::where('title', 'Bypassed approval')->first()?->status)
        ->toBe('submitted');
});

it('pushes a report into the approvals queue and locks it', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $academicYear = AcademicYear::create(['name' => '2025/2026', 'status' => 'active']);
    $qaOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    $report = QualityReport::create([
        'title' => 'Ready for approval',
        'category' => 'Academic Quality',
        'status' => 'submitted',
        'academic_year_id' => $academicYear->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaOfficer->id,
    ]);

    $this->actingAs($qaOfficer)
        ->post("/quality/reports/{$report->id}/push")
        ->assertRedirect('/quality/approvals');

    expect($report->refresh()->status)->toBe('under_review');

    $this->actingAs($qaDirector)
        ->get("/quality/reports/{$report->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/reports/Show')
            ->where('canPushReport', false)
            ->where('report.isLocked', true));
});

it('shows pushed reports on the approvals page', function () {
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
            ->where('reports.0.location', 'Approval'));
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
            ->where('reports.0.location', 'Approval')
            ->where('reports.1.title', 'Still in reports')
            ->where('reports.1.location', 'Report Module')
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

it('allows QA officers to view approvals without approving reports', function () {
    $qaOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $qaDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);

    $this->actingAs($qaOfficer)
        ->get('/quality/approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/reports/Index')
            ->where('approvalQueue', true)
            ->where('canApproveReports', false));

    $this->actingAs($qaDirector)
        ->get('/quality/approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/reports/Index')
            ->where('canApproveReports', true));
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
            ->where('reports.0.location', 'Print Queue'));
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
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $qaDirector->id,
    ]);

    QualityReport::create([
        'title' => 'Medicine approved report',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $academicYear->id,
        'school_id' => $otherSchool->id,
        'department_id' => $otherDepartment->id,
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
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 1)
            ->where('reports.0.title', 'Nursing approved report'));

    $this->actingAs($committeeMember)
        ->get('/quality/print-queue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('reports', 2));

    $this->actingAs($ictAdmin)
        ->get('/quality/print-queue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('reports', 2));
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
    $course = Course::create([
        'department_id' => $department->id,
        'code' => 'NURS 401',
        'title' => 'Clinical Practice',
    ]);
    $course->lecturers()->attach($courseLecturer);

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

    $this->actingAs($lecturer)
        ->get('/quality/print-queue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 1)
            ->where('reports.0.title', $directReport->title));

    $this->actingAs($courseLecturer)
        ->get('/quality/print-queue')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 1)
            ->where('reports.0.title', $courseReport->title));
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
