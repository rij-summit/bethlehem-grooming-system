<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetLinkNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $resetUrl)
    {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiryMinutes = max(
            1,
            (int) config('auth.passwords.users.expire', 15),
        );

        return (new MailMessage)
            ->subject('Reset Your Password - Bethlehem Animal Clinic')
            ->greeting("Hi {$notifiable->first_name}!")
            ->line('A password reset was requested for your Bethlehem Animal Clinic account.')
            ->action('Reset Password', $this->resetUrl)
            ->line("This password reset link expires in {$expiryMinutes} minutes and can only be used once.")
            ->line('If you did not request a password reset, no further action is required.');
    }
}
