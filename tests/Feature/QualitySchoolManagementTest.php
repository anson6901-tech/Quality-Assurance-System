<?php

use App\Enums\UniversityRole;
use App\Models\Department;
use App\Models\School;
use App\Models\User;

it('allows an administrator to update a school', function () {
    $administrator = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $school = School::create(['name' => 'Old School', 'code' => 'OLD']);

    $this->actingAs($administrator)
        ->patch("/quality/schools/{$school->id}", [
            'name' => 'Updated School',
            'code' => 'NEW',
            'dean_id' => null,
        ])
        ->assertRedirect('/quality/schools');

    expect($school->refresh())
        ->name->toBe('Updated School')
        ->code->toBe('NEW');
});

it('deletes a school and its departments without deleting users', function () {
    $administrator = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $school = School::create(['name' => 'School to Delete', 'code' => 'DEL']);
    Department::create([
        'school_id' => $school->id,
        'name' => 'Department to Delete',
        'code' => 'DEP',
    ]);
    $schoolUser = User::factory()->create([
        'school_id' => $school->id,
        'university_role' => UniversityRole::Dean,
    ]);

    $this->actingAs($administrator)
        ->delete("/quality/schools/{$school->id}")
        ->assertRedirect('/quality/schools');

    expect(School::find($school->id))->toBeNull()
        ->and(Department::where('school_id', $school->id)->exists())->toBeFalse()
        ->and($schoolUser->refresh()->school_id)->toBeNull();
});
