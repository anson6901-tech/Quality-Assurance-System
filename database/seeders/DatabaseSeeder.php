<?php

namespace Database\Seeders;

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Department;
use App\Models\QualityReport;
use App\Models\School;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $schools = collect([
            ['name' => 'School of Medicine', 'code' => 'SOM', 'departments' => [
                ['name' => 'Medical Biochemistry and Molecular Biology', 'code' => 'MBMB'],
                ['name' => 'Pathology', 'code' => 'PATH'],
            ]],
            ['name' => 'School of Nursing and Midwifery', 'code' => 'SONAM', 'departments' => [
                ['name' => 'Nursing', 'code' => 'NURS'],
                ['name' => 'Midwifery', 'code' => 'MIDW'],
            ]],
            ['name' => 'School of Public Health', 'code' => 'SPH', 'departments' => [
                ['name' => 'Epidemiology and Biostatistics', 'code' => 'EPBI'],
                ['name' => 'Health Policy Planning and Management', 'code' => 'HPPM'],
            ]],
        ])->map(function (array $schoolData) {
            $school = School::firstOrCreate(
                ['code' => $schoolData['code']],
                ['name' => $schoolData['name']],
            );

            foreach ($schoolData['departments'] as $departmentData) {
                Department::firstOrCreate(
                    ['school_id' => $school->id, 'code' => $departmentData['code']],
                    ['name' => $departmentData['name']],
                );
            }

            return $school->load('departments');
        });

        $medicine = $schools->firstWhere('code', 'SOM');
        $nursing = $schools->firstWhere('code', 'SONAM');
        $medicineDepartment = $medicine->departments->first();
        $nursingDepartment = $nursing->departments->first();

        $academicYear = AcademicYear::firstOrCreate(
            ['name' => '2025/2026'],
            [
                'starts_on' => '2025-08-01',
                'ends_on' => '2026-07-31',
                'status' => 'active',
            ],
        );

        $qaOfficer = $this->createOrUpdateUser([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'university_role' => UniversityRole::QaDirector,
            'school_id' => null,
            'department_id' => null,
        ]);

        $deanMedicine = $this->createOrUpdateUser([
            'name' => 'Dean Medicine',
            'email' => 'dean@example.com',
            'university_role' => UniversityRole::Dean,
            'school_id' => $medicine->id,
            'department_id' => null,
        ]);

        $hodBiochemistry = $this->createOrUpdateUser([
            'name' => 'HOD Biochemistry',
            'email' => 'hod@example.com',
            'university_role' => UniversityRole::Hod,
            'school_id' => $medicine->id,
            'department_id' => $medicineDepartment->id,
        ]);

        $lecturerNursing = $this->createOrUpdateUser([
            'name' => 'Lecturer Nursing',
            'email' => 'lecturer@example.com',
            'university_role' => UniversityRole::Lecturer,
            'school_id' => $nursing->id,
            'department_id' => $nursingDepartment->id,
        ]);

        $medicine->update(['dean_id' => $deanMedicine->id]);
        $medicineDepartment->update(['hod_id' => $hodBiochemistry->id]);

        $nursingCourse = Course::firstOrCreate(
            ['department_id' => $nursingDepartment->id, 'code' => 'NURS 301'],
            [
                'title' => 'Adult Health Nursing',
                'level' => 'Level 300',
                'credit_hours' => 3,
            ],
        );

        $midwiferyCourse = Course::firstOrCreate(
            ['department_id' => $nursingDepartment->id, 'code' => 'MIDW 212'],
            [
                'title' => 'Maternal and Newborn Care',
                'level' => 'Level 200',
                'credit_hours' => 3,
            ],
        );

        $lecturerNursing->assignedCourses()->syncWithoutDetaching([
            $nursingCourse->id,
            $midwiferyCourse->id,
        ]);

        $report = QualityReport::firstOrCreate(
            ['title' => 'Semester Course File Audit'],
            [
                'category' => 'Academic Quality',
                'status' => 'submitted',
                'academic_year_id' => $academicYear->id,
                'summary' => 'Initial QA review of course file completeness and assessment records.',
                'school_id' => $nursing->id,
                'department_id' => $nursingDepartment->id,
                'lecturer_id' => $lecturerNursing->id,
                'uploaded_by' => $qaOfficer->id,
            ],
        );

        $report->update(['academic_year_id' => $academicYear->id]);

        $report->courses()->syncWithoutDetaching([
            $nursingCourse->id,
            $midwiferyCourse->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOrUpdateUser(array $attributes): User
    {
        $user = User::where('email', $attributes['email'])->first();

        if ($user) {
            $user->forceFill($attributes)->save();

            return $user;
        }

        return User::factory()->create([
            ...$attributes,
            'password' => Hash::make('password'),
        ]);
    }
}
