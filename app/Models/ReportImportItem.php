<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded PDF in a bulk upload and what it was matched to.
 */
#[Fillable([
    'report_import_batch_id',
    'original_name',
    'file_path',
    'size',
    'status',
    'problem',
    'course_id',
    'lecturer_id',
    'part_key',
    'cells',
    'candidates',
    'report_ids',
])]
class ReportImportItem extends Model
{
    public const READY = 'ready';

    public const NEEDS_CHOICE = 'needs_choice';

    public const ERROR = 'error';

    public const CREATED = 'created';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cells' => 'array',
            'candidates' => 'array',
            'report_ids' => 'array',
        ];
    }

    /** @return BelongsTo<ReportImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ReportImportBatch::class, 'report_import_batch_id');
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function lecturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lecturer_id');
    }
}
