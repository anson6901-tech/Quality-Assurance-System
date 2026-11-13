<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A file kept in Document Storage. The file itself is on the private "local" disk.
 */
#[Fillable(['folder_id', 'name', 'path', 'mime_type', 'size', 'uploaded_by'])]
class DocumentFile extends Model
{
    /** @return BelongsTo<DocumentFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Keeps generated content (e.g. a report) as a file in a folder.
     */
    public static function store(string $contents, string $name, string $mimeType, ?int $folderId, ?User $user): self
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION) ?: 'bin';
        $path = 'documents/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, $contents);

        return self::create([
            'folder_id' => $folderId,
            'name' => self::uniqueName($folderId, $name),
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => strlen($contents),
            'uploaded_by' => $user?->id,
        ]);
    }

    /**
     * The name, or "name (2).ext", "name (3).ext"… when it is already taken in that folder.
     */
    public static function uniqueName(?int $folderId, string $name, ?int $exceptId = null): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $candidate = $name;
        $number = 1;

        while (self::query()->where('folder_id', $folderId)->where('name', $candidate)->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))->exists()) {
            $candidate = $base.' ('.(++$number).')'.($extension !== '' ? ".{$extension}" : '');
        }

        return $candidate;
    }
}
