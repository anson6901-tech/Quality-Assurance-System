<?php

namespace App\Notifications;

use App\Models\EmailSetting;
use App\Models\QualityReport;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Emails the next person in the report workflow: reviewers when a report waits for them,
 * the lecturer when it is published (wording and PDF attachment set on Email Settings), and the officer or supervisor when it is returned.
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
        if ($this->action === 'published') {
            return $this->publishedMail($notifiable);
        }

        $report = $this->report->loadMissing(['courses:id,code,title', 'lecturer:id,name', 'academicYear:id,name']);
        $courses = $report->courses->map(fn ($course) => "{$course->code} {$course->title}")->implode(', ');
        $url = route('quality.reports.show', $report);

        $message = (new MailMessage)->greeting(__('Hello :name,', ['name' => $notifiable->name]));

        $message = match ($this->action) {
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

    /**
     * The "report published" email to the lecturer, written on the Email Settings page, with the
     * report PDF attached unless that is switched off there.
     */
    private function publishedMail(User $notifiable): MailMessage
    {
        $report = $this->report->loadMissing(['courses:id,code,title', 'lecturer:id,name', 'academicYear:id,name', 'semester:id,name']);
        $settings = EmailSetting::cached() ?? new EmailSetting;
        $values = self::publishedValues($report, $notifiable, $settings);
        $fill = fn (string $text) => strtr($text, $values);

        // The first paragraph is the greeting; each following paragraph is a line of the email.
        $paragraphs = collect(preg_split('/\R\s*\R/', trim($fill($settings->publishedBody()))))
            ->map(fn (string $paragraph) => trim((string) preg_replace('/\s*\R\s*/', ' ', $paragraph)))
            ->filter()
            ->values();

        $message = (new MailMessage)
            ->subject($fill($settings->publishedSubject()))
            ->greeting($paragraphs->first() ?? __('Hello :name,', ['name' => $notifiable->name]));

        foreach ($paragraphs->slice(1) as $paragraph) {
            $message->line($paragraph);
        }

        $message->action(__('View the report'), $values['{report_link}']);

        if (filled($this->note)) {
            $message->line('**'.__('Note').':** '.$this->note);
        }

        if ($settings->attachesPublishedPdf() && $report->file_path && Storage::disk('public')->exists($report->file_path)) {
            $message->attach(Storage::disk('public')->path($report->file_path), [
                'as' => str($report->title)->slug()->limit(100, '')->append('.pdf')->toString(),
                'mime' => 'application/pdf',
            ]);
        }

        return $message;
    }

    /**
     * What each {placeholder} in the published email stands for.
     *
     * @return array<string, string>
     */
    public static function publishedValues(QualityReport $report, User $recipient, EmailSetting $settings): array
    {
        return [
            '{lecturer_name}' => $report->lecturer?->name ?? $recipient->name,
            '{report_title}' => $report->title,
            '{courses}' => $report->courses->map(fn ($course) => "{$course->code} {$course->title}")->implode(', ') ?: __('your course'),
            '{academic_year}' => $report->academicYear?->name ?? '',
            '{semester}' => $report->semester?->name ?? '',
            '{report_link}' => route('quality.reports.show', $report),
            '{portal_link}' => route('quality.my-courses.index'),
            '{sender_name}' => $settings->sender_name ?: (string) config('app.name'),
        ];
    }
}
