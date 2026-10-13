<?php

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Department;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function searchStructure(): array
{
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $otherSchool = School::create(['name' => 'School of Medicine', 'code' => 'SOM']);
    $nursing = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $pathology = Department::create(['school_id' => $otherSchool->id, 'name' => 'Pathology', 'code' => 'PATH']);
    $semester = AcademicYear::create(['name' => '2026/2027', 'status' => 'active'])->semesters()->firstOrFail();
    $adultHealth = Course::create(['department_id' => $nursing->id, 'code' => 'NURS 301', 'title' => 'Adult Health Nursing', 'level' => '300']);
    $fundamentals = Course::create(['department_id' => $nursing->id, 'code' => 'NURS 201', 'title' => 'Fundamentals of Nursing', 'level' => '200']);
    $pathologyCourse = Course::create(['department_id' => $pathology->id, 'code' => 'PATH 302', 'title' => 'General Pathology', 'level' => '300']);
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $nursing->id,
    ]);

    DB::table('course_lecturer')->insert([
        'course_id' => $adultHealth->id,
        'lecturer_id' => $lecturer->id,
        'semester_id' => $semester->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return compact('school', 'nursing', 'adultHealth', 'fundamentals', 'pathologyCourse', 'lecturer');
}

function searchCodes($test, User $user, string $query): array
{
    return collect($test->actingAs($user)->getJson('/quality/course-search?q='.urlencode($query))
        ->assertOk()
        ->json('courses'))
        ->pluck('code')
        ->all();
}

it('finds courses by code, title and department for QA staff', function () {
    $data = searchStructure();
    $director = User::factory()->create(['university_role' => UniversityRole::QaDirector]);

    expect(searchCodes($this, $director, 'nurs 301'))->toBe(['NURS 301'])
        ->and(searchCodes($this, $director, 'pathology'))->toBe(['PATH 302'])
        ->and(searchCodes($this, $director, 'nursing'))->toBe(['NURS 201', 'NURS 301'])
        ->and(searchCodes($this, $director, ''))->toBe([]);
});

it('limits search results to the courses each role works with', function () {
    $data = searchStructure();
    $dean = User::factory()->create(['university_role' => UniversityRole::Dean, 'school_id' => $data['school']->id]);
    $hod = User::factory()->create([
        'university_role' => UniversityRole::Hod,
        'school_id' => $data['school']->id,
        'department_id' => $data['nursing']->id,
    ]);

    expect(searchCodes($this, $data['lecturer'], 'nurs'))->toBe(['NURS 301'])
        ->and(searchCodes($this, $data['lecturer'], 'path'))->toBe([])
        ->and(searchCodes($this, $dean, '30'))->toBe(['NURS 301'])
        ->and(searchCodes($this, $hod, 'nurs'))->toBe(['NURS 201', 'NURS 301'])
        ->and(searchCodes($this, $hod, 'path'))->toBe([]);
});

it('requires a signed in user to search courses', function () {
    $this->getJson('/quality/course-search?q=nurs')->assertUnauthorized();
});
