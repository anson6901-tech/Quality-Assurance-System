<?php

use App\Enums\UniversityRole;
use App\Models\DocumentFile;
use App\Models\DocumentFolder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function documentsUser(UniversityRole $role = UniversityRole::QaOfficer): User
{
    return User::factory()->create(['university_role' => $role]);
}

it('opens document storage only for roles with the right', function () {
    foreach ([UniversityRole::IctAdmin, UniversityRole::QaDirector, UniversityRole::QaSupervisor, UniversityRole::QaOfficer] as $role) {
        $this->actingAs(documentsUser($role))->get('/quality/documents')->assertOk();
    }

    $this->actingAs(documentsUser(UniversityRole::Lecturer))->get('/quality/documents')->assertForbidden();

    $unplaced = documentsUser();
    $unplaced->forceFill(['placement' => null])->save();
    $this->actingAs($unplaced)->get('/quality/documents')->assertForbidden();
});

it('creates nested folders and uploads files into them', function () {
    Storage::fake('local');
    $user = documentsUser();

    $this->actingAs($user)->post('/quality/documents/folders', ['name' => 'Minutes'])->assertRedirect();
    $minutes = DocumentFolder::query()->sole();

    $this->actingAs($user)->post('/quality/documents/folders', ['name' => '2025', 'parent_id' => $minutes->id])->assertRedirect();
    $year = DocumentFolder::query()->where('name', '2025')->sole();

    // Names must be unique within a folder.
    $this->actingAs($user)->post('/quality/documents/folders', ['name' => 'Minutes'])->assertSessionHasErrors('name');

    $this->actingAs($user)->post('/quality/documents/files', [
        'folder_id' => $year->id,
        'files' => [
            UploadedFile::fake()->create('Board meeting.pdf', 120, 'application/pdf'),
            UploadedFile::fake()->create('Attendance.xlsx', 30),
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    // The same name again gets a number, so nothing is overwritten.
    $this->actingAs($user)->post('/quality/documents/files', [
        'folder_id' => $year->id,
        'files' => [UploadedFile::fake()->create('Board meeting.pdf', 10, 'application/pdf')],
    ]);

    expect(DocumentFile::query()->where('folder_id', $year->id)->orderBy('id')->pluck('name')->all())
        ->toBe(['Board meeting.pdf', 'Attendance.xlsx', 'Board meeting (2).pdf']);

    $file = DocumentFile::query()->first();
    Storage::disk('local')->assertExists($file->path);

    $this->actingAs($user)
        ->get("/quality/documents?folder={$year->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('quality/documents/Index')
            ->where('breadcrumbs', [['id' => $minutes->id, 'name' => 'Minutes'], ['id' => $year->id, 'name' => '2025']])
            ->has('files', 3)
            ->where('files.0.name', 'Attendance.xlsx'));

    $this->actingAs($user)
        ->get('/quality/documents')
        ->assertInertia(fn (Assert $page) => $page
            ->has('folders', 1)
            ->where('folders.0.items', 1)
            ->has('files', 0));
});

it('searches every folder by name', function () {
    Storage::fake('local');
    $user = documentsUser();
    $policies = DocumentFolder::create(['name' => 'Policies']);
    $archive = DocumentFolder::create(['name' => 'Archive', 'parent_id' => $policies->id]);
    DocumentFile::create(['folder_id' => $archive->id, 'name' => 'Quality policy 2024.pdf', 'path' => 'documents/a.pdf', 'size' => 10]);
    DocumentFile::create(['folder_id' => null, 'name' => 'Staff list.xlsx', 'path' => 'documents/b.xlsx', 'size' => 10]);

    $this->actingAs($user)
        ->get('/quality/documents?q=quality 2024')
        ->assertInertia(fn (Assert $page) => $page
            ->where('search', 'quality 2024')
            ->has('files', 1)
            ->where('files.0.name', 'Quality policy 2024.pdf')
            ->where('files.0.location', 'Documents / Policies / Archive'));

    $this->actingAs($user)
        ->get('/quality/documents?q=arch')
        ->assertInertia(fn (Assert $page) => $page
            ->has('folders', 1)
            ->where('folders.0.location', 'Documents / Policies'));
});

it('opens, downloads, renames and deletes files', function () {
    Storage::fake('local');
    $user = documentsUser();

    $this->actingAs($user)->post('/quality/documents/files', [
        'files' => [UploadedFile::fake()->createWithContent('notes.txt', 'Meeting notes')],
    ]);
    $file = DocumentFile::query()->sole();

    $open = $this->actingAs($user)->get("/quality/documents/files/{$file->id}")->assertOk();
    expect($open->headers->get('content-disposition'))->toStartWith('inline')
        ->and($open->headers->get('x-content-type-options'))->toBe('nosniff');

    $download = $this->actingAs($user)->get("/quality/documents/files/{$file->id}/download")->assertOk();
    expect($download->headers->get('content-disposition'))->toStartWith('attachment')
        ->and($download->streamedContent())->toBe('Meeting notes');

    $this->actingAs($user)->patch("/quality/documents/files/{$file->id}", ['name' => 'Minutes.txt'])->assertRedirect();
    expect($file->refresh()->name)->toBe('Minutes.txt');

    $this->actingAs($user)->delete("/quality/documents/files/{$file->id}")->assertRedirect();
    expect(DocumentFile::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($file->path);
});

it('deletes a folder with everything inside it', function () {
    Storage::fake('local');
    $user = documentsUser();
    $top = DocumentFolder::create(['name' => 'Old']);
    $inner = DocumentFolder::create(['name' => 'Inner', 'parent_id' => $top->id]);
    Storage::disk('local')->put('documents/x.pdf', 'x');
    DocumentFile::create(['folder_id' => $inner->id, 'name' => 'x.pdf', 'path' => 'documents/x.pdf', 'size' => 1]);
    $keep = DocumentFolder::create(['name' => 'Keep']);

    $this->actingAs($user)->delete("/quality/documents/folders/{$top->id}")->assertRedirect('/quality/documents');

    expect(DocumentFolder::query()->pluck('id')->all())->toBe([$keep->id])
        ->and(DocumentFile::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing('documents/x.pdf');
});

it('deletes several selected folders and files at once', function () {
    Storage::fake('local');
    $user = documentsUser();
    $old = DocumentFolder::create(['name' => 'Old']);
    $inside = DocumentFolder::create(['name' => 'Inside', 'parent_id' => $old->id]);
    $keepFolder = DocumentFolder::create(['name' => 'Keep']);
    foreach (['a', 'b', 'c', 'd'] as $name) {
        Storage::disk('local')->put("documents/{$name}.pdf", $name);
    }
    $inOld = DocumentFile::create(['folder_id' => $inside->id, 'name' => 'a.pdf', 'path' => 'documents/a.pdf', 'size' => 1]);
    $loose = DocumentFile::create(['folder_id' => null, 'name' => 'b.pdf', 'path' => 'documents/b.pdf', 'size' => 1]);
    $alsoLoose = DocumentFile::create(['folder_id' => null, 'name' => 'c.pdf', 'path' => 'documents/c.pdf', 'size' => 1]);
    $keepFile = DocumentFile::create(['folder_id' => $keepFolder->id, 'name' => 'd.pdf', 'path' => 'documents/d.pdf', 'size' => 1]);

    $this->actingAs($user)
        ->post('/quality/documents/delete-selected', ['folder_ids' => [$old->id], 'file_ids' => [$loose->id, $alsoLoose->id]])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', '3 items deleted.');

    expect(DocumentFolder::query()->pluck('id')->all())->toBe([$keepFolder->id])
        ->and(DocumentFile::query()->pluck('id')->all())->toBe([$keepFile->id]);
    Storage::disk('local')->assertMissing(['documents/a.pdf', 'documents/b.pdf', 'documents/c.pdf']);
    Storage::disk('local')->assertExists('documents/d.pdf');

    $this->actingAs($user)->post('/quality/documents/delete-selected', [])->assertSessionHasErrors('selection');
    $this->actingAs(documentsUser(UniversityRole::Lecturer))
        ->post('/quality/documents/delete-selected', ['file_ids' => [$keepFile->id]])
        ->assertForbidden();
});

it('moves folders and files into another folder', function () {
    Storage::fake('local');
    $user = documentsUser();
    $inbox = DocumentFolder::create(['name' => 'Inbox']);
    $archive = DocumentFolder::create(['name' => 'Archive']);
    $sub = DocumentFolder::create(['name' => 'Old minutes', 'parent_id' => $inbox->id]);
    $file = DocumentFile::create(['folder_id' => $inbox->id, 'name' => 'Report.pdf', 'path' => 'documents/r.pdf', 'size' => 1]);
    DocumentFile::create(['folder_id' => $archive->id, 'name' => 'Report.pdf', 'path' => 'documents/s.pdf', 'size' => 1]);

    $this->actingAs($user)
        ->post('/quality/documents/move-selected', ['folder_ids' => [$sub->id], 'file_ids' => [$file->id], 'target_id' => $archive->id])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', '2 items moved to "Archive".');

    expect($sub->refresh()->parent_id)->toBe($archive->id)
        ->and($file->refresh()->folder_id)->toBe($archive->id)
        // The destination already had a Report.pdf.
        ->and($file->name)->toBe('Report (2).pdf');

    // To the top level.
    $this->actingAs($user)->post('/quality/documents/move-selected', ['file_ids' => [$file->id], 'target_id' => null]);
    expect($file->refresh()->folder_id)->toBeNull();

    // Never into itself or a folder inside it.
    $this->actingAs($user)
        ->post('/quality/documents/move-selected', ['folder_ids' => [$archive->id], 'target_id' => $sub->id])
        ->assertSessionHasErrors('target_id');
    expect($archive->refresh()->parent_id)->toBeNull();

    $this->actingAs($user)->get('/quality/documents')
        ->assertInertia(fn (Assert $page) => $page->has('tree', 3));
});
