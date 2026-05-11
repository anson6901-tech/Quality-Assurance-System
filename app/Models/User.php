<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTeams;
use App\Enums\UniversityRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'email_verified_at', 'current_team_id', 'university_role', 'school_id', 'department_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'university_role' => UniversityRole::class,
        ];
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<QualityReport, $this>
     */
    public function uploadedQualityReports(): HasMany
    {
        return $this->hasMany(QualityReport::class, 'uploaded_by');
    }

    /**
     * @return HasMany<QualityReport, $this>
     */
    public function lecturerQualityReports(): HasMany
    {
        return $this->hasMany(QualityReport::class, 'lecturer_id');
    }

    /**
     * @return BelongsToMany<Course, $this>
     */
    public function assignedCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_lecturer', 'lecturer_id', 'course_id')
            ->withTimestamps();
    }

    public function isQaOfficer(): bool
    {
        return $this->university_role === UniversityRole::QaOfficer;
    }

    public function canManageQualityStructure(): bool
    {
        return $this->university_role?->canManageQualityStructure() ?? false;
    }

    public function canUploadQualityReports(): bool
    {
        return $this->university_role?->canUploadReports() ?? false;
    }

    /**
     * @return array{role: string|null, roleLabel: string|null, school: array{id: int, name: string}|null, department: array{id: int, name: string}|null, canManageQualityStructure: bool, canUploadReports: bool}
     */
    public function qualityProfile(): array
    {
        return [
            'role' => $this->university_role?->value,
            'roleLabel' => $this->university_role?->label(),
            'school' => $this->school ? [
                'id' => $this->school->id,
                'name' => $this->school->name,
            ] : null,
            'department' => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null,
            'canManageQualityStructure' => $this->canManageQualityStructure(),
            'canUploadReports' => $this->canUploadQualityReports(),
        ];
    }
}
