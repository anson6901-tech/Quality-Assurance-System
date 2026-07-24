<?php

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Program;
use App\Models\QualityReport;
use App\Models\School;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Illuminate\Support\Facades\DB;

function offeringStructure(): array
{
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create([
        'school_id' => $school->id,
        'name' => 'Nursing',
        'code' => 'NURS',
    ]);
    $program = Program::create([
        'department_id' => $department->id,
        'name' => 'BSc Nursing',
        'code' => 'BSCN',
        'max_level' => 400,
    ]);
    $course = Course::create([
        'department_id' => $department->id,
        'program_id' => $program->id,
        'code' => 'NURS101',
        'title' => 'Foundations of Nursing',
        'course_type' => 'normal',
        'level' => '100',
        'credit_hours' => 3,
    ]);
    $currentYear = AcademicYear::create([
        'name' => '2026/2027',
        'starts_on' => '2026-08-01',
        'ends_on' => '2027-07-31',
        'status' => 'active',
    ]);
    $nextYear = AcademicYear::create([
        'name' => '2027/2028',
        'starts_on' => '2027-08-01',
        'ends_on' => '2028-07-31',
        'status' => 'archived',
    ]);

    return compact('school', 'department', 'program', 'course', 'currentYear', 'nextYear');
}

test('an administrator can mount courses and only mounted courses appear for assignment', function () {
    $data = offeringStructure();
    $administrator = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $semester = $data['currentYear']->semesters()->firstOrFail();

    $this->actingAs($administrator)
        ->put('/quality/course-offerings', [
            'program_id' => $data['program']->id,
            'semester_id' => $semester->id,
            'course_ids' => [$data['course']->id],
        ])
        ->assertRedirect('/quality/course-offerings');

    expect(CourseOffering::where([
        'course_id' => $data['course']->id,
        'semester_id' => $semester->id,
    ])->exists())->toBeTrue();

    $this->actingAs($administrator)
        ->get("/quality/course-assignments?academic_year_id={$data['currentYear']->id}&semester_id={$semester->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/course-assignments/Index')
            ->has('courses', 1)
            ->where('courses.0.id', $data['course']->id));
});

test('mounted courses can be copied to an upcoming academic year', function () {
    $data = offeringStructure();
    $administrator = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $source = $data['currentYear']->semesters()->firstOrFail();
    $target = $data['nextYear']->semesters()->firstOrFail();
    CourseOffering::create([
        'course_id' => $data['course']->id,
        'program_id' => $data['program']->id,
        'semester_id' => $source->id,
    ]);

    $this->actingAs($administrator)
        ->post('/quality/course-offerings/copy', [
            'program_id' => $data['program']->id,
            'source_semester_id' => $source->id,
            'target_semester_id' => $target->id,
        ])
        ->assertRedirect('/quality/course-offerings');

    expect(CourseOffering::where([
        'course_id' => $data['course']->id,
        'semester_id' => $target->id,
    ])->exists())->toBeTrue();
});

