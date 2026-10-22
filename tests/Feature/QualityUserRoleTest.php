<?php

use App\Enums\Placement;
use App\Enums\UniversityRole;
use App\Models\Department;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

it('shows document roles on the user management page', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    $this->actingAs($admin)
        ->get('/quality/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/users/Index')
            ->where('roles.1.value', UniversityRole::QaSupervisor->value)
            ->where('roles.3.value', UniversityRole::IctAdmin->value)
            ->where('roles.4.value', UniversityRole::Dean->value)
            ->where('roles.5.value', UniversityRole::Hod->value)
            ->where('roles.7.value', UniversityRole::AppointmentCommittee->value));
});

it('creates users unplaced so they are positioned on the organizational structure', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);

    $this->actingAs($admin)
        ->post('/quality/users', [
            'name' => 'Dean User',
            'email' => 'dean@example.com',
            'university_role' => UniversityRole::Dean->value,
            'school_id' => $school->id,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertRedirect('/quality/users');

    expect(User::where('email', 'dean@example.com')->first())
        ->placement->toBeNull()
        ->school_id->toBeNull()
        ->department_id->toBeNull();
});

it('updates a user role without changing their placement', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create([
        'school_id' => $school->id,
        'name' => 'Nursing',
        'code' => 'NURS',
    ]);
    $user = User::factory()->create([
        'name' => 'Old User',
        'email' => 'old@example.com',
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);

    $this->actingAs($admin)
        ->patch("/quality/users/{$user->id}", [
            'name' => 'Edited User',
            'email' => 'edited@example.com',
            'university_role' => UniversityRole::Hod->value,
        ])
        ->assertRedirect('/quality/users');

    $user->refresh();

    expect($user->name)->toBe('Edited User')
        ->and($user->email)->toBe('edited@example.com')
        ->and($user->university_role)->toBe(UniversityRole::Hod->value)
        ->and($user->placement)->toBe(Placement::Department)
        ->and($user->department_id)->toBe($department->id);
});

it('blocks a role change that breaks the one-head-per-unit rule', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    User::factory()->create([
        'university_role' => UniversityRole::Hod,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $schoolOfficer = User::factory()->create([
        'university_role' => UniversityRole::QaOfficer,
        'school_id' => $school->id,
    ]);

    $this->actingAs($admin)
        ->patch("/quality/users/{$lecturer->id}", [
            'name' => $lecturer->name,
            'email' => $lecturer->email,
            'university_role' => UniversityRole::Hod->value,
        ])
        ->assertInvalid('placement');

    $this->actingAs($admin)
        ->patch("/quality/users/{$schoolOfficer->id}", [
            'name' => $schoolOfficer->name,
            'email' => $schoolOfficer->email,
            'university_role' => UniversityRole::Hod->value,
        ])
        ->assertInvalid('placement');

    expect($lecturer->refresh()->university_role)->toBe(UniversityRole::Lecturer->value);
});

it('derives a lecturer school from the assigned department on the users page', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create([
        'school_id' => $school->id,
        'name' => 'Nursing',
        'code' => 'NURS',
    ]);
    User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => null,
        'department_id' => $department->id,
    ]);

    $this->actingAs($admin)
        ->get('/quality/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('users', fn ($users) => collect($users)->contains(
                fn ($user) => $user['role'] === UniversityRole::Lecturer->value
                    && $user['school']['name'] === 'School of Nursing'
                    && $user['department']['name'] === 'Nursing',
            )));
});

it('resets a user password from user management', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $user = User::factory()->create(['password' => Hash::make('old-password')]);

    $this->actingAs($admin)
        ->patch("/quality/users/{$user->id}/password", [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertRedirect('/quality/users');

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

it('allows an administrator to deactivate and reactivate another user', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $user = User::factory()->create(['is_active' => true]);

    $this->actingAs($admin)
        ->patch("/quality/users/{$user->id}/status", ['is_active' => false])
        ->assertRedirect('/quality/users');

    expect($user->refresh()->is_active)->toBeFalse();

    $this->actingAs($admin)
        ->patch("/quality/users/{$user->id}/status", ['is_active' => true])
        ->assertRedirect('/quality/users');

    expect($user->refresh()->is_active)->toBeTrue();
});

it('does not allow an administrator to deactivate or delete their own account', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    $this->actingAs($admin)
        ->patch("/quality/users/{$admin->id}/status", ['is_active' => false])
        ->assertStatus(422);

    $this->actingAs($admin)
        ->delete("/quality/users/{$admin->id}")
        ->assertStatus(422);

    expect($admin->fresh())->not->toBeNull()
        ->and($admin->fresh()->is_active)->toBeTrue();
});

it('allows an administrator to delete another user', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->delete("/quality/users/{$user->id}")
        ->assertRedirect('/quality/users');

    expect(User::find($user->id))->toBeNull();
});
