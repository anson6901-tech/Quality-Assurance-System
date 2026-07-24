<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTeams;
use App\Enums\UniversityRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'staff_id', 'password', 'must_change_password', 'email_verified_at', 'current_team_id', 'university_role', 'school_id', 'department_id', 'is_active'])]
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
            'must_change_password' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return Attribute<string|null, string|UniversityRole|null>
     */
    protected function universityRole(): Attribute
    {
        return Attribute::make(
            set: fn (string|UniversityRole|null $value): ?string => $value instanceof UniversityRole ? $value->value : $value,
        );
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
            ->withPivot(['program_id', 'semester_id', 'student_count', 'allocated_hours'])
            ->withTimestamps();
    }

    public function isQaOfficer(): bool
    {
        return $this->university_role === UniversityRole::QaOfficer->value;
    }

    public function canManageQualityStructure(): bool
    {
        return QualityRolePermission::roleHas($this->university_role, 'manage_structure');
    }

    public function canManageCourseAssignments(): bool
    {
        return QualityRolePermission::roleHas($this->university_role, 'manage_course_assignments');
    }

    public function canManageUsers(): bool
    {
        return QualityRolePermission::roleHas($this->university_role, 'manage_users');
    }

    public function canUploadQualityReports(): bool
    {
        return QualityRolePermission::roleHas($this->university_role, 'upload_reports');
    }

    public function canViewQualityReports(): bool
    {
        return QualityRolePermission::roleHas($this->university_role, 'view_reports');
    }

    public function canApproveQualityReports(): bool
    {
        return QualityRolePermission::roleHas($this->university_role, 'approve_reports');
    }

    public function canReviewQualityReports(): bool
    {
        return QualityRolePermission::roleHas($this->university_role, 'review_reports');
    }

    public function canViewApprovalQueue(): bool
    {
        return QualityRolePermission::roleHas($this->university_role, 'view_approval_queue')
            || $this->canReviewQualityReports()
            || $this->canApproveQualityReports();
    }

    public function canViewPrintQueue(): bool
    {
        return QualityRolePermission::roleHas($this->university_role, 'view_print_queue');
    }

    public function canViewAuditLogs(): bool
    {
        return QualityRolePermission::roleHas($this->university_role, 'view_audit_logs');
    }

    /**
     * @return array<string, mixed>
     */
    public function qualityProfile(): array
    {
        $permissions = QualityRolePermission::permissionsFor($this->university_role);

        return [
            'role' => $this->university_role,
            'roleLabel' => QualityRolePermission::labelFor($this->university_role),
            'school' => $this->school ? [
                'id' => $this->school->id,
                'name' => $this->school->name,
            ] : null,
            'department' => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null,
            'permissions' => $permissions,
            'canManageUsers' => $this->canManageUsers(),
            'canManageQualityStructure' => $this->canManageQualityStructure(),
            'canManageCourseAssignments' => $this->canManageCourseAssignments(),
            'canViewReports' => $this->canViewQualityReports(),
            'canUploadReports' => $this->canUploadQualityReports(),
            'canViewApprovalQueue' => $this->canViewApprovalQueue(),
            'canReviewReports' => $this->canReviewQualityReports(),
            'canApproveReports' => $this->canApproveQualityReports(),
            'canViewPrintQueue' => $this->canViewPrintQueue(),
            'canViewAuditLogs' => $this->canViewAuditLogs(),
        ];
    }
}
