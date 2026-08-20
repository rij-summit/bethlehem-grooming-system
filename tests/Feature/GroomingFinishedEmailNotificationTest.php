<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\GroomingFinishedNotification;
use Tests\TestCase;

class GroomingFinishedEmailNotificationTest extends TestCase
{
    public function test_email_has_simple_grooming_message_and_embedded_bethlehem_logo(): void
    {
        $customer = new User([
            'first_name' => 'Jamie',
            'email' => 'jamie@example.test',
        ]);

        $customer->notifyNow(new GroomingFinishedNotification(['Max']));

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $email = $messages[0]->getOriginalMessage();
        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();

        $this->assertSame('Grooming Done - Bethlehem Animal Clinic', $email->getSubject());
        $this->assertStringContainsString('Hi Jamie,', $html);
        $this->assertStringContainsString('Grooming is done for <strong>Max</strong>.', $html);
        $this->assertStringContainsString('Bethlehem Animal Clinic &amp; Grooming logo', $html);
        $this->assertStringContainsString('cid:', $html);
        $this->assertStringContainsString('Grooming is done for Max.', $text);
        $this->assertCount(1, $email->getAttachments());
    }
}
