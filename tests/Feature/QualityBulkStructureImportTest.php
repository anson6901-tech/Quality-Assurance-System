<?php

use App\Enums\UniversityRole;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Program;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\UploadedFile;

test('structure templates expose the exact importer columns', function (string $type, string $header) {
    $administrator = User::factory()->create([
        'university_role' => UniversityRole::QaDirector,
    ]);

    $response = $this->actingAs($administrator)
        ->get("/quality/bulk/{$type}/template")
        ->assertOk()
        ->assertDownload("{$type}-import-template.csv");

    expect($response->streamedContent())->toContain($header);
})->with([
    ['schools', 'name,code'],
    ['departments', 'school_code,name,code'],
    ['programs', 'school_code,department_code,name,code,max_level'],
    ['academic-years', 'name,starts_on,ends_on,status'],
]);

test('academic structure can be imported in dependency order', function () {
    $administrator = User::factory()->create([
        'university_role' => UniversityRole::QaDirector,
    ]);

    $imports = [
        'schools' => "name,code\nSchool of Nursing,SON",
        'departments' => "school_code,name,code\nSON,Nursing,NURS",
        'programs' => "school_code,department_code,name,code,max_level\nSON,NURS,BSc Nursing,BSCN,400",
        'academic-years' => "name,starts_on,ends_on,status\n2026/2027,2026-08-01,2027-07-31,active",
    ];

    foreach ($imports as $type => $csv) {
        $this->actingAs($administrator)
            ->post("/quality/bulk/{$type}", [
                'structure_file' => UploadedFile::fake()->createWithContent("{$type}.csv", $csv),
            ])
            ->assertRedirect("/quality/{$type}");
    }

    expect(School::where('code', 'SON')->exists())->toBeTrue()
        ->and(Department::where('code', 'NURS')->exists())->toBeTrue()
        ->and(Program::where('code', 'BSCN')->exists())->toBeTrue()
        ->and(AcademicYear::where('name', '2026/2027')->exists())->toBeTrue();
});
