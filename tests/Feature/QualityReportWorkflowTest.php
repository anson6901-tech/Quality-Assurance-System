<?php

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Department;
use App\Models\QualityReport;
use App\Models\School;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function workflowReport(string $status = 'submitted'): array
{
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create([
        'school_id' => $school->id,
        'name' => 'Nursing',
        'code' => 'NURS',
    ]);
    $year = AcademicYear::create(['name' => '2026/2027', 'status' => 'active']);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $officer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $course = Course::create([
        'department_id' => $department->id,
        'code' => 'NURS 401',
        'title' => 'Clinical Practice',
    ]);
    $report = QualityReport::create([
        'title' => 'Clinical Practice Evaluation',
        'category' => 'Academic Quality',
        'status' => $status,
        'academic_year_id' => $year->id,
        'semester_id' => $year->semesters()->first()->id,
        'school_id' => $school->id,
        'department_id' => $department->id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $officer->id,
    ]);
    $report->courses()->attach($course);

    return compact('school', 'department', 'year', 'lecturer', 'officer', 'course', 'report');
}

it('lets a supervisor forward a submitted report with a note', function () {
    ['report' => $report] = workflowReport();
    $supervisor = User::factory()->create(['university_role' => UniversityRole::QaSupervisor]);

    $this->actingAs($supervisor)
        ->get('/quality/reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 1)
            ->where('canReviewReports', true)
            ->where('canApproveReports', false));

    $this->actingAs($supervisor)
        ->post("/quality/reports/{$report->id}/forward", [
            'note' => 'All checks completed. Ready for final review.',
        ])
        ->assertRedirect('/quality/reports');

    expect($report->refresh()->status)->toBe('under_review')
        ->and($report->reviews()->first()->action)->toBe('forwarded_to_director')
        ->and($report->reviews()->first()->note)->toBe('All checks completed. Ready for final review.');
});

it('requires a reason when a supervisor returns a report', function () {
    ['report' => $report] = workflowReport();
    $supervisor = User::factory()->create(['university_role' => UniversityRole::QaSupervisor]);

    $this->actingAs($supervisor)
        ->post("/quality/reports/{$report->id}/return")
        ->assertInvalid('note');

    $this->actingAs($supervisor)
        ->post("/quality/reports/{$report->id}/return", [
            'note' => 'Replace the unsigned cover page.',
        ])
        ->assertRedirect('/quality/reports');

    expect($report->refresh()->status)->toBe('draft')
        ->and($report->reviews()->first()->action)->toBe('returned_to_officer');
});

it('lets the director return or publish a supervisor-reviewed report', function () {
    ['report' => $report] = workflowReport('under_review');
    $director = User::factory()->create(['university_role' => UniversityRole::QaDirector]);

    $this->actingAs($director)
        ->post("/quality/reports/{$report->id}/return", [
            'note' => 'Supervisor should confirm the course totals.',
        ])
        ->assertRedirect('/quality/approvals');

    expect($report->refresh()->status)->toBe('submitted')
        ->and($report->reviews()->first()->action)->toBe('returned_to_supervisor');

    $report->update(['status' => 'under_review']);

    $this->actingAs($director)
        ->post("/quality/reports/{$report->id}/approve")
        ->assertRedirect('/quality/print-queue');

    expect($report->refresh()->status)->toBe('approved')
        ->and($report->reviews()->first()->action)->toBe('published');
});

it('shows a published report only to its lecturer and school dean', function () {
    ['school' => $school, 'department' => $department, 'lecturer' => $lecturer, 'report' => $report] = workflowReport('approved');
    $dean = User::factory()->create([
        'university_role' => UniversityRole::Dean,
        'school_id' => $school->id,
    ]);
    $otherLecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    $this->actingAs($lecturer)
        ->get('/quality/print-queue')
        ->assertInertia(fn (Assert $page) => $page->has('reports', 1));

    $this->actingAs($dean)
        ->get('/quality/print-queue')
        ->assertInertia(fn (Assert $page) => $page->has('reports', 1));

    $this->actingAs($otherLecturer)
        ->get('/quality/print-queue')
        ->assertInertia(fn (Assert $page) => $page->has('reports', 0));

    $this->actingAs($otherLecturer)
        ->get("/quality/reports/{$report->id}")
        ->assertForbidden();
});

it('soft deletes a report and retains the directors reason', function () {
    ['report' => $report] = workflowReport('under_review');
    $director = User::factory()->create(['university_role' => UniversityRole::QaDirector]);

    $this->actingAs($director)
        ->delete("/quality/reports/{$report->id}", [
            'note' => 'This upload belongs to the wrong lecturer.',
        ])
        ->assertRedirect('/quality/approvals');

    $deleted = QualityReport::withTrashed()->findOrFail($report->id);

    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->reviews()->first()->action)->toBe('deleted')
        ->and($deleted->reviews()->first()->note)->toBe('This upload belongs to the wrong lecturer.');
});
