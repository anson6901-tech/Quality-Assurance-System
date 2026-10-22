<?php

use App\Enums\UniversityRole;
use App\Models\Department;
use App\Models\QualityRolePermission;
use App\Models\School;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows separate role and permission modules to users with manage user access', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    $this->actingAs($admin)
        ->get('/quality/roles')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/roles/Index')
            ->has('roles', count(UniversityRole::cases()))
            ->where('roles.0.value', UniversityRole::QaOfficer->value)
            ->has('roles.0.permissionCount')
            ->has('roles.0.userCount'));

    $this->actingAs($admin)
        ->get('/quality/permissions?role='.UniversityRole::QaOfficer->value)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/permissions/Index')
            ->has('roles', count(UniversityRole::cases()))
            ->has('permissionGroups', 7)
            ->where('selectedRole', UniversityRole::QaOfficer->value)
            ->where('permissionGroups.0.label', 'Upload Reports')
            ->where('permissionGroups.0.permissions.0.value', 'view_reports')
            ->where('permissionGroups.0.permissions.1.value', 'upload_reports'));

    $this->actingAs($admin)
        ->get('/quality/roles-rights')
        ->assertRedirect('/quality/roles');
});

it('repairs stale system role rows instead of showing duplicate roles', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    QualityRolePermission::query()->create([
        'role' => UniversityRole::Dean->value,
        'label' => null,
        'permissions' => ['view_area_reports'],
        'is_system' => false,
    ]);

    $this->actingAs($admin)
        ->get('/quality/roles')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/roles/Index')
            ->has('roles', count(UniversityRole::cases()))
            ->where('roles.4.value', UniversityRole::Dean->value)
            ->where('roles.4.isSystem', true));

    $deanRole = QualityRolePermission::query()
        ->where('role', UniversityRole::Dean->value)
        ->first();

    expect($deanRole?->is_system)->toBeTrue()
        ->and($deanRole?->label)->toBe(UniversityRole::Dean->label());
});

it('updates role rights and applies approval action access checks', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $qaOfficer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);

    $this->actingAs($qaOfficer)
        ->get('/quality/approvals')
        ->assertForbidden();

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

it('allows system role rights to be turned off', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    QualityRolePermission::ensureDefaults();

    $this->actingAs($admin)
        ->patch('/quality/roles-rights/'.UniversityRole::QaOfficer->value, [
            'role' => UniversityRole::QaOfficer->value,
        ])
        ->assertRedirect('/quality/roles-rights');

    expect(QualityRolePermission::roleHas(UniversityRole::QaOfficer, 'view_reports'))->toBeFalse()
        ->and(QualityRolePermission::roleHas(UniversityRole::QaOfficer, 'upload_reports'))->toBeFalse()
        ->and(QualityRolePermission::roleHas(UniversityRole::QaOfficer, 'view_approval_queue'))->toBeFalse();
});

it('creates custom roles and allows users to be assigned to them', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);

    $this->actingAs($admin)
        ->post('/quality/roles-rights', [
            'label' => 'Faculty QA Reviewer',
            'permissions' => ['view_area_reports', 'view_print_queue'],
        ])
        ->assertRedirect('/quality/roles-rights');

    expect(QualityRolePermission::query()->where('role', 'faculty_qa_reviewer')->exists())->toBeTrue();

    $this->actingAs($admin)
        ->post('/quality/users', [
            'name' => 'Reviewer User',
            'email' => 'reviewer@example.com',
            'university_role' => 'faculty_qa_reviewer',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertRedirect('/quality/users');

    $reviewer = User::query()->where('email', 'reviewer@example.com')->first();

    $this->actingAs($admin)
        ->put("/quality/organization/placements/{$reviewer->id}", [
            'placement' => 'department',
            'department_id' => $department->id,
        ])
        ->assertRedirect('/quality/organization');

    $reviewer->refresh();

    expect($reviewer?->university_role)->toBe('faculty_qa_reviewer')
        ->and($reviewer?->department_id)->toBe($department->id)
        ->and($reviewer?->canViewPrintQueue())->toBeTrue();
});

it('deletes custom roles that are not assigned to users', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    QualityRolePermission::query()->create([
        'role' => 'duplicate_role',
        'label' => 'Duplicate Role',
        'permissions' => ['view_print_queue'],
        'is_system' => false,
    ]);

    $this->actingAs($admin)
        ->delete('/quality/roles-rights/duplicate_role')
        ->assertRedirect('/quality/roles-rights');

    expect(QualityRolePermission::query()->where('role', 'duplicate_role')->exists())->toBeFalse();
});

it('does not delete system roles or roles currently assigned to users', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    QualityRolePermission::ensureDefaults();

    QualityRolePermission::query()->create([
        'role' => 'assigned_custom_role',
        'label' => 'Assigned Custom Role',
        'permissions' => ['view_print_queue'],
        'is_system' => false,
    ]);

    User::factory()->create(['university_role' => 'assigned_custom_role']);

    $this->actingAs($admin)
        ->delete('/quality/roles-rights/'.UniversityRole::Dean->value)
        ->assertStatus(409);

    $this->actingAs($admin)
        ->delete('/quality/roles-rights/assigned_custom_role')
        ->assertStatus(409);

    expect(QualityRolePermission::query()->where('role', UniversityRole::Dean->value)->exists())->toBeTrue()
        ->and(QualityRolePermission::query()->where('role', 'assigned_custom_role')->exists())->toBeTrue();
});

it('keeps role rights away from users without manage user access', function () {
    $lecturer = User::factory()->create(['university_role' => UniversityRole::Lecturer]);

    $this->actingAs($lecturer)
        ->get('/quality/roles-rights')
        ->assertForbidden();
});
