<?php

namespace App\Services;

class ChatbotFallbackService
{
    public function __construct(
        private readonly ChatbotKnowledgeService $knowledge,
    ) {}

    public function clarification(string $message): ?string
    {
        $trimmed = trim($message);

        if (preg_match('/^(?:how\s+much|price|cost|magkano|presyo)\s*[?.!]*$/iu', $trimmed) === 1) {
            return 'Do you mean **grooming prices** or a clinic service?';
        }

        if (preg_match('/^(?:what\s+time|what\s+hours?|hours?|until\s+when|anong\s+oras|hanggang\s+kailan)\s*[?.!]*$/iu', $trimmed) === 1) {
            return 'Do you mean **clinic hours**, grooming hours, or the same-day pre-registration cutoff?';
        }

        if (preg_match('/^(?:status|what\s+is\s+the\s+status|ano\s+status)\s*[?.!]*$/iu', $trimmed) === 1) {
            return 'Do you want the clinic open status or the status of **your booking**?';
        }

        if (preg_match('/^(?:how\s+long|until|gaano\s+katagal)\s*[?.!]*$/iu', $trimmed) === 1) {
            return 'Do you mean grooming duration, queue waiting time, or clinic/pre-registration hours?';
        }

        return null;
    }

    /**
     * @param array{
     *     clinic_status: string,
     *     clinic_operating_hours: string,
     *     clinic_pre_registration_cutoff: string,
     *     grooming_operating_hours: string,
     *     grooming_pre_registration_cutoff: string,
     *     groomers_on_duty: int
     * } $context
     */
    public function reply(string $message, array $context): string
    {
        $filipino = $this->prefersFilipino($message);

        $guides = $this->knowledge->customerGuides();
        foreach ([
            'changes' => '/\b(?:reschedule|cancel|adjust|kansela)\b/iu',
            'password' => '/\b(?:password|verification\s+code)\b/iu',
            'account' => '/\b(?:create|make|sign\s*up|signup|verify|verification)\b.*\b(?:account|email)\b|\bsign\s*up\b/iu',
            'pet' => '/\b(?:add|update|edit)\b.*\b(?:pet|dog|cat|aso|pusa|alaga)\b/iu',
            'tracker' => '/\b(?:tracker|being\s+groomed|grooming\s+progress)\b/iu',
            'history' => '/\b(?:grooming\s+history|previous\s+grooming)\b/iu',
            'settings' => '/\b(?:settings|account\s+information|personal\s+information)\b/iu',
            'signin' => '/\b(?:sign\s*in|log\s*in)\b/iu',
            'notifications' => '/\b(?:notification|pickup|pick\s*up|ready)\b/iu',
        ] as $topic => $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return $guides[$topic];
            }
        }

        if (preg_match('/\b(?:appointments?|reserve|reservation|walk-?ins?|walk\s+ins?|queue|pila|check-?in)\b/iu', $message) === 1) {
            return $this->knowledge->visitProcess($filipino ? 'filipino' : 'english');
        }

        if (preg_match('/\b(?:hours?|open|close|cutoff|oras|bukas|sarado|hanggang)\b/iu', $message) === 1) {
            return $filipino
                ? implode("\n\n", [
                    '**Clinic:** '.$context['clinic_operating_hours'].' araw-araw; same-day pre-registration cutoff: '.$context['clinic_pre_registration_cutoff'].'.',
                    '**Grooming:** '.$context['grooming_operating_hours'].' araw-araw; same-day pre-registration cutoff: '.$context['grooming_pre_registration_cutoff'].'.',
                ])
                : implode("\n\n", [
                    '**Clinic:** '.$context['clinic_operating_hours'].' daily; same-day pre-registration cutoff: '.$context['clinic_pre_registration_cutoff'].'.',
                    '**Grooming:** '.$context['grooming_operating_hours'].' daily; same-day pre-registration cutoff: '.$context['grooming_pre_registration_cutoff'].'.',
                ]);
        }

        if (preg_match('/\b(?:pre[- ]?register|preregister|registration|book|schedule)\b/iu', $message) === 1) {
            return $guides['preregister'];
        }

        if (preg_match('/\b(?:location|located|address|directions|contact|phone|lokasyon|numero)\b|\b(?:where|saan)\b.*\b(?:clinic|bethlehem)\b/iu', $message) === 1) {
            return implode("\n\n", [
                '**Location:** Along Ortigas Avenue Extension — https://maps.app.goo.gl/GJapKhegkDLDkoNy9',
                '**Contact:** 7007-3122 or 0917-113-1941.',
            ]);
        }

        if (preg_match('/\b(?:price|cost|fee|magkano|presyo|grooming\s+services?)\b/iu', $message) === 1) {
            return "**Current grooming services and estimates**\n\n"
                .$this->knowledge->conciseCatalog()
                ."\n\nTell me whether your pet is a dog or cat and its size or weight for the applicable package price.";
        }

        if (preg_match('/\b(?:sedation|pampatulog|consent|pahintulot)\b/iu', $message) === 1) {
            return 'Grooming consent is required, while **sedation consent is optional**. Sedation may only be considered if necessary for safe handling after clinic screening. If declined, staff may stop grooming and contact you instead.';
        }

        return $filipino
            ? 'Anong tulong ang kailangan mo tungkol sa Bethlehem — grooming, clinic visit, o customer account? Kung hindi ko makumpirma, puwedeng tumulong ang clinic staff sa 7007-3122 o 0917-113-1941.'
            : 'What would you like help with at Bethlehem — grooming, a clinic visit, or your customer account? For information I cannot confirm, clinic staff can help at 7007-3122 or 0917-113-1941.';
    }

    public function privacyReply(string $message): string
    {
        return $this->prefersFilipino($message)
            ? 'Para sa iyong **privacy**, huwag magpadala dito ng password, verification code, payment card details, email address, o phone number. Itanong muli nang walang private information.'
            : 'For your **privacy**, do not send passwords, verification codes, payment card details, email addresses, or phone numbers here. Please ask again without private information.';
    }

    public function handoff(bool $filipino = false): string
    {
        return $filipino
            ? 'Hindi ko makumpirma ang impormasyong iyon. Makipag-ugnayan sa **clinic staff** sa 7007-3122 o 0917-113-1941.'
            : 'I cannot confirm that information. Please contact **clinic staff** at 7007-3122 or 0917-113-1941.';
    }

    private function prefersFilipino(string $message): bool
    {
        return preg_match(
            '/\b(?:ano|paano|saan|kailan|bakit|magkano|ba|po|kayo|aso|pusa|alaga|bukas|sarado|oras|pila|gupit|paligo)\b/iu',
            $message
        ) === 1;
    }
}
