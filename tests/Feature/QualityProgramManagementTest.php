<?php

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\UploadedFile;

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
        ->toContain('code,title,course_type,level,credit_hours')
        ->toContain(',,normal,100,3,')
        ->toContain('department_code');
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

test('courses can be created without a home department', function () {
    $administrator = User::factory()->create([
        'university_role' => UniversityRole::IctAdmin,
    ]);

    $this->actingAs($administrator)
        ->post('/quality/courses', [
            'code' => 'GSTD101',
            'title' => 'Communication Skills',
            'course_type' => 'normal',
            'level' => '100',
            'credit_hours' => 2,
        ])
        ->assertRedirect('/quality/courses');

    $course = Course::where('code', 'GSTD101')->firstOrFail();

    expect($course->department_id)->toBeNull()
        ->and($course->program_id)->toBeNull();

    $this->actingAs($administrator)
        ->get('/quality/courses')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('quality/courses/Index')
            ->has('courses', 1)
            ->where('courses.0.department', null));
});

test('course codes are unique across the whole catalogue', function () {
    $administrator = User::factory()->create([
        'university_role' => UniversityRole::IctAdmin,
    ]);
    Course::create([
        'code' => 'GSTD101',
        'title' => 'Communication Skills',
        'course_type' => 'normal',
        'level' => '100',
    ]);

    $this->actingAs($administrator)
        ->post('/quality/courses', [
            'code' => 'GSTD101',
            'title' => 'Duplicate',
            'course_type' => 'normal',
            'level' => '100',
        ])
        ->assertSessionHasErrors('code');

    expect(Course::where('code', 'GSTD101')->count())->toBe(1);
});

test('courses import without a department column', function () {
    $administrator = User::factory()->create([
        'university_role' => UniversityRole::IctAdmin,
    ]);
    $csv = UploadedFile::fake()->createWithContent(
        'courses.csv',
        "code,title,course_type,level,credit_hours\nGSTD101,Communication Skills,normal,100,2\nGSTD102,Academic Writing,normal,100,\n",
    );

    $this->actingAs($administrator)
        ->post('/quality/courses/import', ['courses_file' => $csv])
        ->assertRedirect('/quality/courses');

    expect(Course::whereIn('code', ['GSTD101', 'GSTD102'])->whereNull('department_id')->count())->toBe(2);
});

test('a course keeps an optional home department and other departments mount it at their own level', function () {
    $administrator = User::factory()->create([
        'university_role' => UniversityRole::IctAdmin,
    ]);
    $school = School::create(['name' => 'School of Nursing and Midwifery', 'code' => 'SONAM']);
    $nursing = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $midwifery = Department::create(['school_id' => $school->id, 'name' => 'Midwifery', 'code' => 'MIDW']);
    $nursingProgram = Program::create(['department_id' => $nursing->id, 'name' => 'BSc Nursing', 'code' => 'BSCN', 'max_level' => 400]);
    $midwiferyProgram = Program::create(['department_id' => $midwifery->id, 'name' => 'BSc Midwifery', 'code' => 'BSCM', 'max_level' => 400]);
    $semester = AcademicYear::create(['name' => '2026/2027', 'status' => 'active'])->semesters()->firstOrFail();

    $this->actingAs($administrator)
        ->post('/quality/courses', [
            'department_id' => $nursing->id,
            'code' => 'NURS101',
            'title' => 'Foundations of Nursing',
            'course_type' => 'normal',
            'level' => '100',
        ])
        ->assertRedirect('/quality/courses');

    $course = Course::where('code', 'NURS101')->firstOrFail();
    expect($course->department_id)->toBe($nursing->id);

    $this->actingAs($administrator)
        ->get('/quality/courses')
        ->assertInertia(fn ($page) => $page
            ->where('courses.0.department.name', 'Nursing')
            ->has('departments', 2));

    foreach ([[$nursingProgram, '100'], [$midwiferyProgram, '200']] as [$program, $level]) {
        $this->actingAs($administrator)
            ->put('/quality/course-offerings', [
                'program_id' => $program->id,
                'semester_id' => $semester->id,
                'level' => $level,
                'course_ids' => [$course->id],
            ])
            ->assertRedirect('/quality/course-offerings');
    }

    expect(CourseOffering::where('course_id', $course->id)->orderBy('level')->pluck('level', 'program_id')->all())
        ->toBe([$nursingProgram->id => '100', $midwiferyProgram->id => '200']);

    $this->actingAs($administrator)
        ->patch("/quality/courses/{$course->id}", [
            'department_id' => null,
            'code' => 'NURS101',
            'title' => 'Foundations of Nursing',
            'course_type' => 'normal',
            'level' => '100',
        ])
        ->assertRedirect('/quality/courses');

    expect($course->refresh()->department_id)->toBeNull()
        ->and(CourseOffering::where('course_id', $course->id)->count())->toBe(2);
});

test('the course import accepts an optional home department code', function () {
    $administrator = User::factory()->create([
        'university_role' => UniversityRole::IctAdmin,
    ]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $nursing = Department::create(['school_id' => $school->id, 'name' => 'Nursing', 'code' => 'NURS']);
    $csv = UploadedFile::fake()->createWithContent(
        'courses.csv',
        "code,title,course_type,level,credit_hours,department_code\nNURS101,Foundations,normal,100,3,NURS\nGSTD101,Communication Skills,normal,100,2,\nBAD101,Unknown Department,normal,100,2,XXXX\n",
    );

    $this->actingAs($administrator)
        ->post('/quality/courses/import', ['courses_file' => $csv])
        ->assertRedirect('/quality/courses');

    expect(Course::where('code', 'NURS101')->value('department_id'))->toBe($nursing->id)
        ->and(Course::where('code', 'GSTD101')->value('department_id'))->toBeNull()
        ->and(Course::where('code', 'BAD101')->exists())->toBeFalse();
});
