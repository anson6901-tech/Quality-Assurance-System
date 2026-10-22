<?php

use App\Enums\Placement;
use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\QualityReport;
use App\Models\School;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function organization(): array
{
    $nursingSchool = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $medicineSchool = School::create(['name' => 'School of Medicine', 'code' => 'SOM']);
    $nursing = Department::create(['school_id' => $nursingSchool->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $midwifery = Department::create(['school_id' => $nursingSchool->id, 'name' => 'Midwifery', 'code' => 'MIDW']);
    $pathology = Department::create(['school_id' => $medicineSchool->id, 'name' => 'Pathology', 'code' => 'PATH']);

    return compact('nursingSchool', 'medicineSchool', 'nursing', 'midwifery', 'pathology');
}

function reportFor(User $lecturer, string $title, string $status = 'under_review'): QualityReport
{
    return QualityReport::create([
        'title' => $title,
        'category' => 'Academic Quality',
        'status' => $status,
        'academic_year_id' => AcademicYear::firstOrCreate(['name' => '2026/2027'], ['status' => 'active'])->id,
        'school_id' => $lecturer->school_id,
        'department_id' => $lecturer->department_id,
        'lecturer_id' => $lecturer->id,
        'uploaded_by' => $lecturer->id,
    ]);
}

function lecturerIn(Department $department): User
{
    return User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $department->school_id,
        'department_id' => $department->id,
    ]);
}

it('shows the structure with deans and hods derived from placement', function () {
    $org = organization();
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $dean = User::factory()->create(['university_role' => UniversityRole::Dean, 'school_id' => $org['nursingSchool']->id]);
    $hod = User::factory()->create([
        'university_role' => UniversityRole::Hod,
        'school_id' => $org['nursingSchool']->id,
        'department_id' => $org['nursing']->id,
    ]);
    $unplaced = User::factory()->create(['university_role' => UniversityRole::Lecturer]);
    $unplaced->forceFill(['placement' => null])->save();

    $this->actingAs($admin)
        ->get('/quality/organization')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/organization/Index')
            ->where('canPlaceAtUniversity', true)
            ->has('university', 1)
            ->where('schools.1.name', 'School of Nursing')
            ->where('schools.1.head.id', $dean->id)
            ->where('schools.1.departments.1.name', 'Nursing')
            ->where('schools.1.departments.1.head.id', $hod->id)
            ->where('schools.1.departments.0.head', null)
            ->has('unplaced', 1)
            ->where('unplaced.0.id', $unplaced->id));

    expect($org['nursingSchool']->dean->is($dean))->toBeTrue()
        ->and($org['nursing']->hod->is($hod))->toBeTrue();
});

it('places people at university, school and department level without changing their role', function () {
    $org = organization();
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $officer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);

    $this->actingAs($admin)
        ->put("/quality/organization/placements/{$officer->id}", [
            'placement' => 'department',
            'department_id' => $org['nursing']->id,
        ])
        ->assertRedirect('/quality/organization');

    expect($officer->refresh())
        ->placement->toBe(Placement::Department)
        ->school_id->toBe($org['nursingSchool']->id)
        ->department_id->toBe($org['nursing']->id)
        ->university_role->toBe(UniversityRole::QaOfficer->value);

    $this->actingAs($admin)
        ->put("/quality/organization/placements/{$officer->id}", ['placement' => 'school', 'school_id' => $org['medicineSchool']->id])
        ->assertRedirect('/quality/organization');

    expect($officer->refresh())
        ->placement->toBe(Placement::School)
        ->school_id->toBe($org['medicineSchool']->id)
        ->department_id->toBeNull();

    $this->actingAs($admin)
        ->put("/quality/organization/placements/{$officer->id}", ['placement' => 'university'])
        ->assertRedirect('/quality/organization');

    expect($officer->refresh())
        ->placement->toBe(Placement::University)
        ->school_id->toBeNull();

    $this->actingAs($admin)
        ->delete("/quality/organization/placements/{$officer->id}")
        ->assertRedirect('/quality/organization');

    expect($officer->refresh()->placement)->toBeNull();
});

it('allows one dean per school and one hod per department at the right level', function () {
    $org = organization();
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    User::factory()->create(['university_role' => UniversityRole::Dean, 'school_id' => $org['nursingSchool']->id]);
    $secondDean = User::factory()->create(['university_role' => UniversityRole::Dean]);
    $hod = User::factory()->create(['university_role' => UniversityRole::Hod]);

    $this->actingAs($admin)
        ->put("/quality/organization/placements/{$secondDean->id}", ['placement' => 'school', 'school_id' => $org['nursingSchool']->id])
        ->assertInvalid(['placement' => 'already the Dean']);

    $this->actingAs($admin)
        ->put("/quality/organization/placements/{$secondDean->id}", ['placement' => 'department', 'department_id' => $org['pathology']->id])
        ->assertInvalid(['placement' => 'must be placed at a school']);

    $this->actingAs($admin)
        ->put("/quality/organization/placements/{$secondDean->id}", ['placement' => 'school', 'school_id' => $org['medicineSchool']->id])
        ->assertRedirect('/quality/organization');

    $this->actingAs($admin)
        ->put("/quality/organization/placements/{$hod->id}", ['placement' => 'school', 'school_id' => $org['medicineSchool']->id])
        ->assertInvalid(['placement' => 'must be placed at a department']);

    $this->actingAs($admin)
        ->put("/quality/organization/placements/{$hod->id}", ['placement' => 'department', 'department_id' => $org['pathology']->id])
        ->assertRedirect('/quality/organization');

    expect($org['medicineSchool']->dean->is($secondDean))->toBeTrue()
        ->and($org['pathology']->hod->is($hod))->toBeTrue();
});

