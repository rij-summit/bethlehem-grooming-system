<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GroomingFinishedNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<int, string>  $petNames
     */
    public function __construct(public readonly array $petNames)
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
        $firstName = trim((string) ($notifiable->first_name ?? '')) ?: 'there';

        return (new MailMessage)
            ->subject('Grooming Done - Bethlehem Animal Clinic')
            ->view([
                'html' => 'emails.grooming-finished',
                'text' => 'emails.grooming-finished-text',
            ], [
                'firstName' => $firstName,
                'petNames' => $this->formattedPetNames(),
                'logoPath' => base_path('assets/images/clinic/Bethlehem_Logo-256.png'),
            ]);
    }

    private function formattedPetNames(): string
    {
        $names = collect($this->petNames)
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->values();

        return match ($names->count()) {
            0 => 'your pet',
            1 => $names->first(),
            2 => $names->join(' and '),
            default => $names->slice(0, -1)->join(', ').', and '.$names->last(),
        };
    }
}
