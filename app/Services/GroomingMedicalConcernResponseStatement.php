<?php

namespace App\Services;

use App\Models\GroomingMedicalConcern;
use App\Models\Pet;

class GroomingMedicalConcernResponseStatement
{
    public const ACKNOWLEDGMENT_VERSION = 'grooming_concern_acknowledgment_v1';

    public const CONSENT_VERSION = 'grooming_concern_consent_v1';

    private const GROOMING_ACTION_LABELS = [
        GroomingMedicalConcern::ACTION_CONTINUE_WITH_OBSERVATION => 'Continue with observation',
        GroomingMedicalConcern::ACTION_PAUSE_GROOMING => 'Pause grooming',
        GroomingMedicalConcern::ACTION_STOP_GROOMING => 'Stop grooming',
    ];

    public function acknowledgment(
        Pet $pet,
        GroomingMedicalConcern $concern,
    ): string {
        return sprintf(
            'I confirm that I received and understood the grooming medical-concern notice for %s: "%s" The grooming team recommends: "%s." I understand that this acknowledgment records receipt and understanding only. It does not apply the recommended action, change grooming automatically, or replace veterinary advice.',
            $pet->pet_name,
            trim((string) $concern->customer_message),
            $this->actionLabel($concern),
        );
    }

    public function consent(
        Pet $pet,
        GroomingMedicalConcern $concern,
    ): string {
        return sprintf(
            'For %s, I reviewed this grooming medical-concern notice: "%s" The grooming team recommends: "%s." Selecting Approve means I accept this proposed grooming action. Selecting Decline means I do not accept this proposed grooming action. I understand that either decision is final for this response, does not itself apply the action or resume grooming, and does not replace veterinary advice.',
            $pet->pet_name,
            trim((string) $concern->customer_message),
            $this->actionLabel($concern),
        );
    }

    private function actionLabel(GroomingMedicalConcern $concern): string
    {
        return self::GROOMING_ACTION_LABELS[
            $concern->recommended_grooming_action
        ] ?? 'No supported action was provided';
    }
}
