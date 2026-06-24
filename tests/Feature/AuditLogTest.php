<?php

use App\Enums\UniversityRole;
use App\Models\AuditLog;
use App\Models\User;

it('records user logins for auditing', function () {
    $user = User::factory()->create([
        'email' => 'auditor@example.com',
        'university_role' => UniversityRole::QaDirector,
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect();

    expect(AuditLog::query()
        ->where('user_id', $user->id)
        ->where('event', 'login')
        ->where('module', 'Authentication')
        ->exists())->toBeTrue();
});

it('records module visits and write actions', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    $this->actingAs($admin)
        ->get('/quality/users')
        ->assertOk();

    $this->actingAs($admin)
        ->post('/quality/schools', [
            'name' => 'School of Audit',
            'code' => 'SOA',
        ])
        ->assertRedirect('/quality/schools');

    expect(AuditLog::query()
        ->where('user_id', $admin->id)
        ->where('event', 'module_visit')
        ->where('module', 'Users')
        ->exists())->toBeTrue()
        ->and(AuditLog::query()
            ->where('user_id', $admin->id)
            ->where('event', 'action')
            ->where('module', 'Schools')
            ->where('action', 'quality.schools.store')
            ->exists())->toBeTrue();
});

it('records detailed metadata without storing sensitive values', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $target = User::factory()->create(['university_role' => UniversityRole::Lecturer]);

    $this->actingAs($admin)
        ->patch("/quality/users/{$target->id}/password", [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertRedirect('/quality/users');

    $log = AuditLog::query()
        ->where('user_id', $admin->id)
        ->where('event', 'action')
        ->where('module', 'Users')
        ->where('action', 'quality.users.password')
        ->firstOrFail();

    expect($log->metadata)
        ->toHaveKey('summary')
        ->toHaveKey('route', 'quality.users.password')
        ->toHaveKey('status', 302)
        ->and($log->metadata['target']['id'])->toBe($target->id)
        ->and($log->metadata['target']['label'])->toBe($target->name)
        ->and($log->metadata['submitted']['sensitive_fields_present'])->toContain('password')
        ->and(json_encode($log->metadata))->not->toContain('new-password');
});

it('shows audit logs only to users with audit access', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);
    $lecturer = User::factory()->create(['university_role' => UniversityRole::Lecturer]);

    AuditLog::query()->create([
        'user_id' => $admin->id,
        'event' => 'login',
        'module' => 'Authentication',
        'action' => 'login',
        'method' => 'POST',
        'path' => 'login',
    ]);

    $this->actingAs($lecturer)
        ->get('/quality/audit-logs')
        ->assertForbidden();

    $this->actingAs($admin)
        ->get('/quality/audit-logs')
        ->assertOk();
});

it('exports audit logs as a csv for users with audit access', function () {
    $admin = User::factory()->create(['university_role' => UniversityRole::IctAdmin]);

    AuditLog::query()->create([
        'user_id' => $admin->id,
        'event' => 'action',
        'module' => 'Roles & Rights',
        'action' => 'quality.roles-rights.update',
        'method' => 'PATCH',
        'path' => 'quality/roles-rights/lecturer',
        'ip_address' => '127.0.0.1',
        'metadata' => [
            'summary' => 'Updated Roles & Rights for Lecturer. Fields: permissions.',
            'status' => 302,
            'route' => 'quality.roles-rights.update',
            'target' => [
                'type' => 'Role',
                'value' => 'lecturer',
            ],
            'submitted' => [
                'field_names' => ['role', 'permissions'],
            ],
            'actor' => [
                'role' => UniversityRole::IctAdmin->value,
            ],
        ],
    ]);

    $response = $this->actingAs($admin)
        ->get('/quality/audit-logs/export')
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($response->streamedContent())
        ->toContain('User name')
        ->toContain('Roles & Rights')
        ->toContain('Updated Roles & Rights for Lecturer')
        ->toContain('role, permissions');
});
