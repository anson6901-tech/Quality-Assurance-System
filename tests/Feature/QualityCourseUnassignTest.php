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
use App\Models\User;
use Illuminate\Support\Facades\DB;

function unassignSetup(): array
{
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $nursing = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $midwifery = Department::create(['school_id' => $school->id, 'name' => 'Midwifery', 'code' => 'MIDW']);
    $bscn = Program::create(['department_id' => $nursing->id, 'name' => 'BSc Nursing', 'code' => 'BSCN', 'max_level' => 400]);
    $bscm = Program::create(['department_id' => $midwifery->id, 'name' => 'BSc Midwifery', 'code' => 'BSCM', 'max_level' => 400]);
    $semester = AcademicYear::create(['name' => '2026/2027', 'status' => 'active'])->semesters()->firstOrFail();
    $course = Course::create(['code' => 'ANAT102', 'title' => 'Human Anatomy', 'level' => '100', 'course_type' => 'normal']);

    foreach ([$bscn, $bscm] as $program) {
        CourseOffering::create(['course_id' => $course->id, 'program_id' => $program->id, 'semester_id' => $semester->id, 'level' => '100']);
    }

    $grace = User::factory()->create(['name' => 'Grace Appiah', 'university_role' => UniversityRole::Lecturer]);
    $samuel = User::factory()->create(['name' => 'Samuel Tetteh', 'university_role' => UniversityRole::Lecturer]);

    foreach ([[$bscn, $grace], [$bscm, $samuel]] as [$program, $lecturer]) {
        DB::table('course_lecturer')->insert([
            'course_id' => $course->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'lecturer_id' => $lecturer->id,
            'student_count' => 40,
            'allocated_hours' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return compact('school', 'nursing', 'midwifery', 'bscn', 'bscm', 'semester', 'course', 'grace', 'samuel');
}

function cellsFor(Program ...$programs): array
{
    return array_map(fn (Program $program) => ['program_id' => $program->id, 'section_id' => null], $programs);
}

it('unassigns every lecturer from a course', function () {
    $data = unassignSetup();
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    $this->actingAs($admin)
        ->delete("/quality/course-assignments/{$data['course']->id}/lecturers", [
            'semester_id' => $data['semester']->id,
            'cells' => cellsFor($data['bscn'], $data['bscm']),
        ])
        ->assertRedirect('/quality/course-assignments')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Grace Appiah, Samuel Tetteh unassigned from ANAT102.');

    expect(DB::table('course_lecturer')->where('course_id', $data['course']->id)->count())->toBe(0);
});

it('unassigns one part of a split course and keeps the other', function () {
    $data = unassignSetup();
    CourseSplit::create(['course_id' => $data['course']->id, 'semester_id' => $data['semester']->id, 'mode' => 'level_program']);
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    $this->actingAs($admin)
        ->delete("/quality/course-assignments/{$data['course']->id}/lecturers", [
            'semester_id' => $data['semester']->id,
            'cells' => cellsFor($data['bscm']),
        ])
        ->assertRedirect('/quality/course-assignments');

    expect(DB::table('course_lecturer')->where('course_id', $data['course']->id)->pluck('lecturer_id')->all())
        ->toBe([$data['grace']->id]);
});

it('keeps reports already uploaded for an unassigned lecturer', function () {
    $data = unassignSetup();
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $report = QualityReport::create([
        'title' => 'Anatomy review',
        'category' => 'Academic Quality',
        'status' => 'approved',
        'academic_year_id' => $data['semester']->academic_year_id,
        'semester_id' => $data['semester']->id,
        'school_id' => $data['school']->id,
        'department_id' => $data['nursing']->id,
        'program_id' => $data['bscn']->id,
        'lecturer_id' => $data['grace']->id,
        'uploaded_by' => $admin->id,
    ]);

    $this->actingAs($admin)->delete("/quality/course-assignments/{$data['course']->id}/lecturers", [
        'semester_id' => $data['semester']->id,
        'cells' => cellsFor($data['bscn']),
    ]);

    expect($report->refresh())
        ->lecturer_id->toBe($data['grace']->id)
        ->status->toBe('approved');
});

it('lets a head of department unassign only inside their own department', function () {
    $data = unassignSetup();
    $hod = User::factory()->create([
        'university_role' => UniversityRole::Hod,
        'school_id' => $data['school']->id,
        'department_id' => $data['nursing']->id,
    ]);

    $this->actingAs($hod)
        ->delete("/quality/course-assignments/{$data['course']->id}/lecturers", [
            'semester_id' => $data['semester']->id,
            'cells' => cellsFor($data['bscm']),
        ])
        ->assertForbidden();

    $this->actingAs($hod)
        ->delete("/quality/course-assignments/{$data['course']->id}/lecturers", [
            'semester_id' => $data['semester']->id,
            'cells' => cellsFor($data['bscn']),
        ])
        ->assertRedirect('/quality/course-assignments');

    expect(DB::table('course_lecturer')->where('course_id', $data['course']->id)->pluck('lecturer_id')->all())
        ->toBe([$data['samuel']->id]);

    $lecturer = User::factory()->create(['university_role' => UniversityRole::Lecturer]);
    $this->actingAs($lecturer)
        ->delete("/quality/course-assignments/{$data['course']->id}/lecturers", [
            'semester_id' => $data['semester']->id,
            'cells' => cellsFor($data['bscn']),
        ])
        ->assertForbidden();
});

it('unassigns lecturers from a course that is no longer mounted', function () {
    $data = unassignSetup();
    CourseOffering::query()->where('course_id', $data['course']->id)->delete();
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    $this->actingAs($admin)
        ->delete("/quality/course-assignments/{$data['course']->id}/lecturers", [
            'semester_id' => $data['semester']->id,
            'cells' => cellsFor($data['bscn']),
        ])
        ->assertRedirect('/quality/course-assignments');

    expect(DB::table('course_lecturer')->where('course_id', $data['course']->id)->pluck('lecturer_id')->all())
        ->toBe([$data['samuel']->id]);
});
