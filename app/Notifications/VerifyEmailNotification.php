<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private string $verificationUrl)
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
        $expiryHours = (int) config('app.email_verification_ttl_hours', 24);

        return (new MailMessage)
            ->subject('Verify Your Email — Bethlehem Animal Clinic')
            ->greeting("Hi {$notifiable->first_name}!")
            ->line('Thank you for registering at Bethlehem Animal Clinic & Grooming.')
            ->line('Please click the button below to verify your email address and activate your account.')
            ->action('Verify Email Address', $this->verificationUrl)
            ->line("This link expires in {$expiryHours} hours.")
            ->line('If you did not create an account, no further action is required.');
    }
}
