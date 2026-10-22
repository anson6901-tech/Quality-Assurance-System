<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTeams;
use App\Enums\Placement;
use App\Enums\UniversityRole;
use App\Notifications\ResetPasswordNotification;
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

#[Fillable(['name', 'email', 'staff_id', 'password', 'must_change_password', 'email_verified_at', 'current_team_id', 'university_role', 'school_id', 'department_id', 'placement', 'is_active'])]
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
            'placement' => Placement::class,
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
            ->withPivot(['program_id', 'section_id', 'semester_id', 'student_count', 'allocated_hours'])
            ->withTimestamps();
    }

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function isQaOfficer(): bool
    {
        return $this->university_role === UniversityRole::QaOfficer->value;
    }

    /**
     * Rights only take effect once a user is placed on the organizational structure. Until
     * then a user, whatever their role, sees only their own assigned courses and their own
     * published reports (My Courses).
     */
    public function isPlaced(): bool
    {
        return $this->placement !== null;
    }

    public function isPlacedAtUniversity(): bool
    {
        return $this->placement === Placement::University;
    }

    /**
     * Whether the role lets the user see every report in their placement area, rather
     * than only their own.
     */
    public function canViewAreaReports(): bool
    {
        return $this->isPlaced()
            && QualityRolePermission::roleHas($this->university_role, 'view_area_reports');
    }

    /**
     * Schools, departments, programs, the course catalogue and academic years are shared
     * by the whole university, so only university-placed users may change them.
     */
    public function canManageQualityStructure(): bool
    {
        return $this->isPlacedAtUniversity()
            && QualityRolePermission::roleHas($this->university_role, 'manage_structure');
    }

    public function canManageCourseAssignments(): bool
    {
        return $this->isPlaced()
            && QualityRolePermission::roleHas($this->university_role, 'manage_course_assignments');
    }

    public function canManageUsers(): bool
    {
        return $this->isPlaced()
            && QualityRolePermission::roleHas($this->university_role, 'manage_users');
    }

    /**
     * Roles and rights apply across the university, so only university-placed user managers
     * may change them.
     */
    public function canManageRoles(): bool
    {
        return $this->isPlacedAtUniversity() && $this->canManageUsers();
    }

    public function canUploadQualityReports(): bool
    {
        return $this->isPlaced()
            && QualityRolePermission::roleHas($this->university_role, 'upload_reports');
    }

    public function canViewQualityReports(): bool
    {
        return $this->isPlaced()
            && QualityRolePermission::roleHas($this->university_role, 'view_reports');
    }

    public function canApproveQualityReports(): bool
    {
        return $this->isPlaced()
            && QualityRolePermission::roleHas($this->university_role, 'approve_reports');
    }

    public function canReviewQualityReports(): bool
    {
        return $this->isPlaced()
            && QualityRolePermission::roleHas($this->university_role, 'review_reports');
    }

    public function canViewApprovalQueue(): bool
    {
        return ($this->isPlaced() && QualityRolePermission::roleHas($this->university_role, 'view_approval_queue'))
            || $this->canReviewQualityReports()
            || $this->canApproveQualityReports();
    }

    public function canViewPrintQueue(): bool
    {
        if ($this->university_role === UniversityRole::Lecturer->value) {
            return false;
        }

        return $this->isPlaced()
            && QualityRolePermission::roleHas($this->university_role, 'view_print_queue');
    }

    public function canViewAuditLogs(): bool
    {
        return $this->isPlacedAtUniversity()
            && QualityRolePermission::roleHas($this->university_role, 'view_audit_logs');
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
            'placement' => $this->placement?->value,
            'placementLabel' => $this->placementLabel(),
            'permissions' => $permissions,
            'canManageUsers' => $this->canManageUsers(),
            'canManageRoles' => $this->canManageRoles(),
            'canManageQualityStructure' => $this->canManageQualityStructure(),
            'canManageCourseAssignments' => $this->canManageCourseAssignments(),
            'canViewReports' => $this->canViewQualityReports(),
            'canUploadReports' => $this->canUploadQualityReports(),
            'canViewApprovalQueue' => $this->canViewApprovalQueue(),
            'canReviewReports' => $this->canReviewQualityReports(),
            'canApproveReports' => $this->canApproveQualityReports(),
            'canViewPrintQueue' => $this->canViewPrintQueue(),
            'canViewAuditLogs' => $this->canViewAuditLogs(),
            'canManageEmailSettings' => $this->canManageEmailSettings(),
        ];
    }

    /**
     * Email is configured for the whole university, so only university-placed users may change it.
     */
    public function canManageEmailSettings(): bool
    {
        return $this->isPlacedAtUniversity()
            && QualityRolePermission::roleHas($this->university_role, 'manage_email_settings');
    }

    /**
     * Human-readable position on the organizational structure, such as "Dept. of Nursing".
     */
    public function placementLabel(): string
    {
        return match ($this->placement) {
            Placement::University => __('Whole university'),
            Placement::School => $this->school?->name ?? __('School'),
            Placement::Department => $this->department?->name ?? __('Department'),
            null => __('Not placed'),
        };
    }
}
