<?php

use App\Enums\UniversityRole;
use App\Models\Department;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

test('the user import template contains valid scope codes and required columns', function () {
    $administrator = User::factory()->create([
        'university_role' => UniversityRole::IctAdmin,
    ]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    Department::create([
        'school_id' => $school->id,
        'name' => 'Nursing',
        'code' => 'NURS',
    ]);

    $response = $this->actingAs($administrator)
        ->get('/quality/users/import-template')
        ->assertOk()
        ->assertDownload('user-import-template.csv');

    expect($response->streamedContent())
        ->toContain('first_name,last_name,email,staff_id,university_role,school_code,department_code,temporary_password')
        ->toContain('lecturer,SON,NURS');
});

test('valid bulk users are imported with a required first-login password change', function () {
    $administrator = User::factory()->create([
        'university_role' => UniversityRole::IctAdmin,
    ]);
    $school = School::create(['name' => 'School of Nursing', 'code' => 'SON']);
    $department = Department::create([
        'school_id' => $school->id,
        'name' => 'Nursing',
        'code' => 'NURS',
    ]);
    $csv = implode("\n", [
        'first_name,last_name,email,staff_id,university_role,school_code,department_code,temporary_password',
        'Ama,Mensah,ama.mensah@example.test,UH-100,lecturer,SON,NURS,Temporary-Password-2026',
    ]);

    $this->actingAs($administrator)
        ->post('/quality/users/import', [
            'users_file' => UploadedFile::fake()->createWithContent('users.csv', $csv),
        ])
        ->assertRedirect('/quality/users');

    $user = User::where('email', 'ama.mensah@example.test')->firstOrFail();

    expect($user->name)->toBe('Ama Mensah')
        ->and($user->school_id)->toBe($school->id)
        ->and($user->department_id)->toBe($department->id)
        ->and($user->must_change_password)->toBeTrue()
        ->and(Hash::check('Temporary-Password-2026', $user->password))->toBeTrue();
});

test('invalid and duplicate bulk user rows are skipped without stopping valid rows', function () {
    $administrator = User::factory()->create([
        'university_role' => UniversityRole::IctAdmin,
    ]);
    User::factory()->create(['email' => 'duplicate@example.test']);
    $csv = implode("\n", [
        'first_name,last_name,email,staff_id,university_role,school_code,department_code,temporary_password',
        'Duplicate,User,duplicate@example.test,,qa_officer,,,Temporary-Password-2026',
        'Valid,Officer,valid.admin@example.test,,qa_officer,,,Temporary-Password-2026',
    ]);

    $this->actingAs($administrator)
        ->post('/quality/users/import', [
            'users_file' => UploadedFile::fake()->createWithContent('users.csv', $csv),
        ])
        ->assertRedirect('/quality/users');

    expect(User::where('email', 'valid.admin@example.test')->exists())->toBeTrue()
        ->and(User::where('email', 'duplicate@example.test')->count())->toBe(1);
});
