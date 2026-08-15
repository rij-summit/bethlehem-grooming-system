<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends Notification
{
    public function __construct(private string $verificationUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify Your Email — Bethlehem Animal Clinic')
            ->greeting("Hi {$notifiable->first_name}!")
            ->line('Thank you for registering at Bethlehem Animal Clinic & Grooming.')
            ->line('Please click the button below to verify your email address and activate your account.')
            ->action('Verify Email Address', $this->verificationUrl)
            ->line('This link expires in **24 hours**.')
            ->line('If you did not create an account, no further action is required.');
    }
}