test('a mounted course can be removed together with its lecturer assignments', function () {
    $data = offeringStructure();
    $administrator = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $lecturer = User::factory()->create(['university_role' => UniversityRole::Lecturer]);
    $semester = $data['currentYear']->semesters()->firstOrFail();

    CourseOffering::create([
        'course_id' => $data['course']->id,
        'program_id' => $data['program']->id,
        'semester_id' => $semester->id,
        'level' => '100',
    ]);
    DB::table('course_lecturer')->insert([
        'course_id' => $data['course']->id,
        'lecturer_id' => $lecturer->id,
        'program_id' => $data['program']->id,
        'semester_id' => $semester->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($administrator)
        ->delete("/quality/course-offerings/{$data['course']->id}", [
            'program_id' => $data['program']->id,
            'semester_id' => $semester->id,
        ])
        ->assertRedirect('/quality/course-offerings');

    expect(CourseOffering::query()->count())->toBe(0)
        ->and(DB::table('course_lecturer')->count())->toBe(0);

    $this->actingAs($administrator)
        ->delete("/quality/course-offerings/{$data['course']->id}", [
            'program_id' => $data['program']->id,
            'semester_id' => $semester->id,
        ])
        ->assertRedirect('/quality/course-offerings');
});

test('the same catalogue course can be mounted for two programs in one semester', function () {
    $data = offeringStructure();
    $administrator = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $secondProgram = Program::create([
        'department_id' => $data['department']->id,
        'name' => 'BSc Midwifery',
        'code' => 'BSCM',
        'max_level' => 400,
    ]);
    $semester = $data['currentYear']->semesters()->firstOrFail();

    foreach ([$data['program'], $secondProgram] as $program) {
        $this->actingAs($administrator)->put('/quality/course-offerings', [
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'course_ids' => [$data['course']->id],
        ])->assertRedirect('/quality/course-offerings');
    }

    expect(CourseOffering::where('course_id', $data['course']->id)
        ->where('semester_id', $semester->id)
        ->distinct()
        ->count('program_id'))->toBe(2);

    $this->actingAs($administrator)
        ->get("/quality/course-assignments?academic_year_id={$data['currentYear']->id}&semester_id={$semester->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('courses', 1)
            ->has('courses.0.variants', 2));

    $lecturer = User::factory()->create(['university_role' => UniversityRole::Lecturer]);
    foreach ([$data['program'], $secondProgram] as $program) {
        $this->actingAs($administrator)->put(
            "/quality/course-assignments/{$data['course']->id}/lecturers",
            [
                'program_ids' => [$program->id],
                'semester_id' => $semester->id,
                'lecturer_ids' => [$lecturer->id],
                'assignments' => [
                    $lecturer->id => ['student_count' => 30, 'allocated_hours' => 20],
                ],
            ],
        )->assertRedirect('/quality/course-assignments');
    }

    $this->actingAs($administrator)
        ->get("/quality/course-assignments?academic_year_id={$data['currentYear']->id}&semester_id={$semester->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('courses', 1)
            ->has('courses.0.programs', 2)
            ->where('courses.0.level', '100'));
});

test('an HOD can only mount courses for programs in their department', function () {
    $data = offeringStructure();
    $otherSchool = School::create(['name' => 'School of Public Health', 'code' => 'SOPH']);
    $otherDepartment = Department::create([
        'school_id' => $otherSchool->id,
        'name' => 'Public Health',
        'code' => 'PH',
    ]);
    $hod = User::factory()->create([
        'university_role' => UniversityRole::Hod,
        'department_id' => $otherDepartment->id,
    ]);
    $semester = $data['currentYear']->semesters()->firstOrFail();

    $this->actingAs($hod)
        ->put('/quality/course-offerings', [
            'program_id' => $data['program']->id,
            'semester_id' => $semester->id,
            'course_ids' => [$data['course']->id],
        ])
        ->assertNotFound();
});

test('lecturer assignments and reports keep the mounted program context', function () {
    $data = offeringStructure();
    $administrator = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $uploader = User::factory()->create(['university_role' => UniversityRole::UploadOnly]);
    $lecturer = User::factory()->create(['university_role' => UniversityRole::Lecturer]);
    $semester = $data['currentYear']->semesters()->firstOrFail();
    CourseOffering::create([
        'course_id' => $data['course']->id,
        'program_id' => $data['program']->id,
        'semester_id' => $semester->id,
    ]);

    $this->actingAs($administrator)->put(
        "/quality/course-assignments/{$data['course']->id}/lecturers",
        [
            'program_ids' => [$data['program']->id],
            'semester_id' => $semester->id,
            'lecturer_ids' => [$lecturer->id],
            'assignments' => [
                $lecturer->id => ['student_count' => 40, 'allocated_hours' => 24],
            ],
        ],
    )->assertRedirect('/quality/course-assignments');

    $this->actingAs($uploader)->post('/quality/reports', [
        'title' => 'Nursing course report',
        'category' => 'Academic Quality',
        'academic_year_id' => $data['currentYear']->id,
        'semester_id' => $semester->id,
        'program_id' => $data['program']->id,
        'lecturer_id' => $lecturer->id,
        'course_ids' => [$data['course']->id],
    ])->assertRedirect('/quality/reports/create');

    expect(QualityReport::latest('id')->value('program_id'))->toBe($data['program']->id);
});

test('heads of department are eligible for lecturer course assignments', function () {
    $data = offeringStructure();
    $administrator = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $hod = User::factory()->create([
        'university_role' => UniversityRole::Hod,
        'department_id' => $data['department']->id,
    ]);
    $semester = $data['currentYear']->semesters()->firstOrFail();
    CourseOffering::create([
        'course_id' => $data['course']->id,
        'program_id' => $data['program']->id,
        'semester_id' => $semester->id,
        'level' => '100',
    ]);

    $this->actingAs($administrator)->put(
        "/quality/course-assignments/{$data['course']->id}/lecturers",
        [
            'program_ids' => [$data['program']->id],
            'semester_id' => $semester->id,
            'lecturer_ids' => [$hod->id],
            'assignments' => [
                $hod->id => ['student_count' => 35, 'allocated_hours' => 18],
            ],
        ],
    )->assertRedirect('/quality/course-assignments');

    expect(DB::table('course_lecturer')
        ->where('course_id', $data['course']->id)
        ->where('lecturer_id', $hod->id)
        ->exists())->toBeTrue();
});
