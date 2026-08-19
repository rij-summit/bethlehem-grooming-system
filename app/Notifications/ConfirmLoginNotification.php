<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConfirmLoginNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $code)
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
        $expiryMinutes = max(1, (int) config('app.login_confirmation_ttl_minutes', 15));

        return (new MailMessage)
            ->subject('Your Sign-In Code - Bethlehem Animal Clinic')
            ->greeting("Hi {$notifiable->first_name}!")
            ->line('We received a request to sign in to your Bethlehem Animal Clinic account.')
            ->line('Your six-digit sign-in code is:')
            ->line("**{$this->code}**")
            ->line("This code expires in {$expiryMinutes} minutes.")
            ->line('If you did not try to sign in, you can safely ignore this email.');
    }
}
