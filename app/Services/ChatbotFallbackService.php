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
            return implode("\n\n", [
                'Do you mean **grooming prices** or a clinic service?',
                'For grooming, tell me whether your pet is a dog or cat and include its size or weight.',
            ]);
        }

        if (preg_match('/^(?:what\s+time|what\s+hours?|hours?|until\s+when|anong\s+oras|hanggang\s+kailan)\s*[?.!]*$/iu', $trimmed) === 1) {
            return 'Do you mean **clinic hours**, grooming hours, or the same-day pre-registration cutoff?';
        }

        if (preg_match('/^(?:status|what\s+is\s+the\s+status|ano\s+status)\s*[?.!]*$/iu', $trimmed) === 1) {
            return 'Do you want the clinic open status or the status of **your booking**?';
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

        if (preg_match('/\b(?:where|location|located|address|directions|contact|phone|number|saan|lokasyon|numero)\b/iu', $message) === 1) {
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

        if (preg_match('/\b(?:walk-?in|walk\s+in)\b/iu', $message) === 1) {
            return 'Yes, **walk-ins are accepted** for clinic and grooming services while the clinic is open and capacity is available. Queue position begins only after arrival and successful check-in.';
        }

        if (preg_match('/\b(?:queue|pila|check-?in)\b/iu', $message) === 1) {
            return 'Online pre-registration does not reserve a queue position. Your pet is officially queued only after **successful check-in** at the clinic.';
        }

        if (preg_match('/\badjust\b/iu', $message) === 1) {
            return 'To adjust an existing booking date or time, open **"Schedules"**, select the pre-registration, and choose "Reschedule". If you mean a different detail, tell me which one.';
        }

        if (preg_match('/\b(?:book|reserve|reservation|pre-?register|registration|appointment|schedule|magpa-?book)\b/iu', $message) === 1) {
            return "**How to pre-register**\n\n1. Open the Dashboard and click \"Pre-register\".\n2. Choose \"Grooming\" or \"Clinic\" and select your pet.\n3. Complete the information and click \"Submit\".";
        }

        if (preg_match('/\b(?:reschedule|cancel|kansela)\b/iu', $message) === 1) {
            return 'Open **"Schedules"** on the Dashboard, select the pre-registration, then choose "Reschedule" or "Cancel".';
        }

        if (preg_match('/\b(?:password|login|account|sign\s*up|verify|verification)\b/iu', $message) === 1) {
            return 'For password help, click **"Forgot Password"**, enter the account email, open the emailed reset link, create a new password, and sign in again. Verification and password-reset codes should never be shared in chat.';
        }

        if (preg_match('/\b(?:add|update|edit)\b.*\b(?:pet|aso|pusa|alaga)\b/iu', $message) === 1) {
            return 'Use Dashboard > **"Quick Actions"** > "Add Pet", or open "My Pets" to add or update pet information.';
        }

        if (preg_match('/\b(?:pickup|pick\s*up|notification|ready)\b/iu', $message) === 1) {
            return 'When grooming is finished, a **ready-for-pickup notification** appears on the Dashboard and is also sent by email.';
        }

        if (preg_match('/\b(?:sedation|pampatulog|consent|pahintulot)\b/iu', $message) === 1) {
            return 'Grooming consent is required, while **sedation consent is optional**. Sedation may only be considered if necessary for safe handling after clinic screening. If declined, staff may stop grooming and contact you instead.';
        }

        return $this->handoff($filipino);
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
