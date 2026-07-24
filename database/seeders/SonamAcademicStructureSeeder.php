<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SonamAcademicStructureSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $school = School::updateOrCreate(
                ['code' => 'SONAM'],
                ['name' => 'School of Nursing and Midwifery'],
            );

            foreach ($this->departments() as $department) {
                Department::updateOrCreate(
                    ['school_id' => $school->id, 'code' => $department['code']],
                    ['name' => $department['name']],
                );
            }
        });
    }

    /**
     * @return array<int, array{name: string, code: string}>
     */
    private function departments(): array
    {
        return [
            ['name' => 'Nursing', 'code' => 'NURS'],
            ['name' => 'Midwifery', 'code' => 'MIDW'],
            ['name' => 'Public Health Nursing', 'code' => 'PHN'],
            ['name' => 'Health Service Administration', 'code' => 'HSA'],
        ];
    }
}
