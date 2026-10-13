<?php

namespace Database\Seeders;

use App\Actions\Teams\CreateTeam;
use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\CourseSplit;
use App\Models\Department;
use App\Models\Program;
use App\Models\QualityReport;
use App\Models\QualityReportReview;
use App\Models\QualityRolePermission;
use App\Models\School;
use App\Models\Section;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class QualityDemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->resetStandardRoleRights();

            $schools = $this->seedSchools();
            $programs = $this->seedPrograms($schools);
            $semesters = $this->seedAcademicYears();
            $users = $this->seedUsers($schools);
            $courses = $this->seedCourses($schools, $programs);

            $this->seedOfferingsAndAssignments($courses, $programs, $semesters, $users);
            $this->seedReports($semesters, $programs, $users, $courses);
        });
    }

    private function resetStandardRoleRights(): void
    {
        QualityRolePermission::ensureDefaults();

        foreach (QualityRolePermission::defaults() as $role => $permissions) {
            QualityRolePermission::query()->updateOrCreate(
                ['role' => $role],
                [
                    'label' => UniversityRole::tryFrom($role)?->label(),
                    'permissions' => $permissions,
                    'is_system' => (bool) UniversityRole::tryFrom($role),
                ],
            );
        }
    }

    /**
     * @return array<string, School>
     */
    private function seedSchools(): array
    {
        $structure = [
            'SOM' => [
                'name' => 'School of Medicine',
                'departments' => [
                    'MBMB' => 'Medical Biochemistry and Molecular Biology',
                    'PATH' => 'Pathology',
                ],
            ],
            'SONAM' => [
                'name' => 'School of Nursing and Midwifery',
                'departments' => [
                    'NURS' => 'Nursing',
                    'MIDW' => 'Midwifery',
                    'PHN' => 'Public Health Nursing',
                    'HSA' => 'Health Service Administration',
                ],
            ],
            'SPH' => [
                'name' => 'School of Public Health',
                'departments' => [
                    'EPBI' => 'Epidemiology and Biostatistics',
                    'HPPM' => 'Health Policy Planning and Management',
                ],
            ],
        ];

        $schools = [];

        foreach ($structure as $code => $schoolData) {
            $school = School::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $schoolData['name']],
            );

            foreach ($schoolData['departments'] as $departmentCode => $departmentName) {
                Department::query()->updateOrCreate(
                    ['school_id' => $school->id, 'code' => $departmentCode],
                    ['name' => $departmentName],
                );
            }

            $schools[$code] = $school->load('departments');
        }

        return $schools;
    }

    /**
     * @param  array<string, School>  $schools
     * @return array<string, Program>
     */
    private function seedPrograms(array $schools): array
    {
        // code => [school, department, name, max level, sections ([type, weekend])]
        $definitions = [
            'BSN' => ['SONAM', 'NURS', 'BSc Nursing', 400, [['undergraduate', false], ['undergraduate', true]]],
            'BSM' => ['SONAM', 'MIDW', 'BSc Midwifery', 400, [['undergraduate', false], ['sandwich', false]]],
            'BPHN' => ['SONAM', 'PHN', 'BSc Public Health Nursing', 400, [['undergraduate', false]]],
            'BHSA' => ['SONAM', 'HSA', 'BSc Health Service Administration', 400, [['undergraduate', false], ['access', false]]],
            'MBCHB' => ['SOM', 'PATH', 'MB ChB Medicine', 600, [['undergraduate', false]]],
            'BMLS' => ['SOM', 'MBMB', 'BSc Medical Laboratory Science', 400, [['undergraduate', false]]],
            'BPH' => ['SPH', 'EPBI', 'BSc Public Health', 400, [['undergraduate', false], ['sandwich', true]]],
            'BHPM' => ['SPH', 'HPPM', 'BSc Health Policy and Management', 400, [['postgraduate', false]]],
        ];

        $programs = [];

        foreach ($definitions as $code => [$schoolCode, $departmentCode, $name, $maxLevel, $sections]) {
            $department = $schools[$schoolCode]->departments->firstWhere('code', $departmentCode);

            $program = Program::query()->updateOrCreate(
                ['department_id' => $department->id, 'code' => $code],
                ['name' => $name, 'max_level' => $maxLevel],
            );

            foreach ($sections as [$type, $isWeekend]) {
                Section::query()->firstOrCreate([
                    'program_id' => $program->id,
                    'type' => $type,
                    'is_weekend' => $isWeekend,
                ]);
            }

            $programs[$code] = $program->load('sections');
        }

        return $programs;
    }

    /**
     * @return array{current1: Semester, current2: Semester, previous1: Semester, previous2: Semester}
     */
    private function seedAcademicYears(): array
    {
        $previous = $this->academicYear('2025/2026', '2025-08-01', '2026-07-31', 'archived');
        $current = $this->academicYear('2026/2027', '2026-08-01', '2027-07-31', 'active');

        return [
            'previous1' => $previous->semesters->firstWhere('sequence', 1),
            'previous2' => $previous->semesters->firstWhere('sequence', 2),
            'current1' => $current->semesters->firstWhere('sequence', 1),
            'current2' => $current->semesters->firstWhere('sequence', 2),
        ];
    }

    private function academicYear(string $name, string $startsOn, string $endsOn, string $status): AcademicYear
    {
        $academicYear = AcademicYear::query()->updateOrCreate(
            ['name' => $name],
            ['starts_on' => $startsOn, 'ends_on' => $endsOn, 'status' => $status],
        );

        foreach ([1 => 'Semester 1', 2 => 'Semester 2'] as $sequence => $semesterName) {
            Semester::query()->firstOrCreate(
                ['academic_year_id' => $academicYear->id, 'sequence' => $sequence],
                ['name' => $semesterName],
            );
        }

        return $academicYear->load('semesters');
    }

    /**
     * @param  array<string, School>  $schools
     * @return array<string, User>
     */
    private function seedUsers(array $schools): array
    {
        $som = $schools['SOM'];
        $sonam = $schools['SONAM'];
        $sph = $schools['SPH'];

        $department = fn (School $school, string $code): Department => $school->departments->firstWhere('code', $code);

        $users = [
            'director' => $this->user('Test User', 'test@example.com', 'QA-0001', UniversityRole::QaDirector),
            'supervisor' => $this->user('Abena Mensah', 'qa.supervisor@qa.test', 'QA-0002', UniversityRole::QaSupervisor),
            'officer' => $this->user('Kwame Owusu', 'qa.officer@qa.test', 'QA-0003', UniversityRole::QaOfficer),
            'admin' => $this->user('ICT Administrator', 'ict.admin@qa.test', 'ICT-0001', UniversityRole::IctAdmin),
            'committee' => $this->user('Appointment Committee', 'committee@qa.test', 'APC-0001', UniversityRole::AppointmentCommittee),

            'somDean' => $this->user('Prof. Kofi Asante', 'dean.medicine@qa.test', 'SOM-0001', UniversityRole::Dean, $som),
            'sonamDean' => $this->user('Prof. Efua Boateng', 'dean.nursing@qa.test', 'SON-0001', UniversityRole::Dean, $sonam),
            'sphDean' => $this->user('Prof. Yaw Darko', 'dean.publichealth@qa.test', 'SPH-0001', UniversityRole::Dean, $sph),

            'mbmbHod' => $this->user('Dr. Ama Agyeman', 'hod.biochemistry@qa.test', 'SOM-0101', UniversityRole::Hod, $som, $department($som, 'MBMB')),
            'pathHod' => $this->user('Dr. Kojo Antwi', 'hod.pathology@qa.test', 'SOM-0102', UniversityRole::Hod, $som, $department($som, 'PATH')),
            'nursHod' => $this->user('Dr. Akosua Frimpong', 'hod.nursing@qa.test', 'SON-0101', UniversityRole::Hod, $sonam, $department($sonam, 'NURS')),
            'midwHod' => $this->user('Dr. Adwoa Sarpong', 'hod.midwifery@qa.test', 'SON-0102', UniversityRole::Hod, $sonam, $department($sonam, 'MIDW')),
            'epbiHod' => $this->user('Dr. Nana Osei', 'hod.epidemiology@qa.test', 'SPH-0101', UniversityRole::Hod, $sph, $department($sph, 'EPBI')),

            'nursLecturer' => $this->user('Grace Appiah', 'lecturer.nursing@qa.test', 'SON-1001', UniversityRole::Lecturer, $sonam, $department($sonam, 'NURS')),
            'nursLecturer2' => $this->user('Samuel Tetteh', 'lecturer.nursing2@qa.test', 'SON-1002', UniversityRole::Lecturer, $sonam, $department($sonam, 'NURS')),
            'midwLecturer' => $this->user('Patience Addo', 'lecturer.midwifery@qa.test', 'SON-1003', UniversityRole::Lecturer, $sonam, $department($sonam, 'MIDW')),
            'phnLecturer' => $this->user('Joseph Quaye', 'lecturer.phn@qa.test', 'SON-1004', UniversityRole::Lecturer, $sonam, $department($sonam, 'PHN')),
            'pathLecturer' => $this->user('Daniel Ofori', 'lecturer.pathology@qa.test', 'SOM-1001', UniversityRole::Lecturer, $som, $department($som, 'PATH')),
            'mbmbLecturer' => $this->user('Linda Asare', 'lecturer.biochemistry@qa.test', 'SOM-1002', UniversityRole::Lecturer, $som, $department($som, 'MBMB')),
            'sphLecturer' => $this->user('Emmanuel Kumi', 'lecturer.publichealth@qa.test', 'SPH-1001', UniversityRole::Lecturer, $sph, $department($sph, 'EPBI')),
            'hppmLecturer' => $this->user('Rita Amoah', 'lecturer.policy@qa.test', 'SPH-1002', UniversityRole::Lecturer, $sph, $department($sph, 'HPPM')),
            'inactiveLecturer' => $this->user('Former Lecturer', 'lecturer.inactive@qa.test', 'SON-1999', UniversityRole::Lecturer, $sonam, $department($sonam, 'NURS'), isActive: false),
        ];

        $som->update(['dean_id' => $users['somDean']->id]);
        $sonam->update(['dean_id' => $users['sonamDean']->id]);
        $sph->update(['dean_id' => $users['sphDean']->id]);

        $department($som, 'MBMB')->update(['hod_id' => $users['mbmbHod']->id]);
        $department($som, 'PATH')->update(['hod_id' => $users['pathHod']->id]);
        $department($sonam, 'NURS')->update(['hod_id' => $users['nursHod']->id]);
        $department($sonam, 'MIDW')->update(['hod_id' => $users['midwHod']->id]);
        $department($sph, 'EPBI')->update(['hod_id' => $users['epbiHod']->id]);

        return $users;
    }

    private function user(
        string $name,
        string $email,
        string $staffId,
        UniversityRole $role,
        ?School $school = null,
        ?Department $department = null,
        bool $isActive = true,
    ): User {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'staff_id' => $staffId,
                'password' => Hash::make(self::PASSWORD),
                'must_change_password' => false,
                'email_verified_at' => now(),
                'university_role' => $role->value,
                'school_id' => $school?->id,
                'department_id' => $department?->id,
                'is_active' => $isActive,
            ],
        );

        if (! $user->personalTeam()) {
            app(CreateTeam::class)->handle($user, "{$user->name}'s Team", true);
        }

        return $user;
    }

    /**
     * @param  array<string, School>  $schools
     * @param  array<string, Program>  $programs
     * @return array<string, Course>
     */
    private function seedCourses(array $schools, array $programs): array
    {
        $definitions = [
            // key => [school, department, home program, code, title, level, credits, type]
            'nurs201' => ['SONAM', 'NURS', 'BSN', 'NURS 201', 'Fundamentals of Nursing', '200', 3, 'normal'],
            'nurs301' => ['SONAM', 'NURS', 'BSN', 'NURS 301', 'Adult Health Nursing', '300', 3, 'normal'],
            'nurs401' => ['SONAM', 'NURS', 'BSN', 'NURS 401', 'Clinical Nursing Practice', '400', 4, 'module'],
            'midw212' => ['SONAM', 'MIDW', 'BSM', 'MIDW 212', 'Maternal and Newborn Care', '200', 3, 'normal'],
            'midw312' => ['SONAM', 'MIDW', 'BSM', 'MIDW 312', 'High-Risk Pregnancy', '300', 3, 'normal'],
            'phn301' => ['SONAM', 'PHN', 'BPHN', 'PHN 301', 'Community Health Nursing', '300', 3, 'normal'],
            'path302' => ['SOM', 'PATH', 'MBCHB', 'PATH 302', 'General Pathology', '300', 3, 'module'],
            'mbmb201' => ['SOM', 'MBMB', 'BMLS', 'MBMB 201', 'Medical Biochemistry', '200', 3, 'normal'],
            'epbi301' => ['SPH', 'EPBI', 'BPH', 'EPBI 301', 'Principles of Epidemiology', '300', 3, 'normal'],
            'epbi202' => ['SPH', 'EPBI', 'BPH', 'EPBI 202', 'Introductory Biostatistics', '200', 3, 'normal'],
            'hppm301' => ['SPH', 'HPPM', 'BHPM', 'HPPM 301', 'Health Systems and Policy', '300', 3, 'normal'],
        ];

        $courses = [];

        foreach ($definitions as $key => [$schoolCode, $departmentCode, $programCode, $code, $title, $level, $credits, $type]) {
            $department = $schools[$schoolCode]->departments->firstWhere('code', $departmentCode);

            $courses[$key] = Course::query()->updateOrCreate(
                ['department_id' => $department->id, 'code' => $code],
                [
                    'program_id' => $programs[$programCode]->id,
                    'title' => $title,
                    'course_type' => $type,
                    'level' => $level,
                    'credit_hours' => $credits,
                ],
            );
        }

        return $courses;
    }

    /**
     * @param  array<string, Course>  $courses
     * @param  array<string, Program>  $programs
     * @param  array<string, Semester>  $semesters
     * @param  array<string, User>  $users
     */
    private function seedOfferingsAndAssignments(array $courses, array $programs, array $semesters, array $users): void
    {
        // [course, program, semester, level, [[lecturer, students, hours], ...]]
        $allocations = [
            ['nurs201', 'BSN', 'current1', '200', [['nursLecturer2', 120, 45]]],
            ['nurs301', 'BSN', 'current1', '300', [['nursLecturer', 95, 45]]],
            ['nurs301', 'BPHN', 'current1', '300', [['nursLecturer', 40, 30]]],
            ['nurs401', 'BSN', 'current2', '400', [['nursLecturer', 80, 30], ['nursLecturer2', 80, 30], ['nursHod', 80, 15]]],
            ['midw212', 'BSM', 'current1', '200', [['midwLecturer', 70, 45]]],
            ['midw312', 'BSM', 'current2', '300', [['midwLecturer', 65, 45]]],
            ['phn301', 'BPHN', 'current1', '300', [['phnLecturer', 55, 45]]],
            ['path302', 'MBCHB', 'current1', '300', [['pathLecturer', 150, 30], ['pathHod', 150, 15]]],
            ['mbmb201', 'BMLS', 'current1', '200', [['mbmbLecturer', 110, 45]]],
            ['mbmb201', 'MBCHB', 'current1', '200', [['mbmbLecturer', 150, 45]]],
            ['epbi301', 'BPH', 'current1', '300', [['sphLecturer', 85, 45]]],
            ['epbi202', 'BPH', 'current2', '200', [['sphLecturer', 90, 45]]],
            ['hppm301', 'BHPM', 'current1', '300', [['hppmLecturer', 60, 45]]],

            // Previous academic year history
            ['nurs301', 'BSN', 'previous1', '300', [['nursLecturer', 88, 45]]],
            ['midw212', 'BSM', 'previous1', '200', [['midwLecturer', 66, 45]]],
            ['path302', 'MBCHB', 'previous2', '300', [['pathLecturer', 140, 45]]],
            ['epbi301', 'BPH', 'previous2', '300', [['sphLecturer', 80, 45]]],
        ];

        // Sections taught by someone else: [course, program, semester, section type, weekend] => lecturers
        $sectionOverrides = [
            'nurs301:BSN:current1:undergraduate:1' => [['nursLecturer2', 35, 30]],
        ];

        foreach ($allocations as [$courseKey, $programCode, $semesterKey, $level, $lecturers]) {
            $course = $courses[$courseKey];
            $program = $programs[$programCode];
            $semester = $semesters[$semesterKey];

            CourseOffering::query()->updateOrCreate(
                ['course_id' => $course->id, 'program_id' => $program->id, 'semester_id' => $semester->id],
                ['level' => $level],
            );

            DB::table('course_lecturer')
                ->where('course_id', $course->id)
                ->where('program_id', $program->id)
                ->where('semester_id', $semester->id)
                ->delete();

            foreach ($program->sections as $section) {
                $overrideKey = "{$courseKey}:{$programCode}:{$semesterKey}:{$section->type}:".(int) $section->is_weekend;

                foreach ($sectionOverrides[$overrideKey] ?? $lecturers as [$lecturerKey, $students, $hours]) {
                    DB::table('course_lecturer')->insert([
                        'course_id' => $course->id,
                        'program_id' => $program->id,
                        'section_id' => $section->id,
                        'lecturer_id' => $users[$lecturerKey]->id,
                        'semester_id' => $semester->id,
                        'student_count' => $students,
                        'allocated_hours' => $hours,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        CourseSplit::query()->updateOrCreate(
            ['course_id' => $courses['nurs301']->id, 'semester_id' => $semesters['current1']->id],
            ['mode' => 'section_program'],
        );
    }

    /**
     * @param  array<string, Semester>  $semesters
     * @param  array<string, Program>  $programs
     * @param  array<string, User>  $users
     * @param  array<string, Course>  $courses
     */
    private function seedReports(array $semesters, array $programs, array $users, array $courses): void
    {
        $officer = $users['officer'];
        $supervisor = $users['supervisor'];
        $director = $users['director'];

        $uploaded = fn (int $daysAgo) => [$officer, 'uploaded', null, $daysAgo];
        $submitted = fn (int $daysAgo) => [$officer, 'submitted_to_supervisor', null, $daysAgo];
        $forwarded = fn (int $daysAgo, ?string $note = null) => [$supervisor, 'forwarded_to_director', $note, $daysAgo];
        $published = fn (int $daysAgo, ?string $note = null) => [$director, 'published', $note, $daysAgo];

        $reports = [
            // Current year: one report in every workflow stage
            ['Adult Health Nursing course file review', 'draft', 'current1', 'BSN', 'nursLecturer', ['nurs301'], [
                $uploaded(2),
            ]],
            ['Fundamentals of Nursing assessment audit', 'draft', 'current1', 'BSN', 'nursLecturer2', ['nurs201'], [
                $uploaded(12),
                $submitted(11),
                [$supervisor, 'returned_to_officer', 'Attach the moderated marking scheme before resubmitting.', 9],
            ]],
            ['Community Health Nursing fieldwork report', 'submitted', 'current1', 'BPHN', 'phnLecturer', ['phn301'], [
                $uploaded(6),
                $submitted(5),
            ]],
            ['Maternal and Newborn Care skills lab audit', 'submitted', 'current1', 'BSM', 'midwLecturer', ['midw212'], [
                $uploaded(8),
                $submitted(7),
            ]],
            ['Medical Biochemistry practical session review', 'under_review', 'current1', 'BMLS', 'mbmbLecturer', ['mbmb201'], [
                $uploaded(15),
                $submitted(14),
                $forwarded(10, 'Course file complete; recommending approval.'),
            ]],
            ['General Pathology course file audit', 'under_review', 'current1', 'MBCHB', 'pathLecturer', ['path302'], [
                $uploaded(20),
                $submitted(19),
                $forwarded(16),
                [$director, 'returned_to_supervisor', 'Clarify the practical attendance figures.', 13],
                $forwarded(11, 'Attendance figures clarified with the HOD.'),
            ]],
            ['Principles of Epidemiology teaching quality report', 'approved', 'current1', 'BPH', 'sphLecturer', ['epbi301'], [
                $uploaded(30),
                $submitted(29),
                $forwarded(25),
                $published(22, 'Well documented. Approved for printing.'),
            ]],
            ['Health Systems and Policy course evaluation', 'approved', 'current1', 'BHPM', 'hppmLecturer', ['hppm301'], [
                $uploaded(28),
                $submitted(27),
                $forwarded(24),
                $published(21),
            ]],

            // Previous academic year archive
            ['Adult Health Nursing annual moderation report', 'approved', 'previous1', 'BSN', 'nursLecturer', ['nurs301'], [
                $uploaded(300),
                $submitted(298),
                $forwarded(295),
                $published(290),
            ]],
            ['Maternal and Newborn Care annual review', 'approved', 'previous1', 'BSM', 'midwLecturer', ['midw212'], [
                $uploaded(310),
                $submitted(309),
                $forwarded(305),
                $published(300),
            ]],
            ['General Pathology practical audit', 'approved', 'previous2', 'MBCHB', 'pathLecturer', ['path302'], [
                $uploaded(150),
                $submitted(148),
                $forwarded(145),
                $published(140),
            ]],
            ['Epidemiology fieldwork QA report', 'approved', 'previous2', 'BPH', 'sphLecturer', ['epbi301'], [
                $uploaded(160),
                $submitted(158),
                $forwarded(155),
                $published(150),
            ]],
        ];

        // NURS 301 is split by section: Grace Appiah's draft covers only the regular section.
        $sectionsFor = [
            'Adult Health Nursing course file review' => [
                $programs['BSN']->sections->first(fn ($section) => ! $section->is_weekend)->id,
            ],
        ];

        foreach ($reports as [$title, $status, $semesterKey, $programCode, $lecturerKey, $courseKeys, $history]) {
            $lecturer = $users[$lecturerKey];
            $semester = $semesters[$semesterKey];
            $isSubmitted = collect($history)->contains(fn (array $entry) => $entry[1] === 'submitted_to_supervisor');

            $program = $programs[$programCode];
            $sections = $sectionsFor[$title] ?? $program->sections->pluck('id')->all();

            foreach ($sections as $sectionId) {
                $report = QualityReport::query()->updateOrCreate(
                    ['title' => $title, 'section_id' => $sectionId],
                    [
                        'category' => 'Academic Quality',
                        'status' => $status,
                        'academic_year_id' => $semester->academic_year_id,
                        'semester_id' => $semester->id,
                        'summary' => "Quality assurance report for {$lecturer->name}.",
                        'file_path' => $this->demoPdf($title),
                        'school_id' => $lecturer->school_id,
                        'department_id' => $lecturer->department_id,
                        'program_id' => $program->id,
                        'lecturer_id' => $lecturer->id,
                        'uploaded_by' => $officer->id,
                        'assigned_supervisor_id' => $isSubmitted ? $supervisor->id : null,
                    ],
                );

                $report->courses()->sync(collect($courseKeys)->map(fn (string $key) => $courses[$key]->id)->all());

                $this->recordHistory($report, $history);
            }
        }
    }

    /**
     * @param  array<int, array{0: User, 1: string, 2: string|null, 3: int}>  $history
     */
    private function recordHistory(QualityReport $report, array $history): void
    {
        $report->reviews()->delete();

        foreach ($history as [$actor, $action, $note, $daysAgo]) {
            $timestamp = Carbon::now()->subDays($daysAgo)->setTime(10, 0);

            $review = new QualityReportReview([
                'quality_report_id' => $report->id,
                'actor_id' => $actor->id,
                'action' => $action,
                'note' => $note,
            ]);
            $review->created_at = $timestamp;
            $review->updated_at = $timestamp;
            $review->save();
        }

        $firstTimestamp = Carbon::now()->subDays($history[0][3])->setTime(10, 0);
        $report->timestamps = false;
        $report->forceFill(['created_at' => $firstTimestamp])->save();
    }

    private function demoPdf(string $title): string
    {
        $path = 'quality-reports/demo/'.str($title)->slug('-').'.pdf';
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], "Demo QA Report: {$title}");
        $stream = "BT /F1 12 Tf 24 96 Td ({$text}) Tj ET";
        $content = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n".
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n".
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 420 144] /Contents 4 0 R ".
            "/Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> >>\nendobj\n".
            "4 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream\nendobj\n".
            "xref\n0 5\n0000000000 65535 f \ntrailer\n<< /Root 1 0 R /Size 5 >>\nstartxref\n0\n%%EOF";

        Storage::disk('public')->put($path, $content);

        return $path;
    }
}
