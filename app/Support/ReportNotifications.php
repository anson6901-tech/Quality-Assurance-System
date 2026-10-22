<?php

namespace App\Support;

use App\Models\EmailSetting;
use App\Models\QualityReport;
use App\Models\QualityRolePermission;
use App\Models\User;
use App\Notifications\ReportWorkflowNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Decides who is emailed at each step of the report workflow, honouring the toggles on the
 * Email Settings page.
 */
class ReportNotifications
{
    private const TOGGLES = [
        'submitted_to_supervisor' => 'send_review_requests',
        'forwarded_to_director' => 'send_review_requests',
        'published' => 'send_report_published',
        'returned_to_officer' => 'send_report_returned',
        'returned_to_supervisor' => 'send_report_returned',
    ];

    public static function dispatch(QualityReport $report, User $actor, string $action, ?string $note): void
    {
        $toggle = self::TOGGLES[$action] ?? null;

        if ($toggle === null || ! EmailSetting::allows($toggle)) {
            return;
        }

        $recipients = self::recipients($report, $action)
            ->filter(fn (?User $user) => $user !== null && $user->is_active && ! $user->is($actor))
            ->unique('id')
            ->values();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ReportWorkflowNotification($report, $action, $actor->name, $note));
        }
    }

    /**
     * @return Collection<int, User|null>
     */
    private static function recipients(QualityReport $report, string $action): Collection
    {
        return match ($action) {
            'submitted_to_supervisor', 'returned_to_supervisor' => collect([$report->assignedSupervisor]),
            'forwarded_to_director' => self::approversFor($report),
            'published' => collect([$report->lecturer]),
            'returned_to_officer' => collect([$report->uploader]),
            default => collect(),
        };
    }

    /**
     * Active users who may approve reports and whose placement covers this report.
     *
     * @return Collection<int, User>
     */
    private static function approversFor(QualityReport $report): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereNotNull('placement')
            ->whereIn('university_role', QualityRolePermission::rolesWith('approve_reports'))
            ->get()
            ->filter(fn (User $user) => Area::reports(QualityReport::query()->whereKey($report->id), $user)->exists())
            ->values();
    }
}
