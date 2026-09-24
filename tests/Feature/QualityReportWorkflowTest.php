<?php

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Department;
use App\Models\QualityReport;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
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
    $supervisor = User::factory()->create(['university_role' => UniversityRole::QaSupervisor]);
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
        'assigned_supervisor_id' => $supervisor->id,
    ]);
    $report->courses()->attach($course);

    return compact('school', 'department', 'year', 'lecturer', 'officer', 'supervisor', 'course', 'report');
}

it('lets a supervisor forward a submitted report with a note', function () {
    ['report' => $report, 'supervisor' => $supervisor] = workflowReport();

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

it('lets a supervisor forward selected and combined reports together', function () {
    ['report' => $report, 'supervisor' => $supervisor, 'course' => $course] = workflowReport();
    $secondReport = $report->replicate();
    $secondReport->title = 'Second program evaluation';
    $secondReport->save();
    $secondReport->courses()->attach($course);

    $this->actingAs($supervisor)
        ->post('/quality/reports/forward-selected', [
            'report_ids' => [$report->id, $secondReport->id],
            'note' => 'Both program reports are ready for director review.',
        ])
        ->assertRedirect('/quality/reports');

    expect($report->refresh()->status)->toBe('under_review')
        ->and($secondReport->refresh()->status)->toBe('under_review')
        ->and($report->reviews()->latest()->first()->action)->toBe('forwarded_to_director')
        ->and($secondReport->reviews()->latest()->first()->note)
        ->toBe('Both program reports are ready for director review.');
});

it('requires a reason when a supervisor returns a report', function () {
    ['report' => $report, 'supervisor' => $supervisor] = workflowReport();

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

it('shows a submitted report only to its assigned supervisor', function () {
    ['report' => $report, 'supervisor' => $assignedSupervisor] = workflowReport();
    $otherSupervisor = User::factory()->create([
        'university_role' => UniversityRole::QaSupervisor,
    ]);

    $this->actingAs($assignedSupervisor)
        ->get('/quality/reports')
        ->assertInertia(fn (Assert $page) => $page->has('reports', 1));

    $this->actingAs($otherSupervisor)
        ->get('/quality/reports')
        ->assertInertia(fn (Assert $page) => $page->has('reports', 0));

    $this->actingAs($otherSupervisor)
        ->get("/quality/reports/{$report->id}")
        ->assertForbidden();

    $this->actingAs($otherSupervisor)
        ->post("/quality/reports/{$report->id}/forward")
        ->assertForbidden();

    $this->actingAs($otherSupervisor)
        ->post("/quality/reports/{$report->id}/return", [
            'note' => 'I should not be able to return this report.',
        ])
        ->assertForbidden();
});

it('lets the system administrator see reports assigned to every supervisor', function () {
    ['report' => $firstReport] = workflowReport();
    $otherSupervisor = User::factory()->create([
        'university_role' => UniversityRole::QaSupervisor,
    ]);
    $secondReport = $firstReport->replicate();
    $secondReport->title = 'Report assigned to another supervisor';
    $secondReport->assigned_supervisor_id = $otherSupervisor->id;
    $secondReport->save();
    $administrator = User::factory()->create([
        'university_role' => UniversityRole::IctAdmin,
    ]);

    $this->actingAs($administrator)
        ->get('/quality/reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 2)
            ->where('canReviewReports', true));

    $this->actingAs($administrator)
        ->get("/quality/reports/{$firstReport->id}")
        ->assertOk();

    $this->actingAs($administrator)
        ->post("/quality/reports/{$secondReport->id}/forward", [
            'note' => 'Administrator oversight review completed.',
        ])
        ->assertRedirect('/quality/reports');
});

it('assigns bulk pushed reports to one selected supervisor', function () {
    [
        'report' => $firstReport,
        'officer' => $officer,
        'supervisor' => $supervisor,
    ] = workflowReport('draft');
    $secondReport = $firstReport->replicate();
    $secondReport->title = 'Second Clinical Practice Evaluation';
    $secondReport->save();

    $this->actingAs($officer)
        ->post('/quality/reports/push-selected', [
            'report_ids' => [$firstReport->id, $secondReport->id],
            'supervisor_id' => $supervisor->id,
        ])
        ->assertRedirect('/quality/reports/create');

    expect($firstReport->refresh())
        ->status->toBe('submitted')
        ->assigned_supervisor_id->toBe($supervisor->id)
        ->and($secondReport->refresh())
        ->status->toBe('submitted')
        ->assigned_supervisor_id->toBe($supervisor->id);
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
        ->assertForbidden();

    $this->actingAs($dean)
        ->get('/quality/print-queue')
        ->assertInertia(fn (Assert $page) => $page->has('reports', 1));

    $this->actingAs($otherLecturer)
        ->get('/quality/print-queue')
        ->assertForbidden();

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

it('allows a QA officer to delete only their own draft upload', function () {
    Storage::fake('public');

    ['officer' => $officer, 'report' => $report] = workflowReport('draft');
    $report->update(['file_path' => 'quality-reports/draft-to-delete.pdf']);
    Storage::disk('public')->put($report->file_path, '%PDF-1.4');

    $otherOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $this->actingAs($otherOfficer)
        ->delete("/quality/reports/{$report->id}")
        ->assertForbidden();

    $this->actingAs($officer)
        ->delete("/quality/reports/{$report->id}")
        ->assertRedirect();

    expect($report->fresh()->trashed())->toBeTrue();
    Storage::disk('public')->assertMissing($report->file_path);
});
