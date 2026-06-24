<?php

use App\Enums\UniversityRole;
use App\Models\Department;
use App\Models\QualityRolePermission;
use App\Models\School;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows role rights to users with manage user access', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    $this->actingAs($admin)
        ->get('/quality/roles-rights')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/roles-rights/Index')
            ->has('roles', count(UniversityRole::cases()))
            ->where('roles.0.value', UniversityRole::QaOfficer->value)
            ->has('permissionOptions'));
});

it('updates role rights and applies approval action access checks', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $qaOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);

    $this->actingAs($qaOfficer)
        ->get('/quality/approvals')
        ->assertOk();

    $this->actingAs($admin)
        ->patch('/quality/roles-rights/'.UniversityRole::QaOfficer->value, [
            'role' => UniversityRole::QaOfficer->value,
            'permissions' => ['upload_reports', 'view_approval_queue', 'approve_reports'],
        ])
        ->assertRedirect('/quality/roles-rights');

    expect(QualityRolePermission::roleHas(UniversityRole::QaOfficer, 'approve_reports'))->toBeTrue();

    $this->actingAs($qaOfficer)
        ->get('/quality/approvals')
        ->assertOk();
});

it('creates custom roles and allows users to be assigned to them', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);

    $this->actingAs($admin)
        ->post('/quality/roles-rights', [
            'label' => 'Faculty QA Reviewer',
            'permissions' => ['view_department_reports', 'view_print_queue'],
        ])
        ->assertRedirect('/quality/roles-rights');

    expect(QualityRolePermission::query()->where('role', 'faculty_qa_reviewer')->exists())->toBeTrue();

    $this->actingAs($admin)
        ->post('/quality/users', [
            'name' => 'Reviewer User',
            'email' => 'reviewer@example.com',
            'university_role' => 'faculty_qa_reviewer',
            'department_id' => $department->id,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertRedirect('/quality/users');

    $reviewer = User::query()->where('email', 'reviewer@example.com')->first();

    expect($reviewer?->university_role)->toBe('faculty_qa_reviewer')
        ->and($reviewer?->department_id)->toBe($department->id)
        ->and($reviewer?->canViewPrintQueue())->toBeTrue();
});

it('keeps role rights away from users without manage user access', function () {
    $lecturer = User::factory()->create(['university_role' => UniversityRole::Lecturer]);

    $this->actingAs($lecturer)
        ->get('/quality/roles-rights')
        ->assertForbidden();
});
