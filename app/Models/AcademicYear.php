<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'starts_on', 'ends_on', 'status'])]
class AcademicYear extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (self $academicYear): void {
            $academicYear->semesters()->createMany([
                ['name' => 'Semester 1', 'sequence' => 1],
                ['name' => 'Semester 2', 'sequence' => 2],
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * @param  Builder<AcademicYear>  $query
     * @return Builder<AcademicYear>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Years offered when uploading: active years first (the default), then archived ones,
     * newest first.
     *
     * @param  Builder<AcademicYear>  $query
     * @return Builder<AcademicYear>
     */
    public function scopeForUploads(Builder $query): Builder
    {
        return $query
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderByDesc('starts_on')
            ->orderByDesc('id');
    }

    /**
     * The name shown in upload lists, e.g. "2024/2025 (archived)".
     */
    public function uploadLabel(): string
    {
        return $this->status === 'active' ? $this->name : "{$this->name} (archived)";
    }

    /**
     * @return HasMany<QualityReport, $this>
     */
    public function qualityReports(): HasMany
    {
        return $this->hasMany(QualityReport::class);
    }

    /** @return HasMany<Semester, $this> */
    public function semesters(): HasMany
    {
        return $this->hasMany(Semester::class)->orderBy('sequence');
    }
}
