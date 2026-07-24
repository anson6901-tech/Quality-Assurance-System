<?php

use App\Enums\UniversityRole;
use App\Models\Course;
use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use App\Models\User;

it('allows an administrator to update a department', function () {
    $administrator = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create([
        'school_id' => $school->id,
        'name' => 'Old Department',
        'code' => 'OLD',
    ]);

    $this->actingAs($administrator)
        ->patch("/quality/departments/{$department->id}", [
            'school_id' => $school->id,
            'name' => 'Updated Department',
            'code' => 'NEW',
            'hod_id' => null,
        ])
        ->assertRedirect('/quality/departments');

    expect($department->refresh())
        ->name->toBe('Updated Department')
        ->code->toBe('NEW');
});

it('deletes a department and its catalogue without deleting users', function () {
    $administrator = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create([
        'school_id' => $school->id,
        'name' => 'Department to Delete',
        'code' => 'DEL',
    ]);
    $program = Program::create([
        'department_id' => $department->id,
        'name' => 'Nursing',
        'code' => 'BNUR',
    ]);
    Course::create([
        'department_id' => $department->id,
        'program_id' => $program->id,
        'code' => 'NURS 101',
        'title' => 'Foundations of Nursing',
    ]);
    $departmentUser = User::factory()->create([
        'school_id' => $school->id,
        'department_id' => $department->id,
        'university_role' => UniversityRole::Lecturer,
    ]);

    $this->actingAs($administrator)
        ->delete("/quality/departments/{$department->id}")
        ->assertRedirect('/quality/departments');

    expect(Department::find($department->id))->toBeNull()
        ->and(Program::where('department_id', $department->id)->exists())->toBeFalse()
        ->and(Course::where('department_id', $department->id)->exists())->toBeFalse()
        ->and($departmentUser->refresh()->department_id)->toBeNull();
});
