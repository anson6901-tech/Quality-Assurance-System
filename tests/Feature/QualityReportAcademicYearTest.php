<?php

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Department;
use App\Models\QualityReport;
use App\Models\School;
use App\Models\User;
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
            'status' => 'submitted',
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
            'status' => 'submitted',
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
            'status' => 'submitted',
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
        ->assertInvalid('status');
});

it('pushes a report into the approvals queue and locks it', function () {
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

    $this->actingAs($qaOfficer)
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
            ->has('reports', 1)
            ->where('reports.0.title', 'Waiting approval')
            ->where('reports.0.isLocked', true));
});
