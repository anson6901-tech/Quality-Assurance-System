<?php

namespace App\Notifications;

use App\Models\QualityReport;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails the next person in the report workflow: reviewers when a report waits for them,
 * the lecturer when it is published, and the officer or supervisor when it is returned.
 */
class ReportWorkflowNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** A report deleted before the email goes out is simply skipped. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        private readonly QualityReport $report,
        private readonly string $action,
        private readonly string $actorName,
        private readonly ?string $note = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $report = $this->report->loadMissing(['courses:id,code,title', 'lecturer:id,name', 'academicYear:id,name']);
        $courses = $report->courses->map(fn ($course) => "{$course->code} {$course->title}")->implode(', ');
        $url = route('quality.reports.show', $report);

        $message = (new MailMessage)->greeting(__('Hello :name,', ['name' => $notifiable->name]));

        $message = match ($this->action) {
            'published' => $message
                ->subject(__('Your QA report has been published: :title', ['title' => $report->title]))
                ->line(__('A quality assurance report about your teaching has been approved and published.'))
                ->action(__('View the report'), $url),
            'submitted_to_supervisor' => $message
                ->subject(__('Report awaiting your review: :title', ['title' => $report->title]))
                ->line(__(':actor sent you a report to review.', ['actor' => $this->actorName]))
                ->action(__('Review the report'), $url),
            'forwarded_to_director' => $message
                ->subject(__('Report awaiting approval: :title', ['title' => $report->title]))
                ->line(__(':actor forwarded a report for your approval.', ['actor' => $this->actorName]))
                ->action(__('Open the report'), $url),
            'returned_to_officer', 'returned_to_supervisor' => $message
                ->subject(__('Report returned: :title', ['title' => $report->title]))
                ->line(__(':actor returned this report for changes.', ['actor' => $this->actorName]))
                ->action(__('Open the report'), $url),
            default => $message->subject($report->title)->action(__('Open the report'), $url),
        };

        $details = array_filter([
            __('Report') => $report->title,
            __('Lecturer') => $report->lecturer?->name,
            __('Courses') => $courses ?: null,
            __('Academic year') => $report->academicYear?->name,
        ]);

        foreach ($details as $label => $value) {
            $message->line("**{$label}:** {$value}");
        }

        if (filled($this->note)) {
            $message->line('**'.__('Note').':** '.$this->note);
        }

        return $message;
    }
}
