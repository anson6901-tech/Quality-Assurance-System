<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder in Document Storage. Folders nest; a null parent is the top level.
 */
#[Fillable(['parent_id', 'name', 'created_by'])]
class DocumentFolder extends Model
{
    /** @return BelongsTo<DocumentFolder, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<DocumentFolder, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<DocumentFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(DocumentFile::class, 'folder_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * This folder and the folders above it, top level first.
     *
     * @return array<int, DocumentFolder>
     */
    public function ancestry(): array
    {
        $chain = [];
        $folder = $this;
        $seen = [];

        while ($folder && ! isset($seen[$folder->id])) {
            $seen[$folder->id] = true;
            array_unshift($chain, $folder);
            $folder = $folder->parent_id ? self::query()->find($folder->parent_id) : null;
        }

        return $chain;
    }

    /**
     * IDs of this folder and every folder inside it.
     *
     * @return array<int, int>
     */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $level = [$this->id];

        while ($level !== []) {
            $level = self::query()->whereIn('parent_id', $level)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }
}
