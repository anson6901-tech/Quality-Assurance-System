<?php

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\CourseSplit;
use App\Models\Department;
use App\Models\Program;
use App\Models\QualityReport;
use App\Models\School;
use App\Models\Section;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function splitStructure(): array
{
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $otherDepartment = Department::create(['school_id' => $school->id, 'name' => 'Midwifery', 'code' => 'MIDW']);
    $program = Program::create(['department_id' => $department->id, 'name' => 'BSc Nursing', 'code' => 'BSN', 'max_level' => 400]);
    $secondProgram = Program::create(['department_id' => $department->id, 'name' => 'BSc Public Health Nursing', 'code' => 'BPHN', 'max_level' => 400]);
    $regular = Section::create(['program_id' => $program->id, 'type' => 'undergraduate', 'is_weekend' => false]);
    $weekend = Section::create(['program_id' => $program->id, 'type' => 'undergraduate', 'is_weekend' => true]);
    $year = AcademicYear::create(['name' => '2026/2027', 'status' => 'active']);
    $semester = $year->semesters()->firstOrFail();
    $course = Course::create(['department_id' => $department->id, 'code' => 'NURS 301', 'title' => 'Adult Health Nursing', 'level' => '300']);
    $otherCourse = Course::create(['department_id' => $otherDepartment->id, 'code' => 'MIDW 212', 'title' => 'Maternal Care', 'level' => '200']);

    foreach ([$program, $secondProgram] as $offeredProgram) {
        CourseOffering::create([
            'course_id' => $course->id,
            'program_id' => $offeredProgram->id,
            'semester_id' => $semester->id,
            'level' => '300',
        ]);
    }

    $hod = User::factory()->create([
        'university_role' => UniversityRole::Hod,
        'school_id' => $school->id,
        'department_id' => $department->id,
    ]);
    $lecturer = User::factory()->create(['university_role' => UniversityRole::Lecturer]);
    $weekendLecturer = User::factory()->create(['university_role' => UniversityRole::Lecturer]);

    return compact(
        'school', 'department', 'otherDepartment', 'program', 'secondProgram', 'regular', 'weekend',
        'year', 'semester', 'course', 'otherCourse', 'hod', 'lecturer', 'weekendLecturer',
    );
}

function assignmentPage($test, User $user, $semester)
{
    return $test->actingAs($user)->get('/quality/course-assignments?academic_year_id='.$semester->academic_year_id.'&semester_id='.$semester->id);
}

