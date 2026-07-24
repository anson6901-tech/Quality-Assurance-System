<?php

namespace Database\Seeders;

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Department;
use App\Models\QualityReport;
use App\Models\QualityRolePermission;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class QualityDemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $this->resetStandardRoleRights();

        $schools = $this->seedSchools();
        $academicYear = AcademicYear::query()->updateOrCreate(
            ['name' => '2025/2026'],
            [
                'starts_on' => '2025-08-01',
                'ends_on' => '2026-07-31',
                'status' => 'active',
            ],
        );

        AcademicYear::query()->updateOrCreate(
            ['name' => '2024/2025'],
            [
                'starts_on' => '2024-08-01',
                'ends_on' => '2025-07-31',
                'status' => 'archived',
            ],
        );

        $users = $this->seedUsers($schools);
        $courses = $this->seedCourses($schools, $users);

        $this->seedReports($academicYear, $schools, $users, $courses);
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
     * @return array<string, User>
     */
    private function seedUsers(array $schools): array
    {
        $medicine = $schools['SOM'];
        $nursing = $schools['SONAM'];
        $publicHealth = $schools['SPH'];

        $medicineBiochemistry = $medicine->departments->firstWhere('code', 'MBMB');
        $medicinePathology = $medicine->departments->firstWhere('code', 'PATH');
        $nursingDepartment = $nursing->departments->firstWhere('code', 'NURS');
        $midwiferyDepartment = $nursing->departments->firstWhere('code', 'MIDW');
        $epidemiologyDepartment = $publicHealth->departments->firstWhere('code', 'EPBI');

        $users = [
            'director' => $this->user('Test User', 'test@example.com', UniversityRole::QaDirector),
            'supervisor' => $this->user('QA Supervisor', 'qa.supervisor@qa.test', UniversityRole::QaSupervisor),
            'officer' => $this->user('QA Officer', 'qa.officer@qa.test', UniversityRole::QaOfficer),
            'admin' => $this->user('ICT Administrator', 'ict.admin@qa.test', UniversityRole::IctAdmin),
            'medicineDean' => $this->user('Dean Medicine', 'dean.medicine@qa.test', UniversityRole::Dean, $medicine),
            'nursingDean' => $this->user('Dean Nursing', 'dean.nursing@qa.test', UniversityRole::Dean, $nursing),
            'medicineHod' => $this->user('HOD Biochemistry', 'hod.biochemistry@qa.test', UniversityRole::Hod, $medicine, $medicineBiochemistry),
            'nursingHod' => $this->user('HOD Nursing', 'hod.nursing@qa.test', UniversityRole::Hod, $nursing, $nursingDepartment),
            'nursingLecturer' => $this->user('Lecturer Nursing', 'lecturer.nursing@qa.test', UniversityRole::Lecturer, $nursing, $nursingDepartment),
            'midwiferyLecturer' => $this->user('Lecturer Midwifery', 'lecturer.midwifery@qa.test', UniversityRole::Lecturer, $nursing, $midwiferyDepartment),
            'pathologyLecturer' => $this->user('Lecturer Pathology', 'lecturer.pathology@qa.test', UniversityRole::Lecturer, $medicine, $medicinePathology),
            'publicHealthLecturer' => $this->user('Lecturer Public Health', 'lecturer.publichealth@qa.test', UniversityRole::Lecturer, $publicHealth, $epidemiologyDepartment),
            'committee' => $this->user('Appointment Committee', 'committee@qa.test', UniversityRole::AppointmentCommittee),
        ];

        $medicine->update(['dean_id' => $users['medicineDean']->id]);
        $nursing->update(['dean_id' => $users['nursingDean']->id]);
        $medicineBiochemistry?->update(['hod_id' => $users['medicineHod']->id]);
        $nursingDepartment?->update(['hod_id' => $users['nursingHod']->id]);

        return $users;
    }

    private function user(
        string $name,
        string $email,
        UniversityRole $role,
        ?School $school = null,
        ?Department $department = null,
    ): User {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'university_role' => $role->value,
                'school_id' => $school?->id,
                'department_id' => $department?->id,
            ],
        );
    }

    /**
     * @param  array<string, School>  $schools
     * @param  array<string, User>  $users
     * @return array<string, Course>
     */
    private function seedCourses(array $schools, array $users): array
    {
        $courses = [
            'nursingAdult' => $this->course($schools['SONAM'], 'NURS', 'NURS 301', 'Adult Health Nursing', 'Level 300'),
            'nursingClinical' => $this->course($schools['SONAM'], 'NURS', 'NURS 401', 'Clinical Nursing Practice', 'Level 400'),
            'midwiferyCare' => $this->course($schools['SONAM'], 'MIDW', 'MIDW 212', 'Maternal and Newborn Care', 'Level 200'),
            'pathologyGeneral' => $this->course($schools['SOM'], 'PATH', 'PATH 302', 'General Pathology', 'Level 300'),
            'biochemistry' => $this->course($schools['SOM'], 'MBMB', 'MBMB 201', 'Medical Biochemistry', 'Level 200'),
            'epidemiology' => $this->course($schools['SPH'], 'EPBI', 'EPBI 301', 'Principles of Epidemiology', 'Level 300'),
        ];

        $users['nursingLecturer']->assignedCourses()->syncWithoutDetaching([
            $courses['nursingAdult']->id,
            $courses['nursingClinical']->id,
        ]);

        $users['midwiferyLecturer']->assignedCourses()->syncWithoutDetaching([
            $courses['midwiferyCare']->id,
        ]);

        $users['pathologyLecturer']->assignedCourses()->syncWithoutDetaching([
            $courses['pathologyGeneral']->id,
        ]);

        $users['publicHealthLecturer']->assignedCourses()->syncWithoutDetaching([
            $courses['epidemiology']->id,
        ]);

        return $courses;
    }

    private function course(School $school, string $departmentCode, string $code, string $title, string $level): Course
    {
        $department = $school->departments->firstWhere('code', $departmentCode);

        return Course::query()->updateOrCreate(
            ['department_id' => $department->id, 'code' => $code],
            [
                'title' => $title,
                'level' => $level,
                'credit_hours' => 3,
            ],
        );
    }

    /**
     * @param  array<string, School>  $schools
     * @param  array<string, User>  $users
     * @param  array<string, Course>  $courses
     */
    private function seedReports(AcademicYear $academicYear, array $schools, array $users, array $courses): void
    {
        $this->report('QA-DEMO-NURS-SUBMITTED', 'Nursing course file readiness review', 'submitted', $academicYear, $users['nursingLecturer'], $users['officer'], [
            $courses['nursingAdult'],
        ]);

        $this->report('QA-DEMO-NURS-REVIEW', 'Nursing clinical placement audit', 'under_review', $academicYear, $users['nursingLecturer'], $users['officer'], [
            $courses['nursingClinical'],
        ]);

        $this->report('QA-DEMO-NURS-APPROVED', 'Nursing assessment moderation report', 'approved', $academicYear, $users['nursingLecturer'], $users['director'], [
            $courses['nursingAdult'],
        ]);

        $this->report('QA-DEMO-MIDW-APPROVED', 'Midwifery skills lab audit', 'approved', $academicYear, $users['midwiferyLecturer'], $users['director'], [
            $courses['midwiferyCare'],
        ]);

        $this->report('QA-DEMO-PATH-REVIEW', 'Pathology practical session review', 'under_review', $academicYear, $users['pathologyLecturer'], $users['officer'], [
            $courses['pathologyGeneral'],
        ]);

        $this->report('QA-DEMO-PATH-APPROVED', 'Pathology course file audit', 'approved', $academicYear, $users['pathologyLecturer'], $users['director'], [
            $courses['pathologyGeneral'],
        ]);

        $this->report('QA-DEMO-SPH-APPROVED', 'Public health fieldwork QA report', 'approved', $academicYear, $users['publicHealthLecturer'], $users['director'], [
            $courses['epidemiology'],
        ]);

        $this->courseOnlyReport(
            'QA-DEMO-COURSE-ONLY',
            'Shared nursing course archive audit',
            'approved',
            $academicYear,
            $schools['SONAM'],
            $schools['SONAM']->departments->firstWhere('code', 'NURS'),
            $users['director'],
            [$courses['nursingClinical']],
        );
    }

    /**
     * @param  array<int, Course>  $courses
     */
    private function report(
        string $key,
        string $title,
        string $status,
        AcademicYear $academicYear,
        User $lecturer,
        User $uploader,
        array $courses,
    ): QualityReport {
        $path = $this->demoPdf($key, $title);

        $report = QualityReport::query()->updateOrCreate(
            ['title' => $title],
            [
                'category' => 'Academic Quality',
                'status' => $status,
                'academic_year_id' => $academicYear->id,
                'summary' => "Demo report for {$lecturer->name}.",
                'file_path' => $path,
                'school_id' => $lecturer->school_id,
                'department_id' => $lecturer->department_id,
                'lecturer_id' => $lecturer->id,
                'uploaded_by' => $uploader->id,
            ],
        );

        $report->courses()->sync(collect($courses)->pluck('id')->all());

        return $report;
    }

    /**
     * @param  array<int, Course>  $courses
     */
    private function courseOnlyReport(
        string $key,
        string $title,
        string $status,
        AcademicYear $academicYear,
        School $school,
        Department $department,
        User $uploader,
        array $courses,
    ): QualityReport {
        $path = $this->demoPdf($key, $title);

        $report = QualityReport::query()->updateOrCreate(
            ['title' => $title],
            [
                'category' => 'Academic Quality',
                'status' => $status,
                'academic_year_id' => $academicYear->id,
                'summary' => 'Demo course-level report without a named lecturer.',
                'file_path' => $path,
                'school_id' => $school->id,
                'department_id' => $department->id,
                'lecturer_id' => null,
                'uploaded_by' => $uploader->id,
            ],
        );

        $report->courses()->sync(collect($courses)->pluck('id')->all());

        return $report;
    }

    private function demoPdf(string $key, string $title): string
    {
        $path = 'quality-reports/demo/'.str($key)->lower()->slug('-').'.pdf';
        $content = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n".
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n".
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 144] /Contents 4 0 R >>\nendobj\n".
            "4 0 obj\n<< /Length ".(44 + strlen($title))." >>\nstream\nBT /F1 12 Tf 24 96 Td (Demo QA Report: {$title}) Tj ET\nendstream\nendobj\n".
            "xref\n0 5\n0000000000 65535 f \ntrailer\n<< /Root 1 0 R /Size 5 >>\nstartxref\n0\n%%EOF";

        Storage::disk('public')->put($path, $content);

        return $path;
    }
}
