<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['department_id', 'program_id', 'code', 'title', 'course_type', 'level', 'credit_hours'])]
class Course extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function lecturers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_lecturer', 'course_id', 'lecturer_id')
            ->whereIn('university_role', QualityRolePermission::rolesWith('view_own_reports'))
            ->withPivot(['program_id', 'semester_id', 'student_count', 'allocated_hours'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<QualityReport, $this>
     */
    public function qualityReports(): BelongsToMany
    {
        return $this->belongsToMany(QualityReport::class, 'course_quality_report')
            ->withTimestamps();
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }
}