it('lists a merged course as one part covering every program section', function () {
    $data = splitStructure();

    assignmentPage($this, $data['hod'], $data['semester'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/course-assignments/Index')
            ->has('courses', 1)
            ->where('courses.0.splitMode', 'merged')
            ->has('courses.0.parts', 1)
            ->where('courses.0.cellCount', 3)
            ->where('courses.0.parts.0.label', 'Whole course'));
});

it('splits a course by each mode and merges it back', function (string $mode, int $parts) {
    $data = splitStructure();

    $this->actingAs($data['hod'])
        ->post("/quality/course-assignments/{$data['course']->id}/split", [
            'semester_id' => $data['semester']->id,
            'mode' => $mode,
        ])
        ->assertRedirect();

    expect(CourseSplit::query()->where('course_id', $data['course']->id)->value('mode'))->toBe($mode);

    assignmentPage($this, $data['hod'], $data['semester'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('courses.0.splitMode', $mode)
            ->has('courses.0.parts', $parts));

    $this->actingAs($data['hod'])
        ->delete("/quality/course-assignments/{$data['course']->id}/split", [
            'semester_id' => $data['semester']->id,
        ])
        ->assertRedirect();

    expect(CourseSplit::query()->where('course_id', $data['course']->id)->exists())->toBeFalse();
})->with([
    'level and program' => ['level_program', 2],
    'level and section' => ['level_section', 3],
    'section and program' => ['section_program', 3],
    'level, program and section' => ['level_program_section', 3],
]);

it('groups the same section across programs when splitting by level and section', function () {
    $data = splitStructure();
    Section::create(['program_id' => $data['secondProgram']->id, 'type' => 'undergraduate', 'is_weekend' => false]);
    CourseSplit::create(['course_id' => $data['course']->id, 'semester_id' => $data['semester']->id, 'mode' => 'level_section']);

    assignmentPage($this, $data['hod'], $data['semester'])
        ->assertInertia(fn (Assert $page) => $page
            ->has('courses.0.parts', 2)
            ->where('courses.0.parts.0.label', 'Level 300 · Undergraduate · BPHN, BSN')
            ->has('courses.0.parts.0.cells', 2));
});

it('assigns a different lecturer to one split part only', function () {
    $data = splitStructure();
    CourseSplit::create(['course_id' => $data['course']->id, 'semester_id' => $data['semester']->id, 'mode' => 'section_program']);

    $assign = fn (User $lecturer, array $cells) => $this->actingAs($data['hod'])->put(
        "/quality/course-assignments/{$data['course']->id}/lecturers",
        [
            'semester_id' => $data['semester']->id,
            'cells' => $cells,
            'lecturer_ids' => [$lecturer->id],
            'assignments' => [$lecturer->id => ['student_count' => 30, 'allocated_hours' => 20]],
        ],
    )->assertRedirect('/quality/course-assignments');

    $assign($data['lecturer'], [
        ['program_id' => $data['program']->id, 'section_id' => $data['regular']->id],
        ['program_id' => $data['secondProgram']->id, 'section_id' => null],
    ]);
    $assign($data['weekendLecturer'], [
        ['program_id' => $data['program']->id, 'section_id' => $data['weekend']->id],
    ]);

    expect(DB::table('course_lecturer')->where('section_id', $data['weekend']->id)->pluck('lecturer_id')->all())
        ->toBe([$data['weekendLecturer']->id])
        ->and(DB::table('course_lecturer')->where('section_id', $data['regular']->id)->pluck('lecturer_id')->all())
        ->toBe([$data['lecturer']->id]);
});

it('only lets heads of department split courses their department owns', function () {
    $data = splitStructure();
    CourseOffering::create([
        'course_id' => $data['otherCourse']->id,
        'program_id' => Program::create(['department_id' => $data['otherDepartment']->id, 'name' => 'BSc Midwifery', 'code' => 'BSM', 'max_level' => 400])->id,
        'semester_id' => $data['semester']->id,
        'level' => '200',
    ]);
    $officer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);

    $this->actingAs($data['hod'])
        ->post("/quality/course-assignments/{$data['otherCourse']->id}/split", [
            'semester_id' => $data['semester']->id,
            'mode' => 'level_program',
        ])
        ->assertForbidden();

    $this->actingAs($officer)
        ->post("/quality/course-assignments/{$data['course']->id}/split", [
            'semester_id' => $data['semester']->id,
            'mode' => 'level_program',
        ])
        ->assertForbidden();
});

it('uploads one report for a split part and records its section', function () {
    Storage::fake('public');
    $data = splitStructure();
    $officer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    DB::table('course_lecturer')->insert([
        'course_id' => $data['course']->id,
        'program_id' => $data['program']->id,
        'section_id' => $data['weekend']->id,
        'lecturer_id' => $data['weekendLecturer']->id,
        'semester_id' => $data['semester']->id,
        'student_count' => 20,
        'allocated_hours' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($officer)->post('/quality/reports', [
        'title' => 'Weekend report',
        'category' => 'Academic Quality',
        'academic_year_id' => $data['year']->id,
        'semester_id' => $data['semester']->id,
        'cells' => [['program_id' => $data['program']->id, 'section_id' => $data['weekend']->id]],
        'lecturer_id' => $data['weekendLecturer']->id,
        'course_ids' => [$data['course']->id],
        'report_file' => UploadedFile::fake()->create('report.pdf', 20, 'application/pdf'),
    ])->assertRedirect('/quality/reports/create');

    $report = QualityReport::query()->sole();

    expect($report->section_id)->toBe($data['weekend']->id)
        ->and($report->lecturer_id)->toBe($data['weekendLecturer']->id);

    $this->actingAs($officer)->post('/quality/reports', [
        'title' => 'Wrong section',
        'category' => 'Academic Quality',
        'academic_year_id' => $data['year']->id,
        'semester_id' => $data['semester']->id,
        'cells' => [['program_id' => $data['program']->id, 'section_id' => $data['regular']->id]],
        'lecturer_id' => $data['weekendLecturer']->id,
        'course_ids' => [$data['course']->id],
    ])->assertSessionHasErrors('course_ids');
});

it('copies program-wide assignments into sections when a program gets its first sections', function () {
    $data = splitStructure();
    $administrator = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    DB::table('course_lecturer')->insert([
        'course_id' => $data['course']->id,
        'program_id' => $data['secondProgram']->id,
        'section_id' => null,
        'lecturer_id' => $data['lecturer']->id,
        'semester_id' => $data['semester']->id,
        'student_count' => 25,
        'allocated_hours' => 15,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($administrator)->patch("/quality/programs/{$data['secondProgram']->id}", [
        'department_id' => $data['department']->id,
        'name' => 'BSc Public Health Nursing',
        'code' => 'BPHN',
        'max_level' => 400,
        'sections' => ['sandwich', 'sandwich_weekend'],
    ])->assertRedirect('/quality/programs');

    $sectionIds = $data['secondProgram']->sections()->pluck('id');

    expect($sectionIds)->toHaveCount(2)
        ->and(DB::table('course_lecturer')->where('program_id', $data['secondProgram']->id)->whereNull('section_id')->exists())->toBeFalse()
        ->and(DB::table('course_lecturer')->where('program_id', $data['secondProgram']->id)->whereIn('section_id', $sectionIds)->count())->toBe(2);
});

it('lets structure managers save a program\'s sections from the split dialog', function () {
    $data = splitStructure();
    $administrator = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    $this->actingAs($data['hod'])
        ->put("/quality/programs/{$data['secondProgram']->id}/sections", ['sections' => ['sandwich']])
        ->assertForbidden();

    $this->actingAs($administrator)
        ->from('/quality/course-assignments')
        ->put("/quality/programs/{$data['secondProgram']->id}/sections", ['sections' => ['undergraduate', 'sandwich']])
        ->assertRedirect('/quality/course-assignments');

    expect($data['secondProgram']->sections()->pluck('type')->sort()->values()->all())
        ->toBe(['sandwich', 'undergraduate']);

    assignmentPage($this, $data['hod'], $data['semester'])
        ->assertInertia(fn (Assert $page) => $page->where('courses.0.cellCount', 4));
});

it('returns the course parts for the split dialog on any page', function () {
    $data = splitStructure();
    $officer = User::factory()->create(['university_role' => UniversityRole::QaOfficer]);
    CourseSplit::create(['course_id' => $data['course']->id, 'semester_id' => $data['semester']->id, 'mode' => 'level_program']);

    $this->actingAs($data['hod'])
        ->getJson("/quality/course-assignments/{$data['course']->id}/split-options")
        ->assertOk()
        ->assertJsonCount(1, 'semesters')
        ->assertJsonPath('semesters.0.id', $data['semester']->id)
        ->assertJsonPath('semesters.0.mode', 'level_program')
        ->assertJsonCount(3, 'semesters.0.cells');

    $this->actingAs($officer)
        ->getJson("/quality/course-assignments/{$data['course']->id}/split-options")
        ->assertForbidden();
});
