<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Student Evaluation of Teaching data file and its analysis. Once finished it becomes a
 * draft report for the course's lecturer, or waits in the "Waiting for Lecturer" folder.
 */
#[Fillable([
    'user_id',
    'academic_year_id',
    'semester_id',
    'original_name',
    'file_path',
    'dataset',
    'frequencies',
    'status',
    'course_id',
    'lecturer_id',
    'assignment_key',
    'course_code',
    'course_title',
    'lecturer_name',
    'level',
    'school_label',
    'quality_report_ids',
    'drafted_at',
])]
class EvaluationAnalysis extends Model
{
    public const ANALYSED = 'analysed';

    public const WAITING = 'waiting';

    public const DRAFTED = 'drafted';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dataset' => 'array',
            'frequencies' => 'array',
            'quality_report_ids' => 'array',
            'drafted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<Semester, $this> */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
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
