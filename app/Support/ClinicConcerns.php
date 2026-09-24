<?php

namespace App\Support;

class ClinicConcerns
{
    public const OPTIONS = [
        'Routine check-up',
        'Vaccination',
        'Vomiting',
        'Diarrhea',
        'Not eating',
        'Skin / itching',
        'Ear problem',
        'Eye problem',
        'Coughing / sneezing',
        'Limping / injury',
        'Other',
    ];

    public static function summary(array $concerns, ?string $details): string
    {
        $details = trim($details ?? '');

        return implode(', ', $concerns).($details !== '' ? "\n{$details}" : '');
    }
}