it('lets the same role see the whole university or only one department depending on placement', function () {
    $org = organization();
    $nursingReport = reportFor(lecturerIn($org['nursing']), 'Nursing report');
    reportFor(lecturerIn($org['pathology']), 'Pathology report');

    $universityDirector = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $departmentDirector = User::factory()->create([
        'university_role' => UniversityRole::QaDirector,
        'school_id' => $org['nursingSchool']->id,
        'department_id' => $org['nursing']->id,
    ]);

    $this->actingAs($universityDirector)
        ->get('/quality/approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('reports', 2));

    $this->actingAs($departmentDirector)
        ->get('/quality/approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 1)
            ->where('reports.0.title', 'Nursing report'));

    $this->actingAs($departmentDirector)
        ->get("/quality/reports/{$nursingReport->id}")
        ->assertOk();
});

it('scopes deans to their school and hods to their department for published reports', function () {
    $org = organization();
    reportFor(lecturerIn($org['nursing']), 'Nursing report', 'approved');
    reportFor(lecturerIn($org['midwifery']), 'Midwifery report', 'approved');
    reportFor(lecturerIn($org['pathology']), 'Pathology report', 'approved');

    $dean = User::factory()->create(['university_role' => UniversityRole::Dean, 'school_id' => $org['nursingSchool']->id]);
    $hod = User::factory()->create([
        'university_role' => UniversityRole::Hod,
        'school_id' => $org['nursingSchool']->id,
        'department_id' => $org['midwifery']->id,
    ]);

    $this->actingAs($dean)
        ->get('/quality/print-queue')
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 2)
            ->has('filterOptions.schools', 1)
            ->has('filterOptions.departments', 2));

    $this->actingAs($hod)
        ->get('/quality/print-queue')
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports', 1)
            ->where('reports.0.title', 'Midwifery report'));
});

it('gives users who are not placed nothing beyond their own work', function () {
    $org = organization();
    reportFor(lecturerIn($org['nursing']), 'Nursing report');
    $director = User::factory()->create(['university_role' => UniversityRole::QaDirector]);
    $director->forceFill(['placement' => null])->save();

    $this->actingAs($director)
        ->get('/quality/approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('reports', 0));

    $this->actingAs($director)->get('/quality/schools')->assertForbidden();
    $this->actingAs($director)->get('/quality/users')->assertForbidden();
    $this->actingAs($director)->get('/quality/organization')->assertForbidden();
});

it('limits an ict admin placed in a department to that department', function () {
    $org = organization();
    $admin = User::factory()->create([
        'university_role' => UniversityRole::IctAdmin,
        'school_id' => $org['nursingSchool']->id,
        'department_id' => $org['nursing']->id,
    ]);
    $nursingLecturer = lecturerIn($org['nursing']);
    $pathologyLecturer = lecturerIn($org['pathology']);

    $this->actingAs($admin)
        ->get('/quality/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('users', fn ($users) => collect($users)->pluck('id')->sort()->values()->all()
                === collect([$admin->id, $nursingLecturer->id])->sort()->values()->all()));

    $this->actingAs($admin)
        ->patch("/quality/users/{$pathologyLecturer->id}", [
            'name' => 'Changed',
            'email' => $pathologyLecturer->email,
            'university_role' => UniversityRole::Lecturer->value,
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->put("/quality/organization/placements/{$nursingLecturer->id}", ['placement' => 'department', 'department_id' => $org['pathology']->id])
        ->assertInvalid(['placement' => 'inside your own area']);

    $this->actingAs($admin)
        ->post('/quality/users', [
            'name' => 'New Nurse',
            'email' => 'new.nurse@example.com',
            'university_role' => UniversityRole::Lecturer->value,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertRedirect('/quality/users');

    expect(User::where('email', 'new.nurse@example.com')->first())
        ->placement->toBe(Placement::Department)
        ->department_id->toBe($org['nursing']->id);

    foreach (['/quality/schools', '/quality/departments', '/quality/courses', '/quality/academic-years', '/quality/roles', '/quality/audit-logs'] as $page) {
        $this->actingAs($admin)->get($page)->assertForbidden();
    }

    $this->actingAs($admin)
        ->get('/quality/organization')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canPlaceAtUniversity', false)
            ->where('canPlaceAtSchool', false)
            ->where('university', null)
            ->has('schools', 1)
            ->has('schools.0.departments', 1)
            ->where('schools.0.departments.0.name', 'Nursing'));
});

it('does not let anyone change their own placement', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    $this->actingAs($admin)
        ->delete("/quality/organization/placements/{$admin->id}")
        ->assertStatus(422);

    expect($admin->refresh()->placement)->toBe(Placement::University);
});

it('keeps people with their department when it moves to another school', function () {
    $org = organization();
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $lecturer = lecturerIn($org['midwifery']);

    $this->actingAs($admin)
        ->patch("/quality/departments/{$org['midwifery']->id}", [
            'school_id' => $org['medicineSchool']->id,
            'name' => 'Midwifery',
            'code' => 'MIDW',
        ])
        ->assertRedirect('/quality/departments');

    expect($lecturer->refresh()->school_id)->toBe($org['medicineSchool']->id);
});
