<?php

use App\Enums\UniversityRole;
use App\Models\Course;
use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use App\Models\User;

test('structure administrators can create reusable catalogue courses without a program owner', function () {
    $administrator = User::factory()->create([
        'university_role' => UniversityRole::IctAdmin,
    ]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create([
        'school_id' => $school->id,
        'name' => 'Nursing',
        'code' => 'NURS',
    ]);

    $this->actingAs($administrator)
        ->post('/quality/programs', [
            'department_id' => $department->id,
            'name' => 'Bachelor of Science in Nursing',
            'code' => 'BSCN',
            'max_level' => 400,
        ])
        ->assertRedirect('/quality/programs');

    $program = Program::where('code', 'BSCN')->firstOrFail();

    $this->actingAs($administrator)
        ->post('/quality/courses', [
            'department_id' => $department->id,
            'code' => 'NURS101',
            'title' => 'Foundations of Nursing',
            'course_type' => 'normal',
            'level' => '100',
            'credit_hours' => 3,
        ])
        ->assertRedirect('/quality/courses');

    expect(Course::where([
        'code' => 'NURS101',
    ])->exists())->toBeTrue();
    expect(Course::where('code', 'NURS101')->value('program_id'))->toBeNull();
});

test('a catalogue course does not require a program selection', function () {
    $administrator = User::factory()->create([
        'university_role' => UniversityRole::IctAdmin,
    ]);
    $school = School::create(['name' => 'School of Health', 'code' => 'SOH']);
    $department = Department::create([
        'school_id' => $school->id,
        'name' => 'Nursing',
        'code' => 'NURS',
    ]);
    $otherDepartment = Department::create([
        'school_id' => $school->id,
        'name' => 'Midwifery',
        'code' => 'MIDW',
    ]);
    $program = Program::create([
        'department_id' => $otherDepartment->id,
        'name' => 'Bachelor of Midwifery',
        'code' => 'BMID',
    ]);

    $this->actingAs($administrator)
        ->from('/quality/courses')
        ->post('/quality/courses', [
            'department_id' => $department->id,
            'code' => 'NURS101',
            'title' => 'Foundations of Nursing',
            'course_type' => 'normal',
            'level' => '100',
        ])
        ->assertRedirect('/quality/courses')
        ->assertSessionHasNoErrors();
});

test('the course import template uses valid department and program codes', function () {
    $administrator = User::factory()->create([
        'university_role' => UniversityRole::IctAdmin,
    ]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create([
        'school_id' => $school->id,
        'name' => 'Nursing',
        'code' => 'NURS',
    ]);
    Program::create([
        'department_id' => $department->id,
        'name' => 'Bachelor of Science in Nursing',
        'code' => 'BSCN',
    ]);

    $response = $this->actingAs($administrator)
        ->get('/quality/courses/import-template')
        ->assertOk()
        ->assertDownload('course-import-template.csv');

    expect($response->streamedContent())
        ->toContain('department_code,code,title,course_type,level,credit_hours')
        ->toContain('NURS,,,normal,100,3');
});

test('a reusable course can be catalogued before deciding which program will use it', function () {
    $administrator = User::factory()->create([
        'university_role' => UniversityRole::IctAdmin,
    ]);
    $school = School::create(['name' => 'School of Allied Health', 'code' => 'SAH']);
    $department = Department::create([
        'school_id' => $school->id,
        'name' => 'Physiotherapy',
        'code' => 'PHYS',
    ]);
    $program = Program::create([
        'department_id' => $department->id,
        'name' => 'BSc Physiotherapy',
        'code' => 'BSPT',
        'max_level' => 400,
    ]);

    $this->actingAs($administrator)
        ->post('/quality/courses', [
            'department_id' => $department->id,
            'code' => 'PHYS501',
            'title' => 'Advanced Physiotherapy',
            'course_type' => 'normal',
            'level' => '500',
        ])
        ->assertRedirect('/quality/courses');

    expect(Course::where('code', 'PHYS501')->value('program_id'))->toBeNull();
});
