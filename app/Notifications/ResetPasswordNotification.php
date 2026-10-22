<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Password reset link, sent in the background so a slow mail server never holds up the
 * "Forgot password" page.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        parent::__construct($token);
        $this->afterCommit();
    }

    protected function buildMailMessage($url): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject(__('Reset your QA Insight password'))
            ->line(__('We received a request to reset the password for your QA Insight account.'))
            ->action(__('Reset password'), $url)
            ->line(__('This link expires in :count minutes.', ['count' => $minutes]))
            ->line(__('If you did not ask for this, you can ignore this email. Your password stays the same.'));
    }
}
