<?php

namespace App\Services;

use App\Models\Pet;

class GroomingClinicReferralStatement
{
    public const CONSENT_VERSION = 'grooming_clinic_referral_consent_v1';

    public function consent(Pet $pet): string
    {
        return sprintf(
            'I authorize Bethlehem Animal Clinic to create a clinic referral for %s, transfer the pet from grooming handling to clinic intake, and perform an initial veterinary examination or assessment. This consent does not automatically authorize diagnostic procedures, medication, treatment, emergency procedures, or additional clinic charges. Separate approval may still be required.',
            trim((string) $pet->pet_name),
        );
    }
}
