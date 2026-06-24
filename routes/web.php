<?php

use App\Http\Controllers\Quality\AcademicYearController;
use App\Http\Controllers\Quality\AuditLogController;
use App\Http\Controllers\Quality\CourseController;
use App\Http\Controllers\Quality\DepartmentController;
use App\Http\Controllers\Quality\QualityReportController;
use App\Http\Controllers\Quality\RoleRightController;
use App\Http\Controllers\Quality\SchoolController;
use App\Http\Controllers\Quality\UserController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    });

Route::middleware(['auth'])->group(function () {
    Route::get('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
});

Route::middleware(['auth', 'verified'])
    ->prefix('quality')
    ->name('quality.')
    ->group(function () {
        Route::get('reports', [QualityReportController::class, 'index'])->name('reports.index');
        Route::get('approvals', [QualityReportController::class, 'approvals'])->name('reports.approvals');
        Route::get('print-queue', [QualityReportController::class, 'printQueue'])->name('reports.print-queue');
        Route::get('reports/create', [QualityReportController::class, 'create'])->name('reports.create');
        Route::post('reports', [QualityReportController::class, 'store'])->name('reports.store');
        Route::post('reports/{report}/push', [QualityReportController::class, 'push'])->name('reports.push');
        Route::post('reports/{report}/approve', [QualityReportController::class, 'approve'])->name('reports.approve');
        Route::post('reports/{report}/return', [QualityReportController::class, 'returnToOfficer'])->name('reports.return');
        Route::post('reports/{report}/unapprove', [QualityReportController::class, 'unapprove'])->name('reports.unapprove');
        Route::delete('reports/{report}', [QualityReportController::class, 'destroy'])->name('reports.destroy');
        Route::get('reports/{report}/file', [QualityReportController::class, 'file'])->name('reports.file');
        Route::get('reports/{report}/download', [QualityReportController::class, 'download'])->name('reports.download');
        Route::get('reports/{report}', [QualityReportController::class, 'show'])->name('reports.show');

        Route::get('schools', [SchoolController::class, 'index'])->name('schools.index');
        Route::post('schools', [SchoolController::class, 'store'])->name('schools.store');

        Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');

        Route::get('courses', [CourseController::class, 'index'])->name('courses.index');
        Route::post('courses', [CourseController::class, 'store'])->name('courses.store');
        Route::post('courses/import', [CourseController::class, 'import'])->name('courses.import');
        Route::patch('courses/{course}', [CourseController::class, 'update'])->name('courses.update');
        Route::delete('courses/{course}', [CourseController::class, 'destroy'])->name('courses.destroy');

        Route::get('academic-years', [AcademicYearController::class, 'index'])->name('academic-years.index');
        Route::post('academic-years', [AcademicYearController::class, 'store'])->name('academic-years.store');
        Route::patch('academic-years/{academicYear}', [AcademicYearController::class, 'update'])->name('academic-years.update');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');

        Route::get('roles-rights', [RoleRightController::class, 'index'])->name('roles-rights.index');
        Route::post('roles-rights', [RoleRightController::class, 'store'])->name('roles-rights.store');
        Route::patch('roles-rights/{role}', [RoleRightController::class, 'update'])->name('roles-rights.update');
        Route::delete('roles-rights/{role}', [RoleRightController::class, 'destroy'])->name('roles-rights.destroy');

        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('audit-logs/export', [AuditLogController::class, 'export'])->name('audit-logs.export');
    });

require __DIR__.'/settings.php';
