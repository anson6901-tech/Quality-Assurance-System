<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a new user their account exists and gives them the system-generated temporary
 * password. They must choose their own password the first time they sign in. The queued
 * job is encrypted because it carries the password.
 */
class WelcomeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] private readonly string $temporaryPassword)
    {
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
        return (new MailMessage)
            ->subject(__('Your QA Insight account is ready'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('An account has been created for you on QA Insight, the UHAS quality assurance system.'))
            ->line(__('**Email:** :email', ['email' => $notifiable->email]))
            ->line(__('**Temporary password:** `:password`', ['password' => $this->temporaryPassword]))
            ->action(__('Sign in'), route('login'))
            ->line(__('You will be asked to choose your own password the first time you sign in.'))
            ->line(__('If you lose this email, use "Forgot password" on the sign-in page.'));
    }
}
