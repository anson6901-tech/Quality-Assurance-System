<?php

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Department;
use App\Models\Program;
use App\Models\QualityReport;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('shows a lecturer only their assigned courses and published reports', function () {
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $program = Program::create(['department_id' => $department->id, 'name' => 'BSc Nursing', 'code' => 'BSCN', 'max_level' => 400]);
    $year = AcademicYear::create(['name' => '2026/2027', 'status' => 'active']);
    $semester = $year->semesters()->firstOrFail();
    $lecturer = User::factory()->create([
        'university_role' => UniversityRole::Lecturer,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $otherLecturer = User::factory()->create(['university_role' => UniversityRole::Lecturer]);
    $officer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    $course = Course::create(['department_id' => $department->id, 'code' => 'NURS401', 'title' => 'Clinical Nursing', 'level' => '400']);
    $otherCourse = Course::create(['department_id' => $department->id, 'code' => 'NURS402', 'title' => 'Nursing Leadership', 'level' => '400']);

    DB::table('course_lecturer')->insert([
        [
            'course_id' => $course->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'lecturer_id' => $lecturer->id,
            'student_count' => 45,
            'allocated_hours' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'course_id' => $otherCourse->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'lecturer_id' => $otherLecturer->id,
            'student_count' => null,
            'allocated_hours' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    foreach (['approved', 'draft'] as $status) {
        $report = QualityReport::create([
            'title' => ucfirst($status).' clinical report',
            'category' => 'Academic Quality',
            'status' => $status,
            'academic_year_id' => $year->id,
            'semester_id' => $semester->id,
            'school_id' => $school->id,
            'department_id' => $department->id,
            'program_id' => $program->id,
            'lecturer_id' => $lecturer->id,
            'uploaded_by' => $officer->id,
        ]);
        $report->courses()->attach($course);
    }

    $this->actingAs($lecturer)
        ->get('/quality/my-courses')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/my-courses/Index')
            ->has('courses', 1)
            ->where('courses.0.code', 'NURS401')
            ->where('courses.0.studentCount', 45)
            ->has('courses.0.reports', 1)
            ->where('courses.0.reports.0.title', 'Approved clinical report'));
});

it('does not expose the assigned-course portal without own-report scope', function () {
    $officer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);

    $this->actingAs($officer)->get('/quality/my-courses')->assertForbidden();
});
